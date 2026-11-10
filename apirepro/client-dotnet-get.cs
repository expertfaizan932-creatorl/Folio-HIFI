using System;
using System.IO;
using System.Net;
using System.Text;

// Minimal GET via proxy with CookieContainer - is the cookie actually emitted?
class ClientDotNetGet
{
    static void Main()
    {
        ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
        CookieContainer cc = new CookieContainer();
        cc.Add(new Uri("http://workfolio.gt.tc/"), new Cookie("__test", "bfffd8749af763522b3b4ea9bcc3ee7d", "/"));
        HttpWebRequest r = (HttpWebRequest)WebRequest.Create("http://workfolio.gt.tc/login");
        r.Proxy = new WebProxy("127.0.0.1", 8890);
        r.Timeout = 20000;
        r.UserAgent = "WorkFolioAgent/1.0.0";
        r.CookieContainer = cc;
        try
        {
            using (HttpWebResponse resp = (HttpWebResponse)r.GetResponse())
            using (StreamReader rd = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
                Console.WriteLine("GET status " + (int)resp.StatusCode + " body[:80]: " + Safe(rd.ReadToEnd(), 0, 80));
        }
        catch (WebException ex)
        {
            Console.WriteLine("WebException " + (ex.Response != null ? ((int)((HttpWebResponse)ex.Response).StatusCode).ToString() : ex.Message));
        }
    }
    static string Safe(string s, int start, int len) { if (string.IsNullOrEmpty(s)) return ""; if (start >= s.Length) return ""; return s.Substring(start, Math.Min(len, s.Length - start)); }
}