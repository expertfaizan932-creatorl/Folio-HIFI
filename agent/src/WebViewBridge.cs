using System;
using System.Collections.Generic;
using System.IO;
using System.Reflection;
using System.Text;
using System.Web.Script.Serialization;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Hosts an HTML page inside the agent window and bridges it to the C# core.
    ///
    /// The whole UI is HTML/CSS/JS rendered by the WebView2 runtime (Edge). This
    /// class wires the two directions of that bridge:
    ///
    ///   JS  -> C#   window.chrome.webview.postMessage({...})  ->  MessageReceived
    ///   C#  -> JS   PostWebMessageAsJson({...})               ->  'message' event
    ///
    /// The page is shipped inside the EXE as an embedded resource (webui\*), so
    /// the UI cannot be separated from the binary or tampered with on disk.
    /// </summary>
    internal sealed class WebViewBridge
    {
        /// <summary>
        /// Test hook. When true, <see cref="Start"/> does nothing, so the UI test
        /// harness can construct and inspect the forms without launching a real
        /// browser process for every case.
        /// </summary>
        public static bool Suppress;

        private readonly WebView2 _view;
        private readonly string _html;
        private readonly Action<string> _onMessage;
        private readonly System.Drawing.Color _background;

        public bool Ready { get; private set; }
        public bool Failed { get; private set; }

        /// <summary>Raised on the UI thread when the browser could not start.</summary>
        public event Action OnFailed;

        public WebViewBridge(WebView2 view, string html, Action<string> onMessage)
            : this(view, html, onMessage, System.Drawing.Color.White)
        {
        }

        public WebViewBridge(WebView2 view, string html, Action<string> onMessage, System.Drawing.Color background)
        {
            _view = view;
            _html = html;
            _onMessage = onMessage;
            _background = background;
        }

        /// <summary>
        /// Per-user browser cache. Must be writable, so never the install folder:
        /// the agent also runs from Program Files and from a read-only USB stick.
        /// </summary>
        public static string UserDataFolder
        {
            get
            {
                string dir = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                    "WorkFolioAgent", "WebView2");
                try { if (!Directory.Exists(dir)) Directory.CreateDirectory(dir); }
                catch (Exception) { }
                return dir;
            }
        }

        /// <summary>Whether the Edge WebView2 runtime is installed on this machine.</summary>
        public static bool RuntimeAvailable()
        {
            try
            {
                string version = CoreWebView2Environment.GetAvailableBrowserVersionString();
                return !string.IsNullOrEmpty(version);
            }
            catch (Exception)
            {
                return false;
            }
        }

        public void Start()
        {
            if (Suppress) return;
            try
            {
                // Until the browser paints, fill the control with the card colour
                // so there is no white flash on a dark card.
                _view.BackColor = _background;

                CoreWebView2CreationProperties props = new CoreWebView2CreationProperties();
                props.UserDataFolder = UserDataFolder;
                _view.CreationProperties = props;
                _view.CoreWebView2InitializationCompleted += OnInit;
                // Fire and forget: initialisation completes on the UI thread and
                // raises the event above, which is where the page is loaded.
                _view.EnsureCoreWebView2Async();
            }
            catch (Exception)
            {
                Failed = true;
                RaiseFailed();
            }
        }

        private void OnInit(object sender, CoreWebView2InitializationCompletedEventArgs e)
        {
            if (!e.IsSuccess)
            {
                Failed = true;
                RaiseFailed();
                return;
            }
            try
            {
                CoreWebView2 core = _view.CoreWebView2;
                // Match the page background to the window so any gap before the
                // page paints is invisible.
                try { _view.DefaultBackgroundColor = _background; }
                catch (Exception) { }
                core.Settings.AreDefaultContextMenusEnabled = false;
                core.Settings.AreDevToolsEnabled = false;
                core.Settings.IsStatusBarEnabled = false;
                core.Settings.AreBrowserAcceleratorKeysEnabled = false;
                core.WebMessageReceived += Core_WebMessageReceived;
                core.NavigateToString(_html);
                Ready = true;
            }
            catch (Exception)
            {
                Failed = true;
                RaiseFailed();
            }
        }

        private void Core_WebMessageReceived(object sender, CoreWebView2WebMessageReceivedEventArgs e)
        {
            try
            {
                string json = e.WebMessageAsJson;
                if (_onMessage != null) _onMessage(json);
            }
            catch (Exception)
            {
            }
        }

        /// <summary>Sends a JSON message to the page.</summary>
        public void Post(object payload)
        {
            if (!Ready || _view.CoreWebView2 == null) return;
            try { _view.CoreWebView2.PostWebMessageAsJson(Json(payload)); }
            catch (Exception) { }
        }

        private void RaiseFailed()
        {
            Action handler = OnFailed;
            if (handler != null) handler();
        }

        public static string Json(object payload)
        {
            return new JavaScriptSerializer().Serialize(payload);
        }

        public static Dictionary<string, object> Parse(string json)
        {
            if (string.IsNullOrEmpty(json)) return new Dictionary<string, object>();
            try
            {
                Dictionary<string, object> map =
                    new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(json);
                return map ?? new Dictionary<string, object>();
            }
            catch (Exception)
            {
                return new Dictionary<string, object>();
            }
        }
    }

    /// <summary>Loads the embedded HTML pages and injects the brand logo.</summary>
    internal static class WebUi
    {
        public const string LoginResource = "WorkFolio.Agent.webui.login.html";
        public const string AppResource = "WorkFolio.Agent.webui.app.html";

        public static string LoadLogin() { return Load(LoginResource); }
        public static string LoadApp() { return Load(AppResource); }

        private static string Load(string resourceName)
        {
            string html = ReadResource(resourceName);
            if (html == null) return "<!DOCTYPE html><html><body style=\"font-family:Segoe UI;padding:24px\">UI asset missing from this build.</body></html>";
            return html.Replace("__LOGO_DATA_URI__", LogoDataUri());
        }

        private static string ReadResource(string name)
        {
            try
            {
                Assembly assembly = Assembly.GetExecutingAssembly();
                using (Stream stream = assembly.GetManifestResourceStream(name))
                {
                    if (stream == null) return null;
                    using (StreamReader reader = new StreamReader(stream, Encoding.UTF8))
                        return reader.ReadToEnd();
                }
            }
            catch (Exception)
            {
                return null;
            }
        }

        private static string LogoDataUri()
        {
            try
            {
                Assembly assembly = Assembly.GetExecutingAssembly();
                using (Stream stream = assembly.GetManifestResourceStream("WorkFolio.Agent.logo"))
                {
                    if (stream == null) return "";
                    MemoryStream buffer = new MemoryStream();
                    byte[] chunk = new byte[8192];
                    int n;
                    while ((n = stream.Read(chunk, 0, chunk.Length)) > 0) buffer.Write(chunk, 0, n);
                    return "data:image/png;base64," + Convert.ToBase64String(buffer.ToArray());
                }
            }
            catch (Exception)
            {
                return "";
            }
        }
    }
}
