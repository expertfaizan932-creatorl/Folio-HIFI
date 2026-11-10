using System;
using System.Runtime.InteropServices;
using System.Threading;
using System.Windows.Forms;
using Microsoft.Win32;

namespace WorkFolio.Agent
{
    /// <summary>Why the machine stopped counting as working time.</summary>
    internal enum PowerPauseReason
    {
        None,
        LidClosed,
        Suspend,
        SessionLocked,
    }

    /// <summary>
    /// Watches for the two ways a laptop actually leaves an employee's desk:
    /// the lid folding shut, and the machine suspending itself (battery, sleep
    /// timeout, manual sleep).
    ///
    /// Lid close is reported through the power-setting broadcast, which is the
    /// only reliable signal for it: on mains power closing the lid often only
    /// turns the display off without suspending, so a suspend-only listener
    /// would silently keep billing the employee as working. A console display
    /// going off is therefore treated as a pause too, but only when the lid
    /// switch is not reported separately (some docks and external monitors
    /// report it).
    ///
    /// A dimmed display is emphatically not a pause. Windows dims the screen after
    /// a few seconds of no input on most laptops, on battery and on mains alike,
    /// so pausing on it froze worked time every couple of minutes for anyone who
    /// reads rather than types. Dimming is a brightness change; only a display
    /// that has actually switched off stands in for a closed lid.
    ///
    /// Everything is funnelled into the same pause/resume pair so the rest of the
    /// agent does not need to care which of these happened. Events fire on a
    /// background thread; subscribers must marshal to the UI themselves.
    /// </summary>
    internal sealed class PowerMonitor : IDisposable
    {
        // --- messages and values we care about -----------------------------
        private const int WM_POWERBROADCAST = 0x0218;
        private const int PBT_APMSUSPEND = 0x0004;
        private const int PBT_APMRESUMESUSPEND = 0x0007;
        private const int PBT_POWERSETTINGCHANGE = 0x8013; // 32786

        private const int DEVICE_NOTIFY_WINDOW_HANDLE = 0x00000000;

        // ConsoleDisplayState values.
        private const int DISPLAY_OFF = 0;
        private const int DISPLAY_ON = 1;
        private const int DISPLAY_DIMMED = 2;

        private const int WM_APP = 0x8000;
        private const int MSG_PAUSE = WM_APP + 41;
        private const int MSG_RESUME = WM_APP + 42;
        // Wakes the pump thread so it can unwind and destroy the window.
        private const int MSG_STOP_PUMP = WM_APP + 99;

        // GUID_LIDSWITCH - fires the moment the hinge opens or closes.
        private static readonly Guid GuidLidSwitch =
            new Guid("5F3E9F59-C0B5-3034-B1A8-18C21D3F2286");

        // GUID_CONSOLE_DISPLAY_STATE - display power on/off/dim.
        private static readonly Guid GuidConsoleDisplayState =
            new Guid("6FE69556-704A-47A0-8F24-C28D936FDA47");

        [StructLayout(LayoutKind.Sequential)]
        private struct POWERBROADCAST_SETTING
        {
            public Guid PowerSetting;
            public uint DataLength;
            [MarshalAs(UnmanagedType.ByValArray, SizeConst = 1)]
            public byte[] Data;
        }

        [DllImport("user32.dll", SetLastError = true)]
        private static extern IntPtr RegisterPowerSettingNotification(
            IntPtr hRecipient, ref Guid powerSettingGuid, int flags);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern bool UnregisterPowerSettingNotification(IntPtr handle);

        private readonly object _gate = new object();
        private Thread _thread;
        private PowerWindow _window;
        private bool _paused;
        private PowerPauseReason _reason;
        private bool _lidClosed;
        private bool _displayOff;
        private bool _suspendReported;

        /// <summary>Raised when work should stop counting. Runs off-thread.</summary>
        public event Action<PowerPauseReason> Paused;
        /// <summary>Raised when the employee is back and counting resumes.</summary>
        public event Action Resumed;

        /// <summary>True while the machine is folded, asleep or locked away.</summary>
        public bool IsPaused
        {
            get { lock (_gate) { return _paused; } }
        }

        public PowerPauseReason Reason
        {
            get { lock (_gate) { return _reason; } }
        }

        public void Start()
        {
            if (_thread != null) return;
            _thread = new Thread(Run);
            _thread.IsBackground = true;
            _thread.Name = "Folio power monitor";
            _thread.Start();
        }

