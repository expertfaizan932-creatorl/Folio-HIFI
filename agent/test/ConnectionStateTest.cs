using System;
using WorkFolio.Agent;

/// <summary>
/// Checks the reachability state and the retry-delay maths. These matter
/// because the agent must never let a network problem change what it records -
/// only how loudly it says so and how often it retries.
/// </summary>
internal static class ConnectionStateTest
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
        Console.WriteLine("--- connectivity and backoff ---");

        Console.WriteLine("== 1. starts optimistic, then records failures ==");
        ConnectionState state = new ConnectionState(new Random(1), 5, 300, 0.2);
        Check("assumed online at start", state.Online, true);
        Check("no failures yet", state.ConsecutiveFailures, 0);
        state.MarkFailure("timed out");
        Check("a failure takes it offline", state.Online, false);
        Check("and is counted", state.ConsecutiveFailures, 1);
        CheckTrue("the reason is kept", state.StatusText.Contains("timed out"));
        CheckTrue("it promises to keep recording", state.StatusText.Contains("still recording"));

        Console.WriteLine();
        Console.WriteLine("== 2. retry delay doubles and is capped (no jitter) ==");
        Check("no failures means no wait", Backoff.Seconds(0, 5, 300, 0, null), 0);
        Check("1st retry", Backoff.Seconds(1, 5, 300, 0, null), 5);
        Check("2nd retry", Backoff.Seconds(2, 5, 300, 0, null), 10);
        Check("3rd retry", Backoff.Seconds(3, 5, 300, 0, null), 20);
        Check("4th retry", Backoff.Seconds(4, 5, 300, 0, null), 40);
        Check("5th retry", Backoff.Seconds(5, 5, 300, 0, null), 80);
        Check("6th retry", Backoff.Seconds(6, 5, 300, 0, null), 160);
        Check("7th retry hits the cap", Backoff.Seconds(7, 5, 300, 0, null), 300);
        Check("and stays capped", Backoff.Seconds(20, 5, 300, 0, null), 300);

        Console.WriteLine();
        Console.WriteLine("== 3. jitter stays inside the band and under the cap ==");
        Random rng = new Random(42);
        int lo = int.MaxValue, hi = int.MinValue;
        for (int i = 0; i < 2000; i++)
        {
            int v = Backoff.Seconds(1, 100, 1000, 0.5, rng);
            if (v < lo) lo = v;
            if (v > hi) hi = v;
        }
        CheckTrue("never below 50", lo >= 50);
        CheckTrue("never above 150", hi <= 150);
        CheckTrue("actually varies", hi > 50);
        for (int i = 0; i < 500; i++)
        {
            if (Backoff.Seconds(30, 5, 300, 0.5, rng) > 300) { CheckTrue("a huge outage still caps at 300", false); break; }
        }
        CheckTrue("a huge outage still caps at 300", true);

        Console.WriteLine();
        Console.WriteLine("== 4. a success clears the failure state ==");
        ConnectionState fresh = new ConnectionState(new Random(3));
        fresh.MarkFailure("again");
        fresh.MarkFailure("again");
        Check("two failures counted", fresh.ConsecutiveFailures, 2);
        fresh.MarkSuccess();
        Check("back online", fresh.Online, true);
        Check("counter reset", fresh.ConsecutiveFailures, 0);
        CheckTrue("status reads connected", fresh.StatusText.Contains("Connected"));
        CheckTrue("a success timestamp is recorded", fresh.LastSuccessUtc.HasValue);

        Console.WriteLine();
        Console.WriteLine("== 5. the change event fires once per transition ==");
        int changes = 0;
        bool lastOnline = true;
        ConnectionState watched = new ConnectionState(new Random(2));
        watched.Changed += delegate (bool online, string status) { changes++; lastOnline = online; };
        watched.MarkSuccess();
        Check("no event when already online", changes, 0);
        watched.MarkFailure("x");
        Check("one event going offline", changes, 1);
        watched.MarkFailure("x");
        Check("no repeat event while still offline", changes, 1);
        watched.MarkSuccess();
        Check("one event coming back", changes, 2);
        Check("the last event reported online", lastOnline, true);

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILURES") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }
}
