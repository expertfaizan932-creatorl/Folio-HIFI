using System;
using System.Collections.Generic;
using System.Drawing;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;
using System.Windows.Forms;
using System.Web.Script.Serialization;

namespace WorkFolioManager
{
    /// <summary>
    /// WorkFolio Manager - the owner's desktop console.
    ///
    /// A windowed front end for the same owner session the command-line console
    /// used: sign in, pick any PC running Folio, then either browse its drives
    /// with a real file manager (download, upload, rename, copy, move, delete),
    /// run a single cmd line, or keep an interactive terminal open on it.
    ///
    /// Only SUPER_ADMIN / ADMIN accounts may drive it, only EMPLOYEE / HR
    /// systems may be a target, and every step lands in the server audit trail.
    /// The session (cookie + CSRF) is cached under %LOCALAPPDATA%\WorkFolioManager
    /// so a later launch does not ask for the password again.
    /// </summary>
    internal static class Program
    {
        [STAThread]
        internal static void Main(string[] args)
        {
            try { ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12; }
            catch (Exception) { }

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            Application.Run(new ManagerForm());
        }
    }

    /// <summary>Thrown for a user-facing operation failure; shown in a message box.</summary>
    internal sealed class ManagerException : Exception
    {
        public ManagerException(string message) : base(message) { }
    }

    // ---------------------------------------------------------------- api client

    /// <summary>
    /// Talks to the WorkFolio server as an owner session. One cookie container,
    /// one CSRF token, cached on disk between runs. Every call is fire-and-wait;
    /// the GUI runs them off the UI thread so the window never freezes.
    /// </summary>
    internal sealed class ManagerApi
    {
        private const string DefaultServerUrl = "https://folio.biztrack.uk";
        private const string AppName = "WorkFolioManager";

        private string _serverUrl;
        private readonly CookieContainer _cookies = new CookieContainer();
        private readonly JavaScriptSerializer _json = new JavaScriptSerializer { MaxJsonLength = int.MaxValue };
        private readonly object _http = new object();

        public string Name { get; private set; }
        public string Role { get; private set; }
        public string Csrf { get; private set; }

        public ManagerApi()
        {
            _serverUrl = DefaultServerUrl;
            Name = "";
            Role = "";
            Csrf = "";
            LoadSession();
        }

        public string ServerUrl
        {
            get { return _serverUrl; }
            set { _serverUrl = value; }
        }

        public bool HasSession()
        {
            return !string.IsNullOrEmpty(Csrf);
        }

