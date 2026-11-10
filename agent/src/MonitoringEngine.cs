using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Threading;
using System.Windows.Forms;

namespace WorkFolio.Agent
{
    internal enum AttendanceStatus
    {
        OffDuty,
        Working,
        OnBreak
    }

    /// <summary>
    /// The heart of the agent. One 1-second tick drives everything:
    /// foreground-window sampling, mouse/keyboard totals, idle detection,
    /// scheduled screenshots and live-stream frames, all posted to the server.
    /// </summary>
    internal sealed class MonitoringEngine
    {
        private readonly AgentSettings _settings;
        private readonly ApiClient _api;
        private readonly OfflineQueue _queue;
        private readonly InputHooks _hooks = new InputHooks();
        private readonly ActiveWindowTracker _tracker = new ActiveWindowTracker();
        private readonly System.Windows.Forms.Timer _timer = new System.Windows.Forms.Timer();

        // Monotonic bucket accounting. Everything the employee "earned" lives
        // here, not in DateTime arithmetic, so folding the lid or a DST jump can
        // never inflate the shift.
        private readonly ShiftClock _clock;
        private readonly PowerMonitor _power;

        private AttendanceStatus _status = AttendanceStatus.OffDuty;
        private WindowSample _lastSample;
        private DateTime _lastSampleAt = DateTime.Now;
        private DateTime _lastTelemetryAt = DateTime.MinValue;
        private DateTime _lastShotAt = DateTime.MinValue;
        private DateTime _lastIdleChangeAt = DateTime.Now;
        private bool _lastIdle;
        private bool _replaying;
        private int _shotCountToday;
        private string _hostName = Environment.MachineName;
        private string _localIp = "-";

        /// <summary>True while the laptop is folded, asleep or the session locked.</summary>
        public bool IsPaused { get { return _power != null && _power.IsPaused; } }

        /// <summary>True while a break is running, so all capture can be refused.</summary>
        public bool OnBreakNow { get { return _status == AttendanceStatus.OnBreak; } }

        // live screen broadcast: its own thread so a 1 fps capture + upload never
        // delays the telemetry cycle
        private Thread _liveThread;
        private volatile bool _liveRunning;
        private long _liveSeq;
        private int _liveBusy;

        // cumulative input totals since agent start, mirrored to the server each cycle
        private long _totalKeystrokes;
        private long _totalClicks;
        private long _totalScrolls;
        private long _totalDistance;

        public event Action<string> OnLog;
        public event Action OnStateChanged;
        public event Action<string> OnProblem;

        public event Action<WindowSample, bool> OnSampleChanged;
        public event Action<bool, WindowSample> OnScreenshotCaptured;

        public AttendanceStatus Status { get { return _status; } }
        public bool IsIdle { get { return _lastIdle; } }
        public WindowSample CurrentSample { get { return _lastSample; } }
        public long Keystrokes { get { return _totalKeystrokes; } }
        public long Clicks { get { return _totalClicks; } }
        public long Scrolls { get { return _totalScrolls; } }
        public long DistancePx { get { return _totalDistance; } }
        public int ScreenshotsToday { get { return _shotCountToday; } }

        /// <summary>Authoritative shift totals, straight from the monotonic clock.</summary>
        public ShiftClock Clock { get { return _clock; } }
        public bool Collecting { get { return _timer.Enabled; } }
        public bool ServerDayLocked { get; private set; }
        private string _serverStatus = string.Empty;
        public string ServerStatus { get { return _serverStatus; } private set { _serverStatus = value; } }

        /// <summary>Human-readable idle/active totals for the status tiles.</summary>
        public string IdleSecondsLabel
        {
            get { return MainForm.FormatSpan(TimeSpan.FromSeconds(_clock.IdleSeconds)); }
        }

        public string ActiveSecondsLabel
        {
            get { return MainForm.FormatSpan(TimeSpan.FromSeconds(_clock.ActiveSeconds)); }
        }

