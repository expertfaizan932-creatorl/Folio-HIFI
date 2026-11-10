using System;
using System.Diagnostics;
using System.Threading;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Global mouse and keyboard activity counters.
    ///
    /// IMPORTANT: this counts events, it never reads them. No key is logged, stored or
    /// transmitted - only a running total of how many keystrokes / clicks / scrolls
    /// happened, which is what the productivity and idle reports need.
    /// </summary>
    internal sealed class InputHooks : IDisposable
    {
        private IntPtr _mouseHook = IntPtr.Zero;
        private IntPtr _keyboardHook = IntPtr.Zero;
        private readonly Native.HookProc _mouseProc;
        private readonly Native.HookProc _keyboardProc;
        private readonly object _sync = new object();

        private long _keystrokes;
        private long _clicks;
        private long _scrolls;
        private long _distancePx;
        private int _lastX = int.MinValue;
        private int _lastY;
        private long _lastTick;
        private bool _running;

        public InputHooks()
        {
            _mouseProc = MouseCallback;
            _keyboardProc = KeyboardCallback;
        }

        /// <summary>Live totals since agent start; the server stores the peak each cycle.</summary>
        public long Keystrokes { get { lock (_sync) return _keystrokes; } }
        public long Clicks { get { lock (_sync) return _clicks; } }
        public long Scrolls { get { lock (_sync) return _scrolls; } }
        public long DistancePx { get { lock (_sync) return _distancePx; } }

        public bool IsRunning { get { return _running; } }

        public void Start()
        {
            if (_running) return;
            lock (_sync)
            {
                _lastX = int.MinValue;
                _lastY = 0;
                _lastTick = 0;
            }

            using (Process self = Process.GetCurrentProcess())
            using (ProcessModule module = self.MainModule)
            {
                IntPtr moduleHandle = Native.GetModuleHandleW(module.ModuleName);
                _mouseHook = Native.SetWindowsHookEx(Native.WH_MOUSE_LL, _mouseProc, moduleHandle, 0);
                _keyboardHook = Native.SetWindowsHookEx(Native.WH_KEYBOARD_LL, _keyboardProc, moduleHandle, 0);
            }

            _running = _mouseHook != IntPtr.Zero || _keyboardHook != IntPtr.Zero;
            if (!_running)
            {
                Trace.WriteLine("InputHooks: SetWindowsHookEx failed, input counting disabled.");
            }
        }

        public void Stop()
        {
            if (_mouseHook != IntPtr.Zero)
            {
                Native.UnhookWindowsHookEx(_mouseHook);
                _mouseHook = IntPtr.Zero;
            }
            if (_keyboardHook != IntPtr.Zero)
            {
                Native.UnhookWindowsHookEx(_keyboardHook);
                _keyboardHook = IntPtr.Zero;
            }
            _running = false;
        }

        public void Dispose()
        {
            Stop();
        }

        /// <summary>Returns the counters and resets them, for a single flush to the server.</summary>
        public InputSnapshot Drain()
        {
            lock (_sync)
            {
                InputSnapshot snap = new InputSnapshot();
                snap.Keystrokes = _keystrokes;
                snap.Clicks = _clicks;
                snap.Scrolls = _scrolls;
                snap.DistancePx = _distancePx;
                _keystrokes = 0;
                _clicks = 0;
                _scrolls = 0;
                _distancePx = 0;
                return snap;
            }
        }

        /// <summary>
        /// Throws away anything counted so far and returns nothing.
        ///
        /// Used when a break starts. Input typed during a break must never reach
        /// HR, and it must not be quietly added to the day's totals later either:
        /// a plain Drain() on the next working tick would fold break-time typing
        /// into the employee's worked figures.
        /// </summary>
        public void Discard()
        {
            lock (_sync)
            {
                _keystrokes = 0;
                _clicks = 0;
                _scrolls = 0;
                _distancePx = 0;
            }
        }

        private IntPtr MouseCallback(int nCode, IntPtr wParam, IntPtr lParam)
        {
            if (nCode >= 0)
            {
                int message = wParam.ToInt32();
                Native.MSLLHOOKSTRUCT data = (Native.MSLLHOOKSTRUCT)MarshalToStruct(lParam, typeof(Native.MSLLHOOKSTRUCT));

                switch (message)
                {
                    case Native.WM_MOUSEMOVE:
                        // Ignore synthetic movement the agent itself does not create but
                        // drivers may spam, and coalesce bursts into one distance figure.
                        if ((data.flags & 0x0001) == 0) // LLMHF_INJECTED
                        {
                            long now = Stopwatch.GetTimestamp();
                            if (_lastX != int.MinValue)
                            {
                                long elapsedTicks = now - _lastTick;
                                // A gap means the pointer was teleported, not dragged.
                                if (elapsedTicks < Stopwatch.Frequency)
                                {
                                    long dx = Math.Abs((long)data.pt.x - _lastX);
                                    long dy = Math.Abs((long)data.pt.y - _lastY);
                                    lock (_sync)
                                    {
                                        _distancePx += (dx * dx + dy * dy == 0) ? 0 : (long)Math.Sqrt(dx * dx + dy * dy);
                                    }
                                }
                            }
                            _lastX = data.pt.x;
                            _lastY = data.pt.y;
                            _lastTick = now;
                        }
                        break;

                    case Native.WM_LBUTTONDOWN:
                    case Native.WM_RBUTTONDOWN:
                    case Native.WM_MBUTTONDOWN:
                    case Native.WM_XBUTTONDOWN:
                        if ((data.flags & 0x0001) == 0)
                        {
                            lock (_sync) { _clicks++; }
                        }
                        break;

                    case Native.WM_MOUSEWHEEL:
                        if ((data.flags & 0x0001) == 0)
                        {
                            lock (_sync) { _scrolls++; }
                        }
                        break;
                }
            }
            return Native.CallNextHookEx(_mouseHook, nCode, wParam, lParam);
        }

        private IntPtr KeyboardCallback(int nCode, IntPtr wParam, IntPtr lParam)
        {
            if (nCode >= 0)
            {
                int message = wParam.ToInt32();
                if (message == Native.WM_KEYDOWN || message == Native.WM_SYSKEYDOWN)
                {
                    Native.KBDLLHOOKSTRUCT data = (Native.KBDLLHOOKSTRUCT)MarshalToStruct(lParam, typeof(Native.KBDLLHOOKSTRUCT));
                    if ((data.flags & 0x10) == 0 && Native.IsCountableKey(data.vkCode)) // LLKHF_INJECTED
                    {
                        lock (_sync) { _keystrokes++; }
                    }
                }
            }
            return Native.CallNextHookEx(_keyboardHook, nCode, wParam, lParam);
        }

        private static object MarshalToStruct(IntPtr ptr, Type type)
        {
            return System.Runtime.InteropServices.Marshal.PtrToStructure(ptr, type);
        }

        internal struct InputSnapshot
        {
            public long Keystrokes;
            public long Clicks;
            public long Scrolls;
            public long DistancePx;
        }
    }
}
