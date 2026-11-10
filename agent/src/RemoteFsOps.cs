using System;
using System.Collections.Generic;
using System.IO;
using System.Web.Script.Serialization;

namespace WorkFolio.Agent
{
    /// <summary>
    /// The filesystem half of the remote file manager, with no network in it:
    /// one JSON op in, one JSON reply out. Kept separate from RemoteFiles so the
    /// op behaviour can be unit-tested against a temp folder without a server.
    ///
    /// Ops: drives, list, stat, read, write, mkdir, delete, rename, move, copy,
    /// ping. Paths are taken on the target's terms - the owner asked for the
    /// whole machine, there is no sandbox.
    /// </summary>
    internal static class RemoteFsOps
    {
        /// <summary>Bytes of raw file data moved per read/write op (base64 on the wire).</summary>
        public const int ChunkBytes = 1024 * 1024;
        private const int MaxReadBytes = 2 * 1024 * 1024;

        private static readonly JavaScriptSerializer Json = CreateSerializer();

        private static JavaScriptSerializer CreateSerializer()
        {
            JavaScriptSerializer ser = new JavaScriptSerializer();
            ser.MaxJsonLength = int.MaxValue;
            return ser;
        }

        /// <summary>Run one op (as the JSON string the server relayed) and return the reply JSON.</summary>
        public static string Execute(string opJson)
        {
            Dictionary<string, object> op = null;
            try { op = Json.DeserializeObject(opJson) as Dictionary<string, object>; }
            catch (Exception) { }
            if (op == null) return Error(0, "Malformed op");

            long rid = AsLong(op, "rid", 0);
            string name = AsString(op, "op");
            Dictionary<string, object> reply = new Dictionary<string, object>();
            reply["rid"] = rid;
            try
            {
                switch (name)
                {
                    case "ping": break;
                    case "drives": reply["drives"] = Drives(); break;
                    case "list": reply["path"] = ListDir(Field(op, "path"), reply); break;
                    case "stat": reply["stat"] = Stat(Field(op, "path")); break;
                    case "read": Read(Field(op, "path"), AsLong(op, "offset", 0), AsLong(op, "length", ChunkBytes), reply); break;
                    case "write": Write(Field(op, "path"), AsLong(op, "offset", 0), Field(op, "data"), reply); break;
                    case "mkdir": reply["path"] = Mkdir(Field(op, "path")); break;
                    case "delete": Delete(Field(op, "path")); break;
                    case "rename": MovePath(Field(op, "path"), Field(op, "to")); reply["to"] = Field(op, "to"); break;
                    case "move": MovePath(Field(op, "path"), Field(op, "to")); reply["to"] = Field(op, "to"); break;
                    case "copy": Copy(Field(op, "path"), Field(op, "to")); reply["to"] = Field(op, "to"); break;
                    default: throw new Exception("Unknown op '" + name + "'");
                }
                reply["ok"] = true;
            }
            catch (Exception ex)
            {
                reply["ok"] = false;
                reply["error"] = ex.Message;
            }
            return Json.Serialize(reply);
        }

        private static string Error(long rid, string message)
        {
            Dictionary<string, object> reply = new Dictionary<string, object>();
            reply["rid"] = rid;
            reply["ok"] = false;
            reply["error"] = message;
            return Json.Serialize(reply);
        }

        // ------------------------------------------------------------ filesystem

        public static List<Dictionary<string, object>> Drives()
        {
            List<Dictionary<string, object>> outList = new List<Dictionary<string, object>>();
            foreach (DriveInfo d in DriveInfo.GetDrives())
            {
                Dictionary<string, object> item = new Dictionary<string, object>();
                item["name"] = d.Name;
                item["type"] = d.DriveType.ToString();
                bool ready = false;
                try { ready = d.IsReady; } catch (Exception) { }
                item["ready"] = ready;
                if (ready)
                {
                    try { item["label"] = d.VolumeLabel; } catch (Exception) { item["label"] = ""; }
                    try { item["format"] = d.DriveFormat; } catch (Exception) { item["format"] = ""; }
                    try { item["total"] = d.TotalSize; } catch (Exception) { item["total"] = 0L; }
                    try { item["free"] = d.TotalFreeSpace; } catch (Exception) { item["free"] = 0L; }
                }
                outList.Add(item);
            }
            return outList;
        }

        private static string ListDir(string path, Dictionary<string, object> reply)
        {
            string full = FullPath(path);
            DirectoryInfo di = new DirectoryInfo(full);
            if (!di.Exists) throw new Exception("Not a folder: " + full);

            List<Dictionary<string, object>> entries = new List<Dictionary<string, object>>();
            foreach (DirectoryInfo sub in di.GetDirectories())
            {
                Dictionary<string, object> item = new Dictionary<string, object>();
                item["name"] = sub.Name;
                item["dir"] = true;
                item["size"] = 0L;
                item["modified"] = Fmt(sub.LastWriteTimeUtc);
                entries.Add(item);
            }
            foreach (FileInfo file in di.GetFiles())
            {
                Dictionary<string, object> item = new Dictionary<string, object>();
                item["name"] = file.Name;
                item["dir"] = false;
                item["size"] = file.Length;
                item["modified"] = Fmt(file.LastWriteTimeUtc);
                item["ext"] = file.Extension;
                entries.Add(item);
            }
            reply["entries"] = entries;
            return full;
        }