        private void Run()
        {
            // A message-only window needs its own message pump, because these
            // broadcasts are delivered to a window and never to a thread queue.
            var window = new PowerWindow(this);
            lock (_gate)
            {
                _window = window;
            }
            try
            {
                var cp = new CreateParams();
                // A message-only window: parent HWND_MESSAGE, never rendered,
                // never in the taskbar or Alt+Tab.
                cp.Parent = new IntPtr(-3); // HWND_MESSAGE
                window.CreateHandle(cp);
                window.Register(GuidLidSwitch);
                window.Register(GuidConsoleDisplayState);

                // Suspend/resume and session lock are already raised for us on a
                // dedicated thread by SystemEvents, so no pump needed for those.
                SystemEvents.PowerModeChanged += OnPowerModeChanged;
                SystemEvents.SessionSwitch += OnSessionSwitch;

                window.RunMessageLoop();
            }
            catch (Exception)
            {
                // A machine that refuses the registration simply keeps the old
                // behaviour; the agent must still run.
            }
            finally
            {
                SystemEvents.PowerModeChanged -= OnPowerModeChanged;
                SystemEvents.SessionSwitch -= OnSessionSwitch;
                try { window.DestroyHandle(); } catch (Exception) { }
                lock (_gate) { _window = null; }
            }
        }

        private void OnPowerModeChanged(object sender, PowerModeChangedEventArgs e)
        {
            if (e.Mode == PowerModes.Suspend) Begin(PowerPauseReason.Suspend);
            else if (e.Mode == PowerModes.Resume)
            {
                lock (_gate)
                {
                    _lidClosed = false;
                    _displayOff = false;
                    _suspendReported = false;
                }
                End(PowerPauseReason.Suspend);
            }
        }

        private void OnSessionSwitch(object sender, SessionSwitchEventArgs e)
        {
            if (e.Reason == SessionSwitchReason.SessionLock)
                Begin(PowerPauseReason.SessionLocked);
            else if (e.Reason == SessionSwitchReason.SessionUnlock)
            {
                lock (_gate)
                {
                    _lidClosed = false;
                    _displayOff = false;
                    _suspendReported = false;
                }
                End(PowerPauseReason.SessionLocked);
            }
        }

        // Called from the message window, on the power-monitor thread.
        internal void HandleMessage(IntPtr hwnd, int msg, IntPtr wParam, IntPtr lParam, ref bool handled)
        {
            if (msg == MSG_STOP_PUMP)
            {
                // Dispose() runs on the caller's thread, so it can only ask the
                // pump to stop. Without this the thread would sit in GetMessage
                // forever holding a thread handle and a window registration.
                if (_window != null) _window.StopRequested = true;
                handled = true;
                return;
            }
            if (msg == WM_POWERBROADCAST)
            {
                if (wParam.ToInt32() == PBT_APMSUSPEND)
                {
                    lock (_gate) { _suspendReported = true; }
                    Begin(PowerPauseReason.Suspend);
                    handled = true;
                    return;
                }
                if (wParam.ToInt32() == PBT_APMRESUMESUSPEND)
                {
                    bool reported;
                    lock (_gate) { reported = _suspendReported; _suspendReported = false; }
                    // SystemEvents may also fire Resume; whichever arrives first
                    // wins and the other is a no-op because we are not paused.
                    if (reported) End(PowerPauseReason.Suspend);
                    handled = true;
                    return;
                }
                if (wParam.ToInt32() == PBT_POWERSETTINGCHANGE)
                {
                    HandlePowerSetting(lParam);
                    handled = true;
                    return;
                }
            }
            else if (msg == MSG_PAUSE)
            {
                Begin((PowerPauseReason)wParam.ToInt32());
                handled = true;
            }
            else if (msg == MSG_RESUME)
            {
                End((PowerPauseReason)wParam.ToInt32());
                handled = true;
            }
        }

        private void HandlePowerSetting(IntPtr lParam)
        {
            var setting = (POWERBROADCAST_SETTING)Marshal.PtrToStructure(
                lParam, typeof(POWERBROADCAST_SETTING));
            if (setting.DataLength < 4 || setting.Data == null || setting.Data.Length < 4)
                return;

            int value = BitConverter.ToInt32(setting.Data, 0);

            if (setting.PowerSetting == GuidLidSwitch)
            {
                bool closed = value == 0;
                lock (_gate)
                {
                    if (closed == _lidClosed) return;
                    _lidClosed = closed;
                    // Opening the hinge means the panel is physically back, so any
                    // display-off state held for the closed lid is now stale.
                    // Leaving it set would keep the agent paused until the next
                    // display event arrived, which on a dock that only ever reports
                    // the external monitor may never happen at all.
                    if (!closed) _displayOff = false;
                }
                if (closed) Begin(PowerPauseReason.LidClosed);
                else End(PowerPauseReason.LidClosed);
                return;
            }

            if (setting.PowerSetting == GuidConsoleDisplayState)
            {
                bool off = DisplayAway(value);
                lock (_gate)
                {
                    if (off == _displayOff) return;
                    _displayOff = off;
                    // If the hinge told us first, that is the better reason and we
                    // must not flip the pause off on a display-only change.
                    if (_lidClosed) return;
                }
                if (off) Begin(PowerPauseReason.LidClosed);
                else End(PowerPauseReason.LidClosed);
            }
        }