        public MonitoringEngine(AgentSettings settings, ApiClient api, OfflineQueue queue)
        {
            _settings = settings;
            _api = api;
            _queue = queue;
            _timer.Interval = 1000;
            _timer.Tick += OnTick;
            _hostName = SafeHostName();
            _localIp = SafeLocalIp();

            // The shift ledger lives beside the rest of the agent state so it
            // survives a crash or a restart without losing the afternoon. The
            // owner is not known yet - the sign-in dialog has not been shown.
            _clock = new ShiftClock(AgentSettings.StatePathFor("shift-clock.txt"), string.Empty);

            _power = new PowerMonitor();
            _power.Paused += OnPowerPaused;
            _power.Resumed += OnPowerResumed;
            _power.Start();
        }

        public void Start()
        {
            if (_timer.Enabled) return;

            // Bind the ledger to whoever just signed in and pick their own day
            // back up if they have one.
            _clock.AdoptOwner(_api.Email);

            // Always sync from server so superadmin edits are reflected in the agent
            // and we never show stale local numbers after an admin correction.
            AdoptServerTotals();
            // Also pull fresh day summary to catch locked/edited state.
            try
            {
                Dictionary<string, object> day = _api.Day();
                if (day != null)
                {
                    long a = ApiClient.AsLong(day, "activeSec", -1);
                    long i = ApiClient.AsLong(day, "idleSec", -1);
                    long b = ApiClient.AsLong(day, "breakSec", -1);
                    long p = ApiClient.AsLong(day, "pausedSec", -1);
                    if (ApiClient.AsBool(day, "manualLocked", false) || a >= 0 || i >= 0 || b >= 0 || p >= 0)
                    {
                        if (a < 0) a = 0; if (i < 0) i = 0; if (b < 0) b = 0; if (p < 0) p = 0;
                        _clock.ForceBaseline(a, i, b, p);
                        ServerDayLocked = ApiClient.AsBool(day, "manualLocked", false);
                    }
                }
            }
            catch (Exception ex) { Trace.WriteLine(ex.Message); }

            if (_settings.TrackMouseKeyboard) _hooks.Start();
            _lastSample = _tracker.Current(false, _settings.TrackWebsites);
            _lastSampleAt = DateTime.Now;
            _lastIdle = IsIdleNow();
            _lastIdleChangeAt = DateTime.Now;

            // Deliberately NOT Reset() here. Restarting the agent is ordinary -
            // a reboot, an update, a crash, signing out and back in - and zeroing
            // the ledger on launch is what made a long day look half finished. The
            // ledger is already scoped to the day and to this employee, so the
            // totals on disk are exactly the totals that should be on screen.
            if (_clock.RestoredFromDisk)
            {
                Log("Recovered today's shift from disk - " +
                    MainForm.FormatSpan(TimeSpan.FromSeconds(_clock.WorkedSeconds)) + " still counted.");
            }

            _timer.Start();
            StartLiveStream();
            Log("Monitoring started. Uploads every " + _settings.SampleIntervalSeconds + "s.");
        }

        public void Stop()
        {
            _timer.Stop();
            StopLiveStream();
            _hooks.Stop();
            Log("Monitoring paused.");
        }

        /// <summary>
        /// Puts the server's per-bucket totals for today into the local ledger.
        ///
        /// Best effort by design: an unreachable server leaves the clock where it
        /// is, which is exactly what happened before this existed, and the next
        /// sign-in will try again. Only ever called once the local file has failed
        /// to load, so a healthy ledger is never overwritten by a slower reading.
        /// </summary>
        private void AdoptServerTotals()
        {
            try
            {
                Dictionary<string, object> summary = ApiClient.Section(_api.Today(), "today");
                if (summary == null) return;
                ApplyServerSummary(summary);
            }
            catch (Exception ex)
            {
                Trace.WriteLine("Could not read today's totals from the server: " + ex.Message);
            }
        }

