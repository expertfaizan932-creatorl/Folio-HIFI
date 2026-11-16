package uk.biztrack.folimanager;

import android.content.Context;
import android.util.Log;

import org.json.JSONObject;

public class Api {
    private static final String TAG = "Api";

    public static JSONObject post(Context ctx, String path, JSONObject body) throws Exception {
        Prefs p = new Prefs(ctx);
        String token = p.getString(Config.KEY_TOKEN, "");
        body.put("token", token);
        String resp = Http.postJson(Config.BASE_URL + path, body.toString());
        JSONObject j = new JSONObject(resp);
        if (!j.optBoolean("ok", false)) {
            String err = j.optString("error", "");
            if (err.toLowerCase().contains("unauthorized")) {
                Log.w(TAG, "token rejected, refreshing");
                Auth.refresh(ctx);
            }
        }
        return j;
    }
}
