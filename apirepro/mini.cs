using System;
using System.IO;
using System.Net;
using System.Text;

// Winning combo, but DIRECT https (no proxy) - exactly what the agent does.
class Mini
{
    static void Main(string[] args)
    {
        ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
        string body = "{\"email\":\"rahul@workfolio.com\",\"password\":\"emp123\"}";
        string cookie = "bfffd8749af763522b3b4ea9bcc3ee7d";

        ServicePoint sp = ServicePointManager.FindServicePoint(new Uri("https://workfolio.gt.tc"));
        sp.Expect100Continue = false;

        CookieContainer cc = new CookieContainer();
        cc.Add(new Uri("https://workfolio.gt.tc/"), new Cookie("__test", cookie, "/"));

        HttpWebRequest r = (HttpWebRequest)WebRequest.Create("https://workfolio.gt.tc/api/agent/login");
        r.Method = "POST";
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
                Console.WriteLine("DIRECT got " + (int)resp.StatusCode + " body[:120]: " + Safe(rd.ReadToEnd(), 0, 120));
        }
        catch (WebException ex)
        {
            HttpWebResponse resp = ex.Response as HttpWebResponse;
            if (resp != null)
            {
                Console.WriteLine("DIRECT WebException " + (int)resp.StatusCode);
                try
                {
                    using (StreamReader r2 = new StreamReader(resp.GetResponseStream(), Encoding.UTF8))
                        Console.WriteLine("body: " + Safe(r2.ReadToEnd(), 0, 200));
                }
                catch (Exception re) { Console.WriteLine("read err " + re.Message); }
            }
            else Console.WriteLine("DIRECT WebException no-response: " + ex.Message);
        }
        catch (Exception ex)
        {
            Console.WriteLine("DIRECT Exception " + ex.GetType().Name + ": " + ex.Message);
        }
    }
    static string Safe(string s, int start, int len) { if (string.IsNullOrEmpty(s)) return ""; if (start >= s.Length) return ""; return s.Substring(start, Math.Min(len, s.Length - start)); }
}