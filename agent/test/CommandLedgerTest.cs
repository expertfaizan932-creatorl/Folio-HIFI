using System;
using System.IO;
using WorkFolio.Agent;

/// <summary>
/// Checks the idempotency ledger. Remote commands must run exactly once even if
/// the server hands the same id back twice, and that guarantee has to survive a
/// restart of the agent.
/// </summary>
internal static class CommandLedgerTest
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

    private static string TempPath()
    {
        string dir = Path.Combine(Path.GetTempPath(), "wf-ledger-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(dir);
        return Path.Combine(dir, "command-ledger.json");
    }

    public static int Run()
    {
        _pass = 0;
        _fail = 0;
        Console.WriteLine();
        Console.WriteLine("--- remote command idempotency ---");

        Console.WriteLine("== 1. a command id is claimed exactly once ==");
        string path = TempPath();
        CommandLedger ledger = new CommandLedger(path);
        Check("starts empty", ledger.Count, 0);
        CheckTrue("first claim succeeds", ledger.TryBegin(42));
        Check("the id is now known", ledger.HasProcessed(42), true);
        Check("a second claim is refused", ledger.TryBegin(42), false);
        CheckTrue("a different id is independent", ledger.TryBegin(43));

        Console.WriteLine();
        Console.WriteLine("== 2. nonsense ids are never claimed ==");
        Check("id 0 refused", ledger.TryBegin(0), false);
        Check("negative id refused", ledger.TryBegin(-7), false);
        Check("id 0 not remembered", ledger.HasProcessed(0), false);

        Console.WriteLine();
        Console.WriteLine("== 3. the memory survives a restart ==");
        ledger.Save();
        CommandLedger reloaded = new CommandLedger(path);
        Check("reloaded knows the id", reloaded.HasProcessed(42), true);
        Check("and refuses to run it again", reloaded.TryBegin(42), false);
        Check("a fresh id still works", reloaded.TryBegin(99), true);

        Console.WriteLine();
        Console.WriteLine("== 4. the list is capped so the file cannot grow forever ==");
        string capped = TempPath();
        CommandLedger many = new CommandLedger(capped);
        for (int i = 1; i <= CommandLedger.MaxRemembered + 20; i++) many.TryBegin(i);
        Check("count is capped", many.Count, CommandLedger.MaxRemembered);
        Check("the oldest has been forgotten", many.HasProcessed(1), false);
        Check("the newest is remembered", many.HasProcessed(CommandLedger.MaxRemembered + 20), true);

        Console.WriteLine();
        Console.WriteLine("== 5. a corrupt ledger still lets commands run ==");
        string broken = TempPath();
        File.WriteAllText(broken, "{ this is not json ]");
        CommandLedger recovered = new CommandLedger(broken);
        Check("loads as empty", recovered.Count, 0);
        CheckTrue("and still accepts commands", recovered.TryBegin(5));

        Console.WriteLine();
        Console.WriteLine("== 6. Clear empties the ledger ==");
        recovered.Clear();
        Check("cleared", recovered.Count, 0);
        Check("previously seen id is runnable again", recovered.TryBegin(5), true);

        Console.WriteLine();
        Console.WriteLine((_fail == 0 ? "ALL PASS" : "FAILURES") + ": " + _pass + " passed, " + _fail + " failed");
        return _fail;
    }
}