        public void ApplyServerSummary(Dictionary<string, object> summary)
        {
            if (summary == null) return;
            ServerDayLocked = ApiClient.AsBool(summary, "manualLocked", false);
            _serverStatus = ApiClient.AsString(summary, "status") ?? string.Empty;

            long active = ApiClient.AsLong(summary, "activeSec", -1);
            long idle = ApiClient.AsLong(summary, "idleSec", -1);
            long brk = ApiClient.AsLong(summary, "breakSec", -1);
            long paused = ApiClient.AsLong(summary, "pausedSec", -1);
            // If server gives buckets, always follow them (especially when admin edited).
            if (active >= 0 || idle >= 0 || brk >= 0 || paused >= 0)
            {
                if (active < 0) active = 0;
                if (idle < 0) idle = 0;
                if (brk < 0) brk = 0;
                if (paused < 0) paused = 0;
                if (ServerDayLocked || !_clock.RestoredFromDisk)
                    _clock.ForceBaseline(active, idle, brk, paused);
                else
                    _clock.AdoptBaseline(active, idle, brk, paused);
                Log("Synced today's totals from the server - " +
                    MainForm.FormatSpan(TimeSpan.FromSeconds(_clock.WorkedSeconds)) + " counted.");
                return;
            }

            // Fallback: only use when no buckets present and nothing restored.
            if (_clock.RestoredFromDisk) return;
            active = ApiClient.AsLong(summary, "workedSec", 0);
            if (active <= 0) return;
            // Best effort split when not provided; prefer active+idle split from buckets.
            _clock.AdoptBaseline(active, 0, ApiClient.AsLong(summary, "breakSec", 0), 0);
            Log("Recovered today's totals from the server - " +
                MainForm.FormatSpan(TimeSpan.FromSeconds(_clock.WorkedSeconds)) + " counted.");
        }

        public void Shutdown()
        {
            // Close the day properly before the process goes away: bank the last
            // seconds, tell the server, and write the ledger so a restart resumes
            // from this figure rather than from whatever the last 30s save caught.
            FlushTotals();
            Stop();
            _hooks.Dispose();
            _timer.Dispose();
            _power.Paused -= OnPowerPaused;
            _power.Resumed -= OnPowerResumed;
            _power.Dispose();
            _clock.Dispose();
        }

        // ------------------------------------------------------- lid / suspend

        /// <summary>
        /// The laptop was folded or the session locked. Work stops counting from
        /// this instant, and nothing is captured or uploaded until it comes back,
        /// so a closed laptop is not read as a working one.
        /// </summary>
        private void OnPowerPaused(PowerPauseReason reason)
        {
            try
            {
                _clock.SetBucket(_status != AttendanceStatus.OffDuty, _lastIdle,
                    _status == AttendanceStatus.OnBreak, true);
                StopLiveStream();
                Log("Laptop away (" + reason + ") - timer stopped, nothing is being recorded.");
                SendHeartbeat();
            }
            catch (Exception ex)
            {
                Trace.WriteLine("Pause handling failed: " + ex);
            }
        }

        /// <summary>Back at the desk: counting continues from exactly where it stopped.</summary>
        private void OnPowerResumed()
        {
            try
            {
                // Reset the per-tick stamps so the gap while the lid was shut is
                // not attributed to the window that was on screen before it.
                _lastSampleAt = DateTime.Now;
                _lastIdleChangeAt = DateTime.Now;
                _lastTelemetryAt = DateTime.MinValue;
                _lastIdle = IsIdleNow();
                _clock.SetBucket(_status != AttendanceStatus.OffDuty, _lastIdle,
                    _status == AttendanceStatus.OnBreak, false);
                _lastSample = _tracker.Current(_lastIdle, _settings.TrackWebsites);
                RaiseState();
                if (_status != AttendanceStatus.OffDuty) StartLiveStream();
                Log("Back at the desk - timer continued from where it stopped.");
                SendHeartbeat();
            }
            catch (Exception ex)
            {
                Trace.WriteLine("Resume handling failed: " + ex);
            }
        }

        /// <summary>
        /// Cheap liveness ping carrying the monotonic totals only. During a break
        /// this is the *only* thing sent, which is what keeps the break timer
        /// accurate without touching window, usage or input data.
        /// </summary>
        private void SendHeartbeat()
        {
            if (_status == AttendanceStatus.OffDuty) return;
            bool idle = _lastIdle;
            Dictionary<string, object> result = _api.Heartbeat(
                idle ? "IDLE" : "ACTIVE",
                _clock.ActiveSeconds, _clock.IdleSeconds,
                _clock.BreakSeconds, _clock.PausedSeconds);
            if (result != null)
            {
                _settings.ApplyServerConfig(ApiClient.Section(result, "config"));
                Dictionary<string, object> today = ApiClient.Section(result, "today");
                if (today != null) ApplyServerSummary(today);
            }
        }

