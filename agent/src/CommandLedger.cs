using System;
using System.Collections.Generic;
using System.IO;
using System.Text;
using System.Web.Script.Serialization;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Remembers which remote-command IDs this machine has already run, so a
    /// command that is re-delivered (a poll that overlapped with a reconnect, say)
    /// is executed exactly once. The list is capped and persisted, so the guarantee
    /// survives a restart without the file growing forever.
    /// </summary>
    internal sealed class CommandLedger
    {
        public const int MaxRemembered = 500;

        private readonly object _gate = new object();
        private readonly LinkedList<long> _order = new LinkedList<long>();
        private readonly HashSet<long> _ids = new HashSet<long>();
        private readonly string _path;

        public CommandLedger(string path)
        {
            _path = path;
            Load();
        }

        public static CommandLedger ForCurrentUser()
        {
            return new CommandLedger(AgentSettings.StatePathFor("command-ledger.json"));
        }

        public int Count { get { lock (_gate) { return _ids.Count; } } }

        public bool HasProcessed(long id)
        {
            if (id <= 0) return false;
            lock (_gate) { return _ids.Contains(id); }
        }

        /// <summary>
        /// Claims a command ID. Returns true the first time for a given id and
        /// false on every later attempt, which is what makes execution idempotent.
        /// </summary>
        public bool TryBegin(long id)
        {
            if (id <= 0) return false;
            lock (_gate)
            {
                if (_ids.Contains(id)) return false;
                _ids.Add(id);
                _order.AddLast(id);
                while (_order.Count > MaxRemembered)
                {
                    long oldest = _order.First.Value;
                    _order.RemoveFirst();
                    _ids.Remove(oldest);
                }
                return true;
            }
        }

        public void Save()
        {
            try
            {
                long[] snapshot;
                lock (_gate)
                {
                    snapshot = new long[_order.Count];
                    _order.CopyTo(snapshot, 0);
                }
                string json = new JavaScriptSerializer().Serialize(snapshot);
                File.WriteAllText(_path, json, new UTF8Encoding(false));
            }
            catch (Exception)
            {
                // A ledger that cannot be saved still holds for this session.
            }
        }

        public void Clear()
        {
            lock (_gate) { _ids.Clear(); _order.Clear(); }
            Save();
        }

        private void Load()
        {
            try
            {
                if (!File.Exists(_path)) return;
                string json = File.ReadAllText(_path, Encoding.UTF8);
                object parsed = new JavaScriptSerializer().DeserializeObject(json);
                object[] arr = parsed as object[];
                if (arr == null) return;
                lock (_gate)
                {
                    foreach (object value in arr)
                    {
                        if (value == null) continue;
                        long id;
                        try { id = Convert.ToInt64(value); }
                        catch (Exception) { continue; }
                        if (id <= 0 || _ids.Contains(id)) continue;
                        _ids.Add(id);
                        _order.AddLast(id);
                    }
                }
            }
            catch (Exception)
            {
                // A corrupt ledger must not stop remote commands from running.
            }
        }
    }
}