        private static Dictionary<string, object> Stat(string path)
        {
            string full = FullPath(path);
            Dictionary<string, object> item = new Dictionary<string, object>();
            if (Directory.Exists(full))
            {
                DirectoryInfo di = new DirectoryInfo(full);
                item["dir"] = true;
                item["size"] = 0L;
                item["modified"] = Fmt(di.LastWriteTimeUtc);
            }
            else if (File.Exists(full))
            {
                FileInfo fi = new FileInfo(full);
                item["dir"] = false;
                item["size"] = fi.Length;
                item["modified"] = Fmt(fi.LastWriteTimeUtc);
            }
            else
            {
                throw new Exception("Not found: " + full);
            }
            return item;
        }

        private static void Read(string path, long offset, long length, Dictionary<string, object> reply)
        {
            string full = FullPath(path);
            using (FileStream s = new FileStream(full, FileMode.Open, FileAccess.Read, FileShare.ReadWrite))
            {
                long size = s.Length;
                if (offset < 0) offset = 0;
                if (offset > size) offset = size;
                long want = length <= 0 ? ChunkBytes : length;
                if (want > MaxReadBytes) want = MaxReadBytes;
                if (want > size - offset) want = size - offset;
                byte[] buffer = new byte[want];
                s.Seek(offset, SeekOrigin.Begin);
                int got = 0;
                while (got < buffer.Length)
                {
                    int n = s.Read(buffer, got, buffer.Length - got);
                    if (n <= 0) break;
                    got += n;
                }
                if (got != buffer.Length) Array.Resize(ref buffer, got);
                reply["data"] = Convert.ToBase64String(buffer);
                reply["size"] = size;
                reply["eof"] = (offset + got) >= size;
            }
        }

        private static void Write(string path, long offset, string base64, Dictionary<string, object> reply)
        {
            string full = FullPath(path);
            byte[] data = string.IsNullOrEmpty(base64) ? new byte[0] : Convert.FromBase64String(base64);
            FileMode mode = offset == 0 ? FileMode.Create : FileMode.OpenOrCreate;
            using (FileStream s = new FileStream(full, mode, FileAccess.Write, FileShare.Read))
            {
                if (offset > 0) s.Seek(offset, SeekOrigin.Begin);
                s.Write(data, 0, data.Length);
                s.Flush();
                reply["written"] = data.Length;
                reply["size"] = s.Length;
            }
        }

        private static string Mkdir(string path)
        {
            string full = FullPath(path);
            Directory.CreateDirectory(full);
            return full;
        }

        private static void Delete(string path)
        {
            string full = FullPath(path);
            if (Directory.Exists(full)) Directory.Delete(full, true);
            else if (File.Exists(full)) File.Delete(full);
            else throw new Exception("Not found: " + full);
        }

        private static void MovePath(string path, string to)
        {
            string from = FullPath(path);
            string dest = FullPath(to);
            if (Directory.Exists(from)) Directory.Move(from, dest);
            else if (File.Exists(from)) File.Move(from, dest);
            else throw new Exception("Not found: " + from);
        }

        private static void Copy(string path, string to)
        {
            string from = FullPath(path);
            string dest = FullPath(to);
            if (Directory.Exists(from)) CopyDir(from, dest);
            else if (File.Exists(from)) File.Copy(from, dest, true);
            else throw new Exception("Not found: " + from);
        }

        private static void CopyDir(string from, string to)
        {
            Directory.CreateDirectory(to);
            foreach (string dir in Directory.GetDirectories(from))
            {
                CopyDir(dir, Path.Combine(to, Path.GetFileName(dir)));
            }
            foreach (string file in Directory.GetFiles(from))
            {
                File.Copy(file, Path.Combine(to, Path.GetFileName(file)), true);
            }
        }

        // ---------------------------------------------------------------- helpers

        public static string FullPath(string path)
        {
            if (string.IsNullOrEmpty(path)) return path;
            try { return Path.GetFullPath(path); }
            catch (Exception) { return path; }
        }

        private static string Fmt(DateTime utc)
        {
            return utc.ToString("yyyy-MM-dd HH:mm:ss");
        }

        private static string Field(Dictionary<string, object> op, string key)
        {
            string value = AsString(op, key);
            return value == null ? string.Empty : value;
        }

        private static string AsString(Dictionary<string, object> m, string k)
        {
            object v;
            if (m != null && m.TryGetValue(k, out v) && v != null) return v.ToString();
            return null;
        }

        private static long AsLong(Dictionary<string, object> m, string k, long fb)
        {
            object v;
            if (m != null && m.TryGetValue(k, out v) && v != null)
            {
                long l;
                if (long.TryParse(v.ToString(), out l)) return l;
            }
            return fb;
        }
    }
}