        // ---------------------------------------------------------- live video

        /// <summary>
        /// Starts the live screen broadcast on its own thread. It runs only while
        /// the employee is on duty: an off-duty machine streams nothing, so a
        /// manager never sees a screen of someone who is not working.
        /// </summary>
        private void StartLiveStream()
        {
            if (_liveRunning) return;
            _liveRunning = true;
            _liveThread = new Thread(LiveLoop);
            _liveThread.IsBackground = true;
            _liveThread.Name = "Folio live stream";
            _liveThread.Start();
            Log("Live stream started (" + Math.Max(0, _settings.LiveStreamIntervalMs) + "ms per frame).");
        }

        private void StopLiveStream()
        {
            _liveRunning = false;
            Thread t = _liveThread;
            _liveThread = null;
            if (t != null && t.IsAlive && !t.Join(1500))
            {
                Trace.WriteLine("Live stream thread did not stop in time; it will exit on its own.");
            }
        }

        private void LiveLoop()
        {
            while (_liveRunning)
            {
                // Frame-to-frame cadence is measured from the top of the iteration,
                // so a slow capture eats into the interval instead of adding to it:
                // the setting is a rate, not a delay after the work.
                int started = Environment.TickCount;
                bool sentFrame = false;
                try
                {
                    // One frame at a time: if a capture is still running, skip this
                    // tick instead of stacking captures on a slow machine.
                    if (Interlocked.CompareExchange(ref _liveBusy, 1, 0) == 0)
                    {
                        try
                        {
                            if (_settings.CaptureScreenshots && _settings.LiveStreamIntervalMs > 0 &&
                                // Working only. The old check was "not off duty",
                                // which streamed the employee's screen straight
                                // through their break to HR.
                                _status == AttendanceStatus.Working && !IsPaused)
                            {
                                // Live frames honour the same admin policy as screenshots. They used to be
                                // hard-capped to 720px/q42 here, which made the live
                                // view permanently soft no matter what the admin set.
                                byte[] jpeg = ScreenCapture.CaptureJpeg(_settings.ImageMaxWidth,
                                                                          _settings.ImageQuality);
                                if (jpeg != null)
                                {
                                    _api.LiveFrame(ScreenCapture.ToBase64(jpeg), IsIdleNow() ? "IDLE" : "ACTIVE",
                                                   _lastSample, Interlocked.Increment(ref _liveSeq), _hostName, _localIp);
                                    sentFrame = true;
                                }
                            }
                        }
                        finally
                        {
                            Interlocked.Exchange(ref _liveBusy, 0);
                        }
                    }
                }
                catch (Exception ex)
                {
                    Trace.WriteLine("Live frame failed: " + ex.Message);
                }

                // Sleep in slices so StopLiveStream() is honoured quickly. When no
                // frame went out (policy off, paused, capture failed) wait at least
                // 100ms anyway so the loop can never spin on an empty tick.
                int interval = Math.Max(0, _settings.LiveStreamIntervalMs);
                int deadline = started + (sentFrame ? interval : Math.Max(100, interval));
                while (_liveRunning && Environment.TickCount < deadline) Thread.Sleep(15);
            }
        }

        // ------------------------------------------------------------- commands

        public void ClockIn()
        {
            if (_status == AttendanceStatus.Working) return;
            if (_status == AttendanceStatus.OnBreak) { EndBreak(); }
            if (!SendAttendance("check-in")) { Report("Clock-in failed - check the server connection."); return; }
            SetStatus(AttendanceStatus.Working);
            Log("Clocked in.");
        }

        /// <summary>
        /// Ends the shift and reports whether the server accepted it. The status is
        /// only moved to OffDuty once the check-out is acknowledged: if the server
        /// is unreachable the session stays open so the machine keeps counting and
        /// no attendance event is fabricated locally.
        /// </summary>
        public bool ClockOut()
        {
            if (_status == AttendanceStatus.OffDuty) return false;
            if (_status == AttendanceStatus.OnBreak) SendAttendance("break/end");
            // Send the last totals before the server closes the shift, otherwise
            // the final seconds of the day are only ever known to this machine.
            FlushTotals();
            if (!SendAttendance("check-out"))
            {
                Report("Clock-out was not accepted - still counting. Check the server connection.");
                return false;
            }
            SetStatus(AttendanceStatus.OffDuty);
            Log("Clocked out.");
            return true;
        }

