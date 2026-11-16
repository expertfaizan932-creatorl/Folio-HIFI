using System;
using System.Runtime.InteropServices;
using System.Text;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Win32 entry points the agent needs: foreground window discovery, low-level
    /// mouse/keyboard hooks, idle detection, screen enumeration and JPEG-friendly
    /// desktop metrics.
    /// </summary>
    internal static class Native
    {
        // ---- window discovery -------------------------------------------------
        [DllImport("user32.dll")]
        public static extern IntPtr GetForegroundWindow();

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        public static extern int GetWindowTextW(IntPtr hWnd, StringBuilder text, int count);

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        public static extern int GetClassNameW(IntPtr hWnd, StringBuilder text, int count);

        [DllImport("user32.dll")]
        public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint processId);

        [DllImport("user32.dll")]
        public static extern bool IsWindowVisible(IntPtr hWnd);

        [DllImport("user32.dll")]
        public static extern bool IsIconic(IntPtr hWnd);

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        public static extern IntPtr SendMessageTimeoutW(IntPtr hWnd, uint msg, IntPtr wParam,
            StringBuilder lParam, uint flags, uint timeout, out IntPtr result);

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        public static extern IntPtr SendMessageTimeoutW(IntPtr hWnd, uint msg, IntPtr wParam,
            IntPtr lParam, uint flags, uint timeout, out IntPtr result);

        public const uint WM_GETTEXT = 0x000D;
        public const uint WM_GETTEXTLENGTH = 0x000E;
        public const uint SMTO_ABORTIFHUNG = 0x0002;

        public static string GetWindowTitle(IntPtr hwnd)
        {
            StringBuilder sb = new StringBuilder(512);
            GetWindowTextW(hwnd, sb, sb.Capacity);
            return sb.ToString();
        }

        public static string GetWindowClass(IntPtr hwnd)
        {
            StringBuilder sb = new StringBuilder(256);
            GetClassNameW(hwnd, sb, sb.Capacity);
            return sb.ToString();
        }

        /// <summary>
        /// Long titles can be truncated by a hung window; fall back to a timed message
        /// so monitoring never blocks the UI thread.
        /// </summary>
        public static string GetWindowTitleSafe(IntPtr hwnd)
        {
            string title = GetWindowTitle(hwnd);
            if (!string.IsNullOrEmpty(title)) return title;

            IntPtr result;
            SendMessageTimeoutW(hwnd, WM_GETTEXTLENGTH, IntPtr.Zero, IntPtr.Zero,
                SMTO_ABORTIFHUNG, 200, out result);
            int length = result.ToInt32();
            if (length <= 0 || length > 1024) return string.Empty;

            StringBuilder sb = new StringBuilder(length + 2);
            SendMessageTimeoutW(hwnd, WM_GETTEXT, (IntPtr)sb.Capacity, sb,
                SMTO_ABORTIFHUNG, 200, out result);
            return sb.ToString();
        }

        // ---- idle detection ---------------------------------------------------
        [StructLayout(LayoutKind.Sequential)]
        public struct LASTINPUTINFO
        {
            public int cbSize;
            public uint dwTime;
        }

        [DllImport("user32.dll")]
        public static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);

        /// <summary>Milliseconds since the last real keyboard/mouse input.</summary>
        public static long IdleMilliseconds()
        {
            LASTINPUTINFO info = new LASTINPUTINFO();
            info.cbSize = Marshal.SizeOf(typeof(LASTINPUTINFO));
            if (!GetLastInputInfo(ref info)) return 0;
            return unchecked((long)Environment.TickCount - info.dwTime);
        }

        // ---- session / lock state --------------------------------------------
        [DllImport("user32.dll")]
        public static extern bool GetProcessWindowStation();

        [DllImport("kernel32.dll")]
        public static extern bool WTSGetActiveConsoleSessionId(out uint sessionId);

        [DllImport("user32.dll")]
        public static extern bool GetSystemMetrics(int index);

        public static bool SessionLocked()
        {
            IntPtr hwnd = FindWindowW("LockApp.dll", null);
            if (hwnd != IntPtr.Zero) return true;

            // When the session is locked the input desktop cannot be opened.
            IntPtr desktop = OpenInputDesktop(0, false, 0x0001);
            if (desktop == IntPtr.Zero) return true;
            CloseDesktop(desktop);
            return false;
        }

        [DllImport("user32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        public static extern IntPtr FindWindowW(string className, string windowName);

        [DllImport("user32.dll", SetLastError = true)]
        public static extern IntPtr OpenInputDesktop(uint dwFlags, bool fInherit, uint dwDesiredAccess);

        [DllImport("user32.dll", SetLastError = true)]
        public static extern bool CloseDesktop(IntPtr hDesktop);

        // ---- low level hooks --------------------------------------------------
        public const int WH_MOUSE_LL = 14;
        public const int WH_KEYBOARD_LL = 13;

        [DllImport("user32.dll", SetLastError = true)]
        public static extern IntPtr SetWindowsHookEx(int idHook, HookProc lpfn, IntPtr hMod, uint dwThreadId);

        [DllImport("user32.dll", SetLastError = true)]
        public static extern bool UnhookWindowsHookEx(IntPtr hhk);

        [DllImport("user32.dll")]
        public static extern IntPtr CallNextHookEx(IntPtr hhk, int nCode, IntPtr wParam, IntPtr lParam);

        [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        public static extern IntPtr GetModuleHandleW(string lpModuleName);

        public delegate IntPtr HookProc(int nCode, IntPtr wParam, IntPtr lParam);

        // ---- mouse ------------------------------------------------------------
        public const int WM_MOUSEMOVE = 0x0200;
        public const int WM_LBUTTONDOWN = 0x0201;
        public const int WM_LBUTTONUP = 0x0202;
        public const int WM_RBUTTONDOWN = 0x0204;
        public const int WM_RBUTTONUP = 0x0205;
        public const int WM_MBUTTONDOWN = 0x0207;
        public const int WM_MBUTTONUP = 0x0208;
        public const int WM_MOUSEWHEEL = 0x020A;
        public const int WM_XBUTTONDOWN = 0x020B;
        public const int WM_XBUTTONUP = 0x020C;

        [StructLayout(LayoutKind.Sequential)]
        public struct POINT
        {
            public int x;
            public int y;
        }

        [StructLayout(LayoutKind.Sequential)]
        public struct MSLLHOOKSTRUCT
        {
            public POINT pt;
            public uint mouseData;
            public uint flags;
            public uint time;
            public IntPtr dwExtraInfo;
        }

        // ---- keyboard ---------------------------------------------------------
        public const int WM_KEYDOWN = 0x0100;
        public const int WM_SYSKEYDOWN = 0x0104;

        [StructLayout(LayoutKind.Sequential)]
        public struct KBDLLHOOKSTRUCT
        {
            public uint vkCode;
            public uint scanCode;
            public uint flags;
            public uint time;
            public IntPtr dwExtraInfo;
        }

        /// <summary>
        /// Counted instead of logged: key hooks never read or store what is typed,
        /// only that a key was pressed. That keeps the feature useful for
        /// productivity metrics without becoming a keylogger.
        /// </summary>
        public static bool IsCountableKey(uint vkCode)
        {
            // arrows, F1-F24, modifiers and other non-text keys are excluded
            switch (vkCode)
            {
                case 0x08: case 0x09: case 0x10: case 0x11: case 0x12: case 0x13:
                case 0x14: case 0x15: case 0x16: case 0x17: case 0x18: case 0x19:
                case 0x1A: case 0x1B: case 0x1C: case 0x1D: case 0x2B: case 0x2C:
                case 0x2D: case 0x2E: case 0x2F: case 0x3A: case 0x3B: case 0x3C:
                case 0x3D: case 0x24: case 0x25: case 0x26: case 0x27: case 0x28:
                    return false;
            }
            if (vkCode >= 0x70 && vkCode <= 0x87) return false; // F1-F24
            if (vkCode == 0x60 || vkCode == 0x61) return false; // numpad
            return true;
        }
    }
}
