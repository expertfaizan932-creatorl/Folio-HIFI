using System;
using System.Threading;
using WorkFolio.Agent;

/// <summary>
/// Checks the control pipe between the service and the tray agent. It is a local
/// loopback channel, so a round trip can be exercised in-process.
/// </summary>
internal static class IpcTest
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
        Console.WriteLine();
        Console.WriteLine("--- service/tray control pipe ---");

        string pipe = "FolioAgentTest_" + Guid.NewGuid().ToString("N");

        using (AgentIpcServer server = new AgentIpcServer(pipe, delegate (string request)
        {
            return "ok:" + request;
        }))
        {
            Console.WriteLine("== 1. a request gets its answer back ==");
            string reply = AgentIpcClient.Send(pipe, AgentIpc.Status, 5000);
            Check("status round-trips", reply, "ok:STATUS");

            Console.WriteLine();
            Console.WriteLine("== 2. the channel serves more than one call ==");
            string second = AgentIpcClient.Send(pipe, AgentIpc.Ping, 5000);
            Check("a second call also answers", second, "ok:PING");
            string third = AgentIpcClient.Send(pipe, AgentIpc.Restart, 5000);
            Check("and a third", third, "ok:RESTART");
        }

        Console.WriteLine();
        Console.WriteLine("== 3. nobody listening returns null, not a hang ==");
        string missing = AgentIpcClient.Send("FolioAgentTest_absent_" + Guid.NewGuid().ToString("N"),
                                             AgentIpc.Ping, 500);
        Check("no server -> null", missing, null);

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILURES") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }
}