        public void StartBreak()
        {
            if (_status != AttendanceStatus.Working) return;
            if (!SendAttendance("break/start")) { Report("Could not start the break."); return; }
            SetStatus(AttendanceStatus.OnBreak);
            // Stop streaming before the status flips so not one frame of a
            // "break" is captured, and drop the window we were looking at so no
            // half-finished sample can be attributed to break time.
            StopLiveStream();
            _lastSample = null;
            // Drop the input counted so far as well. Anything the hooks buffer
            // while on break would otherwise be reported as the employee's
            // activity on the first tick after the break ends.
            _hooks.Discard();
            Log("Break started. Nothing is recorded while you are on break.");
        }

        public void EndBreak()
        {
            if (_status != AttendanceStatus.OnBreak) return;
            if (!SendAttendance("break/end")) { Report("Could not end the break."); return; }
            SetStatus(AttendanceStatus.Working);
            _lastSampleAt = DateTime.Now;
            _lastTelemetryAt = DateTime.MinValue;
            _lastSample = _tracker.Current(IsIdleNow(), _settings.TrackWebsites);
            StartLiveStream();
            Log("Break ended.");
        }

        /// <summary>Posts an attendance change and surfaces server-side rejection.</summary>
        private bool SendAttendance(string action)
        {
            Dictionary<string, object> response = _api.Attendance(action);
            if (response == null)
            {
                Report("Server unreachable during " + action + ".");
                return false;
            }
            if (!ApiClient.AsBool(response, "ok", false))
            {
                Report(ApiClient.AsString(response, "error") ?? ("Could not " + action + "."));
                return false;
            }
            return true;
        }

        /// <summary>Employee-initiated capture, stored as MANUAL evidence.</summary>
        public void CaptureNow()
        {
            // A break or a folded laptop means no picture, even when asked.
            if (_status != AttendanceStatus.Working || IsPaused)
            {
                Log(IsPaused ? "Screen capture skipped - laptop is away."
                    : "Screen capture is off during a break.");
                return;
            }
            ThreadPool.QueueUserWorkItem(delegate
            {
                // Re-check on the worker: a break can start between the click and
                // the capture actually happening.
                if (_status != AttendanceStatus.Working || IsPaused) return;
                byte[] jpeg = ScreenCapture.CaptureJpeg(_settings.ImageMaxWidth, _settings.ImageQuality);
                if (jpeg == null)
                {
                    Report("Could not read the screen.");
                    return;
                }
                string base64 = ScreenCapture.ToBase64(jpeg);
                bool ok = _api.Screenshot(base64, "MANUAL", _lastSample, false);
                if (!ok)
                {
                    Dictionary<string, object> payload = BuildScreenshotPayload(base64, "MANUAL", _lastSample, false);
                    _queue.Enqueue("screenshot", payload);
                }
                if (ok) _shotCountToday++;
                RaiseShot(ok, _lastSample);
            });
        }

        // ----------------------------------------------------------------- tick