        private string SessionFile
        {
            get
            {
                string dir = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), AppName);
                return Path.Combine(dir, "session.json");
            }
        }

        private Uri ServerUri()
        {
            string url = (_serverUrl ?? "").Trim();
            if (!url.StartsWith("http://") && !url.StartsWith("https://")) url = "http://" + url;
            return new Uri(url.TrimEnd('/'));
        }

        public Dictionary<string, object> Login(string email, string password)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["username"] = email;
            payload["password"] = password;
            Dictionary<string, object> response = Post("/api/manager/login", payload, false);
            if (response != null && AsBool(response, "ok", false))
            {
                Name = AsString(response, "name");
                Role = AsString(response, "role");
                Csrf = AsString(response, "csrf");
                SaveSession();
            }
            return response;
        }

        public void Logout()
        {
            Csrf = "";
            Name = "";
            Role = "";
            try { File.Delete(SessionFile); }
            catch (Exception) { }
        }

        public List<Dictionary<string, object>> Systems()
        {
            Dictionary<string, object> response = Post("/api/manager/systems", new Dictionary<string, object>(), true);
            if (response == null || !AsBool(response, "ok", false)) return null;
            List<Dictionary<string, object>> list = new List<Dictionary<string, object>>();
            foreach (object item in AsArray(response, "systems"))
            {
                Dictionary<string, object> row = item as Dictionary<string, object>;
                if (row != null) list.Add(row);
            }
            return list;
        }

        public Dictionary<string, object> Post(string path, Dictionary<string, object> payload, bool auth)
        {
            lock (_http)
            {
                if (auth && string.IsNullOrEmpty(Csrf))
                {
                    return new Dictionary<string, object> { { "ok", false }, { "error", "not signed in" } };
                }
                if (auth) payload["_csrf"] = Csrf;

                string url = (_serverUrl ?? "").Trim();
                if (!url.StartsWith("http://") && !url.StartsWith("https://")) url = "http://" + url;
                url = url.TrimEnd('/') + path;

                HttpWebRequest request = (HttpWebRequest)WebRequest.Create(url);
                request.Method = "POST";
                request.ContentType = "application/json";
                request.Timeout = 60000;
                request.ReadWriteTimeout = 60000;
                request.UserAgent = AppName + "/2.0";
                request.ServicePoint.Expect100Continue = false;
                request.AllowAutoRedirect = false;
                request.CookieContainer = _cookies;

                try
                {
                    byte[] body = Encoding.UTF8.GetBytes(_json.Serialize(payload ?? new Dictionary<string, object>()));
                    request.ContentLength = body.Length;
                    using (Stream stream = request.GetRequestStream()) stream.Write(body, 0, body.Length);

                    using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                    {
                        int status = (int)response.StatusCode;
                        if (status >= 300 && status < 400)
                        {
                            return new Dictionary<string, object> { { "ok", false }, { "error", "session expired" } };
                        }
                        CaptureCookies(response);
                        string text;
                        using (StreamReader reader = new StreamReader(response.GetResponseStream(), Encoding.UTF8))
                        {
                            text = reader.ReadToEnd();
                        }
                        if (LooksLikeChallenge(text))
                        {
                            return new Dictionary<string, object>
                            {
                                { "ok", false },
                                { "error", "The server asked for a browser verification this app cannot run. "
                                    + "Open " + _serverUrl + "/login once in a browser on this PC, then retry." }
                            };
                        }
                        object parsed;
                        try { parsed = _json.DeserializeObject(text); }
                        catch (Exception)
                        {
                            return new Dictionary<string, object>
                            {
                                { "ok", false },
                                { "error", "Unexpected reply from the server (HTTP " + status + ")." }
                            };
                        }
                        Dictionary<string, object> map = parsed as Dictionary<string, object>;
                        return map ?? new Dictionary<string, object> { { "ok", false }, { "error", "Unexpected reply." } };
                    }
                }
                catch (WebException ex)
                {
                    using (HttpWebResponse r = (HttpWebResponse)ex.Response)
                    {
                        if (r != null)
                        {
                            int status = (int)r.StatusCode;
                            if (r.StatusCode == HttpStatusCode.Unauthorized || r.StatusCode == HttpStatusCode.Forbidden)
                            {
                                return new Dictionary<string, object> { { "ok", false }, { "error", "session expired" } };
                            }
                            string detail = "";
                            try
                            {
                                using (StreamReader rr = new StreamReader(r.GetResponseStream(), Encoding.UTF8))
                                {
                                    detail = rr.ReadToEnd();
                                }
                            }
                            catch (Exception) { }
                            detail = (detail ?? "").Trim();
                            if (detail.Length > 160) detail = detail.Substring(0, 160);
                            return new Dictionary<string, object>
                            {
                                { "ok", false },
                                { "error", "HTTP " + status + (detail.Length > 0 ? " - " + detail : "") }
                            };
                        }
                    }
                    return new Dictionary<string, object> { { "ok", false }, { "error", ex.Message } };
                }
                catch (Exception ex)
                {
                    return new Dictionary<string, object> { { "ok", false }, { "error", ex.Message } };
                }
            }
        }

        private static bool LooksLikeChallenge(string text)
        {
            return text != null && text.IndexOf("__test", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private void CaptureCookies(HttpWebResponse response)
        {
            try
            {
                string[] set = response.Headers.GetValues("Set-Cookie");
                if (set == null || set.Length == 0) return;
                Uri uri = response.ResponseUri ?? ServerUri();
                foreach (string one in set)
                {
                    try { _cookies.SetCookies(uri, one); }
                    catch (Exception) { }
                }
                SaveSession();
            }
            catch (Exception) { }
        }

        private void LoadSession()
        {
            try
            {
                if (!File.Exists(SessionFile)) return;
                string json = File.ReadAllText(SessionFile, Encoding.UTF8);
                Dictionary<string, object> s = _json.Deserialize<Dictionary<string, object>>(json);
                string sv = AsString(s, "server");
                if (sv != "") _serverUrl = sv;
                if (string.IsNullOrEmpty(_serverUrl)) _serverUrl = DefaultServerUrl;
                Csrf = AsString(s, "csrf");
                Name = AsString(s, "name");
                Role = AsString(s, "role");
                string cookieHeader = AsString(s, "cookie");
                if (!string.IsNullOrEmpty(cookieHeader))
                {
                    Uri uri = ServerUri();
                    foreach (string pair in cookieHeader.Split(';'))
                    {
                        string p = pair.Trim();
                        if (p.Length == 0) continue;
                        try { _cookies.SetCookies(uri, p); }
                        catch (Exception) { }
                    }
                }
            }
            catch (Exception) { }
        }

        private void SaveSession()
        {
            try
            {
                string dir = Path.GetDirectoryName(SessionFile);
                if (!Directory.Exists(dir)) Directory.CreateDirectory(dir);
                Uri uri = ServerUri();
                StringBuilder sb = new StringBuilder();
                foreach (Cookie cookie in _cookies.GetCookies(uri))
                {
                    sb.Append(cookie.Name).Append('=').Append(cookie.Value).Append("; ");
                }
                string json = _json.Serialize(new Dictionary<string, object>
                {
                    { "server", _serverUrl }, { "cookie", sb.ToString().TrimEnd(' ', ';') },
                    { "csrf", Csrf ?? "" }, { "name", Name ?? "" }, { "role", Role ?? "" },
                });
                File.WriteAllText(SessionFile, json, Encoding.UTF8);
            }
            catch (Exception) { }
        }

        // json helpers
        public static bool AsBool(Dictionary<string, object> m, string k, bool fb)
        {
            object v;
            if (m != null && m.TryGetValue(k, out v) && v is bool) return (bool)v;
            return fb;
        }

        public static string AsString(Dictionary<string, object> m, string k)
        {
            object v;
            if (m != null && m.TryGetValue(k, out v) && v != null) return v.ToString();
            return "";
        }

        public static long AsLong(Dictionary<string, object> m, string k, long fb)
        {
            object v;
            if (m != null && m.TryGetValue(k, out v) && v != null)
            {
                long l;
                if (long.TryParse(v.ToString(), out l)) return l;
            }
            return fb;
        }

        public static object[] AsArray(Dictionary<string, object> m, string k)
        {
            object v;
            if (m != null && m.TryGetValue(k, out v) && v is object[]) return (object[])v;
            return new object[0];
        }
    }

    // ------------------------------------------------------------------- window

    internal sealed class ManagerForm : Form
    {
        private const int ChunkBytes = 1024 * 1024;

        private readonly ManagerApi _api = new ManagerApi();
        private readonly JavaScriptSerializer _json = new JavaScriptSerializer { MaxJsonLength = int.MaxValue };

        private ListView _systems;
        private ComboBox _sysFilter;
        private readonly List<Dictionary<string, object>> _systemsData = new List<Dictionary<string, object>>();
        private TabControl _tabs;
        private ComboBox _path;
        private ListView _files;
        private ToolStripStatusLabel _status;
        private ToolStripProgressBar _progress;

        // files state
        private long _fsId = 0;
        private long _rid = 0;
        private long _readCursor = 0;
        private string _currentDir = "";
        private bool _atDrives = true;
        private Clip _clip;
        private bool _busy;

        // terminal state
        private RichTextBox _termOut;
        private TextBox _termIn;
        private long _shellId = 0;
        private volatile bool _shellOpen;
        private Thread _termPoller;

        // command state
        private TextBox _cmdIn;
        private RichTextBox _cmdOut;
        private ComboBox _targetScope;

        private sealed class Clip
        {
            public bool Move;
            public string Path;
            public string Name;
        }

        private sealed class Entry
        {
            public string Name;
            public string FullPath;
            public bool Dir;
            public long Size;
            public string Modified;
            public bool IsDrive;
        }

        public ManagerForm()
        {
#if APKMANAGER
            Text = "WorkFolio ApkManager";
#else
            Text = "WorkFolio Manager";
#endif
            Width = 1180;
            Height = 760;
            MinimumSize = new Size(920, 600);
            StartPosition = FormStartPosition.CenterScreen;
            Font = new Font("Segoe UI", 9F);
            BuildUi();
            Load += delegate { Bootstrap(); };
            FormClosing += delegate { Shutdown(); };
        }

        // ------------------------------------------------------------- ui build

        private void BuildUi()
        {
            SplitContainer split = new SplitContainer();
            split.Dock = DockStyle.Fill;
            split.FixedPanel = FixedPanel.Panel1;
            split.SplitterDistance = 300;
            split.Panel1MinSize = 220;

            Panel left = new Panel { Dock = DockStyle.Fill, Padding = new Padding(8) };
            Label sysLabel = new Label { Text = "Connected systems", Left = 0, Top = 0, Width = 280, Height = 18, Font = new Font("Segoe UI", 9F, FontStyle.Bold) };
            _sysFilter = new ComboBox { Left = 0, Top = 20, Width = 280, DropDownStyle = ComboBoxStyle.DropDownList };
#if APKMANAGER
            _sysFilter.Items.Add("Android phones");
#else
            _sysFilter.Items.Add("All systems");
            _sysFilter.Items.Add("PCs only");
            _sysFilter.Items.Add("Android phones");
#endif
            _sysFilter.SelectedIndex = 0;
            _sysFilter.SelectedIndexChanged += delegate { ApplySystemsFilter(); };
            Panel leftTop = new Panel { Dock = DockStyle.Top, Height = 48 };
            leftTop.Controls.Add(_sysFilter);
            leftTop.Controls.Add(sysLabel);
            Button refresh = new Button { Text = "Refresh", Dock = DockStyle.Bottom, Height = 28 };
            refresh.Click += delegate { RefreshSystemsAsync(); };
            _systems = new ListView();
            _systems.Dock = DockStyle.Fill;
            _systems.View = View.Details;
            _systems.FullRowSelect = true;
            _systems.MultiSelect = false;
            _systems.HideSelection = false;
            _systems.Columns.Add("Name", 120);
            _systems.Columns.Add("Role", 55);
            _systems.Columns.Add("Type", 60);
            _systems.Columns.Add("Device", 150);
            _systems.Columns.Add("Host", 110);
            _systems.SelectedIndexChanged += delegate { OnSystemSelected(); };
            left.Controls.Add(_systems);
            left.Controls.Add(refresh);
            left.Controls.Add(leftTop);
            split.Panel1.Controls.Add(left);

            _tabs = new TabControl { Dock = DockStyle.Fill };
            _tabs.TabPages.Add(BuildFilesTab());
#if !APKMANAGER
            _tabs.TabPages.Add(BuildCommandTab());
            _tabs.TabPages.Add(BuildTerminalTab());
#endif
            split.Panel2.Controls.Add(_tabs);

            StatusStrip strip = new StatusStrip();
            _status = new ToolStripStatusLabel("Not signed in.");
            _status.Spring = true;
            _status.TextAlign = ContentAlignment.MiddleLeft;
            _progress = new ToolStripProgressBar { Visible = false, Style = ProgressBarStyle.Marquee, Width = 160 };
            strip.Items.Add(_status);
            strip.Items.Add(_progress);

            Controls.Add(split);
            Controls.Add(strip);
        }

        private TabPage BuildFilesTab()
        {
            TabPage page = new TabPage("Files");

            ToolStrip bar = new ToolStrip { GripStyle = ToolStripGripStyle.Hidden, ImageScalingSize = new Size(16, 16) };
            _path = new ComboBox { Width = 460, DropDownStyle = ComboBoxStyle.DropDown };
            _path.KeyDown += delegate (object s, KeyEventArgs e)
            {
                if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; NavigateAsync(_path.Text); }
            };
            ToolStripControlHost pathHost = new ToolStripControlHost(_path) { Margin = new Padding(2) };

            ToolStripButton up = new ToolStripButton("Up");
            up.Click += delegate { UpAsync(); };
            ToolStripButton go = new ToolStripButton("Go");
            go.Click += delegate { NavigateAsync(_path.Text); };
            ToolStripButton refresh = new ToolStripButton("Refresh");
            refresh.Click += delegate { LoadCurrentAsync(); };
            ToolStripButton newFolder = new ToolStripButton("New folder");
            newFolder.Click += delegate { NewFolder(); };
            ToolStripButton rename = new ToolStripButton("Rename");
            rename.Click += delegate { Rename(); };
            ToolStripButton del = new ToolStripButton("Delete");
            del.Click += delegate { Delete(); };
            ToolStripButton copy = new ToolStripButton("Copy");
            copy.Click += delegate { SetClip(false); };
            ToolStripButton cut = new ToolStripButton("Cut");
            cut.Click += delegate { SetClip(true); };
            ToolStripButton paste = new ToolStripButton("Paste");
            paste.Click += delegate { Paste(); };
            ToolStripButton download = new ToolStripButton("Download");
            download.Click += delegate { Download(); };
            ToolStripButton upload = new ToolStripButton("Upload");
            upload.Click += delegate { Upload(); };

            bar.Items.Add(pathHost);
            bar.Items.Add(up);
            bar.Items.Add(go);
            bar.Items.Add(new ToolStripSeparator());
            bar.Items.Add(refresh);
            bar.Items.Add(newFolder);
            bar.Items.Add(rename);
            bar.Items.Add(del);
            bar.Items.Add(new ToolStripSeparator());
            bar.Items.Add(copy);
            bar.Items.Add(cut);
            bar.Items.Add(paste);
            bar.Items.Add(new ToolStripSeparator());
            bar.Items.Add(download);
            bar.Items.Add(upload);

            _files = new ListView();
            _files.Dock = DockStyle.Fill;
            _files.View = View.Details;
            _files.FullRowSelect = true;
            _files.MultiSelect = false;
            _files.HideSelection = false;
            _files.Columns.Add("Name", 360);
            _files.Columns.Add("Size", 100, HorizontalAlignment.Right);
            _files.Columns.Add("Modified", 160);
            _files.Columns.Add("Type", 120);
            _files.DoubleClick += delegate { OnFileActivate(); };

            ContextMenuStrip menu = new ContextMenuStrip();
            menu.Items.Add("Open", null, delegate { OnFileActivate(); });
            menu.Items.Add("Download", null, delegate { Download(); });
            menu.Items.Add("Upload here", null, delegate { Upload(); });
            menu.Items.Add(new ToolStripSeparator());
            menu.Items.Add("Rename", null, delegate { Rename(); });
            menu.Items.Add("Delete", null, delegate { Delete(); });
            menu.Items.Add("Copy", null, delegate { SetClip(false); });
            menu.Items.Add("Cut", null, delegate { SetClip(true); });
            menu.Items.Add("Paste", null, delegate { Paste(); });
            _files.ContextMenuStrip = menu;

            page.Controls.Add(_files);
            page.Controls.Add(bar);
            return page;
        }

        private TabPage BuildCommandTab()
        {
            TabPage page = new TabPage("Command");
            TableLayoutPanel top = new TableLayoutPanel();
            top.Dock = DockStyle.Top;
            top.Height = 42;
            top.Padding = new Padding(8);
            top.ColumnCount = 3;
            top.RowCount = 1;
            top.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));
            top.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100F));
            top.ColumnStyles.Add(new ColumnStyle(SizeType.AutoSize));

            _targetScope = new ComboBox { DropDownStyle = ComboBoxStyle.DropDownList, Width = 170, Margin = new Padding(0, 2, 8, 0) };
