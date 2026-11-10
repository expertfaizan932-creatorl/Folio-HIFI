using System;
using System.Collections.Generic;
using System.IO;
using System.Text;
using System.Web.Script.Serialization;
using WorkFolio.Agent;

/// <summary>
/// Checks the remote file manager's filesystem ops - the half the agent runs
/// locally - against a scratch folder, with no server involved. Covers browsing,
/// reading and writing chunks, and the rename/copy/move/delete mutations.
/// </summary>
internal static class RemoteFsOpsTest
{
    private static int _pass;
    private static int _fail;
    private static readonly JavaScriptSerializer Json = new JavaScriptSerializer { MaxJsonLength = int.MaxValue };
    private static string _root;

    private static void Check(string label, object got, object want)
    {
        bool ok = Eq(got, want);
        if (ok) _pass++; else _fail++;
        Console.WriteLine("  " + (ok ? "PASS" : "FAIL") + "  " + label);
        if (!ok) Console.WriteLine("         got:  " + got + Environment.NewLine + "         want: " + want);
    }

    /// <summary>Equal by value; JSON numbers come back as int/ long and must compare across those.</summary>
    private static bool Eq(object got, object want)
    {
        if (got == null || want == null) return got == want;
        bool gotNum = got is int || got is long || got is double || got is decimal;
        bool wantNum = want is int || want is long || want is double || want is decimal;
        if (gotNum && wantNum) return Convert.ToDecimal(got) == Convert.ToDecimal(want);
        return Equals(got, want);
    }

    private static void CheckTrue(string label, bool ok)
    {
        Check(label, ok, true);
    }

    private static Dictionary<string, object> Run(string opJson)
    {
        string reply = RemoteFsOps.Execute(opJson);
        return Json.DeserializeObject(reply) as Dictionary<string, object>;
    }

    private static Dictionary<string, object> Op(string op, params object[] pairs)
    {
        Dictionary<string, object> map = new Dictionary<string, object>();
        map["op"] = op;
        for (int i = 0; i + 1 < pairs.Length; i += 2) map[(string)pairs[i]] = pairs[i + 1];
        return map;
    }

    private static Dictionary<string, object> Call(Dictionary<string, object> op)
    {
        return Run(Json.Serialize(op));
    }

    private static string P(string name)
    {
        return Path.Combine(_root, name);
    }

