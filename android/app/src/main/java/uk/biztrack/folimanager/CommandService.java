package uk.biztrack.folimanager;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;
import android.util.Log;

import org.json.JSONArray;
import org.json.JSONObject;

/**
 * The always-on half of Folio Manager. It holds the agent token and runs three
 * short-poll loops against the same PHP endpoints the Windows agent uses:
 *
 *   /api/agent/remote/poll  + /report   one-shot commands (Command tab)
 *   /api/agent/shell/poll   + /out      the interactive terminal
 *   /api/agent/fs/poll      + /out      the remote file manager
 *
 * It is a foreground service so Android keeps it alive while the employee uses
 * the phone for anything else. It does NOT clock in or out and records nothing
 * on its own; it only answers the owner console.
 */
public class CommandService extends Service {
    private static final String TAG = "FolioService";
    public static final String CHANNEL_ID = "folio_service";
    private static final int NOTIF_ID = 4242;

    private volatile boolean stop = false;
    private Thread cmdThread;
    private Thread shellThread;
    private Thread fsThread;

    private ShellRunner.Interactive shell;
    private long shellId = -1;

    @Override
    public void onCreate() {
        super.onCreate();
        createChannel();
        Notification n = new Notification.Builder(this, CHANNEL_ID)
                .setContentTitle("Folio Manager")
                .setContentText("Connected - remote management active")
                .setSmallIcon(android.R.drawable.stat_notify_sync)
                .setOngoing(true)
                .build();
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            startForeground(NOTIF_ID, n, android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC);
        } else {
            startForeground(NOTIF_ID, n);
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (cmdThread == null) {
            stop = false;
            cmdThread = new Thread(this::commandLoop, "folio-cmd");
            shellThread = new Thread(this::shellLoop, "folio-shell");
            fsThread = new Thread(this::fsLoop, "folio-fs");
            cmdThread.setDaemon(true);
            shellThread.setDaemon(true);
            fsThread.setDaemon(true);
            cmdThread.start();
            shellThread.start();
            fsThread.start();
        }
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        stop = true;
        if (shell != null) { shell.close(); shell = null; }
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private void createChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel c = new NotificationChannel(CHANNEL_ID,
                    getString(R.string.service_channel_name),
                    NotificationManager.IMPORTANCE_LOW);
            c.setShowBadge(false);
            getSystemService(NotificationManager.class).createNotificationChannel(c);
        }
    }

    // ------------------------------------------------------------ command loop

    private void commandLoop() {
        while (!stop) {
            try {
                JSONObject j = Api.post(this, "/api/agent/remote/poll", new JSONObject());
                if (j.optBoolean("ok", false)) {
                    JSONObject cmd = j.optJSONObject("command");
                    if (cmd != null) {
                        long id = cmd.optLong("id", 0);
                        String line = cmd.optString("command", "");
                        ShellRunner.Result r = ShellRunner.runOnce(line);
                        JSONObject rep = new JSONObject();
                        rep.put("id", id);
                        rep.put("exitcode", r.exitCode);
                        rep.put("output", r.output);
                        Api.post(this, "/api/agent/remote/report", rep);
                    }
                }
            } catch (Exception e) {
                Log.w(TAG, "command loop: " + e.getMessage());
            }
            sleep(1000);
        }
    }

    // -------------------------------------------------------------- shell loop

    private void shellLoop() {
        while (!stop) {
            boolean open = false;
            try {
                JSONObject j = Api.post(this, "/api/agent/shell/poll", new JSONObject());
                if (j.optBoolean("ok", false)) {
                    JSONObject sh = j.optJSONObject("shell");
                    if (sh != null) {
                        open = true;
                        long id = sh.optLong("id", 0);
                        if (shell == null || shellId != id) {
                            if (shell != null) shell.close();
                            shellId = id;
                            try {
                                shell = new ShellRunner.Interactive(id, data -> postShellOut(id, data));
                            } catch (Exception e) {
                                Log.w(TAG, "shell start failed: " + e.getMessage());
                                shell = null;
                            }
                        }
                        JSONArray inputs = sh.optJSONArray("inputs");
                        if (inputs != null && shell != null) {
                            for (int i = 0; i < inputs.length(); i++) {
                                String line = inputs.optJSONObject(i).optString("command", "");
                                postShellOut(id, "$ " + line + "\n");
                                shell.write(line);
                            }
                        }
                    }
                }
            } catch (Exception e) {
                Log.w(TAG, "shell loop: " + e.getMessage());
            }
            if (!open && shell != null) {
                shell.close();
                shell = null;
                shellId = -1;
            }
            sleep(open ? 300 : 3000);
        }
    }

    private void postShellOut(long id, String data) {
        try {
            JSONObject rep = new JSONObject();
            rep.put("shell", id);
            rep.put("data", data);
            Api.post(this, "/api/agent/shell/out", rep);
        } catch (Exception e) {
            Log.w(TAG, "shell out failed: " + e.getMessage());
        }
    }

    // ----------------------------------------------------------------- fs loop

    private void fsLoop() {
        while (!stop) {
            boolean open = false;
            try {
                JSONObject j = Api.post(this, "/api/agent/fs/poll", new JSONObject());
                if (j.optBoolean("ok", false)) {
                    JSONObject fs = j.optJSONObject("fs");
                    if (fs != null) {
                        open = true;
                        long id = fs.optLong("id", 0);
                        JSONArray inputs = fs.optJSONArray("inputs");
                        if (inputs != null) {
                            for (int i = 0; i < inputs.length(); i++) {
                                String opJson = inputs.optString(i, "");
                                String reply = FsOps.execute(opJson);
                                JSONObject rep = new JSONObject();
                                rep.put("fs", id);
                                rep.put("data", reply);
                                Api.post(this, "/api/agent/fs/out", rep);
                            }
                        }
                    }
                }
            } catch (Exception e) {
                Log.w(TAG, "fs loop: " + e.getMessage());
            }
            sleep(open ? 300 : 3000);
        }
    }

    private void sleep(long ms) {
        try {
            Thread.sleep(ms);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }
}
