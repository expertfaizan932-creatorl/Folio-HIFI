using System;
using System.IO;
using System.Threading;
using WorkFolio.Agent;

/// <summary>
/// Focused checks on ShiftClock. Compiled together with the real ShiftClock.cs,
/// so this exercises shipping code rather than a copy of it.
///
/// The cases that matter most are 6, 9, 10 and 11: they cover the ledger being
/// wiped, which is why a report could show far less time than had actually been
/// worked.
/// </summary>
internal static class ShiftClockTest
{
    private const string Owner = "ali@folio.test";

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

    /// <summary>True when got is within tolerance of want, for elapsed-time checks.</summary>
    private static void CheckNear(string label, long got, long want, long tolerance)
    {
        bool ok = Math.Abs(got - want) <= tolerance;
        if (ok) _pass++;
        else _fail++;
        Console.WriteLine("  " + (ok ? "PASS" : "FAIL") + "  " + label);
        if (!ok) Console.WriteLine("         got:  " + got + Environment.NewLine + "         want: " + want + " +/- " + tolerance);
    }

    private static string TempFile(string name)
    {
        string dir = Path.Combine(Path.GetTempPath(), "wf-clock-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(dir);
        return Path.Combine(dir, name);
    }

    /// <summary>
    /// Runs every ShiftClock case and returns how many failed. Called by
    /// UiTestRunner when the whole agent is compiled into one test executable.
    /// </summary>
    public static int Run()
    {
        _pass = 0;
        _fail = 0;
        Console.WriteLine();
        Console.WriteLine("--- shift clock ledger ---");
        Console.WriteLine("== 1. worked time accrues while working ==");
        string p1 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p1, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1200);
            CheckNear("~1s of active work counted", c.ActiveSeconds, 1, 1);
            Check("nothing paused yet", c.PausedSeconds, (long)0);
        }

