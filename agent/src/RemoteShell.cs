using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Text;
using System.Threading;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Drives the owner's interactive terminal: it keeps one cmd.exe alive for
    /// the whole session so `cd` and running programs persist between keystrokes,
    /// feeds it the lines the owner typed and ships the console's output back.
    ///
    /// Like the one-shot RemoteRunner this starts only after a successful agent
    /// sign-in and stops with the session, and it never runs a line the server
    /// did not address to this signed-in account.
    /// </summary>
    internal sealed class RemoteShell : IDisposable
    {
        private const int PollOpenMs = 700;
        private const int PollIdleMs = 3000;
        private const int MaxPendingChars = 400000;

        private readonly ApiClient _api;
        private readonly Action<string> _log;
        private readonly Thread _thread;
        private readonly List<Thread> _readers = new List<Thread>();
        private readonly object _sync = new object();
        private StringBuilder _pending = new StringBuilder();
        private Process _proc;
        private long _shellId;
        private volatile bool _stop;

        public RemoteShell(ApiClient api, Action<string> log)
        {
            _api = api;
            _log = log;
            _thread = new Thread(Loop);
            _thread.IsBackground = true;
            _thread.Name = "remote-shell";
            _thread.Start();
        }

        public void Shutdown() { _stop = true; }

        public void Dispose() { Shutdown(); }

        private void Loop()
        {
            while (!_stop)
            {
                bool open = false;
                try
                {
                    Dictionary<string, object> response = null;
                    try { response = _api.PollShell(); }
                    catch (Exception ex) { Trace.WriteLine("shell poll failed: " + ex.Message); }

                    Dictionary<string, object> shell = null;
                    if (response != null && ApiClient.AsBool(response, "ok", false)) shell = ApiClient.Section(response, "shell");

                    if (shell == null)
                    {
                        if (_proc != null) StopProcess();
                        _shellId = 0;
                    }
                    else
                    {
                        long id = ApiClient.AsLong(shell, "id", 0);
                        if (id > 0)
                        {
                            _shellId = id;
                            EnsureProcess();
                            FlushOutput(id);
                            DeliverInputs(shell);
                            open = true;
                        }
                    }
                }
                catch (Exception ex)
                {
                    Trace.WriteLine("shell loop error: " + ex.Message);
                }

                if (_stop) break;
                Thread.Sleep(open ? PollOpenMs : PollIdleMs);
            }
            StopProcess();
        }

        private void EnsureProcess()
        {
            if (_proc != null && !_proc.HasExited) return;
            StopProcess();

            ProcessStartInfo psi = new ProcessStartInfo();
            psi.FileName = Environment.GetEnvironmentVariable("ComSpec");
            if (string.IsNullOrEmpty(psi.FileName)) psi.FileName = "cmd.exe";
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            psi.RedirectStandardInput = true;
            psi.RedirectStandardOutput = true;
            psi.RedirectStandardError = true;

            Process p = new Process();
            p.StartInfo = psi;
            p.Start();
            _proc = p;

            Encoding dec = Encoding.Default;
            Thread tOut = new Thread(delegate () { Pump(p.StandardOutput.BaseStream, dec); });
            Thread tErr = new Thread(delegate () { Pump(p.StandardError.BaseStream, dec); });
            tOut.IsBackground = true;
            tErr.IsBackground = true;
            _readers.Add(tOut);
            _readers.Add(tErr);
            tOut.Start();
            tErr.Start();
            _log("Terminal session started.");
        }

        private void Pump(Stream stream, Encoding enc)
        {
            byte[] buffer = new byte[4096];
            try
            {
                while (!_stop)
                {
                    int n = stream.Read(buffer, 0, buffer.Length);
                    if (n <= 0) break;
                    string text = enc.GetString(buffer, 0, n);
                    lock (_sync)
                    {
                        _pending.Append(text);
                        if (_pending.Length > MaxPendingChars) _pending.Remove(0, _pending.Length - MaxPendingChars);
                    }
                }
            }
            catch (Exception) { }
        }

        private void DeliverInputs(Dictionary<string, object> shell)
        {
            object raw;
            if (!shell.TryGetValue("inputs", out raw) || raw == null) return;
            if (_proc == null || _proc.HasExited) return;

            System.Collections.IEnumerable list = raw as System.Collections.IEnumerable;
            if (list == null) return;
            foreach (object entry in list)
            {
                Dictionary<string, object> item = entry as Dictionary<string, object>;
                if (item == null) continue;
                string line = ApiClient.AsString(item, "command");
                WriteStdin(line == null ? string.Empty : line);
            }
        }

        private void WriteStdin(string line)
        {
            try
            {
                if (_proc == null || _proc.HasExited) return;
                _proc.StandardInput.Write(line + "\r\n");
                _proc.StandardInput.Flush();
            }
            catch (Exception ex)
            {
                Trace.WriteLine("shell stdin failed: " + ex.Message);
            }
        }

        private void FlushOutput(long shellId)
        {
            string data = null;
            lock (_sync)
            {
                if (_pending.Length > 0)
                {
                    data = _pending.ToString();
                    _pending.Length = 0;
                }
            }
            if (string.IsNullOrEmpty(data)) return;
            try
            {
                if (!_api.ReportShell(shellId, data))
                {
                    lock (_sync) { _pending.Insert(0, data); }
                }
            }
            catch (Exception ex)
            {
                Trace.WriteLine("shell output flush failed: " + ex.Message);
            }
        }

        private void StopProcess()
        {
            Process p = _proc;
            _proc = null;
            if (p != null)
            {
                try { if (!p.HasExited) p.Kill(); }
                catch (Exception) { }
                try { p.Dispose(); }
                catch (Exception) { }
            }
            foreach (Thread t in _readers)
            {
                try { t.Join(200); }
                catch (Exception) { }
            }
            _readers.Clear();
        }
    }
}
