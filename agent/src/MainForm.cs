using System;
using System.Collections;
using System.Collections.Generic;
using System.Drawing;
using System.Globalization;
using System.Windows.Forms;
using Microsoft.Web.WebView2.WinForms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// The employee-facing time tracker. The window content is the HTML page in
    /// webui\app.html; this class keeps every behaviour it had before - the tray
    /// icon, the monotonic timer, clock in / out, breaks, the daily target and the
    /// day timeline - and drives the page over the WebView2 message bridge.
    /// </summary>
    internal sealed class MainForm : Form
    {
        private readonly AgentSettings _settings;
        private readonly ApiClient _api;
        private readonly MonitoringEngine _engine;
        private readonly OfflineQueue _queue;

        private NotifyIcon _tray;
        private ContextMenuStrip _trayMenu;

        private WebView2 _view;
        private WebViewBridge _bridge;
        private bool _ready;

        private readonly System.Windows.Forms.Timer _uiTimer = new System.Windows.Forms.Timer();
        // Wall clock is only kept for display ("started at 09:15 AM"). Every duration
        // on screen comes from the monotonic ShiftClock instead.
        private DateTime _clockInAt = DateTime.MinValue;
        private DateTime _lastSegmentsFetch = DateTime.MinValue;
        private long _breakAllowanceSeconds = 1800;
        private long _serverTotalWorked;
        private bool _closingToTray;
        private readonly Queue<string> _recentLog = new Queue<string>();
        private string _who = "";

        private readonly ProductivityTarget _productivity = new ProductivityTarget();
        private string _productivityDay = DateTime.Today.ToString("yyyy-MM-dd");
        private bool _driftLogged;

        // Today's shift segments, newest first, in the shape the page renders.
        private List<Dictionary<string, object>> _segments = new List<Dictionary<string, object>>();

        public MainForm(AgentSettings settings, ApiClient api, MonitoringEngine engine, OfflineQueue queue)
        {
            _settings = settings;
            _api = api;
            _engine = engine;
            _queue = queue;
            _breakAllowanceSeconds = (long)settings.BreakAllowanceMinutes * 60;

            Text = "Folio";
            ClientSize = new Size(UiTheme.WidgetCardWidth, UiTheme.WidgetCardHeight);
            StartPosition = FormStartPosition.CenterScreen;
            Icon = AgentIcons.AppIcon;
            Font = new Font("Segoe UI", 9F);
            DoubleBuffered = true;

            // Rounded widget: the window is clipped to a rounded rectangle so the
            // real desktop shows at the corners. The page fills the window with the
            // portrait card and paints its own page colour behind it.
            UiTheme.MakeRoundedWidget(this);
            UiTheme.ApplyDpi(this);

            _view = new WebView2();
            _view.Dock = DockStyle.Fill;
            Controls.Add(_view);
            _bridge = new WebViewBridge(_view, WebUi.LoadApp(), OnMessage, UiTheme.WidgetBackground);
            _bridge.OnFailed += OnBridgeFailed;

            BuildTray();
            WireEngine();

            Load += delegate
            {
                UiTheme.FitWindow(this, UiTheme.WidgetCardWidth, UiTheme.WidgetCardHeight);
                UiTheme.ApplyPageZoom(_view, this);
                _bridge.Start();
            };
            Shown += delegate { PushState(); RefreshDay(true); ShowPendingResult(); };
        }

        // ------------------------------------------------------------------ tray

        private void BuildTray()
        {
            _trayMenu = new ContextMenuStrip();
            _trayMenu.Items.Add("Open Folio", null, delegate { Restore(); });
            _trayMenu.Items.Add(new ToolStripSeparator());
            _trayMenu.Items.Add("Clock In", null, delegate { DoClockIn(); });
            _trayMenu.Items.Add("Clock Out", null, delegate { DoClockOut(); });
            _trayMenu.Items.Add("Take Break", null, delegate { DoBreak(); });
            _trayMenu.Items.Add("Capture Screen Now", null, delegate { _engine.CaptureNow(); });
            _trayMenu.Items.Add(new ToolStripSeparator());
            _trayMenu.Items.Add("Sign Out", null, delegate { RequestSignOut(); });
            _trayMenu.Items.Add("Exit", null, delegate { ExitApplication(); });

            _tray = new NotifyIcon();
            _tray.Icon = AgentIcons.AppIcon;
            _tray.Text = "Folio";
            _tray.ContextMenuStrip = _trayMenu;
            _tray.Visible = true;
            _tray.DoubleClick += delegate { Restore(); };
        }

        // ---------------------------------------------------------------- bridge

        private void OnBridgeFailed()
        {
            if (IsDisposed) return;
            try
            {
                MessageBox.Show(this,
                    "The Folio window needs the Microsoft Edge WebView2 runtime, which is "
                    + "not installed on this computer.\n\n"
                    + "Install it from https://go.microsoft.com/fwlink/p/?LinkId=2124703 and restart Folio.\n\n"
                    + "Time recording continues in the background meanwhile.",
                    "Folio", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }
            catch (Exception) { }
        }

        private void OnMessage(string json)
        {
            Dictionary<string, object> message = WebViewBridge.Parse(json);
            string type = ApiClient.AsString(message, "type");
            if (type == "ready") { _ready = true; PushState(); RefreshDay(true); }
            else if (type == "tick") PushState();
            else if (type == "clockToggle") { if (_engine.Status == AttendanceStatus.OffDuty) DoClockIn(); else DoClockOut(); }
            else if (type == "breakToggle") DoBreak();
            else if (type == "capture") _engine.CaptureNow();
            else if (type == "signOut") RequestSignOut();
            else if (type == "drag") UiTheme.BeginDrag(this);
            else if (type == "minimize") WindowState = FormWindowState.Minimized;
            else if (type == "close") Close();
        }

        private void Toast(string message, string kind)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["type"] = "toast";
            payload["message"] = message;
            payload["kind"] = kind;
            _bridge.Post(payload);
        }

        /// <summary>Pushes the complete UI state to the page. Safe to call often.</summary>
        private void PushState()
        {
            if (!_ready) return;
            try
            {
                AttendanceStatus status = _engine.Status;
                bool onDuty = status != AttendanceStatus.OffDuty;
                bool onBreak = status == AttendanceStatus.OnBreak;
                bool paused = _engine.IsPaused;
                long worked = _engine.Clock.WorkedSeconds;
                // If server has edited totals for today, prefer them for display when locked/off-duty.
                if (_engine != null && _engine.ServerDayLocked)
                {
                    // When admin locked, display server values as source of truth.
                }
                if (!onDuty && _serverTotalWorked > 0) worked = _serverTotalWorked;

                long used = _engine.Clock.BreakSeconds;
                long left = _breakAllowanceSeconds - used;

                ProductivityResult result = _productivity.Evaluate(onDuty ? _engine.Clock.WorkedSeconds : _serverTotalWorked);
                int percent = (int)Math.Round(result.ProgressPercent, MidpointRounding.AwayFromZero);

                Dictionary<string, object> state = new Dictionary<string, object>();
                state["type"] = "state";
                state["who"] = _who;
                state["onDuty"] = onDuty;
                state["onBreak"] = onBreak;
                state["paused"] = paused;
                state["started"] = onDuty && _clockInAt != DateTime.MinValue
                    ? _clockInAt.ToString("hh:mm tt", CultureInfo.InvariantCulture) : "";
                state["date"] = DateTime.Now.ToString("MMMM d, yyyy", CultureInfo.InvariantCulture);
                long dispWorked = onDuty ? _engine.Clock.WorkedSeconds : (_serverTotalWorked > 0 ? _serverTotalWorked : worked);
                state["timer"] = FmtTimer(TimeSpan.FromSeconds(dispWorked));
                state["total"] = FormatSpan(TimeSpan.FromSeconds(dispWorked));
                state["breakCaption"] = paused ? "Paused - laptop away" : onBreak ? "On break now" : "Break time left";
                state["breakLeft"] = left < 0 ? ("over " + FmtMinutes(-left)) : (left == 0 ? "none" : FmtMinutes(left));
                state["breakLeftOver"] = left < 0;
                state["productivity"] = "Daily target " + ProductivityTarget.FormatTarget(result.TargetSeconds)
                    + "  \u00B7  " + ProductivityTarget.FormatDuration(result.EligibleSeconds)
                    + "  \u00B7  " + percent + "%";

                List<Dictionary<string, object>> segs = new List<Dictionary<string, object>>();
                foreach (Dictionary<string, object> s in _segments)
                {
                    if (s == null) continue;
                    bool live = ApiClient.AsBool(s, "onDuty", false);
                    string clockIn = ApiClient.AsString(s, "checkIn");
                    string clockOut = ApiClient.AsString(s, "checkOut");
                    long sec = ApiClient.AsLong(s, "workedSec", 0);

                    Dictionary<string, object> seg = new Dictionary<string, object>();
                    seg["in"] = string.IsNullOrEmpty(clockIn) ? "\u2014" : clockIn;
                    seg["out"] = live || string.IsNullOrEmpty(clockOut) ? "\u2014" : clockOut;
                    seg["dur"] = FmtHms(live && onDuty ? worked : sec);
                    seg["live"] = live && onDuty;
                    segs.Add(seg);
                }
                state["segments"] = segs;

                _bridge.Post(state);
            }
            catch (Exception)
            {
            }
        }

        // --------------------------------------------------------------- engine

        private void WireEngine()
        {
            _engine.OnStateChanged += SyncState;
            _engine.OnLog += AppendLog;
            _engine.OnProblem += delegate (string message) { AppendLog(message); Toast(message, "warning"); UpdateTray(); };
            _engine.OnSampleChanged += delegate (WindowSample s, bool idle) { };
            _engine.OnScreenshotCaptured += delegate (bool ok, WindowSample s)
            {
                AppendLog(ok ? "Screenshot uploaded." : "Screenshot queued for upload.");
            };
            _api.OnTransportProblem += delegate (string reason) { UpdateTray(); };
            _api.Connection.Changed += delegate (bool online, string status) { AppendLog(status); UpdateTray(); };

            _uiTimer.Interval = 1000;
            _uiTimer.Tick += delegate { TickUi(); };
            _uiTimer.Start();

            _who = !string.IsNullOrEmpty(_api.DisplayName) ? _api.DisplayName
                : (!string.IsNullOrEmpty(_api.Email) ? _api.Email : "");
            AppendLog("Signed in as " + (_who.Length == 0 ? "(unknown)" : _who));
            if (_who.Length > 0) Text = "Folio - " + _who;
            Dictionary<string, object> today = _api.Today();
            ApplyServerToday(today);
            RefreshDay(true);

            if (_engine.Status == AttendanceStatus.OffDuty)
            {
                AppendLog("Auto clocking in...");
                _engine.ClockIn();
                RefreshDay(true);
            }
        }

        private void ApplyServerToday(Dictionary<string, object> response)
        {
            Dictionary<string, object> summary = ApiClient.Section(response, "today");
            if (summary == null) return;

            _engine.ApplyServerStatus(summary);
            if (_engine != null) _engine.ApplyServerSummary(summary);
            DateTime parsed;
            string status = ApiClient.AsString(summary, "status");
            if (("WORKING".Equals(status) || "ON_BREAK".Equals(status))
                && TryParseClockIn(ApiClient.AsString(summary, "checkIn"), out parsed))
            {
                _clockInAt = parsed;
            }
            // Always take server totals when present so admin edits reflect immediately.
            long sw = ApiClient.AsLong(summary, "workedSec", -1);
            if (sw >= 0) _serverTotalWorked = sw;
            SyncState();
        }

        private static bool TryParseClockIn(string text, out DateTime value)
        {
            value = DateTime.MinValue;
            if (string.IsNullOrEmpty(text)) return false;

            text = text.Trim();
            bool pm = text.EndsWith("PM", StringComparison.OrdinalIgnoreCase);
            bool am = text.EndsWith("AM", StringComparison.OrdinalIgnoreCase);
            if (am || pm) text = text.Substring(0, text.Length - 2).Trim();

            string[] parts = text.Split(':');
            if (parts.Length < 2) return false;

            int hours, minutes;
            if (!int.TryParse(parts[0], out hours) || !int.TryParse(parts[1], out minutes)) return false;
            if (am && hours == 12) hours = 0;
            if (pm && hours < 12) hours += 12;
            if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return false;

            value = DateTime.Today.AddHours(hours).AddMinutes(minutes);
            return true;
        }

        private void TickUi()
        {
            try
            {
                AttendanceStatus status = _engine.Status;
                bool onDuty = status != AttendanceStatus.OffDuty;
                bool onBreak = status == AttendanceStatus.OnBreak;
                long worked = _engine.Clock.WorkedSeconds;

                UpdateProductivity(onDuty, onBreak, worked);

                if (!_driftLogged && _api.Clock.Significant)
                {
                    _driftLogged = true;
                    AppendLog(_api.Clock.Describe());
                }

                UpdateTray();
                PushState();
                RefreshDay(false);
            }
            catch (Exception)
            {
                // Never let a UI glitch stop the recording.
            }
        }

        // -------------------------------------------------------------- timeline

        private void RefreshDay(bool force)
        {
            if (!force && (DateTime.Now - _lastSegmentsFetch).TotalSeconds < 20) return;
            _lastSegmentsFetch = DateTime.Now;

            Dictionary<string, object> day;
            try { day = _api.Day(); }
            catch (Exception) { return; }
            if (day == null) return;

            _breakAllowanceSeconds = ApiClient.AsLong(day, "breakAllowanceSec", _breakAllowanceSeconds);
            long tw = ApiClient.AsLong(day, "totalWorkedSec", -1);
            if (tw >= 0) _serverTotalWorked = tw;
            else _serverTotalWorked = ApiClient.AsLong(day, "workedSec", _serverTotalWorked);
            if (_engine != null)
            {
                try
                {
                    long a = ApiClient.AsLong(day, "activeSec", -1);
                    long i = ApiClient.AsLong(day, "idleSec", -1);
                    long b = ApiClient.AsLong(day, "breakSec", -1);
                    long p = ApiClient.AsLong(day, "pausedSec", -1);
                    if (ApiClient.AsBool(day, "manualLocked", false) || a >= 0)
                    {
                        if (a < 0) a = 0; if (i < 0) i = 0; if (b < 0) b = 0; if (p < 0) p = 0;
                        _engine.Clock.ForceBaseline(a, i, b, p);
                    }
                }
                catch (Exception) { }
            }

            List<Dictionary<string, object>> list = new List<Dictionary<string, object>>();
            object raw = day.ContainsKey("segments") ? day["segments"] : null;
            IEnumerable en = raw as IEnumerable;
            if (en != null)
            {
                foreach (object o in en)
                {
                    Dictionary<string, object> s = o as Dictionary<string, object>;
                    if (s != null) list.Add(s);
                }
            }
            // Newest first, matching the page.
            list.Reverse();
            _segments = list;
            PushState();
        }

        private void SyncState()
        {
            if (IsDisposed) return;
            if (InvokeRequired)
            {
                if (!IsHandleCreated) return;
                try { BeginInvoke(new MethodInvoker(delegate { SyncState(); })); }
                catch (Exception) { }
                return;
            }

            try
            {
                UpdateTray();
                PushState();
                RefreshDay(true);
            }
            catch (Exception) { }
        }

        private void UpdateTray()
        {
            try
            {
                if (_tray == null) return;
                string state;
                if (_engine.IsPaused) state = "Paused";
                else if (_engine.Status == AttendanceStatus.Working) state = _engine.IsIdle ? "Idle" : "Working";
                else if (_engine.Status == AttendanceStatus.OnBreak) state = "On break";
                else state = "Off duty";
                string tip = _who.Length == 0 ? "Folio - " + state : "Folio - " + _who + " \u00B7 " + state;
                _tray.Text = tip.Length > 63 ? tip.Substring(0, 63) : tip;
            }
            catch (Exception) { }
        }

        // -------------------------------------------------------------- actions

        private void DoClockIn()
        {
            _engine.ClockIn();
            if (_clockInAt == DateTime.MinValue) _clockInAt = DateTime.Now;
            Toast("Clocked in. Time tracking is active.", "success");
            SyncState();
        }

        private void DoClockOut()
        {
            if (_engine.Status == AttendanceStatus.OnBreak) DoBreak();

            long attendance = _engine.Clock.WorkedSeconds + _engine.Clock.BreakSeconds;
            long eligible = _engine.Clock.WorkedSeconds;
            DateTime outAt = DateTime.Now;
            DateTime inAt = _clockInAt != DateTime.MinValue
                ? _clockInAt : outAt.AddSeconds(-attendance);

            if (!_engine.ClockOut())
            {
                SyncState();
                return;
            }

            _clockInAt = DateTime.MinValue;
            ShowFinalSummary(inAt, outAt, attendance, eligible);
            Toast("Clocked out. Have a good day.", "info");
            SyncState();
        }

        private void DoBreak()
        {
            if (_engine.Status == AttendanceStatus.Working)
            {
                _engine.StartBreak();
                Toast("Break started. The timer is paused.", "info");
            }
            else if (_engine.Status == AttendanceStatus.OnBreak)
            {
                _engine.EndBreak();
                Toast("Welcome back. Timer resumed.", "success");
            }
            SyncState();
        }

        private void RequestSignOut()
        {
            DialogResult confirm = MessageBox.Show(
                "Sign out of Folio? Monitoring stops until you sign in again.",
                "Folio", MessageBoxButtons.YesNo, MessageBoxIcon.Question);
            if (confirm != DialogResult.Yes) return;

            EventHandler handler = SignOutRequested;
            if (handler != null) handler(this, EventArgs.Empty);
        }

        /// <summary>Raised when the user asks to sign out; the run loop handles it.</summary>
        public event EventHandler SignOutRequested;

        public void ExitApplication()
        {
            _closingToTray = false;
            Close();
        }

        /// <summary>Builds, persists and shows the end-of-shift productivity result.</summary>
        private void ShowFinalSummary(DateTime clockInAt, DateTime clockOutAt,
                                      long attendanceSeconds, long eligibleSeconds)
        {
            try
            {
                ProductivityReport report = new ProductivityReport();
                report.Date = clockOutAt.ToString("yyyy-MM-dd");
                report.ClockIn = clockInAt;
                report.ClockOut = clockOutAt;
                report.AttendanceSeconds = attendanceSeconds;
                report.EligibleSeconds = eligibleSeconds;
                report.TargetSeconds = _productivity.TargetSeconds;
                report.StatusText = ProductivityTarget.StatusText(_productivity.Evaluate(eligibleSeconds).Status);
                report.Summary = _productivity.BuildSummary(clockInAt, clockOutAt, attendanceSeconds, eligibleSeconds);
                report.Shown = false;
                ProductivityStore.Save(report);

                DeliverSummary(report);

                report.Shown = true;
                ProductivityStore.Save(report);
            }
            catch (Exception)
            {
            }
        }

        private void ShowPendingResult()
        {
            try
            {
                ProductivityReport report = ProductivityStore.Load();
                if (report == null || report.Shown) return;
                if (report.Date != DateTime.Today.ToString("yyyy-MM-dd")) return;
                DeliverSummary(report);
                report.Shown = true;
                ProductivityStore.Save(report);
            }
            catch (Exception)
            {
            }
        }

        private void DeliverSummary(ProductivityReport report)
        {
            if (report == null || string.IsNullOrEmpty(report.Summary)) return;
            Balloon("Folio - daily result", report.StatusText);
            _bridge.Post(BuildSummaryPayload(report));
        }

        /// <summary>
        /// Turns a stored result into the message the page renders as the styled
        /// end-of-shift card, so clocking out shows one attractive summary instead
        /// of a plain system dialog.
        /// </summary>
        private Dictionary<string, object> BuildSummaryPayload(ProductivityReport report)
        {
            long target = report.TargetSeconds > 0 ? report.TargetSeconds : _productivity.TargetSeconds;
            long attendance = Math.Max(0, report.AttendanceSeconds);
            long eligible = Math.Max(0, report.EligibleSeconds);

            long difference = eligible - target;
            string differenceText;
            if (difference == 0) differenceText = "Right on target";
            else if (difference > 0) differenceText = ProductivityTarget.FormatSpoken(difference) + " over target";
            else differenceText = ProductivityTarget.FormatSpoken(-difference) + " short of target";

            string message = report.Summary;
            int split = report.Summary.IndexOf("\n\n", StringComparison.Ordinal);
            if (split >= 0) message = report.Summary.Substring(split).Trim();

            int percent = target > 0 ? (int)Math.Round(eligible * 100.0 / target) : 0;

            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["type"] = "summary";
            payload["date"] = report.ClockOut.ToString("ddd, d MMM yyyy");
            payload["clockIn"] = report.ClockIn.ToString("hh:mm tt");
            payload["clockOut"] = report.ClockOut.ToString("hh:mm tt");
            payload["attendance"] = ProductivityTarget.FormatDuration(attendance);
            payload["eligible"] = ProductivityTarget.FormatDuration(eligible);
            payload["target"] = ProductivityTarget.FormatTarget(target);
            payload["difference"] = differenceText;
            payload["percent"] = percent;
            payload["status"] = report.StatusText;
            payload["reached"] = eligible >= target;
            payload["message"] = message;
            return payload;
        }

        private void UpdateProductivity(bool onDuty, bool onBreak, long workedSeconds)
        {
            try
            {
                string today = DateTime.Today.ToString("yyyy-MM-dd");
                if (today != _productivityDay)
                {
                    _productivityDay = today;
                    _productivity.Reset();
                }

                ProductivityResult result = _productivity.Evaluate(workedSeconds);

                if (onDuty && !onBreak && !_engine.IsPaused)
                {
                    MilestoneNotice notice = _productivity.NextMilestone(workedSeconds);
                    if (notice != null)
                    {
                        AppendLog(notice.Message);
                        Balloon("Folio - " + notice.Percent + "% of target", notice.Message);
                        Toast(notice.Percent + "% of today's target reached.", "success");
                    }
                }
            }
            catch (Exception)
            {
            }
        }

        private void Balloon(string title, string text)
        {
            try
            {
                if (_tray == null || string.IsNullOrEmpty(text)) return;
                if (text.Length > 255) text = text.Substring(0, 252) + "...";
                _tray.ShowBalloonTip(3000, title, text, ToolTipIcon.Info);
            }
            catch (Exception)
            {
            }
        }

        // ---------------------------------------------------------- form events

        private void AppendLog(string message)
        {
            try
            {
                if (IsDisposed) return;
                lock (_recentLog)
                {
                    _recentLog.Enqueue(message);
                    while (_recentLog.Count > 20) _recentLog.Dequeue();
                }
            }
            catch (Exception) { }
        }

        public void PostLog(string message)
        {
            AppendLog(message);
        }

        public void ShowUpdate(string version, string urlPath, string sha256)
        {
            try
            {
                if (IsDisposed) return;
                BeginInvoke((MethodInvoker)delegate { PromptUpdate(version, urlPath, sha256); });
            }
            catch (Exception) { }
        }

        private void PromptUpdate(string version, string urlPath, string sha256)
        {
            DialogResult answer = MessageBox.Show(this,
                "A new version of Folio Agent (" + version + ") is available.\n\n"
                + "Update now? The agent will restart automatically.",
                "Folio update", MessageBoxButtons.YesNo, MessageBoxIcon.Information);
            if (answer != DialogResult.Yes) return;

            string path, error;
            if (!Updater.Download(_api, urlPath, sha256, out path, out error))
            {
                MessageBox.Show(this, "The update could not be downloaded: " + error,
                    "Folio update", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                return;
            }
            if (!Updater.ApplyAndRestart(path, out error))
            {
                MessageBox.Show(this, "The update could not be applied: " + error,
                    "Folio update", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }
        }

        protected override void OnFormClosing(FormClosingEventArgs e)
        {
            if (_closingToTray
                && e.CloseReason != CloseReason.WindowsShutDown
                && e.CloseReason != CloseReason.TaskManagerClosing
                && e.CloseReason != CloseReason.ApplicationExitCall)
            {
                e.Cancel = true;
                Hide();
                try
                {
                    _tray.ShowBalloonTip(2000, "Folio",
                        "Still recording. Right-click the tray icon to open or exit.", ToolTipIcon.Info);
                }
                catch (Exception) { }
                return;
            }
            base.OnFormClosing(e);
        }

        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                _uiTimer.Stop();
                _uiTimer.Dispose();
                if (_tray != null) { _tray.Visible = false; _tray.Dispose(); }
            }
            base.Dispose(disposing);
        }

        internal bool CloseToTray
        {
            get { return _closingToTray; }
            set { _closingToTray = value; }
        }

        /// <summary>Set when the user signs out, so the run loop can ask for credentials again.</summary>
        internal bool SessionEnded { get; set; }

        private void Restore()
        {
            Show();
            WindowState = FormWindowState.Normal;
            Activate();
        }

        // -------------------------------------------------------------- helpers

        internal static string FmtTimer(TimeSpan span)
        {
            if (span < TimeSpan.Zero) span = TimeSpan.Zero;
            return string.Format("{0:00}:{1:00}:{2:00}", (int)span.TotalHours, span.Minutes, span.Seconds);
        }

        internal static string FmtHms(long seconds)
        {
            if (seconds < 0) seconds = 0;
            return string.Format("{0}:{1:00}:{2:00}", seconds / 3600, (seconds % 3600) / 60, seconds % 60);
        }

        private static string FmtMinutes(long seconds)
        {
            long m = seconds / 60;
            if (m < 60) return m + " min";
            long h = m / 60;
            long rem = m % 60;
            return rem == 0 ? h + " h" : h + " h " + rem + " min";
        }

        internal static string FormatSpan(TimeSpan span)
        {
            if (span < TimeSpan.Zero) span = TimeSpan.Zero;
            return string.Format("{0:00}h {1:00}m", (int)span.TotalHours, span.Minutes);
        }
    }
}
