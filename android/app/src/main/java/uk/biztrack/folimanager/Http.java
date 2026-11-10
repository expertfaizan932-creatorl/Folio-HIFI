package uk.biztrack.folimanager;

import android.util.Log;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.CookieManager;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;

public class Http {
    public static final CookieManager COOKIE_MANAGER = new CookieManager();

    public static String get(String urlStr) throws Exception {
        URL url = new URL(urlStr);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        conn.setInstanceFollowRedirects(true);
        conn.setRequestProperty("User-Agent", "FolioManager-Android/1.0");
        conn.setConnectTimeout(30000);
        conn.setReadTimeout(30000);
        int code = conn.getResponseCode();
        String body = readAll(conn);
        Log.d("Http", "GET " + urlStr + " -> " + code);
        return body;
    }

    public static String postForm(String urlStr, String body, boolean followRedirects) throws Exception {
        URL url = new URL(urlStr);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        conn.setInstanceFollowRedirects(followRedirects);
        conn.setDoOutput(true);
        conn.setRequestMethod("POST");
        conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");
        conn.setRequestProperty("User-Agent", "FolioManager-Android/1.0");
        conn.setConnectTimeout(30000);
        conn.setReadTimeout(30000);
        try (OutputStream os = conn.getOutputStream()) {
            os.write(body.getBytes(StandardCharsets.UTF_8));
        }
        int code = conn.getResponseCode();
        String resp = readAll(conn);
        Log.d("Http", "POSTF " + urlStr + " -> " + code);
        return resp;
    }

    public static String postJson(String urlStr, String json) throws Exception {
        URL url = new URL(urlStr);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        conn.setInstanceFollowRedirects(true);
        conn.setDoOutput(true);
        conn.setRequestMethod("POST");
        conn.setRequestProperty("Content-Type", "application/json; charset=utf-8");
        conn.setRequestProperty("User-Agent", "FolioManager-Android/1.0");
        conn.setConnectTimeout(30000);
        conn.setReadTimeout(30000);
        try (OutputStream os = conn.getOutputStream()) {
            os.write(json.getBytes(StandardCharsets.UTF_8));
        }
        int code = conn.getResponseCode();
        String resp = readAll(conn);
        Log.d("Http", "POSTJ " + urlStr + " -> " + code);
        return resp;
    }

    private static String readAll(HttpURLConnection conn) throws Exception {
        InputStream is = (conn.getResponseCode() >= 200 && conn.getResponseCode() < 400) ?
                conn.getInputStream() : conn.getErrorStream();
        if (is == null) return "";
        StringBuilder sb = new StringBuilder();
        BufferedReader br = new BufferedReader(new InputStreamReader(is, StandardCharsets.UTF_8));
        String line;
        while ((line = br.readLine()) != null) {
            sb.append(line).append('\n');
        }
        br.close();
        return sb.toString();
    }

    public static String getCookie(String name) {
        // Not exhaustive; Auth will read from COOKIE_MANAGER.getCookieStore() if needed
        for (java.net.HttpCookie c : COOKIE_MANAGER.getCookieStore().getCookies()) {
            if (c.getName().equals(name)) return c.getValue();
        }
        return null;
    }
}
