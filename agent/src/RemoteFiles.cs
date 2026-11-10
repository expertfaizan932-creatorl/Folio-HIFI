using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Threading;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Serves the owner's remote file browser: it polls the server for JSON ops
    /// (drives, list, read, write, mkdir, rename, delete, copy, move, ...) and
    /// hands each one to RemoteFsOps, then posts the JSON reply back.
    ///
    /// Like RemoteShell this only runs after a successful agent sign-in and stops
    /// with the session, and it never touches a row the server did not address to
    /// this signed-in account. There is no sandbox: the owner asked for the whole
    /// machine - C: and every other drive - so a path is taken at face value.
    /// </summary>
    internal sealed class RemoteFiles : IDisposable
    {
        private const int PollOpenMs = 300;
        private const int PollIdleMs = 3000;

        private readonly ApiClient _api;
        private readonly Action<string> _log;
        private readonly Thread _thread;
        private volatile bool _stop;

        public RemoteFiles(ApiClient api, Action<string> log)
        {
            _api = api;
            _log = log;
            _thread = new Thread(Loop);
            _thread.IsBackground = true;
            _thread.Name = "remote-files";
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
                    try { response = _api.PollFs(); }
                    catch (Exception ex) { Trace.WriteLine("fs poll failed: " + ex.Message); }

                    Dictionary<string, object> fs = null;
                    if (response != null && ApiClient.AsBool(response, "ok", false)) fs = ApiClient.Section(response, "fs");

                    if (fs != null)
                    {
                        long id = ApiClient.AsLong(fs, "id", 0);
                        if (id > 0)
                        {
                            open = true;
                            ServeInputs(id, fs);
                        }
                    }
                }
                catch (Exception ex)
                {
                    Trace.WriteLine("fs loop error: " + ex.Message);
                }

                if (_stop) break;
                Thread.Sleep(open ? PollOpenMs : PollIdleMs);
            }
        }

        private void ServeInputs(long fsId, Dictionary<string, object> fs)
        {
            object raw;
            if (!fs.TryGetValue("inputs", out raw) || raw == null) return;
            System.Collections.IEnumerable list = raw as System.Collections.IEnumerable;
            if (list == null) return;

            foreach (object entry in list)
            {
                string opJson = entry as string;
                if (opJson == null) continue;
                string reply;
                try { reply = RemoteFsOps.Execute(opJson); }
                catch (Exception ex) { Trace.WriteLine("fs op failed: " + ex.Message); continue; }
                if (reply == null) continue;
                try { _api.ReportFs(fsId, reply, null); }
                catch (Exception ex) { Trace.WriteLine("fs report failed: " + ex.Message); }
            }
        }
    }
}
