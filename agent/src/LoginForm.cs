using System;
using System.Collections.Generic;
using System.Drawing;
using System.Threading;
using System.Windows.Forms;
using Microsoft.Web.WebView2.WinForms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Sign-in against the Folio server, rendered as the HTML page in
    /// webui\login.html. The C# side owns the network call and the remembered
    /// credentials; the page owns everything the employee sees.
    /// </summary>
    internal sealed class LoginForm : Form
    {
        private readonly AgentSettings _settings;
        private readonly ApiClient _api;
        private readonly WebView2 _view;
        private readonly WebViewBridge _bridge;
        private bool _busy;

        public bool SignedIn { get; private set; }

        public LoginForm(AgentSettings settings, ApiClient api)
        {
            _settings = settings;
            _api = api;

            Text = "Folio - Sign in";
            StartPosition = FormStartPosition.CenterScreen;
            ClientSize = new Size(440, 620);
            Icon = AgentIcons.AppIcon;
            DoubleBuffered = true;

            // Frameless card: the HTML page draws the window on a solid card
            // background with Windows' rounded corners.
            UiTheme.MakeFramelessCard(this, UiTheme.LoginCard);
            UiTheme.ApplyDpi(this);

            _view = new WebView2();
            _view.Dock = DockStyle.Fill;
            Controls.Add(_view);

            _bridge = new WebViewBridge(_view, WebUi.LoadLogin(), OnMessage, UiTheme.LoginCard);
            _bridge.OnFailed += OnBridgeFailed;

            Load += delegate
            {
                UiTheme.FitWindow(this, 440, 620);
                UiTheme.ApplyPageZoom(_view, this);
                _bridge.Start();
            };
        }

        private void OnBridgeFailed()
        {
            if (IsDisposed) return;
            try
            {
                MessageBox.Show(this,
                    "The Folio sign-in screen needs the Microsoft Edge WebView2 runtime, "
                    + "which is not installed on this computer.\n\n"
                    + "Install it from https://go.microsoft.com/fwlink/p/?LinkId=2124703 and run Folio again.",
                    "Folio", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }
            catch (Exception) { }
            DialogResult = DialogResult.Cancel;
            Close();
        }

        // ---------------------------------------------------------------- bridge

        private void OnMessage(string json)
        {
            Dictionary<string, object> message = WebViewBridge.Parse(json);
            string type = ApiClient.AsString(message, "type");
            if (type == "ready") SendCredentials();
            else if (type == "login")
                DoLogin(ApiClient.AsString(message, "email"), ApiClient.AsString(message, "password"));
            else if (type == "forgot")
                Toast("Ask your administrator to reset your password.", "info");
            else if (type == "drag") UiTheme.BeginDrag(this);
            else if (type == "minimize") WindowState = FormWindowState.Minimized;
            else if (type == "close") { DialogResult = DialogResult.Cancel; Close(); }
        }

        private void SendCredentials()
        {
            string email, password;
            CredentialStore.Load(out email, out password);
            if (string.IsNullOrEmpty(email)) email = _settings.LastEmail;

            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["type"] = "credentials";
            payload["email"] = email ?? string.Empty;
            payload["password"] = password ?? string.Empty;
            _bridge.Post(payload);
        }

        private void DoLogin(string email, string password)
        {
            if (_busy) return;
            string user = (email ?? string.Empty).Trim();
            string pass = password ?? string.Empty;

            if (user.Length == 0 || pass.Length == 0)
            {
                Result(false, "Enter your work email and password.");
                return;
            }

            _busy = true;

            // The network call blocks, so it runs off the UI thread to keep the
            // "Signing in..." animation alive and the window responsive.
            ThreadPool.QueueUserWorkItem(delegate
            {
                string error = null;
                bool ok = false;
                try
                {
                    Dictionary<string, object> response = _api.Login(user, pass);
                    if (response != null && ApiClient.AsBool(response, "ok", false))
                    {
                        _settings.ApplyServerConfig(ApiClient.Section(response, "config"));
                        _settings.LastEmail = user;
                        _settings.Save();
                        CredentialStore.Save(user, pass);
                        ok = true;
                    }
                    else
                    {
                        error = response == null
                            ? "Cannot reach the Folio server at " + ServerAddress()
                              + ". Check this machine's internet and try again."
                            : (ApiClient.AsString(response, "error") ?? "Sign-in failed.");
                    }
                }
                catch (Exception ex)
                {
                    error = "Sign-in failed: " + ex.Message;
                }

                try
                {
                    BeginInvoke((MethodInvoker)delegate { Finish(ok, error); });
                }
                catch (Exception)
                {
                    // The dialog was closed before the call returned.
                }
            });
        }

        private void Finish(bool ok, string error)
        {
            _busy = false;
            if (IsDisposed) return;

            if (ok)
            {
                SignedIn = true;
                Result(true, null);
                DialogResult = DialogResult.OK;
                Close();
                return;
            }
            Result(false, error);
        }

        private void Result(bool ok, string error)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["type"] = "loginResult";
            payload["ok"] = ok;
            payload["error"] = error ?? string.Empty;
            _bridge.Post(payload);
        }

        private void Toast(string message, string kind)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["type"] = "toast";
            payload["message"] = message;
            payload["kind"] = kind;
            _bridge.Post(payload);
        }

        private string ServerAddress()
        {
            string url = (_settings.ServerUrl ?? string.Empty).Trim();
            return url.Length == 0 ? ApiClient.DefaultServerUrl : url;
        }
    }
}