        Console.WriteLine();
        Console.WriteLine("== 2. a folded lid freezes worked time and banks it as paused ==");
        string p2 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p2, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1000);
            c.SetBucket(true, false, false, true);   // lid shuts
            long activeAtFold = c.ActiveSeconds;
            Thread.Sleep(2000);                        // folded, ticking as usual
            CheckNear("worked time did not move while folded", c.ActiveSeconds, activeAtFold, 0);
            Check("folded time went to paused", c.PausedSeconds >= 2, true);

            c.SetBucket(true, false, false, false);   // lid opens
            long pausedAtResume = c.PausedSeconds;
            Thread.Sleep(1000);
            Check("work resumed", c.ActiveSeconds > activeAtFold, true);
            Check("paused time stayed put", c.PausedSeconds, pausedAtResume);
        }

        Console.WriteLine();
        Console.WriteLine("== 3. the suspend gap is booked as paused, not as work ==");
        // This is the regression that matters: Windows keeps the performance
        // counter running through sleep, so on resume the unswept gap must land
        // in paused. Switching the bucket before flushing would book a 2h fold as
        // 2h of work.
        string p3 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p3, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(500);
            c.SetBucket(true, false, false, true);
            long pausedAtFold = c.PausedSeconds;         // flush to a known point
            long activeBefore = c.ActiveSeconds;
            Thread.Sleep(2500);                            // machine "asleep", no ticks
            c.SetBucket(true, false, false, false);       // resume
            Check("no work booked across the suspend", c.ActiveSeconds, activeBefore);
            Check("the whole gap is paused", c.PausedSeconds - pausedAtFold >= 2, true);
        }

        Console.WriteLine();
        Console.WriteLine("== 4. a break keeps running even if the lid shuts ==");
        string p4 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p4, Owner))
        {
            c.SetBucket(true, false, true, false);        // on break, lid open
            Thread.Sleep(1000);
            c.SetBucket(true, false, true, true);         // lid shuts mid-break
            long breakAtFold = c.BreakSeconds;
            Thread.Sleep(1500);
            Check("break kept running while folded", c.BreakSeconds > breakAtFold, true);
            Check("folded time not also counted as paused", c.PausedSeconds, (long)0);
        }

        Console.WriteLine();
        Console.WriteLine("== 5. worked time excludes paused and break ==");
        string p5 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p5, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1000);
            c.SetBucket(true, false, false, true);
            Thread.Sleep(1500);
            long worked = c.WorkedSeconds;
            Check("worked = active + idle only", worked, c.ActiveSeconds + c.IdleSeconds);
            Check("worked excludes the folded stretch", worked < 2, true);
        }

        Console.WriteLine();
        Console.WriteLine("== 6. totals survive a restart on the same day, same employee ==");
        // The agent is restarted constantly - a reboot, an update, a crash, signing
        // out and back in. MonitoringEngine used to call Reset() on launch, which
        // threw this away and left the day's work showing as zero.
        string p6 = TempFile("shift-clock.txt");
        long carried;
        using (ShiftClock c = new ShiftClock(p6, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1200);
            carried = c.ActiveSeconds;
        }
        using (ShiftClock c = new ShiftClock(p6, Owner))
        {
            CheckNear("active time restored from disk", c.ActiveSeconds, carried, 1);
            Check("it knows it restored", c.RestoredFromDisk, true);
        }

        Console.WriteLine();
        Console.WriteLine("== 7. yesterday's totals are not carried into today ==");
        string p7 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p7, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1200);
        }
        // Rewrite the stamp as yesterday, keeping the totals.
        string[] parts = File.ReadAllText(p7).Split('|');
        parts[5] = DateTime.Today.AddDays(-1).ToString("yyyy-MM-dd");
        File.WriteAllText(p7, string.Join("|", parts));
        using (ShiftClock c = new ShiftClock(p7, Owner))
        {
            Check("stale day discarded", c.ActiveSeconds, (long)0);
            Check("nothing else carried over", c.IdleSeconds + c.BreakSeconds + c.PausedSeconds, (long)0);
        }

        Console.WriteLine();
        Console.WriteLine("== 8. a corrupt state file does not stop the agent ==");
        string p8 = TempFile("shift-clock.txt");
        File.WriteAllText(p8, "not|a|numbers|at|all|x|x");
        using (ShiftClock c = new ShiftClock(p8, Owner))
        {
            Check("starts from zero", c.ActiveSeconds, (long)0);
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1000);
            Check("still counts normally afterwards", c.ActiveSeconds >= 1, true);
        }

        Console.WriteLine();
        Console.WriteLine("== 9. a second shift adds to the day instead of replacing it ==");
        // Two clock-ins in one day must not start from zero. This is the exact
        // behaviour that made a split shift report only its last half.
        string p9 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p9, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1200);
        }
        long afterMorning;
        using (ShiftClock c = new ShiftClock(p9, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1200);
            afterMorning = c.ActiveSeconds;
            Check("morning is still there after the restart", afterMorning >= 2, true);
        }

        Console.WriteLine();
        Console.WriteLine("== 10. the ledger is scoped to one employee ==");
        // On a shared machine the state file is per user, not per machine. Handing
        // the previous person's afternoon to whoever signs in next would credit
        // them with time they never worked.
        string p10 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p10, "first@folio.test"))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1200);
        }
        using (ShiftClock c = new ShiftClock(p10, "second@folio.test"))
        {
            Check("a different employee inherits nothing", c.ActiveSeconds, (long)0);
            Check("and is not told it restored", c.RestoredFromDisk, false);
        }

        Console.WriteLine();
        Console.WriteLine("== 11. the real sign-in flow: adopt the owner after construction ==");
        // The agent is built before the login dialog is shown, so the owner is
        // only known afterwards. That is the path production actually takes.
        string p11 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p11, "ali@folio.test"))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1200);
        }
        using (ShiftClock c = new ShiftClock(p11, string.Empty))
        {
            Check("unknown owner starts empty", c.ActiveSeconds, (long)0);
            c.AdoptOwner(Owner);
            CheckNear("adopting the signed-in employee restores the day", c.ActiveSeconds, carried, 2);
            Check("and reports the restore", c.RestoredFromDisk, true);
        }

        Console.WriteLine();
        Console.WriteLine("== 12. a ledger left by an older build is not adopted ==");
        // Version 1 files have no owner field. There is no way to tell whose time
        // that was, so it must be discarded rather than guessed at.
        string p12 = TempFile("shift-clock.txt");
        File.WriteAllText(p12, "1|500|100|0|0|" + DateTime.Today.ToString("yyyy-MM-dd") + "|");
        using (ShiftClock c = new ShiftClock(p12, Owner))
        {
            Check("legacy file ignored", c.ActiveSeconds, (long)0);
        }

        Console.WriteLine();
        Console.WriteLine("== 13. FlushToDisk banks the last seconds immediately ==");
        string p13 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p13, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1100);
            c.FlushToDisk();
            string[] saved = File.ReadAllText(p13).Split('|');
            Check("file written without waiting for the 30s timer", long.Parse(saved[1]) >= 1, true);
            Check("owner recorded", saved[6], Owner);
        }

        Console.WriteLine();
        Console.WriteLine("== 14. a suspend the agent never heard about is not paid as work ==");
        // The regression for the reported bug. Windows keeps the performance counter
        // running through sleep, so when the power notification is missed - a
        // registration that failed, a resume the machine swallowed - the entire gap
        // was added to active. A folded lid turned into an hour of paid work. The
        // clock has to refuse that on its own, with no help from PowerMonitor.
        long maxGap = TimeSpan.TicksPerSecond * 3 / 2;
        string p14 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p14, Owner, maxGap))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(3000);                 // asleep: no Tick, no SetBucket, no event
            Check("no work booked across an unnoticed sleep", c.ActiveSeconds, (long)0);
            Check("the sleep is filed as laptop-away time", c.PausedSeconds >= 2, true);
        }

        Console.WriteLine("  -- gaps short enough to be ticking are still work --");
        string p14b = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p14b, Owner, maxGap))
        {
            c.SetBucket(true, false, false, false);
            for (int i = 0; i < 8; i++) { Thread.Sleep(200); c.Tick(); }
            Check("ordinary 1 Hz ticks still count as work", c.ActiveSeconds >= 1, true);
            Check("and none of them were paused", c.PausedSeconds, (long)0);
        }

        Console.WriteLine();
        Console.WriteLine("== 15. an unreadable ledger picks the server's totals back up ==");
        // A wiped profile or a corrupt file left the panel on 00:00:00 for the rest
        // of the day while the server still remembered the morning, which is what
        // looked like the timer resetting.
        string p15 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p15, Owner))
        {
            c.AdoptBaseline(3600, 600, 900, 1800);
            Check("worked time came back", c.WorkedSeconds, (long)4200);
            Check("break came back", c.BreakSeconds, (long)900);
            Check("laptop-away came back", c.PausedSeconds, (long)1800);

            // A later reading must never talk the day backwards, whatever it says.
            c.AdoptBaseline(10, 0, 0, 0);
            Check("a smaller reading is ignored", c.WorkedSeconds, (long)4200);
            Check("and so is a smaller paused total", c.PausedSeconds, (long)1800);
        }

        Console.WriteLine();
        Console.WriteLine("== 16. the server never overwrites a ledger read back from disk ==");
        string p16 = TempFile("shift-clock.txt");
        using (ShiftClock c = new ShiftClock(p16, Owner))
        {
            c.SetBucket(true, false, false, false);
            Thread.Sleep(1200);
        }
        using (ShiftClock c = new ShiftClock(p16, Owner))
        {
            long restored = c.ActiveSeconds;
            c.AdoptBaseline(3600, 600, 900, 1800);
            Check("restored active time is left alone", c.ActiveSeconds, restored);
            Check("nothing invented on top of it", c.IdleSeconds, (long)0);
        }

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILURES") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }
}