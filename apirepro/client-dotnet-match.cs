using System;
using System.IO;
using System.Net;
using System.Text;

// POST matching node's exact header set: cookie, no accept, no gzip, clean content-type,
// Expect100Continue off. Via proxy so we can see what is sent.
class ClientDotNetMatch
{
    static void Main()
    {
        ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
        ServicePoint sp = ServicePointManager.FindServicePoint(new Uri("http://workfolio.gt.tc"));
        sp.Expect100Continue = false;
        string body = "{\"email\":\"rahul@workfolio.com\",\"password\":\"emp123\"}";

        CookieContainer cc = new CookieContainer();
        cc.Add(new Uri("http://workfolio.gt.tc/"), new Cookie("__test", "bfffd8749af763522b3b4ea9bcc3ee7d", "/"));

        HttpWebRequest r = (HttpWebRequest)WebRequest.Create("http://workfolio.gt.tc/api/agent/login");
        r.Method = "POST";
        r.Proxy = new WebProxy("127.0.0.1", 8890);
        r.Timeout = 20000;
        r.UserAgent = "WorkFolioAgent/1.0.0";
        r.ContentType = "application/json";
        byte[] b = Encoding.UTF8.GetBytes(body);
        r.ContentLength = b.Length;
        r.CookieContainer = cc;
        using (Stream s = r.GetRequestStream()) s.Write(b, 0, b.Length);

        try
        {
            using (HttpWebResponse resp = (HttpWebResponse)r.GetResponse())
            using (StreamReader rd = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
                Console.WriteLine("MATCH got " + (int)resp.StatusCode + " body[:120]: " + Safe(rd.ReadToEnd(), 0, 120));
        }
        catch (WebException ex)
        {
            HttpWebResponse resp = ex.Response as HttpWebResponse;
            Console.WriteLine("MATCH WebException " + (resp != null ? ((int)resp.StatusCode).ToString() : ex.Message) + " | cookieSent?");
        }
    }
    static string Safe(string s, int start, int len) { if (string.IsNullOrEmpty(s)) return ""; if (start >= s.Length) return ""; return s.Substring(start, Math.Min(len, s.Length - start)); }
}