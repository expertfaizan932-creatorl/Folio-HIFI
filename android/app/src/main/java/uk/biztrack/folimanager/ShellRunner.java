package uk.biztrack.folimanager;

import android.util.Log;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.nio.charset.StandardCharsets;

/**
 * Runs shell commands for the remote console. A one-shot command backs the
 * "Command" tab; a long-lived process backs the interactive terminal so `cd`
 * and environment changes persist between keystrokes, exactly like the Windows
 * agent's cmd.exe session.
 */
public class ShellRunner {
    private static final String TAG = "ShellRunner";
    private static final int MAX_OUTPUT = 256 * 1024;

    public static class Result {
        public int exitCode;
        public String output;
    }

    /** Run one command to completion with `sh -c`. */
    public static Result runOnce(String command) {
        Result r = new Result();
        try {
            ProcessBuilder pb = new ProcessBuilder("/system/bin/sh", "-c", command);
            pb.redirectErrorStream(true);
            Process proc = pb.start();
            ByteArrayOutputStream buf = new ByteArrayOutputStream();
            InputStream in = proc.getInputStream();
            byte[] chunk = new byte[8192];
            int n;
            while ((n = in.read(chunk)) > 0) {
                if (buf.size() < MAX_OUTPUT) buf.write(chunk, 0, n);
            }
            r.exitCode = proc.waitFor();
            r.output = new String(buf.toByteArray(), StandardCharsets.UTF_8);
        } catch (Exception e) {
            r.exitCode = 1;
            r.output = "error: " + e.getMessage();
        }
        return r;
    }

    /** A persistent shell process for the interactive terminal. */
    public static class Interactive {
        private Process process;
        private OutputStream stdin;
        private volatile boolean running;
        private Thread reader;
        private long shellId;

        public interface Sink {
            void onOutput(String data);
        }

        public Interactive(long shellId, Sink sink) throws Exception {
            this.shellId = shellId;
            ProcessBuilder pb = new ProcessBuilder("/system/bin/sh");
            pb.redirectErrorStream(true);
            process = pb.start();
            stdin = process.getOutputStream();
            running = true;

            reader = new Thread(() -> {
                byte[] chunk = new byte[8192];
                StringBuilder pending = new StringBuilder();
                long lastFlush = System.currentTimeMillis();
                try {
                    InputStream in = process.getInputStream();
                    int n;
                    while (running && (n = in.read(chunk)) > 0) {
                        pending.append(new String(chunk, 0, n, StandardCharsets.UTF_8));
                        long now = System.currentTimeMillis();
                        if (now - lastFlush > 250 || pending.length() > 8192) {
                            if (pending.length() > 0) {
                                sink.onOutput(pending.toString());
                                pending.setLength(0);
                            }
                            lastFlush = now;
                        }
                    }
                    if (pending.length() > 0) sink.onOutput(pending.toString());
                } catch (Exception e) {
                    Log.w(TAG, "reader ended: " + e.getMessage());
                }
            }, "shell-reader-" + shellId);
            reader.setDaemon(true);
            reader.start();
        }

        public synchronized void write(String line) {
            try {
                stdin.write((line + "\n").getBytes(StandardCharsets.UTF_8));
                stdin.flush();
            } catch (Exception e) {
                Log.w(TAG, "write failed: " + e.getMessage());
            }
        }

        public void close() {
            running = false;
            try { if (stdin != null) stdin.close(); } catch (Exception ignored) {}
            try { process.destroy(); } catch (Exception ignored) {}
        }
    }
}