        private void OnTick(object sender, EventArgs e)
        {
            try
            {
                bool onDuty = _status != AttendanceStatus.OffDuty;
                bool onBreak = _status == AttendanceStatus.OnBreak;
                bool paused = IsPaused;

                // The lid being shut is not work, and not a break either. Freezing
                // here is what makes the timer resume where it stopped instead of
                // racing forward through the closed hours.
                if (paused)
                {
                    // Safety: if we've been paused for too long without resume event,
                    // force resume to prevent timer getting stuck
                    if (_lastIdleChangeAt != DateTime.MinValue && (DateTime.Now - _lastIdleChangeAt).TotalMinutes > 30 && onDuty)
                    {
                        _lastIdleChangeAt = DateTime.Now;
                        // Force resume behavior
                    }
                    _clock.Tick();
                    return;
                }

                bool idle = onDuty && IsIdleNow();
                if (idle != _lastIdle)
                {
                    _lastIdle = idle;
                    _lastIdleChangeAt = DateTime.Now;
                    RaiseState();
                }

                // Time is attributed to the bucket that was current during the
                // interval that just ended, not the one we are in now.
                _clock.SetBucket(onDuty, idle, onBreak, false);
                _clock.Tick();

                if (!onDuty)
                {
                    // Off duty: still keep the window tracker warm so the live view and
                    // the next clock-in start with correct data, but send nothing.
                    _lastSample = _tracker.Current(false, _settings.TrackWebsites);
                    return;
                }

                int sampleEvery = Math.Max(2, _settings.SampleIntervalSeconds);
                if (SecondsSince(_lastTelemetryAt) < sampleEvery) return;

                // On break: keep the clock and the break running, but touch nothing
                // else. No usage, no window details, no screenshots - just a
                // heartbeat so the break length is known and provably data-free.
                if (onBreak)
                {
                    // Keep discarding whatever the hooks counted. If this buffer is
                    // left to fill during the break, the next working Drain() would
                    // report break-time typing as the employee's activity.
                    _hooks.Discard();
                    SendHeartbeat();
                    _lastTelemetryAt = DateTime.Now;
                    return;
                }

                WindowSample sample = _tracker.Current(idle, _settings.TrackWebsites);
                int elapsed = (int)(DateTime.Now - _lastSampleAt).TotalSeconds;

                // Attribute the elapsed window to the target the employee was on
                // *before* this tick, which is what actually happened.
                if (_lastSample != null && elapsed > 0 && _status == AttendanceStatus.Working)
                {
                    ReportUsage(_lastSample, elapsed, _lastIdle);
                }

                if (!ReferenceEquals(sample, _lastSample))
                {
                    _lastSample = sample;
                    Action<WindowSample, bool> handler = OnSampleChanged;
                    if (handler != null) handler(sample, idle);
                }

                _lastSampleAt = DateTime.Now;
                SendTelemetry(idle);
                MaybeCaptureScreenshots();
                _lastTelemetryAt = DateTime.Now;
            }
            catch (Exception ex)
            {
                Trace.WriteLine("Tick failed: " + ex);
                Report("Cycle error: " + ex.Message);
            }
        }

        private void SendTelemetry(bool idle)
        {
            InputHooks.InputSnapshot input = _hooks.Drain();
            _totalKeystrokes += input.Keystrokes;
            _totalClicks += input.Clicks;
            _totalScrolls += input.Scrolls;
            _totalDistance += input.DistancePx;

            // The server keeps the highest counter it has seen for the shift, so
            // always send the running totals rather than the per-cycle deltas.
            InputHooks.InputSnapshot totals = new InputHooks.InputSnapshot();
            totals.Keystrokes = _totalKeystrokes;
            totals.Clicks = _totalClicks;
            totals.Scrolls = _totalScrolls;
            totals.DistancePx = _totalDistance;

            WindowSample sample = _lastSample;
            Dictionary<string, object> result = _api.Telemetry(idle ? "IDLE" : "ACTIVE", totals,
                sample == null ? null : sample.DisplayName,
                sample == null ? null : sample.Title,
                sample == null ? null : sample.Host,
                sample == null ? null : sample.TargetType,
                _hostName, _localIp,
                _clock.ActiveSeconds, _clock.IdleSeconds,
                _clock.BreakSeconds, _clock.PausedSeconds);

            if (result != null)
            {
                _settings.ApplyServerConfig(ApiClient.Section(result, "config"));
                Dictionary<string, object> today = ApiClient.Section(result, "today");
                if (today != null) ApplyServerSummary(today);
                if (!_replaying) _replayQueue();
                RaiseState();
            }
            else
            {
                // Park the totals. They are cumulative and the server only ever
                // accepts the largest value it has seen, so replaying an old
                // snapshot is harmless - whereas dropping it is not. Without this
                // a twenty-minute dropout threw away everything but the five
                // minutes the server was willing to guess from its own clock.
                _queue.Enqueue("totals", BuildTotalsPayload(idle ? "IDLE" : "ACTIVE"));
                Report("Server not reachable - data is queued locally.");
            }
        }

