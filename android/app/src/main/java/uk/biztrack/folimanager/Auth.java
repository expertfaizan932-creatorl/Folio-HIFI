package uk.biztrack.folimanager;

import android.content.Context;
import android.provider.Settings;
import android.util.Log;
import android.webkit.CookieManager;

import org.json.JSONObject;

import java.util.UUID;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public class Auth {
    private static final String TAG = "Auth";

    public static class Result {
        public boolean ok;
        public String error;
        public String token;
        public String email;
        public String name;
        public long deviceId;
    }

    public static Result login(Context ctx, String email, String password) throws Exception {
        Result r = new Result();
        Prefs prefs = new Prefs(ctx);

        // The agent sign-in is the credential gate: the server password_verifies
        // there and hands back the opaque token the background service polls with.
        String devKey = getDeviceKey(ctx);
        JSONObject j = new JSONObject();
        j.put("email", email);
        j.put("password", password);
        j.put("deviceType", "ANDROID");
        j.put("deviceKey", devKey);
        j.put("osName", "Android");
        j.put("osVersion", android.os.Build.VERSION.RELEASE);
        j.put("manufacturer", android.os.Build.MANUFACTURER);
        j.put("model", android.os.Build.MODEL);
        j.put("appVersion", "1.0.2");
        j.put("hostName", android.os.Build.MODEL);
        String agentResp = Http.postJson(Config.BASE_URL + "/api/agent/login", j.toString());
        JSONObject aj = new JSONObject(agentResp);
        if (!aj.optBoolean("ok", false)) {
            r.ok = false;
            r.error = aj.optString("error", "Invalid email or password");
            return r;
        }
        r.token = aj.optString("token", "");
        r.email = aj.optString("email", email);
        r.name = aj.optString("name", "");
        r.deviceId = aj.optLong("deviceId", 0);

        // Now establish the browser session the WebView will reuse. A wrong
        // password was already refused above, so a cookie here is authenticated.
        String loginPage = Http.get(Config.BASE_URL + "/login");
        Pattern p = Pattern.compile("name=\"_csrf\" value=\"([0-9a-fA-F]+)\"");
        Matcher m = p.matcher(loginPage);
        String csrf = m.find() ? m.group(1) : "";
        String form = "username=" + urlEncode(email) + "&password=" + urlEncode(password) + "&_csrf=" + urlEncode(csrf);
        Http.postForm(Config.BASE_URL + "/login", form, false);

        String sess = Http.getCookie("WORKFOLIOSID");
        if (sess == null) {
            java.util.List<java.net.HttpCookie> cookies = Http.COOKIE_MANAGER.getCookieStore().getCookies();
            for (java.net.HttpCookie c : cookies) {
                if ("WORKFOLIOSID".equals(c.getName())) { sess = c.getValue(); break; }
            }
        }
        if (sess != null && !sess.isEmpty()) {
            CookieManager wm = CookieManager.getInstance();
            wm.setAcceptCookie(true);
            wm.setCookie(Config.BASE_URL, "WORKFOLIOSID=" + sess + "; Path=/");
            wm.flush();
            prefs.putString(Config.KEY_WORKFOLIOSID, sess);
        }

        // Persist only once the sign-in actually succeeded.
        r.ok = true;
        prefs.putString(Config.KEY_TOKEN, r.token);
        prefs.putString(Config.KEY_EMAIL, r.email);
        prefs.putString(Config.KEY_PASSWORD, password);
        prefs.putString(Config.KEY_NAME, r.name);
        prefs.putLong(Config.KEY_DEVICE_ID, r.deviceId);
        prefs.putBoolean(Config.KEY_SIGNED_IN, true);
        return r;
    }

    public static void refresh(Context ctx) throws Exception {
        Prefs p = new Prefs(ctx);
        String email = p.getString(Config.KEY_EMAIL, "");
        String pass = p.getString(Config.KEY_PASSWORD, "");
        if (!email.isEmpty() && !pass.isEmpty()) {
            login(ctx, email, pass);
        }
    }

    private static String getDeviceKey(Context ctx) {
        Prefs p = new Prefs(ctx);
        String k = p.getString("device_key", null);
        if (k != null && !k.isEmpty()) return k;
        String id = Settings.Secure.getString(ctx.getContentResolver(), Settings.Secure.ANDROID_ID);
        if (id == null || id.isEmpty()) id = UUID.randomUUID().toString();
        p.putString("device_key", id);
        return id;
    }

    private static String urlEncode(String s) {
        try { return java.net.URLEncoder.encode(s, "UTF-8"); } catch (Exception e) { return s; }
    }
}
