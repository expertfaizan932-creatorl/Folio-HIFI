using System;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Windows.Forms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Replaces the running agent with a downloaded build. A tiny batch script
    /// waits for this process to exit, copies the new EXE over the old one and
    /// relaunches it, so an employee never reinstalls anything by hand.
    /// </summary>
    internal static class Updater
    {
        public static bool Download(ApiClient api, string urlPath, string expectedSha256,
                                    out string destPath, out string error)
        {
            destPath = Path.Combine(Path.GetTempPath(), "WorkFolioAgent.update.exe");
            error = null;
            try
            {
                if (File.Exists(destPath)) File.Delete(destPath);
            }
            catch (Exception) { }

            if (!api.DownloadTo(urlPath, destPath))
            {
                error = "The download did not complete.";
                return false;
            }
            try
            {
                if (!File.Exists(destPath) || new FileInfo(destPath).Length < 1024)
                {
                    error = "The downloaded update looks empty.";
                    return false;
                }
            }
            catch (Exception ex)
            {
                error = ex.Message;
                return false;
            }

            // Integrity gate: a download that does not match the published hash is
            // deleted and never run. The signature hook runs after the hash, and
            // only bites once the build is code-signed.
            string detail;
            if (!UpdateVerifier.VerifySha256(destPath, expectedSha256, out detail))
            {
                error = detail;
                TryDelete(destPath);
                return false;
            }
            if (!UpdateVerifier.VerifySignature(destPath, out detail))
            {
                error = detail;
                TryDelete(destPath);
                return false;
            }
            return true;
        }

        private static void TryDelete(string path)
        {
            try { if (File.Exists(path)) File.Delete(path); }
            catch (Exception) { }
        }

        public static bool ApplyAndRestart(string newExePath, out string error)
        {
            error = null;
            try
            {
                string exe = Application.ExecutablePath;
                string bat = Path.Combine(Path.GetTempPath(), "workfolio-update.bat");

                StringBuilder sb = new StringBuilder();
                sb.Append("@echo off\r\n");
                sb.Append("timeout /t 2 /nobreak >nul\r\n");
                // Back up the current EXE once, so a copy that never succeeds can
                // be rolled back rather than leaving the machine with no agent.
                sb.Append("copy /y \"").Append(exe).Append("\" \"").Append(exe).Append(".bak\" >nul 2>&1\r\n");
                sb.Append("set /a tries=0\r\n");
                sb.Append(":retry\r\n");
                sb.Append("set /a tries+=1\r\n");
                sb.Append("taskkill /im WorkFolioAgent.exe /f >nul 2>&1\r\n");
                sb.Append("timeout /t 1 /nobreak >nul\r\n");
                sb.Append("copy /y \"").Append(newExePath).Append("\" \"").Append(exe).Append("\" >nul 2>&1\r\n");
                sb.Append("if not errorlevel 1 goto started\r\n");
                sb.Append("if %tries% geq 6 goto rollback\r\n");
                sb.Append("timeout /t 1 /nobreak >nul\r\n");
                sb.Append("goto retry\r\n");
                sb.Append(":rollback\r\n");
                sb.Append("copy /y \"").Append(exe).Append(".bak\" \"").Append(exe).Append("\" >nul 2>&1\r\n");
                sb.Append(":started\r\n");
                sb.Append("start \"\" \"").Append(exe).Append("\" --silent\r\n");
                sb.Append("del \"%~f0\"\r\n");
                File.WriteAllText(bat, sb.ToString());

                ProcessStartInfo psi = new ProcessStartInfo("cmd.exe", "/c \"" + bat + "\"");
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                Process.Start(psi);
                Application.Exit();
                return true;
            }
            catch (Exception ex)
            {
                error = ex.Message;
                return false;
            }
        }
    }
}
