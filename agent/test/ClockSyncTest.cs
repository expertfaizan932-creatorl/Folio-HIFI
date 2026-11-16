using System;
using WorkFolio.Agent;

/// <summary>
/// Checks the clock-drift detector. The point of these is that drift is only ever
/// reported, never used to move the clock: durations stay on the monotonic clock,
/// so the detector's job is to notice and describe, not to correct.
/// </summary>
internal static class ClockSyncTest
{
    private static int _pass;
    private static int _fail;

    private static void Check(string label, object got, object want)
    {
        bool ok = Equals(got, want);
        if (ok) _pass++;
        else _fail++;
        Console.WriteLine("  " + (ok ? "PASS" : "FAIL") + "  " + label);
        if (!ok) Console.WriteLine("         got:  " + got + Environment.NewLine + "         want: " + want);
    }

    public static int Run()
    {
        _pass = 0;
        _fail = 0;
        Console.WriteLine();
        Console.WriteLine("--- clock drift ---");

        Console.WriteLine("== 1. a clock that agrees within tolerance is not flagged ==");
        ClockSync sync = new ClockSync(TimeSpan.FromMinutes(2));
        DateTime local = new DateTime(2026, 1, 1, 12, 0, 0, DateTimeKind.Utc);
        Check("a few seconds out is fine", sync.Observe(local.AddSeconds(30), local), false);
        Check("it records that it observed a reading", sync.Observed, true);
        Check("and reports no significance", sync.Significant, false);

        Console.WriteLine();
        Console.WriteLine("== 2. a significant gap is flagged with the right sign ==");
        ClockSync behind = new ClockSync(TimeSpan.FromMinutes(2));
        Check("5 minutes behind is significant", behind.Observe(local.AddMinutes(5), local), true);
        Check("drift is positive when local is behind", behind.DriftSeconds, 300L);
        CheckTrue2("the description says behind", behind.Describe().Contains("behind"));

        ClockSync ahead = new ClockSync(TimeSpan.FromMinutes(2));
        Check("5 minutes ahead is significant", ahead.Observe(local.AddMinutes(-5), local), true);
        Check("drift is negative when local is ahead", ahead.DriftSeconds, -300L);
        CheckTrue2("the description says ahead", ahead.Describe().Contains("ahead"));

        Console.WriteLine();
        Console.WriteLine("== 3. the tolerance boundary is exact ==");
        ClockSync edge = new ClockSync(TimeSpan.FromSeconds(120));
        Check("119s is within tolerance", edge.Observe(local.AddSeconds(119), local), false);
        Check("121s is over tolerance", edge.Observe(local.AddSeconds(121), local), true);

        Console.WriteLine();
        Console.WriteLine("== 4. an ISO server time is parsed and compared ==");
        ClockSync iso = new ClockSync(TimeSpan.FromMinutes(2));
        Check("a far-future server time is significant", iso.ObserveIso("2099-01-01T00:00:00Z"), true);
        ClockSync isoBad = new ClockSync();
        Check("garbage is ignored", isoBad.ObserveIso("not-a-date"), false);
        Check("garbage leaves the detector unobserved", isoBad.Observed, false);

        Console.WriteLine();
        Console.WriteLine("== 5. the description is available for the admin log ==");
        ClockSync fresh = new ClockSync();
        CheckTrue2("says nothing observed yet", fresh.Describe().Contains("not yet observed"));
        fresh.Observe(local, local);
        CheckTrue2("and reports agreement afterwards", fresh.Describe().Contains("agrees"));

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILURES") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }

    private static void CheckTrue2(string label, bool ok)
    {
        Check(label, ok, true);
    }
}
