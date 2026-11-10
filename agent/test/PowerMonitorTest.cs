using System;
using WorkFolio.Agent;

/// <summary>
/// Checks which Windows signals are allowed to stop worked time.
///
/// The bug these guard against is the one employees actually noticed: worked time
/// freezing a couple of minutes into a shift and the panel claiming the laptop was
/// away. The cause was a dimmed screen being read as a closed lid.
/// </summary>
internal static class PowerMonitorTest
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

    /// <summary>Runs the pause-signal checks and returns how many failed.</summary>
    public static int Run()
    {
        _pass = 0;
        _fail = 0;
        Console.WriteLine();
        Console.WriteLine("--- laptop-away signals ---");
        Console.WriteLine("== 1. only a display that is really off counts as away ==");

        // GUID_CONSOLE_DISPLAY_STATE values: 0 = off, 1 = on, 2 = dimmed.
        Check("display off is away", PowerMonitor.DisplayAway(0), true);
        Check("display on is present", PowerMonitor.DisplayAway(1), false);

        // The regression. Windows dims the panel after a few seconds of no input on
        // most laptops, on battery and on mains alike, so treating DIM as a closed
        // lid froze worked time every couple of minutes for anyone who reads
        // documents or thinks at a screen instead of typing at it.
        Check("dimmed is not away", PowerMonitor.DisplayAway(2), false);

        // Defensive: an unrecognised value must not be read as a closed lid either.
        Check("an unknown state is not away", PowerMonitor.DisplayAway(99), false);

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILURES") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }
}