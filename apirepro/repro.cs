using System;
using System.IO;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;

// Mirror node's proven flow in .NET: 1) GET /login -> challenge -> solve; 2) POST /api/agent/login with cookie.
class ApiRepro
{
    static string _challengeCookie = string.Empty;
    static int _calls = 0;
    const string Base = "https://workfolio.gt.tc";

    static void Main()
    {
        ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
        // step 1: pre-solve challenge via GET /login (fresh connection)
        string prep = Step("GET", "/login", null);
        Console.WriteLine("PREP result: " + prep);
        // step 2: the real call with the cookie
        string login = Step("POST", "/api/agent/login", "{\"email\":\"rahul@workfolio.com\",\"password\":\"emp123\"}");
        Console.WriteLine("LOGIN result: " + login);
    }

    static string Step(string method, string path, string body)
    {
        _calls++;
        try
        {
            HttpWebRequest request = (HttpWebRequest)WebRequest.Create(Base + path);
            request.Method = method;
            request.ConnectionGroupName = "g" + _calls;
            request.Timeout = 20000;
            request.UserAgent = "WorkFolioAgent/1.0.0";
            ServicePoint sp = ServicePointManager.FindServicePoint(new Uri(Base));
            sp.Expect100Continue = false;
            if (!string.IsNullOrEmpty(_challengeCookie))
            {
                CookieContainer cc = new CookieContainer();
                cc.Add(new Uri(Base + "/"), new Cookie("__test", _challengeCookie, "/"));
                request.CookieContainer = cc;
                Console.WriteLine("[" + path + "] WITH __test=" + _challengeCookie);
            }
            else Console.WriteLine("[" + path + "] no cookie");

            if (body != null)
            {
                request.ContentType = "application/json";
                byte[] b = Encoding.UTF8.GetBytes(body);
                request.ContentLength = b.Length;
                using (Stream s = request.GetRequestStream()) s.Write(b, 0, b.Length);
            }

            using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
            using (StreamReader reader = new StreamReader(response.GetResponseStream(), Encoding.UTF8))
            {
                string text = reader.ReadToEnd();
                Console.WriteLine("[" + path + "] status=" + (int)response.StatusCode + " body[" + text.Length + "]: " + Safe(text, 0, 90).Replace("\n", " "));
                if (LooksLikeChallenge(text))
                {
                    string solved = SolveChallenge(text);
                    Console.WriteLine("[" + path + "] SOLVED=" + (solved ?? "NULL"));
                    if (solved == null) return "unsolvable challenge";
                    _challengeCookie = solved;
                    return "solved (retry separately)";
                }
                return text.Length > 400 ? text.Substring(0, 400) : text;
            }
        }
        catch (WebException ex)
        {
            HttpWebResponse resp = ex.Response as HttpWebResponse;
            return "WebException " + (resp != null ? ((int)resp.StatusCode).ToString() : ex.Message);
        }
        catch (Exception ex)
        {
            return "Exception " + ex.GetType().Name + ": " + ex.Message;
        }
    }

    static bool LooksLikeChallenge(string text)
    {
        return text != null && text.IndexOf("/aes.js", StringComparison.OrdinalIgnoreCase) >= 0 && text.IndexOf("__test", StringComparison.OrdinalIgnoreCase) >= 0;
    }

    static string SolveChallenge(string html)
    {
        try
        {
            string a = Regex.Match(html, "a=toNumbers\\(\"([0-9a-f]{32})\"\\)").Groups[1].Value;
            string b = Regex.Match(html, "b=toNumbers\\(\"([0-9a-f]{32})\"\\)").Groups[1].Value;
            string c = Regex.Match(html, "c=toNumbers\\(\"([0-9a-f]{32})\"\\)").Groups[1].Value;
            if (a.Length != 32 || b.Length != 32 || c.Length != 32) return null;
            using (AesManaged aes = new AesManaged())
            {
                aes.KeySize = 128; aes.BlockSize = 128; aes.Mode = CipherMode.CBC; aes.Padding = PaddingMode.None;
                aes.Key = HexToBytes(a); aes.IV = HexToBytes(b);
                using (ICryptoTransform d = aes.CreateDecryptor())
                    return BytesToHex(d.TransformFinalBlock(HexToBytes(c), 0, 16));
            }
        }
        catch (Exception ex) { Console.WriteLine("solve exception: " + ex.Message); return null; }
    }

    static byte[] HexToBytes(string hex) { byte[] x = new byte[hex.Length / 2]; for (int i = 0; i < x.Length; i++) x[i] = Convert.ToByte(hex.Substring(i * 2, 2), 16); return x; }
    static string BytesToHex(byte[] data) { StringBuilder sb = new StringBuilder(data.Length * 2); foreach (byte v in data) sb.Append(v.ToString("x2")); return sb.ToString(); }
    static string Safe(string s, int start, int len) { if (string.IsNullOrEmpty(s)) return ""; if (start >= s.Length) return ""; return s.Substring(start, Math.Min(len, s.Length - start)); }
}