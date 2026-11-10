package uk.biztrack.folimanager;

import android.content.Context;
import android.content.SharedPreferences;

public class Prefs {
    private final SharedPreferences sp;

    public Prefs(Context ctx) {
        sp = ctx.getSharedPreferences(Config.PREFS_NAME, Context.MODE_PRIVATE);
    }

    public void putString(String k, String v) {
        sp.edit().putString(k, v).apply();
    }
    public String getString(String k, String def) {
        return sp.getString(k, def);
    }
    public void putBoolean(String k, boolean v) {
        sp.edit().putBoolean(k, v).apply();
    }
    public boolean getBoolean(String k, boolean def) {
        return sp.getBoolean(k, def);
    }
    public void putLong(String k, long v) {
        sp.edit().putLong(k, v).apply();
    }
    public long getLong(String k, long def) {
        return sp.getLong(k, def);
    }
}
