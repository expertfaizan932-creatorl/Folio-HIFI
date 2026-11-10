using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Text;
using System.Threading;
using System.Web.Script.Serialization;

namespace WorkFolioManager
{
    /// <summary>
    /// Remote Control console for the owner's machine.
    ///
    /// Talks to the SAME WorkFolio server the agents use, but as an owner
    /// session. It lists every PC running Folio, and can either fire a single
    /// cmd line at one of them or open a live terminal where you keep typing
    /// into that PC's cmd.exe exactly as if you were sitting at it.
    ///
    /// Only SUPER_ADMIN / ADMIN accounts may issue commands, only EMPLOYEE / HR
    /// systems may receive them, and every step is stored in the audit trail.
    /// The terminal transcript itself is never kept.
    ///
    /// Usage (run it with no arguments for the guided menu):
    ///   WorkFolioManager                       - menu: sign in, pick a PC, get a shell
    ///   WorkFolioManager login alice@company.com
    ///   WorkFolioManager systems
    ///   WorkFolioManager shell [email]         - live terminal on one PC
    ///   WorkFolioManager run <email> "ipconfig /all"
    ///   WorkFolioManager history [email]
    ///   WorkFolioManager logout
    ///
    /// The session (cookie + CSRF) is cached under %LOCALAPPDATA%\WorkFolioManager
    /// so later commands do not ask for a password again.
    /// </summary>
    internal static class Program
    {
        private const string DefaultServerUrl = "https://folio.biztrack.uk";
        private const string AppName = "WorkFolioManager";

        private static string _serverUrl = DefaultServerUrl;
        private static string _cookieHeader = "";
        private static string _csrf = "";
        private static readonly System.Net.CookieContainer _cookies = new System.Net.CookieContainer();
        private static string _name = "";
        private static string _role = "";

        private static readonly object _http = new object();
        private static readonly object _io = new object();
        private static long _activeShell = 0;
        private static volatile bool _shellOpen = false;

