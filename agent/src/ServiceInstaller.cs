using System;
using System.Diagnostics;
using System.IO;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Installs and removes the Session-0 service with sc.exe. The agent copies
    /// itself into a machine-wide folder first, because a service must run from a
    /// location that does not depend on any one user being signed in.
    ///
    /// Needs administrator rights, which is exactly why it is a separate,
    /// deliberate command (--install-service) rather than anything automatic.
    /// </summary>
    internal static class ServiceInstaller
    {
        public const string ServiceName = "FolioAgentService";
        public const string DisplayName = "FZ FOLIO Agent Service";
        private const string Description =
            "Keeps the FZ FOLIO agent running and reachable for every signed-in user.";

        public static string InstallDir
        {
            get
            {
                return Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                    "FZ FOLIO Agent");
            }
        }

        public static string InstalledExePath
        {
            get { return Path.Combine(InstallDir, "WorkFolioAgent.exe"); }
        }

        public static bool Install(out string error)
        {
            error = null;
            try
            {
                Directory.CreateDirectory(InstallDir);
                string self = System.Windows.Forms.Application.ExecutablePath;
                string target = InstalledExePath;
                if (!string.Equals(self, target, StringComparison.OrdinalIgnoreCase))
                {
                    File.Copy(self, target, true);
                }

                // binPath carries the "--service" switch that routes the process
                // into RunAsService instead of the tray UI. sc.exe is picky about
                // the space after "binPath=" and the embedded quotes.
                string binPath = "\\\"" + target + "\\\" --service";
                string createArgs =
                    "create " + ServiceName +
                    " binPath= \"" + binPath + "\"" +
                    " start= auto" +
                    " DisplayName= \"" + DisplayName + "\"";

                bool created = RunSc(createArgs);
                if (!created)
                {
                    // Already installed; refresh the path and leave the rest.
                    RunSc("config " + ServiceName + " binPath= \"" + binPath + "\" start= auto DisplayName= \"" + DisplayName + "\"");
                }
                RunSc("description " + ServiceName + " \"" + Description + "\"");
                RunSc("failure " + ServiceName + " reset= 86400 actions= restart/5000/restart/10000/restart/30000");
                bool started = RunSc("start " + ServiceName);

                AuditLog.Append("Service install: created=" + created + " started=" + started + " path=" + target);
                error = started ? null : "The service was installed but did not start. Run this from an elevated prompt.";
                return true;
            }
            catch (Exception ex)
            {
                error = ex.Message;
                return false;
            }
        }

        public static bool Uninstall(out string error)
        {
            error = null;
            try
            {
                RunSc("stop " + ServiceName);
                bool deleted = RunSc("delete " + ServiceName);
                AuditLog.Append("Service uninstall: deleted=" + deleted);
                try
                {
                    if (File.Exists(InstalledExePath))
                    {
                        File.Delete(InstalledExePath);
                    }
                }
                catch (Exception)
                {
                    // The EXE may still be locked by a stopping service; leaving it
                    // behind is harmless and the next install overwrites it.
                }
                return deleted;
            }
            catch (Exception ex)
            {
                error = ex.Message;
                return false;
            }
        }

        private static bool RunSc(string arguments)
        {
            try
            {
                ProcessStartInfo psi = new ProcessStartInfo("sc.exe", arguments);
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                psi.RedirectStandardOutput = true;
                psi.RedirectStandardError = true;
                using (Process p = Process.Start(psi))
                {
                    if (p == null) return false;
                    p.StandardOutput.ReadToEnd();
                    p.StandardError.ReadToEnd();
                    p.WaitForExit(20000);
                    return p.HasExited && p.ExitCode == 0;
                }
            }
            catch (Exception ex)
            {
                AuditLog.Append("sc.exe failed (" + arguments + "): " + ex.Message);
                return false;
            }
        }
    }
}
