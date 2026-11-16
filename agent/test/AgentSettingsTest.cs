using System;
using WorkFolio.Agent;

/// <summary>
/// Checks the server-address repair. A machine built before the move to the
/// current server carries a per-user agent.config pointing at the retired host,
/// and that file is never overwritten by the installer. The agent has to heal it
/// on load, or the laptop shows a permanent "cannot reach" with no way out.
/// </summary>
internal static class AgentSettingsTest
{
    private static int _pass;
    private static int _fail;

    private static void Check(string label, object got, object want)
    {
        bool ok = Equals(got, want);
        if (ok) _pass++; else _fail++;
        Console.WriteLine("  " + (ok ? "PASS" : "FAIL") + "  " + label);
        if (!ok) Console.WriteLine("         got:  " + got + Environment.NewLine + "         want: " + want);
    }

    private static void CheckTrue(string label, bool ok)
    {
        Check(label, ok, true);
    }

    public static int Run()
    {
        _pass = 0;
        _fail = 0;
        string live = ApiClient.DefaultServerUrl;
        Console.WriteLine();
        Console.WriteLine("--- server address auto-heal ---");

        Console.WriteLine("== 1. a missing address falls back to the live server ==");
        Check("null -> live", AgentSettings.HealServerUrl(null), live);
        Check("empty -> live", AgentSettings.HealServerUrl(""), live);
        Check("whitespace -> live", AgentSettings.HealServerUrl("   "), live);

        Console.WriteLine();
        Console.WriteLine("== 2. the retired host is replaced, however it is written ==");
        Check("bare host", AgentSettings.HealServerUrl("workfolio.gt.tc"), live);
        Check("host with slash", AgentSettings.HealServerUrl("workfolio.gt.tc/"), live);
        Check("http scheme", AgentSettings.HealServerUrl("http://workfolio.gt.tc"), live);
        Check("https scheme", AgentSettings.HealServerUrl("https://workfolio.gt.tc/"), live);
        Check("upper case host", AgentSettings.HealServerUrl("https://WORKFOLIO.GT.TC"), live);
        Check("padded", AgentSettings.HealServerUrl("  workfolio.gt.tc  "), live);
        CheckTrue("IsRetiredServerUrl agrees", ApiClient.IsRetiredServerUrl("workfolio.gt.tc"));

        Console.WriteLine();
        Console.WriteLine("== 3. a good address is left alone (only trimmed) ==");
        Check("live, as shipped", AgentSettings.HealServerUrl(live), live);
        Check("live with trailing slash", AgentSettings.HealServerUrl(live + "/"), live + "/");
        Check("live padded", AgentSettings.HealServerUrl("  " + live + "  "), live);
        Check("a self-hosted address", AgentSettings.HealServerUrl("http://192.168.1.5:8080"), "http://192.168.1.5:8080");
        Check("another domain", AgentSettings.HealServerUrl("https://folio.example.com"), "https://folio.example.com");
        CheckTrue("IsRetiredServerUrl says no to the live host", !ApiClient.IsRetiredServerUrl(live));

        Console.WriteLine();
        Console.WriteLine("== 4. healing is idempotent ==");
        string once = AgentSettings.HealServerUrl("https://workfolio.gt.tc/path");
        Check("healing the healed value changes nothing", AgentSettings.HealServerUrl(once), once);
        CheckTrue("an empty string is not a retired host", !ApiClient.IsRetiredServerUrl(""));
        CheckTrue("garbage is not a retired host", !ApiClient.IsRetiredServerUrl("not a url"));

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILURES") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }
}
