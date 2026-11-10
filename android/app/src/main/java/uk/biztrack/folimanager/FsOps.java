package uk.biztrack.folimanager;

import android.os.Build;
import android.os.Environment;
import android.os.StatFs;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.RandomAccessFile;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;
import java.util.TimeZone;

/**
 * The filesystem half of the remote file manager, with no network in it: one
 * JSON op in, one JSON reply out. Mirrors the Windows agent's RemoteFsOps op
 * contract exactly (drives, list, stat, read, write, mkdir, rename, delete,
 * copy, move, ping) so the same desktop console drives both platforms.
 *
 * Android sandboxes an app to its own UID, so unlike the Windows agent this can
 * only reach shared storage (with "All files access") and the app's own folder.
 */
public class FsOps {
    public static final int CHUNK_BYTES = 1024 * 1024;
    private static final int MAX_READ_BYTES = 2 * 1024 * 1024;

    public static String execute(String opJson) {
        long rid = 0;
        try {
            JSONObject op = new JSONObject(opJson);
            rid = op.optLong("rid", 0);
            String name = op.optString("op", "");
            JSONObject reply = new JSONObject();
            reply.put("rid", rid);
            try {
                switch (name) {
                    case "ping": break;
                    case "drives": reply.put("drives", drives()); break;
                    case "list": reply.put("path", listDir(field(op, "path"), reply)); break;
                    case "stat": reply.put("stat", stat(field(op, "path"))); break;
                    case "read": readOp(field(op, "path"), op.optLong("offset", 0), op.optLong("length", CHUNK_BYTES), reply); break;
                    case "write": writeOp(field(op, "path"), op.optLong("offset", 0), field(op, "data"), reply); break;
                    case "mkdir": reply.put("path", mkdir(field(op, "path"))); break;
                    case "delete": delete(field(op, "path")); break;
                    case "rename": movePath(field(op, "path"), field(op, "to")); reply.put("to", field(op, "to")); break;
                    case "move": movePath(field(op, "path"), field(op, "to")); reply.put("to", field(op, "to")); break;
                    case "copy": copy(field(op, "path"), field(op, "to")); reply.put("to", field(op, "to")); break;
                    default: throw new Exception("Unknown op '" + name + "'");
                }
                reply.put("ok", true);
            } catch (Exception ex) {
                reply.put("ok", false);
                reply.put("error", ex.getMessage() == null ? "error" : ex.getMessage());
            }
            return reply.toString();
        } catch (Exception e) {
            return "{\"rid\":" + rid + ",\"ok\":false,\"error\":\"Malformed op\"}";
        }
    }

    private static JSONArray drives() {
        JSONArray arr = new JSONArray();
        java.util.LinkedHashMap<String, String> roots = new java.util.LinkedHashMap<>();

        String internal = null;
        try {
            File ext = Environment.getExternalStorageDirectory();
            if (ext != null) internal = ext.getAbsolutePath();
        } catch (Exception ignored) {}
        if (internal == null || internal.isEmpty()) internal = "/storage/emulated/0";
        roots.put(internal, "Internal storage");

        try {
            File[] vols = new File("/storage").listFiles();
            if (vols != null) {
                for (File v : vols) {
                    String p = v.getAbsolutePath();
                    if (p.contains("emulated") || p.contains("/self")) continue;
                    if (p.equals(internal) || p.equals("/sdcard")) continue;
                    if (!v.isDirectory()) continue;
                    if (!roots.containsKey(p)) roots.put(p, "SD card");
                }
            }
        } catch (Exception ignored) {}

        roots.put("/", "Device root");

        for (java.util.Map.Entry<String, String> e : roots.entrySet()) {
            String path = e.getKey();
            String label = e.getValue();
            File f = new File(path);
            try {
                JSONObject item = new JSONObject();
                item.put("name", path);
                item.put("type", "SD card".equals(label) ? "Removable" : "Fixed");
                item.put("ready", f.exists() && f.canRead());
                item.put("label", label);
                item.put("format", "");
                long[] space = spaceOf(path);
                item.put("total", space[0]);
                item.put("free", space[1]);
                arr.put(item);
            } catch (Exception ignored) {}
        }
        return arr;
    }

    private static long[] spaceOf(String path) {
        try {
            StatFs sf = new StatFs(path);
            return new long[]{ sf.getTotalBytes(), sf.getAvailableBytes() };
        } catch (Exception e) {
            return new long[]{ 0L, 0L };
        }
    }

    private static String listDir(String path, JSONObject reply) throws Exception {
        String full = fullPath(path);
        File dir = new File(full);
        if (!dir.isDirectory()) throw new Exception("Not a folder: " + full);
        JSONArray entries = new JSONArray();
        File[] subs = dir.listFiles();
        if (subs != null) {
            for (File f : subs) {
                JSONObject item = new JSONObject();
                item.put("name", f.getName());
                item.put("dir", f.isDirectory());
                item.put("size", f.isDirectory() ? 0L : f.length());
                item.put("modified", fmt(f.lastModified()));
                if (!f.isDirectory()) item.put("ext", extOf(f.getName()));
                entries.put(item);
            }
        }
        reply.put("entries", entries);
        return full;
    }