    public static int Run()
    {
        _pass = 0;
        _fail = 0;
        Console.WriteLine();
        Console.WriteLine("--- remote file manager ops ---");

        _root = Path.Combine(Path.GetTempPath(), "wf-fsops-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_root);
        try
        {
            Console.WriteLine("== 1. ping and malformed ops ==");
            Dictionary<string, object> pingOp = Op("ping");
            pingOp["rid"] = 7L;
            Dictionary<string, object> ping = Call(pingOp);
            Check("ping answers ok", ping["ok"], true);
            Check("ping echoes the rid", Convert.ToInt64(ping["rid"]), 7L);
            Dictionary<string, object> malformed = Run("not json");
            Check("malformed op is refused", malformed["ok"], false);
            Dictionary<string, object> unknown = Call(Op("frobnicate"));
            Check("unknown op is refused", unknown["ok"], false);

            Console.WriteLine();
            Console.WriteLine("== 2. mkdir and list ==");
            Dictionary<string, object> mk = Call(Op("mkdir", "path", P("alpha")));
            Check("mkdir succeeds", mk["ok"], true);
            Check("mkdir returns the full path", mk["path"], RemoteFsOps.FullPath(P("alpha")));

            Directory.CreateDirectory(P("alpha\\sub"));
            File.WriteAllText(P("alpha\\one.txt"), "hello");
            File.WriteAllText(P("alpha\\two.bin"), new string('x', 10));

            Dictionary<string, object> list = Call(Op("list", "path", P("alpha")));
            Check("list succeeds", list["ok"], true);
            object[] entries = list["entries"] as object[];
            Check("list finds three entries", entries == null ? -1 : entries.Length, 3);
            bool sawDir = false, sawOne = false;
            foreach (object raw in entries)
            {
                Dictionary<string, object> e = raw as Dictionary<string, object>;
                string name = (string)e["name"];
                if (name == "sub") { sawDir = (bool)e["dir"]; }
                if (name == "one.txt") { sawOne = !(bool)e["dir"] && Convert.ToInt64(e["size"]) == 5; }
            }
            CheckTrue("sub is reported as a folder", sawDir);
            CheckTrue("one.txt is a 5-byte file", sawOne);

            Dictionary<string, object> missing = Call(Op("list", "path", P("nope")));
            Check("listing a missing folder fails", missing["ok"], false);

            Console.WriteLine();
            Console.WriteLine("== 3. write then read back a chunk ==");
            byte[] payload = Encoding.ASCII.GetBytes(new string('A', 1000));
            Dictionary<string, object> wr = Call(Op("write", "path", P("alpha\\big.dat"), "offset", 0L,
                "data", Convert.ToBase64String(payload)));
            Check("write succeeds", wr["ok"], true);
            Check("write reports the byte count", Convert.ToInt64(wr["written"]), 1000);
            Check("write reports the file size", Convert.ToInt64(wr["size"]), 1000);

            Dictionary<string, object> rd = Call(Op("read", "path", P("alpha\\big.dat"), "offset", 0L, "length", 1000L));
            Check("read succeeds", rd["ok"], true);
            Check("read reports the size", Convert.ToInt64(rd["size"]), 1000);
            Check("read flags eof", rd["eof"], true);
            string back = Encoding.ASCII.GetString(Convert.FromBase64String((string)rd["data"]));
            Check("read returns the same bytes", back, new string('A', 1000));

            Console.WriteLine();
            Console.WriteLine("== 4. a chunked write reassembles, and eof is exact ==");
            byte[] half = Encoding.ASCII.GetBytes(new string('B', 400));
            Call(Op("write", "path", P("chunked.bin"), "offset", 0L, "data", Convert.ToBase64String(half)));
            Call(Op("write", "path", P("chunked.bin"), "offset", 400L, "data", Convert.ToBase64String(half)));
            Dictionary<string, object> first = Call(Op("read", "path", P("chunked.bin"), "offset", 0L, "length", 400L));
            Check("first chunk is not eof", first["eof"], false);
            Dictionary<string, object> second = Call(Op("read", "path", P("chunked.bin"), "offset", 400L, "length", 400L));
            Check("second chunk is eof", second["eof"], true);
            Check("reassembled size is 800", Convert.ToInt64(second["size"]), 800);

            Console.WriteLine();
            Console.WriteLine("== 5. stat ==");
            Dictionary<string, object> stat = Call(Op("stat", "path", P("alpha")));
            Check("stat a folder", stat["stat"] is Dictionary<string, object>
                && (bool)((Dictionary<string, object>)stat["stat"])["dir"], true);
            Dictionary<string, object> statFile = Call(Op("stat", "path", P("chunked.bin")));
            Check("stat a file reports its size",
                Convert.ToInt64(((Dictionary<string, object>)statFile["stat"])["size"]), 800);

            Console.WriteLine();
            Console.WriteLine("== 6. rename, copy, move, delete ==");
            Dictionary<string, object> ren = Call(Op("rename", "path", P("alpha\\one.txt"), "to", P("alpha\\renamed.txt")));
            Check("rename succeeds", ren["ok"], true);
            Check("the old name is gone", File.Exists(P("alpha\\one.txt")), false);
            Check("the new name exists", File.Exists(P("alpha\\renamed.txt")), true);

            Dictionary<string, object> cp = Call(Op("copy", "path", P("alpha"), "to", P("copy-alpha")));
            Check("copy succeeds", cp["ok"], true);
            Check("the copy has the nested file", File.Exists(P("copy-alpha\\renamed.txt")), true);
            Check("the original still exists", File.Exists(P("alpha\\renamed.txt")), true);

            Dictionary<string, object> mv = Call(Op("move", "path", P("copy-alpha\\renamed.txt"), "to", P("moved.txt")));
            Check("move succeeds", mv["ok"], true);
            Check("the moved file is at its new home", File.Exists(P("moved.txt")), true);
            Check("the moved file left the old home", File.Exists(P("copy-alpha\\renamed.txt")), false);

            Dictionary<string, object> del = Call(Op("delete", "path", P("copy-alpha")));
            Check("delete a folder recursively succeeds", del["ok"], true);
            Check("the deleted folder is gone", Directory.Exists(P("copy-alpha")), false);

            Dictionary<string, object> delMissing = Call(Op("delete", "path", P("ghost.txt")));
            Check("deleting a missing path fails", delMissing["ok"], false);

            Console.WriteLine();
            Console.WriteLine("== 7. drives are enumerated ==");
            Dictionary<string, object> drives = Call(Op("drives"));
            Check("drives succeeds", drives["ok"], true);
            object[] driveList = drives["drives"] as object[];
            CheckTrue("at least one drive is listed", driveList != null && driveList.Length >= 1);
            bool cDrive = false;
            if (driveList != null)
            {
                foreach (object raw in driveList)
                {
                    Dictionary<string, object> d = raw as Dictionary<string, object>;
                    if (((string)d["name"]).ToUpperInvariant().StartsWith("C:")) cDrive = true;
                }
            }
            CheckTrue("the C: drive is among them", cDrive);
        }
        finally
        {
            try { Directory.Delete(_root, true); } catch (Exception) { }
        }

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILED") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }
}
