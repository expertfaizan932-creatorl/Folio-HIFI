using System;
using System.IO;
using System.Net;
using System.Text;

// .NET HttpWebRequest through the same logging proxy, to diff headers vs node.
class ClientDotNet
{
    static void Main()
    {
        ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
        string cookie = "bfffd8749af763522b3b4ea9bcc3ee7d";
        string body = "{\"email\":\"rahul@workfolio.com\",\"password\":\"emp123\"}";

        HttpWebRequest r = (HttpWebRequest)WebRequest.Create("https://workfolio.gt.tc/api/agent/login");
        r.Method = "POST";
        r.Proxy = new WebProxy("127.0.0.1", 8890);
        r.Timeout = 20000;
        r.UserAgent = "WorkFolioAgent/1.0.0";
        r.ContentType = "application/json; charset=utf-8";
        byte[] b = Encoding.UTF8.GetBytes(body);
        r.ContentLength = b.Length;
        using (Stream s = r.GetRequestStream()) s.Write(b, 0, b.Length);
        CookieContainer cc = new CookieContainer();
        cc.Add(new Uri("https://workfolio.gt.tc/"), new Cookie("__test", cookie, "/"));
        r.CookieContainer = cc;

        try
        {
            using (HttpWebResponse resp = (HttpWebResponse)r.GetResponse())
            using (StreamReader rd = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
                Console.WriteLine("DOTNET got " + (int)resp.StatusCode + " body[:160]: " + Safe(rd.ReadToEnd(), 0, 160));
        }
        catch (WebException ex)
        {
            HttpWebResponse resp = ex.Response as HttpWebResponse;
            Console.WriteLine("DOTNET WebException " + (resp != null ? ((int)resp.StatusCode).ToString() : ex.Message));
        }
    }
    static string Safe(string s, int start, int len) { if (string.IsNullOrEmpty(s)) return ""; if (start >= s.Length) return ""; return s.Substring(start, Math.Min(len, s.Length - start)); }
}