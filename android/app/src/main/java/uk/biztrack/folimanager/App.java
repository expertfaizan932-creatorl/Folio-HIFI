package uk.biztrack.folimanager;

import android.app.Application;

public class App extends Application {
    @Override
    public void onCreate() {
        super.onCreate();
        java.net.CookieHandler.setDefault(Http.COOKIE_MANAGER);
    }
}