        /// <summary>The monotonic totals in the shape the heartbeat endpoint expects.</summary>
        private Dictionary<string, object> BuildTotalsPayload(string status)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["status"] = status;
            payload["activeSec"] = _clock.ActiveSeconds;
            payload["idleSec"] = _clock.IdleSeconds;
            payload["breakSec"] = _clock.BreakSeconds;
            payload["pausedSec"] = _clock.PausedSeconds;
            payload["agentVersion"] = Program.Version;
            return payload;
        }

        /// <summary>
        /// Pushes the final totals before the session ends.
        ///
        /// Clock-out and shutdown are the two moments where the last few seconds
        /// of work are most likely to be the ones that never reach the server -
        /// the employee is closing the app, or the network is already going down.
        /// Sending once more here is what stops a day finishing minutes short.
        /// </summary>
        public void FlushTotals()
        {
            if (_status == AttendanceStatus.OffDuty) return;
            try
            {
                _clock.SetBucket(_status != AttendanceStatus.OffDuty, _lastIdle,
                    _status == AttendanceStatus.OnBreak, IsPaused);
                _clock.Tick();
                _clock.FlushToDisk();

                if (_api.Heartbeat(_lastIdle ? "IDLE" : "ACTIVE",
                        _clock.ActiveSeconds, _clock.IdleSeconds,
                        _clock.BreakSeconds, _clock.PausedSeconds) == null)
                {
                    _queue.Enqueue("totals", BuildTotalsPayload(_lastIdle ? "IDLE" : "ACTIVE"));
                }
            }
            catch (Exception ex)
            {
                Trace.WriteLine("Final totals could not be sent: " + ex.Message);
            }
        }

        private void ReportUsage(WindowSample sample, int seconds, bool idle)
        {
            if (sample == null || seconds <= 0) return;
            if (_settings.IsProcessExcluded(sample.ProcessName)) return;
            if (!string.IsNullOrEmpty(sample.Host) && _settings.IsSiteBlocked(sample.Host)) return;
            if (!idle && sample.TargetType == "WEB" && !_settings.TrackWebsites) return;
            if (!idle && sample.TargetType == "APP" && !_settings.TrackAppUsage) return;

            if (_api.Usage(sample, seconds, idle)) return;

            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["targetKey"] = sample.TargetKey;
            payload["targetType"] = sample.TargetType;
            payload["displayName"] = sample.DisplayName;
            payload["process"] = sample.ProcessName;
            payload["title"] = sample.Title;
            payload["host"] = sample.Host;
            payload["seconds"] = seconds;
            payload["idle"] = idle;
            _queue.Enqueue("usage", payload);
        }

        private void MaybeCaptureScreenshots()
        {
            // Evidence captures only. The live screen broadcast runs on its own
            // thread (LiveLoop) and overwrites a single server-side slot, so it
            // never lands in the archive or the gallery.
            if (!_settings.CaptureScreenshots) return;

            // No picture of a machine that is folded or on a break, ever.
            if (_status != AttendanceStatus.Working || IsPaused) return;

            if (_settings.ScreenshotIntervalSeconds > 0 &&
                SecondsSince(_lastShotAt) >= _settings.ScreenshotIntervalSeconds)
            {
                _lastShotAt = DateTime.Now;
                Capture("AUTO", false, _settings.ImageMaxWidth, _settings.ImageQuality);
            }
        }

        private void Capture(string trigger, bool live, int maxWidth, int quality)
        {
            byte[] jpeg = ScreenCapture.CaptureJpeg(maxWidth, quality);
            if (jpeg == null)
            {
                Report("Screen capture failed.");
                return;
            }

            string base64 = ScreenCapture.ToBase64(jpeg);
            bool ok = _api.Screenshot(base64, trigger, _lastSample, live);
            if (!ok)
            {
                _queue.Enqueue("screenshot", BuildScreenshotPayload(base64, trigger, _lastSample, live));
            }
            else
            {
                _shotCountToday++;
            }
            RaiseShot(ok, _lastSample);
        }