    private static JSONObject stat(String path) throws Exception {
        String full = fullPath(path);
        File f = new File(full);
        if (!f.exists()) throw new Exception("Not found: " + full);
        JSONObject item = new JSONObject();
        item.put("dir", f.isDirectory());
        item.put("size", f.isDirectory() ? 0L : f.length());
        item.put("modified", fmt(f.lastModified()));
        return item;
    }

    private static void readOp(String path, long offset, long length, JSONObject reply) throws Exception {
        String full = fullPath(path);
        RandomAccessFile raf = new RandomAccessFile(full, "r");
        try {
            long size = raf.length();
            if (offset < 0) offset = 0;
            if (offset > size) offset = size;
            long want = length <= 0 ? CHUNK_BYTES : length;
            if (want > MAX_READ_BYTES) want = MAX_READ_BYTES;
            if (want > size - offset) want = size - offset;
            byte[] buffer = new byte[(int) want];
            raf.seek(offset);
            int got = raf.read(buffer);
            if (got < 0) got = 0;
            byte[] out = got == buffer.length ? buffer : java.util.Arrays.copyOf(buffer, got);
            reply.put("data", android.util.Base64.encodeToString(out, android.util.Base64.NO_WRAP));
            reply.put("size", size);
            reply.put("eof", (offset + got) >= size);
        } finally {
            raf.close();
        }
    }

    private static void writeOp(String path, long offset, String base64, JSONObject reply) throws Exception {
        String full = fullPath(path);
        byte[] data = (base64 == null || base64.isEmpty()) ? new byte[0]
                : android.util.Base64.decode(base64, android.util.Base64.DEFAULT);
        File f = new File(full);
        if (f.getParentFile() != null) f.getParentFile().mkdirs();
        RandomAccessFile raf = new RandomAccessFile(f, "rw");
        try {
            if (offset > 0) raf.seek(offset);
            else raf.setLength(0);
            raf.write(data);
            reply.put("written", data.length);
            reply.put("size", raf.length());
        } finally {
            raf.close();
        }
    }

    private static String mkdir(String path) throws Exception {
        String full = fullPath(path);
        File f = new File(full);
        if (!f.exists() && !f.mkdirs()) throw new Exception("Cannot create folder: " + full);
        return full;
    }

    private static void delete(String path) throws Exception {
        String full = fullPath(path);
        File f = new File(full);
        if (!f.exists()) throw new Exception("Not found: " + full);
        if (!deleteRecursive(f)) throw new Exception("Cannot delete: " + full);
    }

    private static boolean deleteRecursive(File f) {
        if (f.isDirectory()) {
            File[] children = f.listFiles();
            if (children != null) {
                for (File c : children) deleteRecursive(c);
            }
        }
        return f.delete();
    }

    private static void movePath(String path, String to) throws Exception {
        String from = fullPath(path);
        String dest = fullPath(to);
        File src = new File(from);
        if (!src.exists()) throw new Exception("Not found: " + from);
        File dst = new File(dest);
        if (dst.getParentFile() != null) dst.getParentFile().mkdirs();
        if (!src.renameTo(dst)) {
            copyPath(src, dst);
            deleteRecursive(src);
        }
    }

    private static void copy(String path, String to) throws Exception {
        String from = fullPath(path);
        String dest = fullPath(to);
        File src = new File(from);
        if (!src.exists()) throw new Exception("Not found: " + from);
        copyPath(src, new File(dest));
    }

    private static void copyPath(File src, File dst) throws Exception {
        if (src.isDirectory()) {
            if (!dst.exists() && !dst.mkdirs()) throw new Exception("Cannot create: " + dst);
            File[] children = src.listFiles();
            if (children != null) {
                for (File c : children) copyPath(c, new File(dst, c.getName()));
            }
        } else {
            if (dst.getParentFile() != null) dst.getParentFile().mkdirs();
            FileInputStream in = new FileInputStream(src);
            FileOutputStream out = new FileOutputStream(dst);
            try {
                byte[] buf = new byte[65536];
                int n;
                while ((n = in.read(buf)) > 0) out.write(buf, 0, n);
            } finally {
                in.close();
                out.close();
            }
        }
    }

    private static String fullPath(String path) {
        if (path == null || path.isEmpty()) return "/";
        if (!path.startsWith("/")) path = "/" + path;
        return path;
    }

    private static String extOf(String name) {
        int dot = name.lastIndexOf('.');
        return dot >= 0 ? name.substring(dot) : "";
    }

    private static String fmt(long millis) {
        try {
            SimpleDateFormat sdf = new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US);
            sdf.setTimeZone(TimeZone.getTimeZone("UTC"));
            return sdf.format(new Date(millis));
        } catch (Exception e) {
            return "";
        }
    }

    private static String field(JSONObject op, String key) {
        String v = op.optString(key, null);
        return v == null ? "" : v;
    }
}