        private static string SessionFile
        {
            get
            {
                string dir = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), AppName);
                return Path.Combine(dir, "session.json");
            }
        }

        private static int Main(string[] args)
        {
            try
            {
                // The rest of the app talks TLS 1.2 to the server. Without this the
                // HttpWebRequest default (TLS 1.0) is refused by modern HTTPS hosts
                // and every call fails with "Could not create SSL/TLS secure channel",
                // which used to close the console the instant a password was entered.
                try { ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12; }
                catch (Exception) { }

                try { Console.OutputEncoding = Encoding.UTF8; }
                catch (Exception) { }

                Console.CancelKeyPress += delegate(object s, ConsoleCancelEventArgs e)
                {
                    e.Cancel = true;
                    _shellOpen = false;
                    if (_activeShell > 0) CloseShell(_activeShell);
                    Environment.Exit(0);
                };

                LoadSession();
                args = args ?? new string[0];

                string server = ArgValue(args, "--server");
                if (!string.IsNullOrEmpty(server)) _serverUrl = server;
                args = WithoutArg(args, "--server");

                if (args.Length == 0)
                {
                    int code = Interactive();
                    Pause();
                    return code;
                }

                switch (args[0].ToLowerInvariant())
                {
                    case "login": return Login(args);
                    case "systems": return Systems();
                    case "run": return Run(args);
                    case "history": return History(args);
                    case "shell":
                    case "cmd": return Shell(args);
                    case "logout":
                        File.Delete(SessionFile);
                        Console.WriteLine("Signed out.");
                        return 0;
                    default:
                        PrintUsage();
                        return 1;
                }
            }
            catch (Exception ex)
            {
                Console.Error.WriteLine("Error: " + ex.Message);
                try
                {
                    string dir = Path.GetDirectoryName(SessionFile);
                    if (!Directory.Exists(dir)) Directory.CreateDirectory(dir);
                    File.AppendAllText(Path.Combine(dir, "error.log"),
                        DateTime.Now.ToString("s") + " " + ex + Environment.NewLine);
                }
                catch (Exception) { }
                if (args == null || args.Length == 0) Pause();
                return 1;
            }
        }

        private static void PrintUsage()
        {
            Console.WriteLine("WorkFolio Remote Control console");
            Console.WriteLine("  (no arguments)                      - guided menu: sign in, pick a PC, open a live terminal");
            Console.WriteLine("  login <email>                       - sign in as an owner (prompts for password)");
            Console.WriteLine("  systems                             - list every PC running Folio");
            Console.WriteLine("  shell [email]                       - live terminal: keep typing into that PC's cmd.exe");
            Console.WriteLine("  run <email> \"<cmd line>\"            - run one cmd command and read the output");
            Console.WriteLine("  history [email]                     - recent commands (optionally for one PC)");
            Console.WriteLine("  logout                              - forget the saved session");
            Console.WriteLine("  --server <url>                      - use another server (default " + DefaultServerUrl + ")");
        }

        /* ------------------------------------------------------------ guided menu ---- */

        private static int Interactive()
        {
            Console.WriteLine(AppName + " - Remote Control");
            Console.WriteLine(new string('=', 52));

            if (string.IsNullOrEmpty(_csrf))
            {
                while (!PromptLogin())
                {
                    Console.Write("Press Enter to try again, or type 'q' to quit: ");
                    string again = Console.ReadLine();
                    if (again == null || again.Trim().Equals("q", StringComparison.OrdinalIgnoreCase)) return 1;
                }
            }
            else
            {
                Console.WriteLine("Signed in as " + _name + " (" + _role + ").");
            }

            while (true)
            {
                List<Dictionary<string, object>> systems = FetchSystems();
                if (systems == null)
                {
                    Console.WriteLine();
                    Console.Write("Cannot reach the server (or the session expired). Type 'l' to sign in again, Enter to retry, 'q' to quit: ");
                    string ans = Console.ReadLine();
                    if (ans == null) break;
                    ans = ans.Trim();
                    if (ans.Equals("q", StringComparison.OrdinalIgnoreCase)) break;
                    if (ans.Equals("l", StringComparison.OrdinalIgnoreCase))
                    {
                        _csrf = "";
                        _cookieHeader = "";
                        while (!PromptLogin())
                        {
                            Console.Write("Press Enter to try again, or type 'q' to quit: ");
                            string again = Console.ReadLine();
                            if (again == null || again.Trim().Equals("q", StringComparison.OrdinalIgnoreCase)) return 1;
                        }
                    }
                    continue;
                }

                Console.WriteLine();
                if (systems.Count == 0)
                {
                    Console.WriteLine("No system is connected right now.");
                }
                else
                {
                    Console.WriteLine("Connected systems:");
                    for (int i = 0; i < systems.Count; i++)
                    {
                        Dictionary<string, object> s = systems[i];
                        string name = (AsString(s, "first_name") + " " + AsString(s, "last_name")).Trim();
                        Console.WriteLine(string.Format("  [{0}] {1,-24} {2,-28} {3,-4} {4}",
                            i + 1, Short(name, 24), Short(AsString(s, "email"), 28),
                            Short(AsString(s, "role"), 4), AsString(s, "host_name")));
                    }
                }

                Console.WriteLine();
                Console.Write("Type a number to open a terminal, 'r' to refresh, 'q' to quit: ");
                string pick = Console.ReadLine();
                if (pick == null) break;
                pick = pick.Trim();
                if (pick.Length == 0 || pick.Equals("r", StringComparison.OrdinalIgnoreCase)) continue;
                if (pick.Equals("q", StringComparison.OrdinalIgnoreCase)) break;

                int index;
                if (!int.TryParse(pick, out index) || index < 1 || index > systems.Count)
                {
                    Console.WriteLine("Not a valid choice.");
                    continue;
                }
                RunShell(AsString(systems[index - 1], "email"));
            }
            return 0;
        }

        private static bool PromptLogin()
        {
            Console.Write("Email: ");
            string email = Console.ReadLine();
            if (email == null) return false;
            email = email.Trim();
            if (email.Length == 0) { Console.Error.WriteLine("No email given."); return false; }

            Console.Write("Password: ");
            string password = ReadPassword();
            if (password.Length == 0) { Console.Error.WriteLine("Empty password."); return false; }

            Dictionary<string, object> response = Post("/api/manager/login",
                new Dictionary<string, object> { { "username", email }, { "password", password } }, false);
            if (!IsOk(response)) return false;
            _name = AsString(response, "name");
            _role = AsString(response, "role");
            _csrf = AsString(response, "csrf");
            SaveSession();
            Console.WriteLine("Signed in as " + _name + " (" + _role + ").");
            return true;
        }

        /* ------------------------------------------------------------------ shell ---- */

        private static string PickSystem()
        {
            while (true)
            {
                List<Dictionary<string, object>> systems = FetchSystems();
                if (systems == null) return "";
                if (systems.Count == 0) { Console.WriteLine("No system is connected right now."); return ""; }
                Console.WriteLine();
                for (int i = 0; i < systems.Count; i++)
                {
                    Dictionary<string, object> s = systems[i];
                    string name = (AsString(s, "first_name") + " " + AsString(s, "last_name")).Trim();
                    Console.WriteLine(string.Format("  [{0}] {1,-24} {2,-28} {3}",
                        i + 1, Short(name, 24), Short(AsString(s, "email"), 28), AsString(s, "host_name")));
                }
                Console.Write("Pick a PC number (blank to cancel): ");
                string pick = Console.ReadLine();
                if (pick == null) return "";
                pick = pick.Trim();
                if (pick.Length == 0) return "";
                int index;
                if (int.TryParse(pick, out index) && index >= 1 && index <= systems.Count)
                    return AsString(systems[index - 1], "email");
                Console.WriteLine("Not a valid choice.");
            }
        }

        /// <summary>
        /// Open a live terminal on one PC and pump it: a background thread polls
        /// for whatever that cmd.exe printed, while this thread reads the lines
        /// you type and sends them. Nothing is retained once it closes.
        ///
        /// If the server build does not have the live-terminal routes yet, we
        /// degrade to a one-shot shell: every line you type is queued through
        /// /api/manager/command and its output is shown when the PC finishes.
        /// That keeps the console useful on servers that only have the older
        /// remote-command endpoints.
        /// </summary>
        private static bool RunShell(string target)
        {
            if (string.IsNullOrEmpty(target)) return false;

            Dictionary<string, object> open = Post("/api/manager/shell/open",
                new Dictionary<string, object> { { "target", target } }, true);

            if (open == null || !AsBool(open, "ok", false))
            {
                string err = open == null ? "" : AsString(open, "error");
                if (IsMissingRoute(err))
                {
                    Console.WriteLine();
                    Console.WriteLine("This server does not offer the live interactive terminal yet.");
                    Console.WriteLine("Falling back to one-shot mode on " + target + ":");
                    Console.WriteLine("each line runs on that PC in a fresh cmd.exe and its output is shown below.");
                    return FallbackShell(target);
                }
                IsOk(open);
                return false;
            }

            long shell = AsLong(open, "shell", 0);
            if (shell <= 0) { Console.Error.WriteLine("The server did not return a session."); return false; }

            _activeShell = shell;
            _shellOpen = true;

            Console.WriteLine();
            Console.WriteLine("Connected to " + target + ". Type commands, 'exit' to close.");
            Console.WriteLine(new string('-', 60));

            Thread poller = new Thread(delegate() { Poll(shell); });
            poller.IsBackground = true;
            poller.Start();

            try
            {
                while (_shellOpen)
                {
                    lock (_io) { Console.Write("remote> "); }
                    string line = Console.ReadLine();
                    if (line == null) break;
                    string trimmed = line.Trim();
                    if (trimmed.Equals("exit", StringComparison.OrdinalIgnoreCase)
                        || trimmed.Equals("quit", StringComparison.OrdinalIgnoreCase)) break;

                    Dictionary<string, object> sent = Post("/api/manager/shell/write",
                        new Dictionary<string, object> { { "shell", shell }, { "line", line } }, true);
                    if (sent != null && !AsBool(sent, "ok", false))
                    {
                        lock (_io) Console.Error.WriteLine("! " + AsString(sent, "error"));
                    }
                }
            }
            finally
            {
                _shellOpen = false;
                CloseShell(shell);
                lock (_io)
                {
                    Console.WriteLine();
                    Console.WriteLine("Session with " + target + " closed.");
                }
            }
            return true;
        }

        /// <summary>True when the server simply does not have the route (older build).</summary>
        private static bool IsMissingRoute(string error)
        {
            if (string.IsNullOrEmpty(error)) return false;
            return error.IndexOf("404", StringComparison.OrdinalIgnoreCase) >= 0
                || error.IndexOf("Not found", StringComparison.OrdinalIgnoreCase) >= 0
                || error.IndexOf("Unexpected reply", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        /// <summary>
        /// One-shot shell used when the live terminal routes are absent. Each
        /// typed line is queued with /api/manager/command and its result read
        /// back from /api/manager/history. State is not kept between lines
        /// because every line starts a fresh cmd.exe on the target PC.
        /// </summary>
        private static bool FallbackShell(string target)
        {
            Console.WriteLine();
            Console.WriteLine("Connected to " + target + " (one-shot mode). Type commands, 'exit' to close.");
            Console.WriteLine(new string('-', 60));

            while (true)
            {
                Console.Write("remote> ");
                string line = Console.ReadLine();
                if (line == null) break;
                string trimmed = line.Trim();
                if (trimmed.Length == 0) continue;
                if (trimmed.Equals("exit", StringComparison.OrdinalIgnoreCase)
                    || trimmed.Equals("quit", StringComparison.OrdinalIgnoreCase)) break;

                Dictionary<string, object> queued = Post("/api/manager/command",
                    new Dictionary<string, object> { { "target", target }, { "command", trimmed } }, true);
                if (!IsOk(queued)) continue;

                if (AsBool(queued, "direct", false))
                {
                    string direct = AsString(queued, "output");
                    Console.WriteLine(direct.Length > 0 ? direct.TrimEnd() : "(no output)");
                    continue;
                }
                PollCommand(target, AsLong(queued, "id", 0));
            }

            Console.WriteLine();
            Console.WriteLine("Session with " + target + " closed.");
            return true;
        }

        private static void Poll(long shell)
        {
            long cursor = 0;
            while (_shellOpen && _activeShell == shell)
            {
                Dictionary<string, object> response = null;
                try
                {
                    response = Post("/api/manager/shell/read",
                        new Dictionary<string, object> { { "shell", shell }, { "cursor", cursor } }, true);
                }
                catch (Exception) { }

                if (response != null && AsBool(response, "ok", false))
                {
                    string data = AsString(response, "data");
                    long c = AsLong(response, "cursor", cursor);
                    if (c > cursor) cursor = c;
                    if (!string.IsNullOrEmpty(data))
                    {
                        lock (_io) { Console.Out.Write(data); Console.Out.Flush(); }
                    }
                    string status = AsString(response, "status");
                    if (status.Length > 0 && status != "OPEN")
                    {
                        lock (_io) Console.Out.Write("\r\n[session ended by the other side]");
                        _shellOpen = false;
                        break;
                    }
                }
                Thread.Sleep(600);
            }
        }

        private static void CloseShell(long shell)
        {
            _activeShell = 0;
            try
            {
                Post("/api/manager/shell/close",
                    new Dictionary<string, object> { { "shell", shell } }, true);
            }
            catch (Exception) { }
        }

        /* --------------------------------------------------------------- one-shot ---- */

        private static int Login(string[] args)
        {
            if (args.Length < 2) { Console.Error.WriteLine("usage: login <email>"); return 1; }
            string email = args[1].Trim();
            Console.Write("Password: ");
            string password = ReadPassword();
            if (password.Length == 0) { Console.Error.WriteLine("Empty password."); return 1; }

            Dictionary<string, object> response = Post("/api/manager/login",
                new Dictionary<string, object> { { "username", email }, { "password", password } }, false);
            if (!IsOk(response)) return 1;
            _name = AsString(response, "name");
            _role = AsString(response, "role");
            _csrf = AsString(response, "csrf");
            SaveSession();
            Console.WriteLine("Signed in as " + _name + " (" + _role + ").");
            return 0;
        }

        private static List<Dictionary<string, object>> FetchSystems()
        {
            Dictionary<string, object> response = Post("/api/manager/systems", new Dictionary<string, object>(), true);
            if (!IsOk(response)) return null;
            List<Dictionary<string, object>> list = new List<Dictionary<string, object>>();
            object raw;
            if (response.TryGetValue("systems", out raw) && raw is Array)
            {
                foreach (object item in (object[])raw)
                {
                    Dictionary<string, object> row = item as Dictionary<string, object>;
                    if (row != null) list.Add(row);
                }
            }
            return list;
        }

        private static int Systems()
        {
            List<Dictionary<string, object>> list = FetchSystems();
            if (list == null) return 1;
            if (list.Count == 0) { Console.WriteLine("No system is connected right now."); return 0; }
            Console.WriteLine();
            Console.WriteLine(string.Format("{0,-28} {1,-8} {2,-10} {3,-24} {4,-20} {5}",
                "SYSTEM", "ROLE", "SHIFT", "CURRENT APP", "HOST", "LAST SEEN"));
            Console.WriteLine(new string('-', 110));
            foreach (Dictionary<string, object> s in list)
            {
                string name = (AsString(s, "first_name") + " " + AsString(s, "last_name")).Trim();
                Console.WriteLine(string.Format("{0,-28} {1,-8} {2,-10} {3,-24} {4,-20} {5}",
                    Short(name, 28), Short(AsString(s, "role"), 8), Short(AsString(s, "shift_status"), 10),
                    Short(AsString(s, "current_app"), 24), Short(AsString(s, "host_name"), 20),
                    Short(AsString(s, "last_seen"), 19)));
            }
            Console.WriteLine();
            return 0;
        }

        private static int Shell(string[] args)
        {
            if (string.IsNullOrEmpty(_csrf))
            {
                Console.Error.WriteLine("Not signed in. Run: " + AppName + " login <email>");
                return 1;
            }
            string target = args.Length > 1 ? args[1].Trim() : "";
            if (target.Length == 0)
            {
                target = PickSystem();
                if (target.Length == 0) return 1;
            }
            return RunShell(target) ? 0 : 1;
        }

        private static int Run(string[] args)
        {
            if (args.Length < 3) { Console.Error.WriteLine("usage: run <email> \"<cmd line>\""); return 1; }
            string target = args[1].Trim();
            string command = string.Join(" ", args.Skip(2)).Trim();
            if (command.Length == 0) { Console.Error.WriteLine("Empty command."); return 1; }

            Dictionary<string, object> response = Post("/api/manager/command",
                new Dictionary<string, object> { { "target", target }, { "command", command } }, true);
            if (!IsOk(response)) return 1;

            if (AsBool(response, "direct", false))
            {
                string direct = AsString(response, "output");
                Console.WriteLine(direct.Length > 0 ? direct : "(no output)");
                return 0;
            }

            long id = AsLong(response, "id", 0);
            Console.WriteLine("Command #" + id + " queued for " + target + ". Waiting for the PC…");
            return PollCommand(target, id);
        }

        /// <summary>
        /// Wait for one queued command to finish and print its status + output.
        /// Shared by the "run" verb and the one-shot fallback shell.
        /// </summary>
        private static int PollCommand(string target, long id)
        {
            string status = "QUEUED";
            Dictionary<string, object> row = null;
            for (int i = 0; i < 60; i++)
            {
                Thread.Sleep(1500);
                Dictionary<string, object> h = Post("/api/manager/history",
                    new Dictionary<string, object> { { "target", target } }, true);
                if (!IsOk(h)) return 1;
                row = FindRow(h, id);
                if (row == null) continue;
                status = AsString(row, "status");
                if (status == "DONE" || status == "FAILED") break;
            }

            if (row == null)
            {
                Console.WriteLine("Still waiting. Use: " + AppName + " history " + target);
                return 1;
            }
            string output = AsString(row, "output");
            if (output.Length > 0) Console.WriteLine(output.TrimEnd());
            else Console.WriteLine("(no output)");
            return status == "DONE" ? 0 : 1;
        }

        private static Dictionary<string, object> FindRow(Dictionary<string, object> response, long id)
        {
            if (response == null) return null;
            object raw;
            if (!response.TryGetValue("history", out raw) || !(raw is Array)) return null;
            foreach (object item in (object[])raw)
            {
                Dictionary<string, object> row = item as Dictionary<string, object>;
                if (row != null && AsLong(row, "id", 0) == id) return row;
            }
            return null;
        }

        private static int History(string[] args)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            if (args.Length > 1) payload["target"] = args[1].Trim();
            Dictionary<string, object> response = Post("/api/manager/history", payload, true);
            if (!IsOk(response)) return 1;
            object raw;
            if (!response.TryGetValue("history", out raw) || !(raw is Array)) { Console.WriteLine("No history."); return 0; }
            object[] list = (object[])raw;
            if (list.Length == 0) { Console.WriteLine("No commands yet."); return 0; }
            Console.WriteLine();
            foreach (object item in list)
            {
                Dictionary<string, object> r = item as Dictionary<string, object>;
                if (r == null) continue;
                Console.WriteLine("#" + AsLong(r, "id", 0)
                    + "  [" + Short(AsString(r, "status"), 9) + "]  " + AsString(r, "target_email")
                    + "  ::  " + Short(AsString(r, "command"), 90)
                    + (r.ContainsKey("exitcode") && r["exitcode"] != null ? "   exit " + r["exitcode"] : ""));
            }
            Console.WriteLine();
            return 0;
        }

        // -------------------------------------------------------------- session

        private static void LoadSession()
        {
            try
            {
                if (!File.Exists(SessionFile)) return;
                string json = File.ReadAllText(SessionFile, Encoding.UTF8);
                JavaScriptSerializer ser = new JavaScriptSerializer();
                Dictionary<string, object> s = ser.Deserialize<Dictionary<string, object>>(json);
                string sv = AsString(s, "server");
                if (sv != "") _serverUrl = sv;
                if (_serverUrl == "") _serverUrl = DefaultServerUrl;
                _cookieHeader = AsString(s, "cookie") ?? "";
                _csrf = AsString(s, "csrf") ?? "";
                _name = AsString(s, "name") ?? "";
                _role = AsString(s, "role") ?? "";

                if (_cookieHeader.Length > 0)
                {
                    Uri uri = ServerUri();
                    foreach (string pair in _cookieHeader.Split(';'))
                    {
                        string p = pair.Trim();
                        if (p.Length == 0) continue;
                        try { _cookies.SetCookies(uri, p); }
                        catch (Exception) { }
                    }
                }
            }
            catch (Exception)
            {
                // a corrupt cache is just a prompt for a fresh login
            }
        }

        private static void SaveSession()
        {
            try
            {
                string dir = Path.GetDirectoryName(SessionFile);
                if (!Directory.Exists(dir)) Directory.CreateDirectory(dir);
                JavaScriptSerializer ser = new JavaScriptSerializer();
                string json = ser.Serialize(new Dictionary<string, object>
                {
                    { "server", _serverUrl }, { "cookie", _cookieHeader },
                    { "csrf", _csrf }, { "name", _name }, { "role", _role },
                });
                File.WriteAllText(SessionFile, json, Encoding.UTF8);
            }
            catch (Exception) { }
        }

        /* --------------------------------------------------------------- verbs ---- */

        private static bool IsOk(Dictionary<string, object> response)
        {
            if (response != null && AsBool(response, "ok", false)) return true;
            string error = response == null ? "Cannot reach the server." : AsString(response, "error");
            if (!string.IsNullOrEmpty(error)
                && (error.IndexOf("csrf", StringComparison.OrdinalIgnoreCase) >= 0
                    || error.IndexOf("owner", StringComparison.OrdinalIgnoreCase) >= 0
                    || error.IndexOf("expired", StringComparison.OrdinalIgnoreCase) >= 0))
            {
                Console.Error.WriteLine("Session problem: " + error + ". Run: " + AppName + " login <email>");
            }
            else
            {
                Console.Error.WriteLine("Error: " + (error ?? "unknown"));
            }
            return false;
        }

        private static Dictionary<string, object> Post(string path, Dictionary<string, object> payload, bool auth)
        {
            lock (_http)
            {
                if (auth && string.IsNullOrEmpty(_csrf))
                {
                    Console.Error.WriteLine("Not signed in. Run: " + AppName + " login <email>");
                    return new Dictionary<string, object> { { "ok", false }, { "error", "not signed in" } };
                }
                if (auth) payload["_csrf"] = _csrf;

                string url = _serverUrl.Trim();
                if (!url.StartsWith("http://") && !url.StartsWith("https://")) url = "http://" + url;
                url = url.TrimEnd('/') + path;

                HttpWebRequest request = (HttpWebRequest)WebRequest.Create(url);
                request.Method = "POST";
                request.ContentType = "application/json";
                request.Timeout = 25000;
                request.ReadWriteTimeout = 25000;
                request.UserAgent = AppName + "/1.1";
                request.ServicePoint.Expect100Continue = false;
                request.AllowAutoRedirect = false;
                request.CookieContainer = _cookies;

                JavaScriptSerializer ser = new JavaScriptSerializer();
                try
                {
                    byte[] body = Encoding.UTF8.GetBytes(ser.Serialize(payload));
                    request.ContentLength = body.Length;
                    using (Stream stream = request.GetRequestStream()) stream.Write(body, 0, body.Length);

                    using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                    {
                        int status = (int) response.StatusCode;
                        if (status >= 300 && status < 400)
                        {
                            return new Dictionary<string, object> { { "ok", false }, { "error", "session expired" } };
                        }
                        CaptureCookies(response);
                        string text;
                        using (StreamReader reader = new StreamReader(response.GetResponseStream(), Encoding.UTF8))
                        {
                            text = reader.ReadToEnd();
                        }
                        if (LooksLikeChallenge(text))
                        {
                            Console.Error.WriteLine("The server requested a browser verification that this console cannot run. "
                                + "Open " + _serverUrl + "/login once in a browser on this PC, then retry.");
                            return new Dictionary<string, object> { { "ok", false }, { "error", "browser verification required" } };
                        }
                        object parsed;
                        try
                        {
                            parsed = ser.DeserializeObject(text);
                        }
                        catch (Exception)
                        {
                            return new Dictionary<string, object>
                            {
                                { "ok", false },
                                { "error", "Unexpected reply from the server (HTTP " + (int) response.StatusCode + ")." }
                            };
                        }
                        Dictionary<string, object> map = parsed as Dictionary<string, object>;
                        if (map == null)
                        {
                            return new Dictionary<string, object> { { "ok", false }, { "error", "Unexpected reply from the server." } };
                        }
                        return map;
                    }
                }
                catch (WebException ex)
                {
                    using (HttpWebResponse r = (HttpWebResponse)ex.Response)
                    {
                        if (r != null)
                        {
                            int status = (int)r.StatusCode;
                            if (r.StatusCode == HttpStatusCode.Unauthorized || r.StatusCode == HttpStatusCode.Forbidden)
                            {
                                return new Dictionary<string, object> { { "ok", false }, { "error", "session expired" } };
                            }
                            // Surface the real reason (e.g. "HTTP 404") instead of the
                            // generic "Cannot reach the server." that hid a missing route.
                            string detail = "";
                            try
                            {
                                using (StreamReader rr = new StreamReader(r.GetResponseStream(), Encoding.UTF8))
                                {
                                    detail = rr.ReadToEnd();
                                }
                            }
                            catch (Exception) { }
                            detail = detail == null ? "" : detail.Trim();
                            if (detail.Length > 160) detail = detail.Substring(0, 160);
                            string message = "HTTP " + status + (detail.Length > 0 ? " - " + detail : "");
                            return new Dictionary<string, object> { { "ok", false }, { "error", message } };
                        }
                    }
                    return null;
                }
            }
        }

        private static bool LooksLikeChallenge(string text)
        {
            return text != null &&
                   text.IndexOf("__test", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        /// <summary>Keep the window open when the exe was launched by double-click.</summary>
        private static void Pause()
        {
            try
            {
                Console.WriteLine();
                Console.Write("Press Enter to close this window...");
                Console.ReadLine();
            }
            catch (Exception) { }
        }

        private static void CaptureCookies(HttpWebResponse response)
        {
            try
            {
                string[] set = response.Headers.GetValues("Set-Cookie");
                if (set == null || set.Length == 0) return;
                Uri uri = response.ResponseUri ?? ServerUri();
                foreach (string one in set)
                {
                    try { _cookies.SetCookies(uri, one); }
                    catch (Exception) { }
                }
                StringBuilder sb = new StringBuilder();
                foreach (Cookie cookie in _cookies.GetCookies(uri))
                {
                    sb.Append(cookie.Name).Append('=').Append(cookie.Value).Append("; ");
                }
                _cookieHeader = sb.ToString().TrimEnd(' ', ';');
                SaveSession();
            }
            catch (Exception) { }
        }

        private static Uri ServerUri()
        {
            string url = _serverUrl.Trim();
            if (!url.StartsWith("http://") && !url.StartsWith("https://")) url = "http://" + url;
            return new Uri(url.TrimEnd('/'));
        }

        /* -------------------------------------------------------------- helpers ---- */

        private static string ArgValue(string[] args, string flag)
        {
            int i = Array.IndexOf(args, flag);
            if (i >= 0 && i + 1 < args.Length) return args[i + 1];
            return null;
        }

        private static string[] WithoutArg(string[] args, string flag)
        {
            int i = Array.IndexOf(args, flag);
            if (i < 0) return args;
            List<string> kept = new List<string>();
            for (int n = 0; n < args.Length; n++)
            {
                if (n == i || n == i + 1) continue;
                kept.Add(args[n]);
            }
            return kept.ToArray();
        }

        private static string ReadPassword()
        {
            ConsoleKeyInfo key;
            var sb = new StringBuilder();
            do
            {
                key = Console.ReadKey(true);
                if (key.Key == ConsoleKey.Backspace && sb.Length > 0) { sb.Length--; }
                else if (key.KeyChar >= 32) sb.Append(key.KeyChar);
            } while (key.Key != ConsoleKey.Enter);
            Console.WriteLine();
            return sb.ToString();
        }

        private static string Short(string value, int max)
        {
            if (value == null) return "";
            return value.Length <= max ? value : value.Substring(0, max - 1) + "…";
        }

        private static bool AsBool(Dictionary<string, object> map, string key, bool fallback)
        {
            object v;
            if (map != null && map.TryGetValue(key, out v) && v is bool) return (bool)v;
            return fallback;
        }

        private static string AsString(Dictionary<string, object> map, string key)
        {
            object v;
            if (map != null && map.TryGetValue(key, out v) && v != null) return v.ToString();
            return "";
        }

        private static long AsLong(Dictionary<string, object> map, string key, long fallback)
        {
            object v;
            if (map != null && map.TryGetValue(key, out v) && v != null)
            {
                long l;
                if (long.TryParse(v.ToString(), out l)) return l;
            }
            return fallback;
        }
    }
}