        /// <summary>
        /// True for the ConsoleDisplayState values that mean the employee is not
        /// in front of the machine. Exposed so the rule can be tested directly:
        /// the dimmed state must never reach the pause path.
        /// </summary>
        internal static bool DisplayAway(int consoleDisplayState)
        {
            return consoleDisplayState == DISPLAY_OFF;
        }

        /// <summary>Ends any pause and restarts counting, e.g. after sign-in.</summary>
        public void Clear()
        {
            lock (_gate)
            {
                _paused = false;
                _reason = PowerPauseReason.None;
                _lidClosed = false;
                _displayOff = false;
                _suspendReported = false;
            }
        }

        private void Begin(PowerPauseReason reason)
        {
            Action<PowerPauseReason> handler;
            lock (_gate)
            {
                if (_paused) return;
                _paused = true;
                _reason = reason;
                handler = Paused;
            }
            Trace("pause: " + reason);
            if (handler != null)
            {
                try { handler(reason); } catch (Exception) { }
            }
        }

        private void End(PowerPauseReason reason)
        {
            PowerPauseReason was;
            Action handler;
            lock (_gate)
            {
                if (!_paused) return;
                // On resume/wake/unlock, clear stuck states and allow resume
                // This fixes the bug where timer stops and never resumes
                was = _reason;
                _paused = false;
                _reason = PowerPauseReason.None;
                _lidClosed = false;
                _displayOff = false;
                _suspendReported = false;
                handler = Resumed;
            }
            Trace("resume (was " + was + ", trigger " + reason + ")");
            if (handler != null)
            {
                try { handler(); } catch (Exception) { }
            }
        }

        private static void Trace(string message)
        {
            System.Diagnostics.Trace.WriteLine("Folio power: " + message);
        }

        public void Dispose()
        {
            Clear();
            IntPtr handle;
            lock (_gate)
            {
                handle = _window == null ? IntPtr.Zero : _window.Handle;
            }
            if (handle != IntPtr.Zero)
                PostMessage(handle, MSG_STOP_PUMP, IntPtr.Zero, IntPtr.Zero);

            SystemEvents.PowerModeChanged -= OnPowerModeChanged;
            SystemEvents.SessionSwitch -= OnSessionSwitch;
        }

        [DllImport("user32.dll", SetLastError = true)]
        private static extern bool PostMessage(IntPtr hWnd, int msg, IntPtr wParam, IntPtr lParam);

        /// <summary>Message-only window that receives the power broadcasts.</summary>
        private sealed class PowerWindow : NativeWindow
        {
            private readonly PowerMonitor _owner;
            private readonly System.Collections.Generic.List<IntPtr> _registrations =
                new System.Collections.Generic.List<IntPtr>();

            internal PowerWindow(PowerMonitor owner) { _owner = owner; }

            /// <summary>Set by Dispose() via MSG_STOP_PUMP, polled by the pump.</summary>
            internal volatile bool StopRequested;

            internal void Register(Guid setting)
            {
                IntPtr h = RegisterPowerSettingNotification(
                    Handle, ref setting, DEVICE_NOTIFY_WINDOW_HANDLE);
                if (h != IntPtr.Zero) _registrations.Add(h);
            }

            protected override void WndProc(ref Message m)
            {
                bool handled = false;
                try { _owner.HandleMessage(m.HWnd, m.Msg, m.WParam, m.LParam, ref handled); }
                catch (Exception) { }
                if (!handled) base.WndProc(ref m);
            }

            internal void RunMessageLoop()
            {
                NativeMessage message;
                while (!StopRequested)
                {
                    int r = GetMessage(out message, IntPtr.Zero, 0, 0);
                    if (r <= 0) break;
                    if (r == -1) break;
                    TranslateMessage(ref message);
                    DispatchMessage(ref message);
                }
            }

            public override void DestroyHandle()
            {
                foreach (IntPtr h in _registrations)
                {
                    try { UnregisterPowerSettingNotification(h); } catch (Exception) { }
                }
                _registrations.Clear();
                base.DestroyHandle();
            }
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct NativeMessage
        {
            public IntPtr hwnd;
            public int message;
            public IntPtr wParam;
            public IntPtr lParam;
            public uint time;
            public System.Drawing.Point pt;
        }

        [DllImport("user32.dll", SetLastError = true)]
        private static extern int GetMessage(out NativeMessage msg, IntPtr hWnd, int min, int max);

        [DllImport("user32.dll")]
        private static extern bool TranslateMessage(ref NativeMessage msg);

        [DllImport("user32.dll")]
        private static extern IntPtr DispatchMessage(ref NativeMessage msg);
    }
}