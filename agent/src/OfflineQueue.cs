using System;
using System.Collections.Generic;
using System.IO;
using System.Text;
using System.Web.Script.Serialization;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Disk-backed retry buffer. Usage samples and screenshots taken while the server
    /// is unreachable are appended to a file and replayed on the next successful
    /// connection, so a flaky network never silently loses recorded work time.
    /// </summary>
    internal sealed class OfflineQueue
    {
        private const int MaxEntries = 500;

        private readonly string _path;
        private readonly object _sync = new object();
        private readonly JavaScriptSerializer _json = new JavaScriptSerializer();

        /// <summary>
        /// The signed-in employee. Queued work belongs to whoever recorded it, so
        /// on a shared machine the next person to sign in must not upload it -
        /// that would put one employee's window titles and totals under another
        /// employee's name.
        /// </summary>
        public string Owner { get; set; }

        public OfflineQueue(string folder)
        {
            try
            {
                Directory.CreateDirectory(folder);
                _path = Path.Combine(folder, "pending-queue.json");
            }
            catch (Exception)
            {
                _path = null;
            }
        }

        public int Count
        {
            get
            {
                lock (_sync)
                {
                    return Read().Count;
                }
            }
        }

        public void Enqueue(string kind, Dictionary<string, object> payload)
        {
            if (_path == null || payload == null) return;
            try
            {
                lock (_sync)
                {
                    List<Dictionary<string, object>> entries = Read();
                    Dictionary<string, object> entry = new Dictionary<string, object>();
                    entry["kind"] = kind;
                    entry["payload"] = payload;
                    entry["owner"] = Owner ?? string.Empty;
                    entry["at"] = DateTime.UtcNow.ToString("o");
                    entries.Add(entry);
                    while (entries.Count > MaxEntries) entries.RemoveAt(0);
                    Write(entries);
                }
            }
            catch (Exception)
            {
                // Queue is a safety net; never let it break collection.
            }
        }

        /// <summary>Replays queued entries. Returns true when the queue drained fully.</summary>
        public bool Replay(Func<string, Dictionary<string, object>, bool> send)
        {
            if (_path == null || send == null) return true;

            List<Dictionary<string, object>> entries;
            lock (_sync)
            {
                entries = Read();
            }
            if (entries.Count == 0) return true;

            List<Dictionary<string, object>> remaining = new List<Dictionary<string, object>>();
            string owner = (Owner ?? string.Empty).Trim().ToLowerInvariant();
            foreach (Dictionary<string, object> entry in entries)
            {
                string kind = AsString(entry, "kind");
                Dictionary<string, object> payload = AsMap(entry, "payload");
                if (payload == null) continue;

                // Recorded by somebody else, or by a build that did not stamp an
                // owner: drop it rather than upload it under the wrong name.
                string entryOwner = (AsString(entry, "owner") ?? string.Empty).Trim().ToLowerInvariant();
                if (entryOwner.Length == 0 || !entryOwner.Equals(owner, StringComparison.OrdinalIgnoreCase)) continue;

                if (!send(kind, payload)) remaining.Add(entry);
            }

            lock (_sync)
            {
                Write(remaining);
            }
            return remaining.Count == 0;
        }

        public void Clear()
        {
            lock (_sync)
            {
                try { if (_path != null && File.Exists(_path)) File.Delete(_path); }
                catch (Exception) { }
            }
        }

        private List<Dictionary<string, object>> Read()
        {
            try
            {
                if (_path == null || !File.Exists(_path)) return new List<Dictionary<string, object>>();
                object parsed = _json.DeserializeObject(File.ReadAllText(_path, Encoding.UTF8));
                object[] array = parsed as object[];
                if (array == null) return new List<Dictionary<string, object>>();

                List<Dictionary<string, object>> list = new List<Dictionary<string, object>>();
                foreach (object item in array)
                {
                    Dictionary<string, object> map = item as Dictionary<string, object>;
                    if (map != null) list.Add(map);
                }
                return list;
            }
            catch (Exception)
            {
                return new List<Dictionary<string, object>>();
            }
        }

        private void Write(List<Dictionary<string, object>> entries)
        {
            try
            {
                if (_path == null) return;
                File.WriteAllText(_path, _json.Serialize(entries), Encoding.UTF8);
            }
            catch (Exception) { }
        }

        private static string AsString(Dictionary<string, object> map, string key)
        {
            object value = map.ContainsKey(key) ? map[key] : null;
            return value == null ? null : value.ToString();
        }

        private static Dictionary<string, object> AsMap(Dictionary<string, object> map, string key)
        {
            object value = map.ContainsKey(key) ? map[key] : null;
            return value as Dictionary<string, object>;
        }
    }
}
