using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Threading;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Asks the server what build the agent should be running and, when a newer
    /// one exists, asks the UI to prompt the employee. Checked shortly after
    /// sign-in and then every few hours; once a prompt has been raised this
    /// session it stops, so it cannot nag.
    /// </summary>
    internal sealed class UpdateChecker : IDisposable
    {
        private readonly ApiClient _api;
        private readonly Action<string, string, string> _onUpdate;
        private readonly Thread _thread;
        private volatile bool _stop;

        public UpdateChecker(ApiClient api, Action<string, string, string> onUpdate)
        {
            _api = api;
            _onUpdate = onUpdate;
            _thread = new Thread(Loop);
            _thread.IsBackground = true;
            _thread.Name = "update-checker";
            _thread.Start();
        }

        public void Shutdown() { _stop = true; }

        public void Dispose() { Shutdown(); }

        private void Loop()
        {
            int[] waits = new int[] { 8000, 6 * 3600 * 1000 };
            int index = 0;
            while (!_stop)
            {
                try
                {
                    System.Collections.Generic.Dictionary<string, object> res = _api.CheckUpdate();
                    if (res != null && ApiClient.AsBool(res, "ok", false) && ApiClient.AsBool(res, "available", false))
                    {
                        string version = ApiClient.AsString(res, "version");
                        string url = ApiClient.AsString(res, "url");
                        string sha256 = ApiClient.AsString(res, "sha256");
                        // Never install anything that is not strictly newer. An old
                        // or equal build is ignored, so a compromised or misconfigured
                        // release listing cannot force a downgrade.
                        if (!string.IsNullOrEmpty(version) && !string.IsNullOrEmpty(url)
                            && IsNewer(version, Program.Version))
                        {
                            _onUpdate(version, url, sha256);
                            return;
                        }
                    }
                }
                catch (Exception ex)
                {
                    Trace.WriteLine("update check failed: " + ex.Message);
                }

                int wait = waits[Math.Min(index, waits.Length - 1)];
                index++;
                for (int slept = 0; slept < wait && !_stop; slept += 250) Thread.Sleep(250);
            }
        }

        public static bool IsNewer(string candidate, string current)
        {
            return Compare(candidate, current) > 0;
        }

        private static int Compare(string a, string b)
        {
            string[] pa = (a ?? string.Empty).Split('.');
            string[] pb = (b ?? string.Empty).Split('.');
            int len = Math.Max(pa.Length, pb.Length);
            for (int i = 0; i < len; i++)
            {
                int va = Part(pa, i);
                int vb = Part(pb, i);
                if (va != vb) return va < vb ? -1 : 1;
            }
            return 0;
        }

        private static int Part(string[] parts, int index)
        {
            if (index >= parts.Length) return 0;
            int value;
            return int.TryParse(parts[index].Trim(), out value) ? value : 0;
        }
    }
}
