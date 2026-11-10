using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Text;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Executes cmd lines queued by the owner's Remote Control console/manager.
    ///
    /// This is deliberately NOT a silent backdoor:
    ///   - the server only ever returns rows aimed at the signed-in account;
    ///   - the command is written to this very screen (the OnLog callback) both
    ///     before and after it runs;
    ///   - the full line, its exit code and its output are reported back and
    ///     stored in the audit trail with the admin's name on it.
    /// The loop starts after a successful agent sign-in and stops with the
    /// session, so a signed-out machine executes nothing.
    /// </summary>
    internal sealed class RemoteRunner : IDisposable
    {
        private const int PollWaitMs = 3000;
        private const int RunTimeoutMs = 90000;
        private const int MaxOutput = 250000;

        private readonly ApiClient _api;
        private readonly Action<string> _log;
        private readonly CommandLedger _ledger;
        private readonly System.Threading.Thread _thread;
        private volatile bool _stop;

        public RemoteRunner(ApiClient api, Action<string> log, CommandLedger ledger)
        {
            _api = api;
            _log = log;
            _ledger = ledger;
            _thread = new System.Threading.Thread(Loop);
            _thread.IsBackground = true;
            _thread.Name = "remote-runner";
            _thread.Start();
        }

        /// <summary>Stops the poll loop; the session is ending.</summary>
        public void Shutdown()
        {
            _stop = true;
        }

        public void Dispose()
        {
            Shutdown();
        }

        private void Loop()
        {
            while (!_stop)
            {
                bool executing = false;
                try
                {
                    Dictionary<string, object> response = null;
                    try { response = _api.PollRemoteCommand(); }
                    catch (Exception ex) { Trace.WriteLine("remote poll failed: " + ex.Message); }

                    Dictionary<string, object> command = null;
                    if (response != null && ApiClient.AsBool(response, "ok", false))
                    {
                        command = ApiClient.Section(response, "command");
                    }
                    if (command != null)
                    {
                        executing = true;
                        RunAndReport(command);
                    }
                }
                catch (Exception ex)
                {
                    Trace.WriteLine("remote runner error: " + ex.Message);
                }

                int wait = _stop ? 0 : (executing ? 1000 : PollWaitMs);
                if (wait == 0) break;
                System.Threading.Thread.Sleep(wait);
            }
        }

        private void RunAndReport(Dictionary<string, object> command)
        {
            long id = ApiClient.AsLong(command, "id", 0);
            string line = ApiClient.AsString(command, "command");
            if (id <= 0 || string.IsNullOrEmpty(line))
            {
                return;
            }

            // Idempotency: a re-delivered command (an overlapping poll, a reconnect
            // replay) must not run twice on the machine. The ledger claims the id
            // exactly once.
            if (_ledger != null && !_ledger.TryBegin(id))
            {
                _log("Manager command #" + id + " already handled; ignoring duplicate.");
                AuditLog.Append("Remote command #" + id + " ignored (duplicate).");
                return;
            }
            if (_ledger != null) _ledger.Save();

            _log("Manager command #" + id + ": " + line);
            AuditLog.Append("Remote command #" + id + " received: " + line);
            try
            {
                RunResult r = RunCmd(line);
                string output = r.Output ?? string.Empty;
                if (output.Length > MaxOutput) output = output.Substring(0, MaxOutput);
                try
                {
                    _api.ReportRemote(id, r.ExitCode, output);
                }
                catch (Exception ex)
                {
                    Trace.WriteLine("remote report failed: " + ex.Message);
                }
                _log("Manager command #" + id + " finished" + (r.TimedOut ? " (timed out after " + (RunTimeoutMs / 1000) + "s)" : "")
                    + "; exit " + r.ExitCode);
                AuditLog.Append("Remote command #" + id + " finished; exit " + r.ExitCode
                    + (r.TimedOut ? " (timed out)" : ""));
            }
            catch (Exception ex)
            {
                _log("Manager command #" + id + " could not run: " + ex.Message);
                AuditLog.Append("Remote command #" + id + " could not run: " + ex.Message);
                try { _api.ReportRemote(id, -1, "Could not run: " + ex.Message); }
                catch (Exception ex2) { Trace.WriteLine("remote report failed: " + ex2.Message); }
            }
        }

        /// <summary>Runs a line through cmd.exe without a console window, capturing stdout+stderr.</summary>
        private static RunResult RunCmd(string line)
        {
            ProcessStartInfo psi = new ProcessStartInfo();
            psi.FileName = Environment.GetEnvironmentVariable("COMSPEC") ?? "cmd.exe";
            psi.Arguments = "/c " + line;
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            psi.RedirectStandardOutput = true;
            psi.RedirectStandardError = true;
            psi.RedirectStandardInput = true;

            using (Process p = new Process())
            {
                StringBuilder sb = new StringBuilder();
                p.StartInfo = psi;
                p.OutputDataReceived += delegate (object s, DataReceivedEventArgs e)
                {
                    if (e.Data != null) lock (sb) sb.AppendLine(e.Data);
                };
                p.ErrorDataReceived += delegate (object s, DataReceivedEventArgs e)
                {
                    if (e.Data != null) lock (sb) sb.AppendLine(e.Data);
                };
                p.Start();
                try { p.StandardInput.Close(); } catch (Exception) { }
                p.BeginOutputReadLine();
                p.BeginErrorReadLine();

                bool timedOut = !p.WaitForExit(RunTimeoutMs);
                if (timedOut)
                {
                    try { KillTree(p); } catch (Exception) { }
                    p.WaitForExit();
                }

                string output;
                lock (sb) output = sb.ToString();
                return new RunResult
                {
                    ExitCode = timedOut ? -1 : p.ExitCode,
                    Output = output,
                    TimedOut = timedOut,
                };
            }
        }

        /// <summary>Kills the process and its children so a runaway batch cannot linger.</summary>
        private static void KillTree(Process p)
        {
            try { p.Kill(); } catch (Exception) { }
            // taskkill /T /F also removes the tree; /PID re-uses the same handle.
            try
            {
                ProcessStartInfo psi = new ProcessStartInfo
                {
                    FileName = "taskkill.exe",
                    Arguments = "/pid " + p.Id + " /T /F",
                    UseShellExecute = false,
                    CreateNoWindow = true,
                };
                using (Process k = Process.Start(psi)) { if (k != null) k.WaitForExit(5000); }
            }
            catch (Exception) { }
        }

        private sealed class RunResult
        {
            public int ExitCode;
            public string Output;
            public bool TimedOut;
        }
    }
}