#if APKMANAGER
            _targetScope.Items.Add("Selected system");
            _targetScope.Items.Add("All Android phones");
#else
            _targetScope.Items.Add("Selected system");
            _targetScope.Items.Add("All PCs");
            _targetScope.Items.Add("All Android phones");
            _targetScope.Items.Add("All systems");
#endif
            _targetScope.SelectedIndex = 0;

            _cmdIn = new TextBox { Dock = DockStyle.Fill, Margin = new Padding(0, 2, 8, 0) };
            _cmdIn.KeyDown += delegate (object s, KeyEventArgs e)
            {
                if (e.KeyCode == Keys.Enter) { e.SuppressKeyPress = true; RunCommand(); }
            };
            Button run = new Button { Text = "Run", Width = 90, Margin = new Padding(0, 0, 0, 0) };
            run.Click += delegate { RunCommand(); };

            top.Controls.Add(_targetScope, 0, 0);
            top.Controls.Add(_cmdIn, 1, 0);
            top.Controls.Add(run, 2, 0);

            _cmdOut = new RichTextBox { Dock = DockStyle.Fill, ReadOnly = true, Font = new Font("Consolas", 9F), BackColor = Color.FromArgb(20, 20, 24), ForeColor = Color.Gainsboro };
            page.Controls.Add(_cmdOut);
            page.Controls.Add(top);
            return page;
        }

        private TabPage BuildTerminalTab()
        {
            TabPage page = new TabPage("Terminal");
            _termOut = new RichTextBox { Dock = DockStyle.Fill, ReadOnly = true, Font = new Font("Consolas", 9F), BackColor = Color.FromArgb(12, 12, 16), ForeColor = Color.Gainsboro };
            _termIn = new TextBox { Dock = DockStyle.Bottom, Font = new Font("Consolas", 9F) };
            _termIn.KeyDown += delegate (object s, KeyEventArgs e)
            {
                if (e.KeyCode == Keys.Enter)
                {
                    e.SuppressKeyPress = true;
                    SendTerminal(_termIn.Text);
                    _termIn.Clear();
                }
            };
            page.Controls.Add(_termOut);
            page.Controls.Add(_termIn);
            return page;
        }

        // -------------------------------------------------------------- startup

        private void Bootstrap()
        {
            if (_api.HasSession() && TrySilentSession())
            {
                SetStatus("Signed in as " + _api.Name + " (" + _api.Role + ").");
                RefreshSystemsAsync();
                return;
            }
            ShowLogin();
        }

        private bool TrySilentSession()
        {
            List<Dictionary<string, object>> systems = _api.Systems();
            return systems != null;
        }

        private void ShowLogin()
        {
            using (LoginDialog dialog = new LoginDialog(_api))
            {
                if (dialog.ShowDialog(this) != DialogResult.OK)
                {
                    Close();
                    return;
                }
            }
            SetStatus("Signed in as " + _api.Name + " (" + _api.Role + ").");
            RefreshSystemsAsync();
        }

        private void Shutdown()
        {
            _shellOpen = false;
            if (_shellId > 0)
            {
                long id = _shellId;
                _shellId = 0;
                try { _api.Post("/api/manager/shell/close", new Dictionary<string, object> { { "shell", id } }, true); }
                catch (Exception) { }
            }
            if (_fsId > 0)
            {
                long id = _fsId;
                _fsId = 0;
                try { _api.Post("/api/manager/fs/close", new Dictionary<string, object> { { "fs", id } }, true); }
                catch (Exception) { }
            }
        }

        // ------------------------------------------------------------- systems

        private void RefreshSystemsAsync()
        {
            SetStatus("Loading systems...");
            RunAsync(delegate
            {
                List<Dictionary<string, object>> list = _api.Systems();
                Invoke(new Action(delegate
                {
                    _systemsData.Clear();
                    if (list == null)
                    {
                        SetStatus("Cannot reach the server or the session expired.");
                    }
                    else
                    {
                        _systemsData.AddRange(list);
                        SetStatus(list.Count + " system(s) connected.");
                    }
                    ApplySystemsFilter();
                }));
            });
        }

        /// <summary>Rebuild the visible system list from the cached rows, honouring the filter.</summary>
        private void ApplySystemsFilter()
        {
            _systems.BeginUpdate();
            _systems.Items.Clear();
            int shown = 0;
            foreach (Dictionary<string, object> s in _systemsData)
            {
                string type = ManagerApi.AsString(s, "device_type");
                if (type.Length == 0) type = "PC";
                bool android = type.Equals("ANDROID", StringComparison.OrdinalIgnoreCase);

                int filter = _sysFilter == null ? 0 : _sysFilter.SelectedIndex;
#if APKMANAGER
                if (!android) continue;                       /* this console is phone-only */
#else
                if (filter == 1 && android) continue;         /* PCs only */
                if (filter == 2 && !android) continue;        /* Android phones */
#endif

                string email = ManagerApi.AsString(s, "email");
                long deviceId = ManagerApi.AsLong(s, "device_id", 0);
                string name = (ManagerApi.AsString(s, "first_name") + " " + ManagerApi.AsString(s, "last_name")).Trim();
                if (name.Length == 0) name = email;

                string host = ManagerApi.AsString(s, "host_name");
                if (host.Length == 0) host = ManagerApi.AsString(s, "device_host");
                string device = android
                    ? (ManagerApi.AsString(s, "manufacturer") + " " + ManagerApi.AsString(s, "model")).Trim()
                    : host;
                if (device.Length == 0) device = android ? "Android device" : "Windows PC";

                ListViewItem item = new ListViewItem(name);
                item.SubItems.Add(ManagerApi.AsString(s, "role"));
                item.SubItems.Add(android ? "Android" : "PC");
                item.SubItems.Add(device);
                item.SubItems.Add(host);
                /* Newer servers return a device id: target that exact device. An
                   older server has none, so fall back to the address (legacy). */
                item.Tag = deviceId > 0 ? ("dev:" + deviceId) : email;
                if (!ManagerApi.AsBool(s, "online", true)) item.ForeColor = Color.Gray;
                _systems.Items.Add(item);
                shown++;
            }
            _systems.EndUpdate();
            if (_systemsData.Count > 0 && shown != _systemsData.Count)
            {
                SetStatus(shown + " of " + _systemsData.Count + " system(s) shown.");
            }
        }

        private void OnSystemSelected()
        {
            if (_systems.SelectedItems.Count == 0) return;
            string target = _systems.SelectedItems[0].Tag as string;
            if (string.IsNullOrEmpty(target)) return;
            if (_busy) return;
            CloseSessionIfOpen();
            _files.Items.Clear();
            _atDrives = true;
            _currentDir = "";
            SetBusy(true, "Opening " + target + "...");
            RunAsync(delegate
            {
                Dictionary<string, object> open = _api.Post("/api/manager/fs/open",
                    new Dictionary<string, object> { { "target", target } }, true);
                if (open == null || !ManagerApi.AsBool(open, "ok", false))
                {
                    string err = open == null ? "Cannot reach the server." : ManagerApi.AsString(open, "error");
                    Invoke(new Action(delegate { SetBusy(false, ""); MessageBox.Show(this, err, "WorkFolio Manager", MessageBoxButtons.OK, MessageBoxIcon.Warning); }));
                    return;
                }
                long fs = ManagerApi.AsLong(open, "fs", 0);
                Invoke(new Action(delegate
                {
                    _fsId = fs;
                    _readCursor = 0;
                    SetBusy(false, "Connected to " + target + ".");
                    LoadDrivesAsync();
                }));
            });
        }

        private void CloseSessionIfOpen()
        {
            if (_fsId <= 0) return;
            long id = _fsId;
            _fsId = 0;
            RunAsync(delegate
            {
                try { _api.Post("/api/manager/fs/close", new Dictionary<string, object> { { "fs", id } }, true); }
                catch (Exception) { }
            });
        }

        // --------------------------------------------------------- file ops core

        /// <summary>Send one op and wait for its matching reply. Runs off the UI thread.</summary>
        private Dictionary<string, object> FsRequest(Dictionary<string, object> op)
        {
            if (_fsId <= 0) throw new ManagerException("No open session. Pick a system first.");
            long rid = ++_rid;
            op["rid"] = rid;
            string command = _json.Serialize(op);
            Dictionary<string, object> push = _api.Post("/api/manager/fs/write",
                new Dictionary<string, object> { { "fs", _fsId }, { "command", command } }, true);
            if (push == null || !ManagerApi.AsBool(push, "ok", false))
            {
                string err = push == null ? "Cannot reach the server." : ManagerApi.AsString(push, "error");
                throw new ManagerException(err);
            }

            for (int i = 0; i < 600; i++)
            {
                Dictionary<string, object> read = _api.Post("/api/manager/fs/read",
                    new Dictionary<string, object> { { "fs", _fsId }, { "cursor", _readCursor } }, true);
                if (read != null && ManagerApi.AsBool(read, "ok", false))
                {
                    long cursor = ManagerApi.AsLong(read, "cursor", _readCursor);
                    foreach (object raw in ManagerApi.AsArray(read, "results"))
                    {
                        string text = raw as string;
                        if (text == null) continue;
                        Dictionary<string, object> reply = null;
                        try { reply = _json.DeserializeObject(text) as Dictionary<string, object>; }
                        catch (Exception) { }
                        if (reply != null && ManagerApi.AsLong(reply, "rid", -1) == rid)
                        {
                            _readCursor = cursor;
                            if (!ManagerApi.AsBool(reply, "ok", false))
                            {
                                throw new ManagerException(ManagerApi.AsString(reply, "error"));
                            }
                            return reply;
                        }
                    }
                    _readCursor = cursor;
                    string status = ManagerApi.AsString(read, "status");
                    if (status == "GONE" || status == "CLOSED") throw new ManagerException("The session was closed.");
                }
                Thread.Sleep(150);
            }
            throw new ManagerException("Timed out waiting for the remote PC.");
        }

        // ------------------------------------------------------------- browsing

        private void LoadDrivesAsync()
        {
            _atDrives = true;
            SetBusy(true, "Reading drives...");
            RunAsync(delegate
            {
                try
                {
                    Dictionary<string, object> reply = FsRequest(new Dictionary<string, object> { { "op", "drives" } });
                    object[] drives = ManagerApi.AsArray(reply, "drives");
                    Invoke(new Action(delegate { ShowDrives(drives); SetBusy(false, ""); }));
                }
                catch (ManagerException ex) { Fail(ex.Message); }
            });
        }

        private void ShowDrives(object[] drives)
        {
            _atDrives = true;
            _currentDir = "";
            _path.Text = "This PC";
            _files.BeginUpdate();
            _files.Items.Clear();
            foreach (object raw in drives)
            {
                Dictionary<string, object> d = raw as Dictionary<string, object>;
                if (d == null) continue;
                string name = ManagerApi.AsString(d, "name");
                bool ready = ManagerApi.AsBool(d, "ready", false);
                long total = ManagerApi.AsLong(d, "total", 0);
                long free = ManagerApi.AsLong(d, "free", 0);
                string type = ManagerApi.AsString(d, "type");
                string label = ManagerApi.AsString(d, "label");

                Entry entry = new Entry { Name = name, FullPath = name, Dir = true, IsDrive = true };
                ListViewItem item = new ListViewItem(name + (label.Length > 0 ? "  (" + label + ")" : ""));
                item.SubItems.Add(ready ? FmtSize(free) + " free" : "");
                item.SubItems.Add(ready ? FmtSize(total) : "not ready");
                item.SubItems.Add(type);
                item.Tag = entry;
                if (!ready) item.ForeColor = Color.Gray;
                _files.Items.Add(item);
            }
            _files.EndUpdate();
        }

        private void NavigateAsync(string path)
        {
            if (_fsId <= 0) return;
            if (string.IsNullOrEmpty(path) || path == "This PC") { LoadDrivesAsync(); return; }
            SetBusy(true, "Reading " + path + "...");
            RunAsync(delegate
            {
                try
                {
                    Dictionary<string, object> reply = FsRequest(new Dictionary<string, object> { { "op", "list" }, { "path", path } });
                    string full = ManagerApi.AsString(reply, "path");
                    object[] entries = ManagerApi.AsArray(reply, "entries");
                    Invoke(new Action(delegate { ShowDir(full, entries); SetBusy(false, ""); }));
                }
                catch (ManagerException ex) { Fail(ex.Message); }
            });
        }

        private void ShowDir(string full, object[] entries)
        {
            _atDrives = false;
            _currentDir = full;
            _path.Text = full;
            _files.BeginUpdate();
            _files.Items.Clear();
            List<ListViewItem> folders = new List<ListViewItem>();
            List<ListViewItem> files = new List<ListViewItem>();
            foreach (object raw in entries)
            {
                Dictionary<string, object> e = raw as Dictionary<string, object>;
                if (e == null) continue;
                bool dir = ManagerApi.AsBool(e, "dir", false);
                string name = ManagerApi.AsString(e, "name");
                Entry entry = new Entry
                {
                    Name = name,
                    FullPath = Combine(full, name),
                    Dir = dir,
                    Size = ManagerApi.AsLong(e, "size", 0),
                    Modified = ManagerApi.AsString(e, "modified"),
                };
                ListViewItem item = new ListViewItem(name);
                item.SubItems.Add(dir ? "" : FmtSize(entry.Size));
                item.SubItems.Add(entry.Modified);
                item.SubItems.Add(dir ? "Folder" : "File");
                item.Tag = entry;
                (dir ? folders : files).Add(item);
            }
            foreach (ListViewItem i in folders) _files.Items.Add(i);
            foreach (ListViewItem i in files) _files.Items.Add(i);
            _files.EndUpdate();
        }

        private void LoadCurrentAsync()
        {
            if (_atDrives || string.IsNullOrEmpty(_currentDir)) LoadDrivesAsync();
            else NavigateAsync(_currentDir);
        }

        private void UpAsync()
        {
            if (_atDrives || string.IsNullOrEmpty(_currentDir)) return;
            string parent = ParentOf(_currentDir);
            if (string.IsNullOrEmpty(parent)) LoadDrivesAsync();
            else NavigateAsync(parent);
        }

        private void OnFileActivate()
        {
            Entry entry = SelectedEntry();
            if (entry == null) return;
            if (entry.Dir) NavigateAsync(entry.FullPath);
            else Download();
        }

        private Entry SelectedEntry()
        {
            if (_files.SelectedItems.Count == 0) return null;
            return _files.SelectedItems[0].Tag as Entry;
        }

        // ----------------------------------------------------------- mutations

        private void NewFolder()
        {
            if (_fsId <= 0) return;
            if (_atDrives) { MessageBox.Show(this, "Pick a folder to create the new one inside."); return; }
            string name = Prompt.Show(this, "New folder", "Folder name:", "New folder");
            if (string.IsNullOrEmpty(name)) return;
            string path = Combine(_currentDir, name);
            RunMutation(new Dictionary<string, object> { { "op", "mkdir" }, { "path", path } }, "Creating folder...");
        }

        private void Rename()
        {
            Entry entry = SelectedEntry();
            if (entry == null) { MessageBox.Show(this, "Select a file or folder to rename."); return; }
            if (entry.IsDrive) { MessageBox.Show(this, "A drive cannot be renamed here."); return; }
            string name = Prompt.Show(this, "Rename", "New name:", entry.Name);
            if (string.IsNullOrEmpty(name) || name == entry.Name) return;
            string path = Combine(_currentDir, entry.Name);
            string to = Combine(_currentDir, name);
            RunMutation(new Dictionary<string, object> { { "op", "rename" }, { "path", path }, { "to", to } }, "Renaming...");
        }

        private void Delete()
        {
            Entry entry = SelectedEntry();
            if (entry == null) { MessageBox.Show(this, "Select a file or folder to delete."); return; }
            if (entry.IsDrive) { MessageBox.Show(this, "A drive cannot be deleted."); return; }
            DialogResult confirm = MessageBox.Show(this,
                "Delete \"" + entry.Name + "\"" + (entry.Dir ? " and everything inside it" : "") + "?",
                "Confirm delete", MessageBoxButtons.YesNo, MessageBoxIcon.Warning);
            if (confirm != DialogResult.Yes) return;
            string path = Combine(_currentDir, entry.Name);
            RunMutation(new Dictionary<string, object> { { "op", "delete" }, { "path", path } }, "Deleting...");
        }

        private void SetClip(bool move)
        {
            Entry entry = SelectedEntry();
            if (entry == null || entry.IsDrive) { MessageBox.Show(this, "Select a file or folder first."); return; }
            _clip = new Clip { Move = move, Path = Combine(_currentDir, entry.Name), Name = entry.Name };
            SetStatus("Clipboard: " + (move ? "move " : "copy ") + entry.Name);
        }

        private void Paste()
        {
            if (_clip == null) { return; }
            if (_fsId <= 0) return;
            if (_atDrives) { MessageBox.Show(this, "Open a folder to paste into."); return; }
            string to = Combine(_currentDir, _clip.Name);
            Dictionary<string, object> op = new Dictionary<string, object> { { "op", _clip.Move ? "move" : "copy" }, { "path", _clip.Path }, { "to", to } };
            string label = _clip.Move ? "Moving..." : "Copying...";
            if (_clip.Move) _clip = null;
            RunMutation(op, label);
        }

        private void RunMutation(Dictionary<string, object> op, string label)
        {
            SetBusy(true, label);
            RunAsync(delegate
            {
                try
                {
                    FsRequest(op);
                    Invoke(new Action(delegate { SetBusy(false, label.TrimEnd('.') + " done."); LoadCurrentAsync(); }));
                }
                catch (ManagerException ex) { Fail(ex.Message); }
            });
        }

        // ------------------------------------------------------------- transfers

        private void Download()
        {
            Entry entry = SelectedEntry();
            if (entry == null) { MessageBox.Show(this, "Select a file or folder to download."); return; }
            if (entry.IsDrive) { MessageBox.Show(this, "Open the drive first."); return; }

            if (!entry.Dir)
            {
                SaveFileDialog save = new SaveFileDialog();
                save.FileName = entry.Name;
                if (save.ShowDialog(this) != DialogResult.OK) return;
                string local = save.FileName;
                SetBusy(true, "Downloading " + entry.Name + "...");
                RunAsync(delegate
                {
                    try { DownloadFile(entry.FullPath, local); Invoke(new Action(delegate { SetBusy(false, "Downloaded " + Path.GetFileName(local) + "."); })); }
                    catch (ManagerException ex) { Fail(ex.Message); }
                    catch (Exception ex) { Fail(ex.Message); }
                });
            }
            else
            {
                FolderBrowserDialog pick = new FolderBrowserDialog();
                pick.Description = "Choose where to save \"" + entry.Name + "\"";
                if (pick.ShowDialog(this) != DialogResult.OK) return;
                string localDir = Path.Combine(pick.SelectedPath, entry.Name);
                SetBusy(true, "Downloading folder " + entry.Name + "...");
                RunAsync(delegate
                {
                    try { DownloadDir(entry.FullPath, localDir); Invoke(new Action(delegate { SetBusy(false, "Downloaded folder " + entry.Name + "."); })); }
                    catch (ManagerException ex) { Fail(ex.Message); }
                    catch (Exception ex) { Fail(ex.Message); }
                });
            }
        }

        private void DownloadFile(string remote, string local)
        {
            string dir = Path.GetDirectoryName(local);
            if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir)) Directory.CreateDirectory(dir);
            using (FileStream fs = new FileStream(local, FileMode.Create, FileAccess.Write))
            {
                long offset = 0;
                while (true)
                {
                    Dictionary<string, object> reply = FsRequest(new Dictionary<string, object>
                    {
                        { "op", "read" }, { "path", remote }, { "offset", offset }, { "length", ChunkBytes },
                    });
                    byte[] data = Convert.FromBase64String(ManagerApi.AsString(reply, "data"));
                    fs.Write(data, 0, data.Length);
                    offset += data.Length;
                    SetStatus("Downloading " + Path.GetFileName(local) + " - " + FmtSize(offset));
                    if (ManagerApi.AsBool(reply, "eof", false)) break;
                    if (data.Length == 0) break;
                }
            }
        }

        private void DownloadDir(string remote, string local)
        {
            Directory.CreateDirectory(local);
            Dictionary<string, object> reply = FsRequest(new Dictionary<string, object> { { "op", "list" }, { "path", remote } });
            foreach (object raw in ManagerApi.AsArray(reply, "entries"))
            {
                Dictionary<string, object> e = raw as Dictionary<string, object>;
                if (e == null) continue;
                string name = ManagerApi.AsString(e, "name");
                string remoteChild = Combine(remote, name);
                string localChild = Path.Combine(local, name);
                if (ManagerApi.AsBool(e, "dir", false)) DownloadDir(remoteChild, localChild);
                else DownloadFile(remoteChild, localChild);
            }
        }

        private void Upload()
        {
            if (_fsId <= 0) { MessageBox.Show(this, "Pick a system and open a folder first."); return; }
            if (_atDrives) { MessageBox.Show(this, "Open a folder to upload into."); return; }

            int choice = ChoiceDialog.Show(this, "Upload", "What would you like to upload?",
                new string[] { "File(s)...", "Folder...", "Cancel" });
            if (choice != 0 && choice != 1) return;

            if (choice == 0)
            {
                OpenFileDialog open = new OpenFileDialog();
                open.Multiselect = true;
                if (open.ShowDialog(this) != DialogResult.OK) return;
                string[] localFiles = open.FileNames;
                SetBusy(true, "Uploading " + localFiles.Length + " file(s)...");
                RunAsync(delegate
                {
                    try
                    {
                        foreach (string file in localFiles)
                        {
                            string dest = Combine(_currentDir, Path.GetFileName(file));
                            UploadFile(file, dest);
                        }
                        Invoke(new Action(delegate { SetBusy(false, "Upload complete."); LoadCurrentAsync(); }));
                    }
                    catch (ManagerException ex) { Fail(ex.Message); }
                    catch (Exception ex) { Fail(ex.Message); }
                });
            }
            else
            {
                FolderBrowserDialog pick = new FolderBrowserDialog();
                pick.Description = "Choose a folder to upload";
                if (pick.ShowDialog(this) != DialogResult.OK) return;
                string localDir = pick.SelectedPath;
                string destDir = Combine(_currentDir, Path.GetFileName(localDir.TrimEnd('\\', '/')));
                SetBusy(true, "Uploading folder " + Path.GetFileName(localDir) + "...");
                RunAsync(delegate
                {
                    try { UploadDir(localDir, destDir); Invoke(new Action(delegate { SetBusy(false, "Upload complete."); LoadCurrentAsync(); })); }
                    catch (ManagerException ex) { Fail(ex.Message); }
                    catch (Exception ex) { Fail(ex.Message); }
                });
            }
        }

        private void UploadFile(string local, string remote)
        {
            byte[] data = File.ReadAllBytes(local);
            long offset = 0;
            while (offset < data.Length || data.Length == 0)
            {
                int len = Math.Min(ChunkBytes, data.Length - (int)offset);
                if (len < 0) len = 0;
                byte[] slice = new byte[len];
                Array.Copy(data, offset, slice, 0, len);
                FsRequest(new Dictionary<string, object>
                {
                    { "op", "write" }, { "path", remote }, { "offset", offset },
                    { "data", Convert.ToBase64String(slice) },
                });
                offset += len;
                SetStatus("Uploading " + Path.GetFileName(local) + " - " + FmtSize(offset));
                if (data.Length == 0) break;
            }
        }

        private void UploadDir(string local, string remote)
        {
            FsRequest(new Dictionary<string, object> { { "op", "mkdir" }, { "path", remote } });
            foreach (string file in Directory.GetFiles(local))
            {
                UploadFile(file, Combine(remote, Path.GetFileName(file)));
            }
            foreach (string sub in Directory.GetDirectories(local))
            {
                UploadDir(sub, Combine(remote, Path.GetFileName(sub)));
            }
        }

        // ---------------------------------------------------------------- command

        private void RunCommand()
        {
            string command = _cmdIn.Text.Trim();
            if (command.Length == 0) return;
            string target = SelectedCommandTarget();
            if (target == null) return;
            if (_busy) return;

            SetBusy(true, "Running command...");
            AppendCmd("> [" + TargetLabel(target) + "] " + command + Environment.NewLine);
            RunAsync(delegate
            {
                Dictionary<string, object> queued = _api.Post("/api/manager/command",
                    new Dictionary<string, object> { { "target", target }, { "command", command } }, true);
                if (queued == null || !ManagerApi.AsBool(queued, "ok", false))
                {
                    string err = queued == null ? "Cannot reach the server." : ManagerApi.AsString(queued, "error");
                    Invoke(new Action(delegate { AppendCmd("! " + err + Environment.NewLine); SetBusy(false, ""); }));
                    return;
                }
                if (ManagerApi.AsBool(queued, "direct", false))
                {
                    string output = ManagerApi.AsString(queued, "output");
                    Invoke(new Action(delegate { AppendCmd(output + Environment.NewLine); SetBusy(false, ""); }));
                    return;
                }
                long id = ManagerApi.AsLong(queued, "id", 0);
                for (int i = 0; i < 80; i++)
                {
                    Thread.Sleep(1500);
                    Dictionary<string, object> h = _api.Post("/api/manager/history", new Dictionary<string, object> { { "target", target } }, true);
                    string output = null;
                    string status = null;
                    foreach (object raw in ManagerApi.AsArray(h, "history"))
                    {
                        Dictionary<string, object> row = raw as Dictionary<string, object>;
                        if (row != null && ManagerApi.AsLong(row, "id", 0) == id)
                        {
                            status = ManagerApi.AsString(row, "status");
                            output = ManagerApi.AsString(row, "output");
                            break;
                        }
                    }
                    if (status == "DONE" || status == "FAILED")
                    {
                        string text = output;
                        Invoke(new Action(delegate { AppendCmd((text ?? "") + Environment.NewLine); SetBusy(false, "Command " + status + "."); }));
                        return;
                    }
                }
                Invoke(new Action(delegate { SetBusy(false, "Command still running. Check history later."); }));
            });
        }

        private void AppendCmd(string text)
        {
            _cmdOut.AppendText(text);
            _cmdOut.SelectionStart = _cmdOut.TextLength;
            _cmdOut.ScrollToCaret();
        }

        /// <summary>
        /// The target string to send for the Command tab: either the selected
        /// device/address, or a fleet sentinel chosen in the scope box. Returns
        /// null (after warning) when "Selected system" is chosen but nothing is.
        /// </summary>
        private string SelectedCommandTarget()
        {
            int scope = _targetScope == null ? 0 : _targetScope.SelectedIndex;
            if (scope == 1 && _targetScope.Items[1].ToString() == "All PCs") return "__ALL_PC__";
            if (scope == 1 && _targetScope.Items[1].ToString() == "All Android phones") return "__ALL_ANDROID__";
            if (scope == 2) return "__ALL_ANDROID__";
            if (scope == 3) return "__ALL__";
            if (_systems.SelectedItems.Count == 0) { MessageBox.Show(this, "Pick a system first."); return null; }
            string target = _systems.SelectedItems[0].Tag as string;
            if (string.IsNullOrEmpty(target)) { MessageBox.Show(this, "Pick a system first."); return null; }
            return target;
        }

        private static string TargetLabel(string target)
        {
            if (target == "__ALL__") return "all systems";
            if (target == "__ALL_PC__") return "all PCs";
            if (target == "__ALL_ANDROID__") return "all Android phones";
            if (target.StartsWith("dev:")) return "device " + target.Substring(4);
            return target;
        }

        // --------------------------------------------------------------- terminal

        private void EnsureTerminal()
        {
            if (_shellOpen) return;
            if (_systems.SelectedItems.Count == 0) { MessageBox.Show(this, "Pick a system first."); return; }
            string target = _systems.SelectedItems[0].Tag as string;
            if (string.IsNullOrEmpty(target)) return;

            Dictionary<string, object> open = _api.Post("/api/manager/shell/open",
                new Dictionary<string, object> { { "target", target } }, true);
            if (open == null || !ManagerApi.AsBool(open, "ok", false))
            {
                string err = open == null ? "Cannot reach the server." : ManagerApi.AsString(open, "error");
                MessageBox.Show(this, err, "Terminal", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                return;
            }
            _shellId = ManagerApi.AsLong(open, "shell", 0);
            if (_shellId <= 0) return;
            _shellOpen = true;
            _termOut.AppendText("Connected to " + target + ". Type commands, 'exit' to close.\r\n");
            _termPoller = new Thread(TerminalLoop);
            _termPoller.IsBackground = true;
            _termPoller.Start();
        }

        private void TerminalLoop()
        {
            long cursor = 0;
            long shell = _shellId;
            while (_shellOpen && _shellId == shell)
            {
                Dictionary<string, object> read = null;
                try
                {
                    read = _api.Post("/api/manager/shell/read",
                        new Dictionary<string, object> { { "shell", shell }, { "cursor", cursor } }, true);
                }
                catch (Exception) { }

                if (read != null && ManagerApi.AsBool(read, "ok", false))
                {
                    string data = ManagerApi.AsString(read, "data");
                    long c = ManagerApi.AsLong(read, "cursor", cursor);
                    if (c > cursor) cursor = c;
                    if (!string.IsNullOrEmpty(data))
                    {
                        string text = data.Replace("\n", "\r\n");
                        BeginInvoke(new Action(delegate { _termOut.AppendText(text); _termOut.SelectionStart = _termOut.TextLength; _termOut.ScrollToCaret(); }));
                    }
                    string status = ManagerApi.AsString(read, "status");
                    if (status.Length > 0 && status != "OPEN")
                    {
                        BeginInvoke(new Action(delegate { _termOut.AppendText("\r\n[session ended]\r\n"); }));
                        _shellOpen = false;
                        break;
                    }
                }
                Thread.Sleep(600);
            }
        }

        private void SendTerminal(string line)
        {
            if (!_shellOpen) { EnsureTerminal(); if (!_shellOpen) return; }
            if (line != null && line.Trim().Equals("exit", StringComparison.OrdinalIgnoreCase))
            {
                _shellOpen = false;
                long id = _shellId;
                _shellId = 0;
                RunAsync(delegate
                {
                    try { _api.Post("/api/manager/shell/close", new Dictionary<string, object> { { "shell", id } }, true); }
                    catch (Exception) { }
                });
                _termOut.AppendText("[session closed]\r\n");
                return;
            }
            long shell = _shellId;
            RunAsync(delegate
            {
                _api.Post("/api/manager/shell/write", new Dictionary<string, object> { { "shell", shell }, { "line", line ?? "" } }, true);
            });
        }

        // ---------------------------------------------------------------- helpers

        private static string Combine(string dir, string name)
        {
            if (string.IsNullOrEmpty(dir)) return name;
            if (dir.EndsWith("\\") || dir.EndsWith("/")) return dir + name;
            /* A POSIX path (an Android phone) must join with '/', a Windows path
               with '\'. Deciding from the path itself keeps Upload/Download and
               New folder correct on both platforms. */
            char sep = dir.IndexOf('/') >= 0 ? '/' : Path.DirectorySeparatorChar;
            return dir + sep + name;
        }

        private static string ParentOf(string dir)
        {
            if (string.IsNullOrEmpty(dir)) return "";
            if (dir.StartsWith("/"))
            {
                /* Android/POSIX: walk up on '/' only, so Directory.GetParent does
                   not rewrite the path onto a Windows drive and strand the user. */
                string posix = dir.TrimEnd('/');
                int cut = posix.LastIndexOf('/');
                return cut <= 0 ? "" : posix.Substring(0, cut);
            }
            try
            {
                string trimmed = dir.TrimEnd('\\', '/');
                DirectoryInfo parent = Directory.GetParent(trimmed);
                if (parent == null) return "";
                if (!parent.FullName.EndsWith("\\") && parent.FullName.Length == 2 && parent.FullName[1] == ':')
                    return parent.FullName + "\\";
                return parent.FullName;
            }
            catch (Exception) { return ""; }
        }

        private static string FmtSize(long bytes)
        {
            if (bytes < 0) return "";
            if (bytes < 1024) return bytes + " B";
            double kb = bytes / 1024.0;
            if (kb < 1024) return kb.ToString("0.0") + " KB";
            double mb = kb / 1024.0;
            if (mb < 1024) return mb.ToString("0.0") + " MB";
            double gb = mb / 1024.0;
            return gb.ToString("0.0") + " GB";
        }

        private void RunAsync(Action work)
        {
            Thread t = new Thread(delegate () { try { work(); } catch (Exception ex) { Trace("worker error: " + ex); } });
            t.IsBackground = true;
            t.Start();
        }

        private static void Trace(string message)
        {
            System.Diagnostics.Trace.WriteLine(message);
        }

        private void SetStatus(string text)
        {
            if (InvokeRequired) { BeginInvoke(new Action(delegate { SetStatus(text); })); return; }
            _status.Text = text;
        }

        private void SetBusy(bool busy, string message)
        {
            if (InvokeRequired) { BeginInvoke(new Action(delegate { SetBusy(busy, message); })); return; }
            _busy = busy;
            _progress.Visible = busy;
            if (!string.IsNullOrEmpty(message)) _status.Text = message;
        }

        private void Fail(string message)
        {
            Invoke(new Action(delegate
            {
                SetBusy(false, "");
                MessageBox.Show(this, message, "WorkFolio Manager", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }));
        }
    }

    // ------------------------------------------------------------- login dialog

    internal sealed class LoginDialog : Form
    {
        private readonly ManagerApi _api;
        private TextBox _server;
        private TextBox _email;
        private TextBox _password;
        private Label _error;

        public LoginDialog(ManagerApi api)
        {
            _api = api;
#if APKMANAGER
            Text = "WorkFolio ApkManager - Sign in";
#else
            Text = "WorkFolio Manager - Sign in";
#endif
            Width = 420;
            Height = 300;
            FormBorderStyle = FormBorderStyle.FixedDialog;
            StartPosition = FormStartPosition.CenterParent;
            MaximizeBox = false;
            MinimizeBox = false;
            Font = new Font("Segoe UI", 9F);

#if APKMANAGER
            Label title = new Label { Text = "WorkFolio ApkManager", Font = new Font("Segoe UI", 14F, FontStyle.Bold), Location = new Point(20, 16), AutoSize = true };
#else
            Label title = new Label { Text = "WorkFolio Manager", Font = new Font("Segoe UI", 14F, FontStyle.Bold), Location = new Point(20, 16), AutoSize = true };
#endif
            Label sub = new Label { Text = "Owner sign-in", ForeColor = Color.Gray, Location = new Point(22, 46), AutoSize = true };

            Label sLbl = new Label { Text = "Server", Location = new Point(20, 80), AutoSize = true };
            _server = new TextBox { Location = new Point(20, 100), Width = 360, Text = _api.ServerUrl };
            Label eLbl = new Label { Text = "Email", Location = new Point(20, 130), AutoSize = true };
            _email = new TextBox { Location = new Point(20, 150), Width = 360 };
            Label pLbl = new Label { Text = "Password", Location = new Point(20, 180), AutoSize = true };
            _password = new TextBox { Location = new Point(20, 200), Width = 360, UseSystemPasswordChar = true };
            _error = new Label { Location = new Point(20, 228), Width = 360, ForeColor = Color.Firebrick, AutoSize = false, Height = 16 };

            Button ok = new Button { Text = "Sign in", Location = new Point(280, 252), Width = 100, DialogResult = DialogResult.None };
            ok.Click += delegate { DoLogin(ok); };
            AcceptButton = ok;

            Controls.Add(title);
            Controls.Add(sub);
            Controls.Add(sLbl);
            Controls.Add(_server);
            Controls.Add(eLbl);
            Controls.Add(_email);
            Controls.Add(pLbl);
            Controls.Add(_password);
            Controls.Add(_error);
            Controls.Add(ok);
        }

        private void DoLogin(Button ok)
        {
            _error.Text = "";
            string email = _email.Text.Trim();
            string password = _password.Text;
            if (email.Length == 0 || password.Length == 0) { _error.Text = "Enter your email and password."; return; }
            _api.ServerUrl = _server.Text.Trim();
            ok.Enabled = false;
            Cursor = Cursors.WaitCursor;
            Dictionary<string, object> response = null;
            Thread worker = new Thread(delegate ()
            {
                response = _api.Login(email, password);
                BeginInvoke(new Action(delegate
                {
                    ok.Enabled = true;
                    Cursor = Cursors.Default;
                    if (response != null && ManagerApi.AsBool(response, "ok", false))
                    {
                        DialogResult = DialogResult.OK;
                        Close();
                    }
                    else
                    {
                        _error.Text = response == null ? "Cannot reach the server." : ManagerApi.AsString(response, "error");
                    }
                }));
            });
            worker.IsBackground = true;
            worker.Start();
        }
    }

    // -------------------------------------------------------------- choice box

    /// <summary>A tiny modal dialog with a stack of buttons; returns the index picked, -1 if closed.</summary>
    internal static class ChoiceDialog
    {
        public static int Show(IWin32Window owner, string title, string question, string[] options)
        {
            using (Form form = new Form())
            {
                form.Text = title;
                form.Width = 380;
                form.Height = 130 + options.Length * 34;
                form.FormBorderStyle = FormBorderStyle.FixedDialog;
                form.StartPosition = FormStartPosition.CenterParent;
                form.MaximizeBox = false;
                form.MinimizeBox = false;
                form.Font = new Font("Segoe UI", 9F);

                Label lbl = new Label { Text = question, Location = new Point(16, 16), AutoSize = true };
                form.Controls.Add(lbl);

                int result = -1;
                int y = 46;
                for (int i = 0; i < options.Length; i++)
                {
                    int index = i;
                    Button button = new Button { Text = options[i], Location = new Point(16, y), Width = 330, Height = 28 };
                    button.Click += delegate { result = index; form.Close(); };
                    form.Controls.Add(button);
                    y += 34;
                }
                form.ShowDialog(owner);
                return result;
            }
        }
    }

    // ------------------------------------------------------------- input prompt

    internal static class Prompt
    {
        public static string Show(IWin32Window owner, string title, string label, string defaultValue)
        {
            using (Form form = new Form())
            {
                form.Text = title;
                form.Width = 420;
                form.Height = 170;
                form.FormBorderStyle = FormBorderStyle.FixedDialog;
                form.StartPosition = FormStartPosition.CenterParent;
                form.MaximizeBox = false;
                form.MinimizeBox = false;
                form.Font = new Font("Segoe UI", 9F);

                Label lbl = new Label { Text = label, Location = new Point(16, 16), AutoSize = true };
                TextBox box = new TextBox { Location = new Point(16, 40), Width = 370, Text = defaultValue ?? "" };
                Button ok = new Button { Text = "OK", Location = new Point(290, 80), Width = 90, DialogResult = DialogResult.OK };
                Button cancel = new Button { Text = "Cancel", Location = new Point(196, 80), Width = 90, DialogResult = DialogResult.Cancel };
                form.AcceptButton = ok;
                form.CancelButton = cancel;
                form.Controls.Add(lbl);
                form.Controls.Add(box);
                form.Controls.Add(ok);
                form.Controls.Add(cancel);
                box.SelectAll();
                return form.ShowDialog(owner) == DialogResult.OK ? box.Text.Trim() : null;
            }
        }
    }
}
