using System;
using System.IO;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;

// Matrix: which .NET HttpWebRequest setup does openresty accept for the challenge-cookie POST login?
class Matrix
{
    const string Base = "https://workfolio.gt.tc";
    const string Body = "{\"email\":\"rahul@workfolio.com\",\"password\":\"emp123\"}";

    static void Main()
    {
        ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
        string cookie = FreshCookie();
        Console.WriteLine("fresh cookie: " + cookie);
        var combos = new[] { "accept-none", "accept-star", "accept-text-html" };
        foreach (var c in combos) Probe(c, cookie);
#if DEBUG
        Console.ReadLine();
#endif
    }

    static string FreshCookie()
    {
        HttpWebRequest r = (HttpWebRequest)WebRequest.Create(Base + "/login");
        r.Method = "GET";
        r.AllowAutoRedirect = false;
        r.UserAgent = "WorkFolioAgent/1.0.0";
        using (HttpWebResponse resp = (HttpWebResponse)r.GetResponse())
        using (StreamReader rd = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
        {
            string html = rd.ReadToEnd();
            string a = Match(html, @"a=toNumbers\(""([0-9a-f]{32})""\)");
            string b = Match(html, @"b=toNumbers\(""([0-9a-f]{32})""\)");
            string c = Match(html, @"c=toNumbers\(""([0-9a-f]{32})""\)");
            if (a == null || b == null || c == null) return "ERR";
            using (AesManaged aes = new AesManaged())
            {
                aes.KeySize = 128; aes.BlockSize = 128; aes.Mode = CipherMode.CBC; aes.Padding = PaddingMode.None;
                aes.Key = Hex(a); aes.IV = Hex(b);
                using (ICryptoTransform d = aes.CreateDecryptor())
                    return HexStr(d.TransformFinalBlock(Hex(c), 0, 16));
            }
        }
    }

    static void Probe(string acceptMode, string cookie)
    {
        try
        {
            HttpWebRequest r = (HttpWebRequest)WebRequest.Create(Base + "/api/agent/login");
            r.Method = "POST";
            r.AllowAutoRedirect = false;
            r.Timeout = 20000;
            r.UserAgent = "WorkFolioAgent/1.0.0";
            if (acceptMode == "accept-star") r.Accept = "*/*";
            if (acceptMode == "accept-text-html") r.Accept = "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
            r.ContentType = "application/json; charset=utf-8";
            byte[] b = Encoding.UTF8.GetBytes(Body);
            r.ContentLength = b.Length;
            using (Stream s = r.GetRequestStream()) s.Write(b, 0, b.Length);
            CookieContainer cc = new CookieContainer();
            cc.Add(new Uri(Base + "/"), new Cookie("__test", cookie, "/"));
            r.CookieContainer = cc;

            using (HttpWebResponse resp = (HttpWebResponse)r.GetResponse())
            using (StreamReader rd = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
            {
                string t = rd.ReadToEnd();
                Console.WriteLine("{0} -> {1} body[{2}]: {3}", acceptMode, (int)resp.StatusCode, t.Length, Safe(t, 0, 100));
            }
        }
        catch (WebException ex)
        {
            HttpWebResponse resp = ex.Response as HttpWebResponse;
            Console.WriteLine("{0} -> WebException {1}: {2}", acceptMode, resp != null ? ((int)resp.StatusCode).ToString() : ex.Message, resp != null ? (resp.Headers["Location"] ?? "") : "");
        }
        catch (Exception ex)
        {
            Console.WriteLine("{0} -> Exception {1}: {2}", acceptMode, ex.GetType().Name, ex.Message);
        }
    }

    static string Match(string s, string pattern)
    {
        Match m = Regex.Match(s, pattern);
        return m.Success ? m.Groups[1].Value : null;
    }
    static byte[] Hex(string h) { byte[] x = new byte[h.Length / 2]; for (int i = 0; i < x.Length; i++) x[i] = Convert.ToByte(h.Substring(i * 2, 2), 16); return x; }
    static string HexStr(byte[] d) { StringBuilder sb = new StringBuilder(d.Length * 2); foreach (byte v in d) sb.Append(v.ToString("x2")); return sb.ToString(); }
    static string Safe(string s, int start, int len) { if (string.IsNullOrEmpty(s)) return ""; if (start >= s.Length) return ""; return s.Substring(start, Math.Min(len, s.Length - start)); }
}