        private static Dictionary<string, object> BuildScreenshotPayload(string base64, string trigger,
                                                                          WindowSample sample, bool live)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["image"] = base64;
            payload["trigger"] = trigger;
            payload["app"] = sample == null ? null : sample.DisplayName;
            payload["title"] = sample == null ? null : sample.Title;
            payload["host"] = sample == null ? null : sample.Host;
            return payload;
        }

        private void _replayQueue()
        {
            if (_replaying) return;
            _replaying = true;
            try
            {
                ThreadPool.QueueUserWorkItem(delegate
                {
                    try
                    {
                        int before = _queue.Count;
                        if (before == 0) return;
                        _queue.Replay(delegate(string kind, Dictionary<string, object> payload)
                        {
                            if (kind == "usage")
                            {
                                return _api.SendQueued("/api/agent/usage", payload) != null;
                            }
                            if (kind == "screenshot")
                            {
                                return _api.SendQueued("/api/agent/screenshot", payload) != null;
                            }
                            if (kind == "totals")
                            {
                                // Cumulative totals, so the server keeps the
                                // largest and a replay can never rewind the day.
                                return _api.SendQueued("/api/agent/heartbeat", payload) != null;
                            }
                            return true;
                        });
                        Log("Replayed " + before + " queued item(s) after reconnect.");
                    }
                    finally
                    {
                        _replaying = false;
                    }
                });
            }
            catch (Exception) { _replaying = false; }
        }

        // -------------------------------------------------------------- helpers

        private bool IsIdleNow()
        {
            if (Native.SessionLocked()) return true;
            long idleMs = Native.IdleMilliseconds();
            return idleMs >= _settings.IdleThresholdSeconds * 1000L;
        }

        private static int SecondsSince(DateTime then)
        {
            if (then == DateTime.MinValue) return int.MaxValue;
            return (int)(DateTime.Now - then).TotalSeconds;
        }

        private void SetStatus(AttendanceStatus status)
        {
            _status = status;
            // Bank the time spent in the old bucket before switching, so a clock-in
            // that lands mid-second still accounts for it exactly once.
            _clock.SetBucket(status != AttendanceStatus.OffDuty, _lastIdle,
                status == AttendanceStatus.OnBreak, IsPaused);
            _clock.Tick();
            if (status == AttendanceStatus.OffDuty)
            {
                _lastIdle = false;
                _lastIdleChangeAt = DateTime.Now;
            }
            RaiseState();
        }

        /// <summary>Called by the login flow so a reconnect starts in the right state.</summary>
        public void ApplyServerStatus(Dictionary<string, object> summary)
        {
            if (summary == null) return;
            string status = ApiClient.AsString(summary, "status");
            if ("WORKING".Equals(status)) SetStatus(AttendanceStatus.Working);
            else if ("ON_BREAK".Equals(status)) SetStatus(AttendanceStatus.OnBreak);
            else SetStatus(AttendanceStatus.OffDuty);
        }

        private void RaiseState()
        {
            Action handler = OnStateChanged;
            if (handler != null) handler();
        }

        private void RaiseShot(bool ok, WindowSample sample)
        {
            Action<bool, WindowSample> handler = OnScreenshotCaptured;
            if (handler != null) handler(ok, sample);
        }

        private void Log(string message)
        {
            Action<string> handler = OnLog;
            if (handler != null) handler(message);
        }

        private void Report(string message)
        {
            Action<string> handler = OnProblem;
            if (handler != null) handler(message);
        }

        private static string SafeHostName()
        {
            try { return Environment.MachineName; }
            catch (Exception) { return "unknown-pc"; }
        }

        private static string SafeLocalIp()
        {
            try
            {
                foreach (NetworkInterface nic in NetworkInterface.GetAllNetworkInterfaces())
                {
                    if (nic.OperationalStatus != OperationalStatus.Up) continue;
                    if (nic.NetworkInterfaceType == NetworkInterfaceType.Loopback) continue;
                    foreach (UnicastIPAddressInformation ip in nic.GetIPProperties().UnicastAddresses)
                    {
                        if (ip.Address.AddressFamily == AddressFamily.InterNetwork && !IPAddress.IsLoopback(ip.Address))
                        {
                            return ip.Address.ToString();
                        }
                    }
                }
            }
            catch (Exception) { }
            return "-";
        }
    }
}
