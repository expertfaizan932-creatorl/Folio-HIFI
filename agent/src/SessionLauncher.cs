using System;
using System.IO;
using System.Runtime.InteropServices;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Starts a process inside the interactive user session from a Session-0
    /// service. A service cannot simply Process.Start a windowed program: that
    /// child would be born in Session 0, where nobody can see it. Duplicating the
    /// signed-in user's token and using CreateProcessAsUser is what puts the agent
    /// on the user's own desktop with the user's own profile.
    ///
    /// Every failure is swallowed and reported through the audit log; the service
    /// must survive a machine with nobody signed in.
    /// </summary>
    internal static class SessionLauncher
    {
        private const uint MAXIMUM_ALLOWED = 0x02000000;
        private const int SecurityImpersonation = 2;
        private const int TokenPrimary = 1;
        private const uint CREATE_UNICODE_ENVIRONMENT = 0x00000400;

        [DllImport("kernel32.dll")]
        private static extern uint WTSGetActiveConsoleSessionId();

        [DllImport("wtsapi32.dll", SetLastError = true)]
        private static extern bool WTSQueryUserToken(uint sessionId, out IntPtr phToken);

        [DllImport("advapi32.dll", SetLastError = true)]
        private static extern bool DuplicateTokenEx(IntPtr hExistingToken, uint dwDesiredAccess,
            IntPtr lpTokenAttributes, int impersonationLevel, int tokenType, out IntPtr phNewToken);

        [DllImport("userenv.dll", SetLastError = true)]
        private static extern bool CreateEnvironmentBlock(out IntPtr lpEnvironment, IntPtr hToken, bool bInherit);

        [DllImport("userenv.dll", SetLastError = true)]
        private static extern bool DestroyEnvironmentBlock(IntPtr lpEnvironment);

        [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
        private static extern bool CreateProcessAsUser(IntPtr hToken, string lpApplicationName,
            string lpCommandLine, IntPtr lpProcessAttributes, IntPtr lpThreadAttributes,
            bool bInheritHandles, uint dwCreationFlags, IntPtr lpEnvironment, string lpCurrentDirectory,
            ref STARTUPINFO lpStartupInfo, out PROCESS_INFORMATION lpProcessInformation);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool CloseHandle(IntPtr hObject);

        [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
        private struct STARTUPINFO
        {
            public int cb;
            public string lpReserved;
            public string lpDesktop;
            public string lpTitle;
            public int dwX;
            public int dwY;
            public int dwXSize;
            public int dwYSize;
            public int dwXCountChars;
            public int dwYCountChars;
            public int dwFillAttribute;
            public int dwFlags;
            public short wShowWindow;
            public short cbReserved2;
            public IntPtr lpReserved2;
            public IntPtr hStdInput;
            public IntPtr hStdOutput;
            public IntPtr hStdError;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct PROCESS_INFORMATION
        {
            public IntPtr hProcess;
            public IntPtr hThread;
            public int dwProcessId;
            public int dwThreadId;
        }

        public static bool LaunchInActiveSession(string exePath, string arguments)
        {
            if (string.IsNullOrEmpty(exePath) || !File.Exists(exePath))
            {
                AuditLog.Append("Session launch skipped: agent not found at " + exePath);
                return false;
            }

            uint sessionId = WTSGetActiveConsoleSessionId();
            if (sessionId == 0xFFFFFFFF)
            {
                AuditLog.Append("Session launch skipped: no active console session.");
                return false;
            }

            IntPtr userToken = IntPtr.Zero;
            IntPtr primaryToken = IntPtr.Zero;
            IntPtr environment = IntPtr.Zero;
            try
            {
                if (!WTSQueryUserToken(sessionId, out userToken))
                {
                    AuditLog.Append("Session launch: WTSQueryUserToken failed for session " + sessionId +
                                    " (error " + Marshal.GetLastWin32Error() + ").");
                    return false;
                }
                if (!DuplicateTokenEx(userToken, MAXIMUM_ALLOWED, IntPtr.Zero, SecurityImpersonation,
                                      TokenPrimary, out primaryToken))
                {
                    AuditLog.Append("Session launch: DuplicateTokenEx failed (error " +
                                    Marshal.GetLastWin32Error() + ").");
                    return false;
                }

                CreateEnvironmentBlock(out environment, primaryToken, false);

                STARTUPINFO startup = new STARTUPINFO();
                startup.cb = Marshal.SizeOf(typeof(STARTUPINFO));
                startup.lpDesktop = "winsta0\\default";

                string commandLine = "\"" + exePath + "\"" +
                                     (string.IsNullOrEmpty(arguments) ? string.Empty : " " + arguments);
                PROCESS_INFORMATION process;
                bool ok = CreateProcessAsUser(primaryToken, exePath, commandLine, IntPtr.Zero, IntPtr.Zero,
                    false, CREATE_UNICODE_ENVIRONMENT, environment, Path.GetDirectoryName(exePath),
                    ref startup, out process);
                if (ok)
                {
                    CloseHandle(process.hProcess);
                    CloseHandle(process.hThread);
                    AuditLog.Append("Session launch: started " + exePath + " in session " + sessionId + ".");
                }
                else
                {
                    AuditLog.Append("Session launch: CreateProcessAsUser failed (error " +
                                    Marshal.GetLastWin32Error() + ").");
                }
                return ok;
            }
            catch (Exception ex)
            {
                AuditLog.Append("Session launch error: " + ex.Message);
                return false;
            }
            finally
            {
                if (environment != IntPtr.Zero) DestroyEnvironmentBlock(environment);
                if (primaryToken != IntPtr.Zero) CloseHandle(primaryToken);
                if (userToken != IntPtr.Zero) CloseHandle(userToken);
            }
        }
    }
}
