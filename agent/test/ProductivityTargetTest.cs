using System;
using System.IO;
using WorkFolio.Agent;

/// <summary>
/// Checks the 8-hour productivity classification and messaging.
///
/// The boundary cases (exactly 8h, one second under, one second over) are the
/// ones that matter: off-by-one here is the difference between telling an
/// employee they met the target and telling them they missed it. Compiled
/// against the real ProductivityTarget.cs, so the verified arithmetic is the
/// arithmetic that ships.
/// </summary>
internal static class ProductivityTargetTest
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

    private static void CheckTrue(string label, bool ok)
    {
        Check(label, ok, true);
    }

    private static string TempFile(string name)
    {
        string dir = Path.Combine(Path.GetTempPath(), "wf-prod-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(dir);
        return Path.Combine(dir, name);
    }

    public static int Run()
    {
        _pass = 0;
        _fail = 0;
        Console.WriteLine();
        Console.WriteLine("--- productivity target (8h) ---");

        ProductivityTarget target = new ProductivityTarget();

        Console.WriteLine("== 1. target is exactly 28800 seconds ==");
        Check("default target is 8 hours", target.TargetSeconds, ProductivityTarget.DefaultTargetSeconds);
        Check("8 hours in seconds", target.TargetSeconds, 28800L);

        Console.WriteLine();
        Console.WriteLine("== 2. classification is correct to the second ==");
        Check("7h59m59s is below target", target.Evaluate(28799).Status, ProductivityStatus.BelowTarget);
        Check("exactly 8h is on target", target.Evaluate(28800).Status, ProductivityStatus.OnTarget);
        Check("8h + 1s is above target", target.Evaluate(28801).Status, ProductivityStatus.AboveTarget);
        Check("7h is below", target.Evaluate(25200).Status, ProductivityStatus.BelowTarget);
        Check("9h is above", target.Evaluate(32400).Status, ProductivityStatus.AboveTarget);

        Console.WriteLine();
        Console.WriteLine("== 3. time values are reported accurately ==");
        ProductivityResult below = target.Evaluate(26400);           // 7h 20m
        Check("eligible time", below.EligibleSeconds, 26400L);
        Check("difference is negative below target", below.DifferenceSeconds, -2400L);
        Check("remaining to target", below.RemainingSeconds, 2400L);
        Check("no overtime below target", below.OvertimeSeconds, 0L);
        Check("did not reach target", below.ReachedTarget, false);

        ProductivityResult above = target.Evaluate(29700);           // 8h 15m
        Check("overtime above target", above.OvertimeSeconds, 900L);
        Check("no remaining above target", above.RemainingSeconds, 0L);
        Check("reached target", above.ReachedTarget, true);

        Console.WriteLine();
        Console.WriteLine("== 4. below-target message names the shortfall ==");
        CheckTrue("mentions the actual time", below.Message.Contains("7h 20m"));
        CheckTrue("mentions the 8 hour target", below.Message.Contains("8 hours"));
        CheckTrue("names the 40 minute shortfall", below.Message.Contains("40 minutes"));
        CheckTrue("stays encouraging", below.Message.Contains("Keep improving"));

        Console.WriteLine();
        Console.WriteLine("== 5. exact-target message celebrates completion ==");
        ProductivityResult exact = target.Evaluate(28800);
        CheckTrue("celebrates the target", exact.Message.Contains("Outstanding"));
        CheckTrue("mentions 8 hours", exact.Message.Contains("8 hours"));

        Console.WriteLine();
        Console.WriteLine("== 6. above-target messages grow with the overtime ==");
        CheckTrue("under an hour reads as extra minutes", above.Message.Contains("extra 15 minutes"));
        ProductivityResult oneHourOver = target.Evaluate(32400);     // 9h
        CheckTrue("an hour over is 'exceeded your target by 1 hour'",
            oneHourOver.Message.Contains("exceeded your target by 1 hour"));
        ProductivityResult twoHoursOver = target.Evaluate(36000);    // 10h
        CheckTrue("two hours over celebrates the total",
            twoHoursOver.Message.Contains("10 hours"));

        Console.WriteLine();
        Console.WriteLine("== 7. progress percentage keeps full precision ==");
        CheckTrue("28799 is just under 100%", Math.Abs(target.Evaluate(28799).ProgressPercent - 99.9965) < 0.01);
        CheckTrue("28800 is exactly 100%", Math.Abs(target.Evaluate(28800).ProgressPercent - 100.0) < 0.0001);
        CheckTrue("14400 is exactly 50%", Math.Abs(target.Evaluate(14400).ProgressPercent - 50.0) < 0.0001);

        Console.WriteLine();
        Console.WriteLine("== 8. milestones fire once, in order, with a cooldown ==");
        ProductivityTarget m = new ProductivityTarget(28800, null, 600);
        DateTime t0 = new DateTime(2026, 1, 1, 9, 0, 0, DateTimeKind.Utc);
        MilestoneNotice n25 = m.NextMilestone(7200, t0);            // 25%
        Check("25% announced", n25 == null ? -1 : n25.Percent, 25);
        CheckTrue("25% message is the 'great start' line", n25.Message.Contains("Great start"));
        Check("the same milestone is not announced twice", m.NextMilestone(7200, t0.AddSeconds(5)), null);
        Check("a second crossing is held by the cooldown", m.NextMilestone(14400, t0.AddSeconds(30)), null);
        MilestoneNotice n50 = m.NextMilestone(14400, t0.AddSeconds(601));
        Check("50% announced once the cooldown has passed", n50 == null ? -1 : n50.Percent, 50);
        MilestoneNotice n75 = m.NextMilestone(21600, t0.AddSeconds(1300));
        Check("75% announced", n75 == null ? -1 : n75.Percent, 75);
        MilestoneNotice n90 = m.NextMilestone(25920, t0.AddSeconds(2000));
        Check("90% announced", n90 == null ? -1 : n90.Percent, 90);
        Check("nothing after 90%", m.NextMilestone(28800, t0.AddSeconds(3000)), null);
        m.Reset();
        Check("after reset the first milestone can show again",
            m.NextMilestone(7200, t0.AddSeconds(4000)) != null, true);

        Console.WriteLine();
        Console.WriteLine("== 9. no milestone before the first threshold ==");
        ProductivityTarget m2 = new ProductivityTarget();
        Check("24% is not a milestone", m2.NextMilestone(6912, t0), null);

        Console.WriteLine();
        Console.WriteLine("== 10. a custom target is honoured ==");
        ProductivityTarget custom = new ProductivityTarget(14400);    // 4 hours
        Check("custom target stored", custom.TargetSeconds, 14400L);
        Check("3h59m is below a 4h target", custom.Evaluate(14340).Status, ProductivityStatus.BelowTarget);
        Check("4h is on target", custom.Evaluate(14400).Status, ProductivityStatus.OnTarget);
        CheckTrue("message uses the custom target", custom.Evaluate(14400).Message.Contains("4 hours"));

        Console.WriteLine();
        Console.WriteLine("== 11. negative input is clamped, never counted ==");
        Check("negative time evaluates as zero", target.Evaluate(-500).EligibleSeconds, 0L);
        Check("negative time is below target", target.Evaluate(-500).Status, ProductivityStatus.BelowTarget);

        Console.WriteLine();
        Console.WriteLine("== 12. duration formatting ==");
        Check("7h 20m", ProductivityTarget.FormatDuration(26400), "7h 20m");
        Check("8h", ProductivityTarget.FormatDuration(28800), "8h");
        Check("30m", ProductivityTarget.FormatDuration(1800), "30m");
        Check("8 hours target", ProductivityTarget.FormatTarget(28800), "8 hours");
        Check("1 hour target", ProductivityTarget.FormatTarget(3600), "1 hour");
        Check("40 minutes spoken", ProductivityTarget.FormatSpoken(2400), "40 minutes");
        Check("15 minutes spoken", ProductivityTarget.FormatSpoken(900), "15 minutes");
        Check("1 hour spoken", ProductivityTarget.FormatSpoken(3600), "1 hour");
        Check("9 hours spoken", ProductivityTarget.FormatSpoken(32400), "9 hours");

        Console.WriteLine();
        Console.WriteLine("== 13. the daily summary carries every required figure ==");
        string summary = target.BuildSummary(
            new DateTime(2026, 1, 1, 9, 0, 0), new DateTime(2026, 1, 1, 17, 0, 0), 28800, 26400);
        CheckTrue("has the clock-in time", summary.Contains("09:00 AM"));
        CheckTrue("has the clock-out time", summary.Contains("05:00 PM"));
        CheckTrue("has total attendance", summary.Contains("Attendance:"));
        CheckTrue("has eligible productive time", summary.Contains("Eligible productive time: 7h 20m"));
        CheckTrue("has the daily target", summary.Contains("Daily target: 8 hours"));
        CheckTrue("has the difference from target", summary.Contains("40 minutes short"));
        CheckTrue("has the resulting status", summary.Contains("Below target"));
        Check("status text when on target", ProductivityTarget.StatusText(ProductivityStatus.OnTarget), "Target met");
        Check("status text when above target", ProductivityTarget.StatusText(ProductivityStatus.AboveTarget), "Target exceeded");

        Console.WriteLine();
        Console.WriteLine("== 14. a saved result survives a round trip ==");
        string resultPath = TempFile("productivity-result.json");
        ProductivityReport saved = new ProductivityReport();
        saved.Date = "2026-01-01";
        saved.ClockIn = new DateTime(2026, 1, 1, 9, 0, 0);
        saved.ClockOut = new DateTime(2026, 1, 1, 17, 0, 0);
        saved.AttendanceSeconds = 28800;
        saved.EligibleSeconds = 28800;
        saved.TargetSeconds = 28800;
        saved.StatusText = "Target met";
        saved.Summary = "test summary";
        saved.Shown = false;
        ProductivityStore.SaveTo(resultPath, saved);
        ProductivityReport loaded = ProductivityStore.LoadFrom(resultPath);
        Check("the file loads back", loaded != null, true);
        Check("date preserved", loaded == null ? null : loaded.Date, "2026-01-01");
        Check("eligible seconds preserved", loaded == null ? -1L : loaded.EligibleSeconds, 28800L);
        Check("status preserved", loaded == null ? null : loaded.StatusText, "Target met");
        Check("the unseen flag survives so it can be delivered once", loaded == null ? null : (object)loaded.Shown, false);
        Check("a missing file loads as null", ProductivityStore.LoadFrom(resultPath + ".missing"), null);

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILURES") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }
}
