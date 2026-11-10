using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Web.Script.Serialization;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Talks to the WorkFolio server. Everything is fire-and-forget from the
    /// collector's point of view: a failed call is queued on disk and retried, so a
    /// dropped network never loses a shift's worth of data or blocks the UI.
    /// </summary>
    internal sealed class ApiClient
    {
        private const int TimeoutMs = 15000;
        /// <summary>
        /// A tighter ceiling for the sign-in call: it is interactive, so three
        /// stacked 15-second timeouts before an error message would read as a
        /// hang rather than as a network problem.
        /// </summary>
        private const int InteractiveTimeoutMs = 8000;

        /// <summary>The live WorkFolio server. Must match AgentSettings.ServerUrl.</summary>
        public const string DefaultServerUrl = "https://folio.biztrack.uk";

        /// <summary>
        /// The retired host. It still answers, but only with a JavaScript cookie
        /// challenge page that no desktop client can pass, so a stale config
        /// pointing at it surfaces as a permanent "invalid email or password"
        /// instead of a transport error. Refuse it outright so the employee sees
        /// what is actually wrong.
        /// </summary>
        private static readonly string[] RetiredServerUrls = { "workfolio.gt.tc" };

        /// <summary>
        /// True when an address points at a host we have retired. Public so the
        /// settings layer can repair a stale agent.config before the agent ever
        /// tries to dial it - a machine built before the move to the current
        /// server otherwise stays offline until somebody edits the file by hand.
        /// A bare host with no scheme is accepted, exactly as <see cref="BaseUrl"/>.
        /// </summary>
        public static bool IsRetiredServerUrl(string url)
        {
            if (string.IsNullOrEmpty(url)) return false;
            string candidate = url.Trim();
            if (candidate.Length == 0) return false;
            if (!candidate.StartsWith("http://") && !candidate.StartsWith("https://")) candidate = "http://" + candidate;
            string host;
            try { host = new Uri(candidate).Host.ToLowerInvariant(); }
            catch (UriFormatException) { return false; }
            if (host.Length == 0) return false;
            foreach (string retired in RetiredServerUrls)
            {
                if (host == retired) return true;
            }
            return false;
        }

        private readonly AgentSettings _settings;
        private readonly JavaScriptSerializer _json = new JavaScriptSerializer();
        private readonly OfflineQueue _queue;

        // Server-authoritative time, observed from the serverTime field on every
        // response. Read-only diagnostics: durations never depend on the wall clock.
        private readonly ClockSync _clockSync = new ClockSync();

        public ClockSync Clock { get { return _clockSync; } }

        // Reachability and retry spacing. Purely descriptive - it never stops the
        // local ledger, which keeps counting while the link is down.
        private readonly ConnectionState _connection = new ConnectionState();

        public ConnectionState Connection { get { return _connection; } }

        public string Token { get; set; }
        public string Email { get; set; }
        public string DisplayName { get; set; }

        /// <summary>Solves InfinityFree's JS "__test" cookie challenge once per process; cleared on logout.</summary>
        private string _challengeCookie = string.Empty;

        /// <summary>Raised when a call fails, carrying a short reason for the status bar.</summary>
        public event Action<string> OnTransportProblem;
        public event Action<bool> OnAuthLost;

        public ApiClient(AgentSettings settings, OfflineQueue queue)
        {
            _settings = settings;
            _queue = queue;
            ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
        }

        private string BaseUrl
        {
            get
            {
                string url = _settings.ServerUrl ?? string.Empty;
                url = url.Trim();
                if (url.Length == 0) url = DefaultServerUrl;
                if (!url.StartsWith("http://") && !url.StartsWith("https://")) url = "http://" + url;
                return url.TrimEnd('/');
            }
        }

        /// <summary>True when the configured address is the retired host that cannot serve the API.</summary>
        private bool ServerUrlIsRetired()
        {
            return IsRetiredServerUrl(BaseUrl);
        }

        // ---------------------------------------------------------------- auth

        /// <summary>
        /// A stable, machine-local key that tells this PC apart from another on
        /// the same account. The computer name is enough on a single site and is
        /// what the owner sees; it never leaves the device except at sign-in.
        /// </summary>
        private static string DeviceKey()
        {
            string name = Environment.MachineName;
            return string.IsNullOrEmpty(name) ? "PC" : name;
        }

        public Dictionary<string, object> Login(string email, string password)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["email"] = email;
            payload["password"] = password;
            // Register this PC as a device so the owner console can list it (and
            // any future phone) side by side and target it by a stable id rather
            // than by address alone.
            payload["deviceType"] = "PC";
            payload["deviceKey"] = DeviceKey();
            payload["osName"] = "Windows";
            payload["osVersion"] = Environment.OSVersion.VersionString;
            payload["hostName"] = Environment.MachineName;
            payload["appVersion"] = Program.Version;

            Dictionary<string, object> response = Post("/api/agent/login", payload, false, InteractiveTimeoutMs);
            if (response == null)
            {
                // The sign-in dialog is the one place a transient blip must not
                // cost the user a bare "cannot reach". A login mints a fresh
                // token every time, so one more attempt carries no side effect
                // the server does not already prune.
                Thread.Sleep(1200);
                response = Post("/api/agent/login", payload, false, InteractiveTimeoutMs);
            }
            if (response == null) return null;

            if (AsBool(response, "ok", false))
            {
                Token = AsString(response, "token");
                Email = AsString(response, "email");
                DisplayName = AsString(response, "name");
                _settings.LastEmail = email;
                _settings.Save();
            }
            return response;
        }

        public void Logout()
        {
            if (!string.IsNullOrEmpty(Token))
            {
                Dictionary<string, object> payload = new Dictionary<string, object>();
                payload["token"] = Token;
                Post("/api/agent/logout", payload, true);
            }
            Token = null;
        }

        // --------------------------------------------------------- agent calls

        /// <summary>Clock in / out / break, all keyed off the stored token.</summary>
        public Dictionary<string, object> Attendance(string action)
        {
            return WithToken("/api/agent/" + action);
        }

        /// <summary>
        /// One telemetry batch: active/idle state, input counters, foreground window
        /// and agent identity. Returns the refreshed server summary plus any config
        /// change the admin made in the meantime.
        ///
        /// The four totals are the agent's monotonic, authoritative figures. The
        /// server stores them verbatim instead of re-deriving elapsed time from
        /// wall-clock gaps, which is what made folded-laptop hours appear as work.
        /// </summary>
        public Dictionary<string, object> Telemetry(string status, InputHooks.InputSnapshot input,
                                                     string app, string title, string host,
                                                     string category, string hostName, string ip,
                                                     long activeSec, long idleSec,
                                                     long breakSec, long pausedSec)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["status"] = status;
            payload["keystrokes"] = input.Keystrokes;
            payload["mouseClicks"] = input.Clicks;
            payload["mouseScrolls"] = input.Scrolls;
            payload["mouseDistancePx"] = input.DistancePx;
            payload["app"] = app;
            payload["title"] = title;
            payload["host"] = host;
            payload["category"] = category;
            payload["hostName"] = hostName;
            payload["agentVersion"] = Program.Version;
            payload["ip"] = ip;
            payload["activeSec"] = activeSec;
            payload["idleSec"] = idleSec;
            payload["breakSec"] = breakSec;
            payload["pausedSec"] = pausedSec;
            return WithToken("/api/agent/telemetry", payload);
        }

        /// <summary>
        /// Liveness beat with totals only - no window, no usage, no input. This is
        /// what the agent sends during a break and while the laptop is folded, so
        /// the break keeps an accurate length without recording any activity.
        /// </summary>
        public Dictionary<string, object> Heartbeat(string status,
                                                     long activeSec, long idleSec,
                                                     long breakSec, long pausedSec)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["status"] = status;
            payload["activeSec"] = activeSec;
            payload["idleSec"] = idleSec;
            payload["breakSec"] = breakSec;
            payload["pausedSec"] = pausedSec;
            return WithToken("/api/agent/heartbeat", payload);
        }

        /// <summary>Reports time spent on the current app or website.</summary>
        public bool Usage(WindowSample sample, int seconds, bool idle)
        {
            if (sample == null || seconds <= 0) return false;
            if (idle && string.IsNullOrEmpty(sample.TargetKey)) return false;

            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["targetKey"] = sample.TargetKey;
            payload["targetType"] = sample.TargetType;
            payload["displayName"] = sample.DisplayName;
            payload["process"] = sample.ProcessName;
            payload["title"] = sample.Title;
            payload["host"] = sample.Host;
            payload["seconds"] = seconds;
            payload["idle"] = idle;
            return WithToken("/api/agent/usage", payload) != null;
        }

        public bool Screenshot(string base64, string trigger, WindowSample sample, bool live)
        {
            if (string.IsNullOrEmpty(base64)) return false;
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["image"] = base64;
            payload["trigger"] = trigger;
            payload["app"] = sample == null ? null : sample.DisplayName;
            payload["title"] = sample == null ? null : sample.Title;
            payload["host"] = sample == null ? null : sample.Host;
            Dictionary<string, object> result = WithToken("/api/agent/screenshot", payload);
            return result != null && AsBool(result, "saved", false);
        }

        /// <summary>
        /// One frame of the live screen broadcast. The server keeps a single
        /// overwrite slot per employee, so a frame that fails to upload is simply
        /// dropped - it is never queued, or a flaky link would replay minutes of
        /// stale screen after the network came back.
        /// </summary>
        public bool LiveFrame(string base64, string status, WindowSample sample, long seq,
                              string hostName, string ip)
        {
            if (string.IsNullOrEmpty(base64)) return false;
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["frame"] = base64;
            payload["seq"] = seq;
            payload["status"] = status;
            payload["app"] = sample == null ? null : sample.DisplayName;
            payload["title"] = sample == null ? null : sample.Title;
            payload["host"] = sample == null ? null : sample.Host;
            payload["category"] = sample == null ? null : sample.TargetType;
            payload["hostName"] = hostName;
            payload["agentVersion"] = Program.Version;
            payload["ip"] = ip;
            return WithToken("/api/agent/live-frame", payload) != null;
        }

        public Dictionary<string, object> Today()
        {
            return Get("/api/agent/today?token=" + Uri.EscapeDataString(Token ?? string.Empty), false);
        }

        /// <summary>
        /// Asks whether the owner has queued a remote cmd line for this PC. Only
        /// rows aimed at the signed-in email are ever returned by the server, and
        /// the agent shows the line on its own screen before running it.
        /// </summary>
        public Dictionary<string, object> PollRemoteCommand()
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["token"] = Token;
            return Post("/api/agent/remote/poll", payload, true);
        }

        /// <summary>Reports the exit code and captured output of one remote command.</summary>
        public bool ReportRemote(long id, int exitcode, string output)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["token"] = Token;
            payload["id"] = id;
            payload["exitcode"] = exitcode;
            payload["output"] = output == null ? "" : output;
            return Post("/api/agent/remote/report", payload, true) != null;
        }

        // --------------------------------------------------- interactive shell ----

        /// <summary>
        /// Asks the owner's terminal for the next keystrokes. Returns the OPEN
        /// session this agent should be driving, or a null "shell" member when
        /// nobody is connected. Kept on a short timeout because it runs in a fast
        /// loop - a slow reply must not stall the whole session.
        /// </summary>
        public Dictionary<string, object> PollShell()
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["token"] = Token;
            return Post("/api/agent/shell/poll", payload, true, 10000);
        }

        /// <summary>Sends whatever the console printed since the last flush.</summary>
        public bool ReportShell(long shellId, string data)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["token"] = Token;
            payload["shell"] = shellId;
            payload["data"] = data == null ? "" : data;
            return Post("/api/agent/shell/out", payload, true, 15000) != null;
        }

        // ------------------------------------------------- remote file manager ----

        /// <summary>
        /// Asks the owner's file browser for the next ops. Returns the OPEN
        /// session this agent should be serving (with a batch of JSON ops), or a
        /// null "fs" member when nobody is browsing this PC.
        /// </summary>
        public Dictionary<string, object> PollFs()
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["token"] = Token;
            return Post("/api/agent/fs/poll", payload, true, 10000);
        }

        /// <summary>
        /// Posts the reply to one file op. Larger timeout than a normal call:
        /// a reply can be a full base64 chunk of a download.
        /// </summary>
        public bool ReportFs(long fsId, string data, string cwd)
        {
            Dictionary<string, object> payload = new Dictionary<string, object>();
            payload["token"] = Token;
            payload["fs"] = fsId;
            payload["data"] = data == null ? "" : data;
            if (!string.IsNullOrEmpty(cwd)) payload["cwd"] = cwd;
            return Post("/api/agent/fs/out", payload, true, 30000) != null;
        }

        /// <summary>The build the server wants this agent to be running.</summary>
        public Dictionary<string, object> CheckUpdate()
        {
            return Get("/api/agent/update", false, 10000);
        }

        /// <summary>
        /// Downloads a file (the new agent EXE) to disk. Handles the same JS
        /// cookie challenge as a normal call, and only writes the destination once
        /// the body is confirmed to be the binary and not the challenge page.
        /// </summary>
        public bool DownloadTo(string urlPath, string destPath)
        {
            string url = urlPath;
            if (!url.StartsWith("http://") && !url.StartsWith("https://"))
            {
                url = BaseUrl + (url.StartsWith("/") ? url : "/" + url);
            }
            for (int attempt = 0; attempt < 3; attempt++)
            {
                try
                {
                    HttpWebRequest request = (HttpWebRequest)WebRequest.Create(url);
                    request.Method = "GET";
                    request.Timeout = 60000;
                    request.ReadWriteTimeout = 60000;
                    request.UserAgent = "WorkFolioAgent/" + Program.Version;
                    request.ServicePoint.Expect100Continue = false;
                    if (!string.IsNullOrEmpty(_challengeCookie))
                    {
                        CookieContainer container = new CookieContainer();
                        container.Add(new Uri(BaseUrl + "/"), new Cookie("__test", _challengeCookie, "/"));
                        request.CookieContainer = container;
                    }

                    byte[] body;
                    using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                    using (Stream stream = response.GetResponseStream())
                    using (MemoryStream buffer = new MemoryStream())
                    {
                        byte[] chunk = new byte[81920];
                        int n;
                        while ((n = stream.Read(chunk, 0, chunk.Length)) > 0) buffer.Write(chunk, 0, n);
                        body = buffer.ToArray();
                    }

                    int probe = Math.Min(body.Length, 4000);
                    string head = Encoding.UTF8.GetString(body, 0, probe);
                    if (LooksLikeChallenge(head))
                    {
                        string solved = SolveChallenge(head);
                        if (solved == null)
                        {
                            RaiseTransport("The server asked for a browser verification that could not be completed");
                            return false;
                        }
                        if (solved != _challengeCookie)
                        {
                            _challengeCookie = solved;
                            continue;
                        }
                        RaiseTransport("The server browser verification could not be passed");
                        return false;
                    }

                    File.WriteAllBytes(destPath, body);
                    return true;
                }
                catch (WebException ex)
                {
                    if (attempt == 0) { Thread.Sleep(400); continue; }
                    RaiseTransport(ex.Message);
                    return false;
                }
                catch (Exception ex)
                {
                    RaiseTransport(ex.Message);
                    return false;
                }
            }
            return false;
        }

        /// <summary>Today's shift segments and totals, used to draw the panel timeline.</summary>
        public Dictionary<string, object> Day()
        {
            return Get("/api/agent/day?token=" + Uri.EscapeDataString(Token ?? string.Empty), false);
        }

        // ------------------------------------------------------ supervisor talk ----

        /// <summary>
        /// Asks whether a supervisor is talking to this machine right now, and takes
        /// the next audio chunk if one is waiting.
        ///
        /// Deliberately not retried or queued. Audio is a live stream, not a log: if
        /// this call fails, the right thing is to ask again in a moment. Replaying a
        /// response would repeat words the employee already heard.
        /// </summary>
        public Dictionary<string, object> TalkPoll()
        {
            if (string.IsNullOrEmpty(Token)) return null;
            try
            {
                Dictionary<string, object> payload = new Dictionary<string, object>();
                payload["token"] = Token;
                return Send("POST", "/api/agent/talk/poll", payload);
            }
            catch (Exception ex)
            {
                Trace.WriteLine("talk poll failed: " + ex.Message);
                return null;
            }
        }

        /// <summary>
        /// Confirms a chunk has been played, or reports the employee muted or
        /// declined. Acking is what advances the server's watermark.
        /// </summary>
        public void TalkAck(long seq, string ack)
        {
            if (string.IsNullOrEmpty(Token) || seq < 0) return;
            try
            {
                Dictionary<string, object> payload = new Dictionary<string, object>();
                payload["token"] = Token;
                payload["seq"] = seq;
                payload["ack"] = ack;
                Send("POST", "/api/agent/talk/ack", payload);
            }
            catch (Exception ex)
            {
                Trace.WriteLine("talk ack failed: " + ex.Message);
            }
        }

        /// <summary>Replays one queued payload through the normal authenticated path.</summary>
        public Dictionary<string, object> SendQueued(string path, Dictionary<string, object> payload)
        {
            return WithToken(path, payload);
        }

        // ------------------------------------------------------------ transport

        private Dictionary<string, object> WithToken(string path)
        {
            return WithToken(path, null);
        }

        private Dictionary<string, object> WithToken(string path, Dictionary<string, object> extra)
        {
            Dictionary<string, object> payload = extra ?? new Dictionary<string, object>();
            payload["token"] = Token;
            return Post(path, payload, true);
        }

        private Dictionary<string, object> Post(string path, Dictionary<string, object> payload, bool authenticated, int timeoutMs = 0)
        {
            if (authenticated && string.IsNullOrEmpty(Token)) return null;
            return Send("POST", path, payload, timeoutMs);
        }

        private Dictionary<string, object> Get(string path, bool authenticated)
        {
            return Get(path, authenticated, 0);
        }

        private Dictionary<string, object> Get(string path, bool authenticated, int timeoutMs)
        {
            if (authenticated && string.IsNullOrEmpty(Token)) return null;
            return Send("GET", path, null, timeoutMs);
        }

        private Dictionary<string, object> Send(string method, string path, Dictionary<string, object> payload, int timeoutMs = 0)
        {
            if (ServerUrlIsRetired())
            {
                RaiseTransport("This agent points at the retired server " + BaseUrl +
                                " - update agent.config to " + DefaultServerUrl);
                return null;
            }

            string url = BaseUrl + path;
            for (int attempt = 0; attempt < 3; attempt++)
            {
                try
                {
                    HttpWebRequest request = CreateRequest(method, url, payload, timeoutMs);

                    string text;
                    using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                    using (StreamReader reader = new StreamReader(response.GetResponseStream(), Encoding.UTF8))
                    {
                        text = reader.ReadToEnd();
                    }

                    // InfinityFree serves a JavaScript cookie challenge (a page that
                    // sets a __test cookie via AES in the browser) to clients that
                    // cannot run JavaScript - exactly what this agent is. Solve it
                    // once (AES-128-CBC, params from the inline script) and retry
                    // with the cookie, the same way a browser does.
                    if (LooksLikeChallenge(text))
                    {
                        string solved = SolveChallenge(text);
                        if (solved == null)
                        {
                            RaiseTransport("The server asked for a browser verification that could not be completed");
                            return null;
                        }
                        if (solved != _challengeCookie)
                        {
                            _challengeCookie = solved;
                            continue; // retry the same call with the cookie attached
                        }
                        RaiseTransport("The server browser verification could not be passed");
                        return null;
                    }

                    object parsed = _json.DeserializeObject(text);
                    Dictionary<string, object> map = parsed as Dictionary<string, object>;
                    // Feed the server's clock to the drift detector when it is present;
                    // older servers simply omit the field and nothing changes.
                    if (map != null)
                    {
                        object serverTime;
                        if (map.TryGetValue("serverTime", out serverTime) && serverTime != null)
                            _clockSync.ObserveIso(serverTime.ToString());
                    }
                    if (map != null && map.Count > 0 && !AsBool(map, "ok", true)) RaiseAuthLost(map);
                    _connection.MarkSuccess();
                    return map;
                }
                catch (WebException ex)
                {
                    if (IsUnauthorized(ex))
                    {
                        RaiseTransport("Session expired, please sign in again");
                        RaiseAuthLost(null);
                        return null;
                    }
                    if (attempt == 0)
                    {
                        Thread.Sleep(400);
                        continue;
                    }
                    RaiseTransport(ex.Message);
                    return null;
                }
                catch (Exception ex)
                {
                    RaiseTransport(ex.Message);
                    return null;
                }
            }
            return null;
        }

        private HttpWebRequest CreateRequest(string method, string url, Dictionary<string, object> payload, int timeoutMs)
        {
            HttpWebRequest request = (HttpWebRequest)WebRequest.Create(url);
            request.Method = method;
            request.Timeout = timeoutMs > 0 ? timeoutMs : TimeoutMs;
            request.ReadWriteTimeout = timeoutMs > 0 ? timeoutMs : TimeoutMs;
            request.UserAgent = "WorkFolioAgent/" + Program.Version;
            // InfinityFree (openresty) rejects Accept-Encoding gzip/deflate from this
            // client and also mis-handles Expect: 100-continue; keep the request
            // byte-minimal like a plain JSON client so the __test cookie is accepted.
            request.ServicePoint.Expect100Continue = false;

            // Attach the challenge cookie BEFORE opening the request stream - setting
            // CookieContainer afterwards is too late: .NET has already sent the
            // headers (with Expect:100-continue) by the time GetRequestStream writes.
            if (!string.IsNullOrEmpty(_challengeCookie))
            {
                CookieContainer container = new CookieContainer();
                container.Add(new Uri(BaseUrl + "/"), new Cookie("__test", _challengeCookie, "/"));
                request.CookieContainer = container;
            }

            if (payload != null)
            {
                request.ContentType = "application/json";
                byte[] body = Encoding.UTF8.GetBytes(_json.Serialize(payload));
                request.ContentLength = body.Length;
                using (Stream stream = request.GetRequestStream())
                {
                    stream.Write(body, 0, body.Length);
                }
            }

            return request;
        }

        private static bool LooksLikeChallenge(string text)
        {
            return text != null &&
                   text.IndexOf("/aes.js", StringComparison.OrdinalIgnoreCase) >= 0 &&
                   text.IndexOf("__test", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        /// <summary>
        /// Solves InfinityFree's JS challenge: AES-128-CBC decrypt of the cipher
        /// block c with key a and IV b (hex from the inline script); the plaintext
        /// hex is the __test cookie value. Verified against slowAES and the server.
        /// </summary>
        private static string SolveChallenge(string html)
        {
            try
            {
                string a = Regex.Match(html, "a=toNumbers\\(\"([0-9a-f]{32})\"\\)").Groups[1].Value;
                string b = Regex.Match(html, "b=toNumbers\\(\"([0-9a-f]{32})\"\\)").Groups[1].Value;
                string c = Regex.Match(html, "c=toNumbers\\(\"([0-9a-f]{32})\"\\)").Groups[1].Value;
                if (a.Length != 32 || b.Length != 32 || c.Length != 32) return null;

                byte[] keyBytes = HexToBytes(a);
                byte[] ivBytes = HexToBytes(b);
                byte[] cipherBytes = HexToBytes(c);

                using (AesManaged aes = new AesManaged())
                {
                    aes.KeySize = 128;
                    aes.BlockSize = 128;
                    aes.Mode = CipherMode.CBC;
                    aes.Padding = PaddingMode.None;
                    aes.Key = keyBytes;
                    aes.IV = ivBytes;
                    using (ICryptoTransform decryptor = aes.CreateDecryptor())
                    {
                        byte[] plain = decryptor.TransformFinalBlock(cipherBytes, 0, cipherBytes.Length);
                        return BytesToHex(plain);
                    }
                }
            }
            catch (Exception)
            {
                return null;
            }
        }

        private static byte[] HexToBytes(string hex)
        {
            byte[] bytes = new byte[hex.Length / 2];
            for (int i = 0; i < bytes.Length; i++)
                bytes[i] = Convert.ToByte(hex.Substring(i * 2, 2), 16);
            return bytes;
        }

        private static string BytesToHex(byte[] data)
        {
            StringBuilder sb = new StringBuilder(data.Length * 2);
            foreach (byte value in data) sb.Append(value.ToString("x2"));
            return sb.ToString();
        }

        private static bool IsUnauthorized(WebException ex)
        {
            HttpWebResponse response = ex.Response as HttpWebResponse;
            return response != null && response.StatusCode == HttpStatusCode.Unauthorized;
        }

        private void RaiseTransport(string reason)
        {
            _connection.MarkFailure(reason);
            Action<string> handler = OnTransportProblem;
            if (handler != null) handler(reason);
        }

        private void RaiseAuthLost(Dictionary<string, object> response)
        {
            Action<bool> handler = OnAuthLost;
            if (handler != null) handler(response == null || !AsBool(response, "ok", true));
        }

        // ----------------------------------------------------------- json utils

        public static bool AsBool(Dictionary<string, object> map, string key, bool fallback)
        {
            if (map == null || !map.ContainsKey(key) || map[key] == null) return fallback;
            object value = map[key];
            if (value is bool) return (bool)value;
            try { return Convert.ToBoolean(value); } catch (Exception) { return fallback; }
        }

        public static string AsString(Dictionary<string, object> map, string key)
        {
            if (map == null || !map.ContainsKey(key) || map[key] == null) return null;
            return map[key].ToString();
        }

        public static long AsLong(Dictionary<string, object> map, string key, long fallback)
        {
            if (map == null || !map.ContainsKey(key) || map[key] == null) return fallback;
            object value = map[key];
            if (value is long) return (long)value;
            if (value is int) return (int)value;
            if (value is double) return (long)(double)value;
            try { return Convert.ToInt64(value); } catch (Exception) { return fallback; }
        }

        /// <summary>Nested object access, e.g. Today() -&gt; ["today"] -&gt; ["worked"].</summary>
        public static Dictionary<string, object> Section(Dictionary<string, object> map, string key)
        {
            if (map == null || !map.ContainsKey(key) || map[key] == null) return null;
            return map[key] as Dictionary<string, object>;
        }
    }
}
