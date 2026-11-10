package uk.biztrack.folimanager;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.os.AsyncTask;
import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;
import android.widget.Toast;

public class LoginActivity extends Activity {
    private EditText email, password;
    private Button signIn;
    private TextView error;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Prefs prefs = new Prefs(this);
        if (prefs.getBoolean(Config.KEY_SIGNED_IN, false)) {
            // Re-run the agent sign-in on every launch so the server always has a
            // fresh token and an up-to-date device row (the device is derived from
            // the token server-side). Runs in the background; a failure here does
            // not block the app - the stored session still opens the site.
            final Context appCtx = getApplicationContext();
            new Thread(new Runnable() {
                @Override public void run() {
                    try { Auth.refresh(appCtx); } catch (Exception ignored) { }
                }
            }).start();
            startActivity(new Intent(this, MainActivity.class));
            startService(new Intent(this, CommandService.class));
            finish();
            return;
        }
        setContentView(R.layout.activity_login);
        email = findViewById(R.id.email);
        password = findViewById(R.id.password);
        signIn = findViewById(R.id.sign_in);
        error = findViewById(R.id.error);

        signIn.setOnClickListener(v -> doLogin());
    }

    private void doLogin() {
        String e = email.getText().toString().trim();
        String p = password.getText().toString();
        if (e.isEmpty() || p.isEmpty()) {
            error.setText("Enter email and password");
            return;
        }
        error.setText("");
        signIn.setEnabled(false);
        new LoginTask().execute(e, p);
    }

    private class LoginTask extends AsyncTask<String, Void, Auth.Result> {
        @Override
        protected Auth.Result doInBackground(String... args) {
            try {
                return Auth.login(LoginActivity.this, args[0], args[1]);
            } catch (Exception ex) {
                Auth.Result r = new Auth.Result();
                r.ok = false;
                r.error = ex.getMessage();
                return r;
            }
        }

        @Override
        protected void onPostExecute(Auth.Result r) {
            signIn.setEnabled(true);
            if (r.ok) {
                startService(new Intent(LoginActivity.this, CommandService.class));
                startActivity(new Intent(LoginActivity.this, MainActivity.class));
                finish();
            } else {
                error.setText(r.error == null ? "Login failed" : r.error);
            }
        }
    }
}
