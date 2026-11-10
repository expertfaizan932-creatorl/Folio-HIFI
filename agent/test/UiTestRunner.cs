using System;

/// <summary>
/// Entry point for the agent test build.
///
/// ShiftClockTest exercises the persisted ledger, PowerMonitorTest the pause
/// signals; UiLayoutTest builds the real forms and inspects them. All three need
/// the agent's internal types, so every agent source file is compiled into this
/// one test executable with /main: set to this class.
/// </summary>
internal static class UiTestRunner
{
    [STAThread]
    public static int Main(string[] args)
    {
        int failures = 0;

        failures += ShiftClockTest.Run();
        failures += PowerMonitorTest.Run();
        failures += ProductivityTargetTest.Run();
        failures += ClockSyncTest.Run();
        failures += ConnectionStateTest.Run();
        failures += AgentSettingsTest.Run();
        failures += UpdateVerifierTest.Run();
        failures += CommandLedgerTest.Run();
        failures += IpcTest.Run();
        failures += RemoteFsOpsTest.Run();

        // The UI checks construct real windows, so they need a message loop and
        // a display. In a headless session there is nothing to inspect.
        if (System.Environment.UserInteractive && HasDesktop())
        {
            failures += UiLayoutTest.Run();
        }
        else
        {
            Console.WriteLine();
            Console.WriteLine("--- UI layout and window chrome ---");
            Console.WriteLine("  SKIP  no interactive desktop in this session");
        }

        Console.WriteLine();
        Console.WriteLine(failures == 0 ? "OK: all suites passed" : "FAILED: " + failures + " check(s)");
        return failures == 0 ? 0 : 1;
    }

    private static bool HasDesktop()
    {
        try
        {
            return !string.IsNullOrEmpty(System.Windows.Forms.SystemInformation.UserInteractive
                ? System.Windows.Forms.SystemInformation.UserName
                : null);
        }
        catch (Exception)
        {
            return false;
        }
    }
}