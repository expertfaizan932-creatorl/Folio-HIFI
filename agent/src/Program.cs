using System;
using System.Diagnostics;
using System.IO;
using System.Threading;
using System.Windows.Forms;

namespace WorkFolio.Agent
{
    internal static class Program
    {
        public const string Version = "1.5.1";
        private const string MutexName = "Global\\WorkFolioAgentSingleInstance";

        [STAThread]
        private static void Main(string[] args)
        {
            // Must run first: it registers the resolver that serves the embedded
            // WebView2 assemblies, so a self-updated EXE works without the loose
            // DLLs the updater cannot deliver.
            EmbeddedRuntime.EnsureAvailable();

            // Service mode and service administration run before any UI setup and
            // before the single-instance mutex, which only guards the tray app.
            if (HasFlag(args, "--service")) { RunAsService(); return; }
            if (HasFlag(args, "--install-service")) { InstallService(); return; }
            if (HasFlag(args, "--uninstall-service")) { UninstallService(); return; }

            bool created;
            using (Mutex mutex = new Mutex(true, MutexName, out created))
            {
                if (!created)
                {
                    MessageBox.Show("Folio Agent is already running. Look for it in the notification area.",
                        "Folio Agent", MessageBoxButtons.OK, MessageBoxIcon.Information);
                    return;
                }

                Application.EnableVisualStyles();
                Application.SetCompatibleTextRenderingDefault(false);

                // Per-Monitor V2 DPI awareness has to be requested before the first
                // window is created, otherwise Windows bitmap-stretches the whole UI
                // and the text goes soft on a high-resolution display.
                UiTheme.EnableDpiAwareness();

                InstallCrashGuards();

                RunAgent(args);
            }
        }

        /// <summary>
        /// Keeps one bad paint or one bad heartbeat from killing an agent that has
        /// been monitoring all day.
        ///
        /// This is the single biggest reason the app felt unstable: there was no
        /// handler at all, so any exception raised on the UI thread - a repaint of a
        /// control that had just been resized, a timer tick after the engine had
        /// shut down - terminated the process immediately, in the middle of a
        /// tracked shift, with the day-to-date total stuck at whatever the last
        /// successful flush was.
        ///
        /// Ui-thread exceptions are swallowed (after being logged) so the window
        /// survives. Exceptions on a worker thread cannot be recovered from, so they
        /// are logged and the agent is restarted cleanly: the shift ledger is
        /// persisted on every tick, so a restart resumes the day rather than
        /// resetting it.
        /// </summary>
        private static void InstallCrashGuards()
        {
            Application.SetUnhandledExceptionMode(UnhandledExceptionMode.CatchException);
            Application.ThreadException += delegate (object s, ThreadExceptionEventArgs e)
            {
                LogCrash("ui", e.Exception);
            };
            AppDomain.CurrentDomain.UnhandledException += delegate (object s, UnhandledExceptionEventArgs e)
            {
                LogCrash("fatal", e.ExceptionObject as Exception);
            };
            try
            {
                // TextWriterTraceListener throws if the folder is missing, and a
                // missing log folder must not become a second crash.
                string dir = Path.GetDirectoryName(CrashLogPath);
                if (!Directory.Exists(dir)) Directory.CreateDirectory(dir);
                Trace.Listeners.Add(new TextWriterTraceListener(CrashLogPath));
                Trace.AutoFlush = true;
            }
            catch (Exception)
            {
            }
        }

        private static readonly string CrashLogPath = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "WorkFolioAgent", "agent-crash.log");

        private static void LogCrash(string kind, Exception ex)
        {
            try
            {
                Trace.WriteLine("[" + DateTime.Now.ToString("s") + "] " + kind + ": " +
                                (ex == null ? "(no detail)" : ex.ToString()));
            }
            catch (Exception)
            {
                // Never let the crash handler itself throw.
            }
        }

        private static void RunAgent(string[] args)
        {
            AgentSettings settings = AgentSettings.Load();
            bool silent = HasFlag(args, "--silent") || HasFlag(args, "--minimized");

            if (HasFlag(args, "--install-autostart")) EnsureAutoStart(true);
            if (HasFlag(args, "--uninstall-autostart")) EnsureAutoStart(false);

            // A utility-only launch has done its job. Exiting here keeps the
            // installer and its uninstaller from waiting on a sign-in window
            // that nobody is going to fill in.
            if (HasFlag(args, "--install-autostart") || HasFlag(args, "--uninstall-autostart")) return;

            string dataFolder = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "WorkFolioAgent");

            // One session = one sign-in. Signing out tears everything down and the
            // loop asks for credentials again without restarting the process.
            while (true)
            {
                OfflineQueue queue = new OfflineQueue(dataFolder);
                ApiClient api = new ApiClient(settings, queue);
                MonitoringEngine engine = new MonitoringEngine(settings, api, queue);

                LoginForm login = new LoginForm(settings, api);

                if (login.ShowDialog() != DialogResult.OK || !login.SignedIn)
                {
                    engine.Shutdown();
                    return;
                }

                // Anything the queue is holding was recorded by whoever just
                // signed in; without this it would be uploaded on their behalf.
                queue.Owner = api.Email;

                MainForm main = new MainForm(settings, api, engine, queue);
                main.CloseToTray = true;
                main.SignOutRequested += delegate { OnSignOut(main, engine, api); };

                if (settings.StartWithWindows) EnsureAutoStart(true);

                engine.Start();

                // Supervisor talk. Starts only after a successful sign-in, because it
                // needs the agent token; stopped with the rest of the session so a
                // sign-out can never leave an audio thread running.
                TalkPlayer talk = new TalkPlayer(api, main);
                talk.Start();

                // Remote Control. Polls the owner's command queue for THIS account,
                // runs any cmd line on this screen and reports the output back.
                // Stops with the session, so a signed-out machine runs nothing.
                // The ledger makes each command id run at most once per machine.
                CommandLedger ledger = CommandLedger.ForCurrentUser();
                RemoteRunner remote = new RemoteRunner(api, delegate (string msg) { main.PostLog(msg); }, ledger);

                // Interactive terminal. Keeps one cmd.exe alive while the owner has
                // a session open on this PC; also stops with the session.
                RemoteShell shell = new RemoteShell(api, delegate (string msg) { main.PostLog(msg); });

                // Remote file manager. Serves the owner's browse session on this
                // PC's drives; also stops with the session.
                RemoteFiles files = new RemoteFiles(api, delegate (string msg) { main.PostLog(msg); });

                // Self-update. Quietly compares this build with the server's and
                // offers to install a newer one, so nobody reinstalls by hand.
                UpdateChecker updater = new UpdateChecker(api, delegate (string version, string url, string sha256) { main.ShowUpdate(version, url, sha256); });

                // The control panel is always shown after signing in, so the employee
                // can clock in and see their totals straight away. Only a background
                // launch (--silent, which is what the autostart entry uses) starts
                // hidden in the notification area.
                bool startHidden = silent && settings.StartMinimized;
                main.Show();
                main.WindowState = FormWindowState.Normal;
                if (!startHidden) main.Activate();
                if (startHidden) main.Hide();

                Application.Run(main);
                talk.Shutdown();
                talk.Dispose();
                shell.Shutdown();
                shell.Dispose();
                files.Shutdown();
                files.Dispose();
                updater.Shutdown();
                updater.Dispose();
                remote.Shutdown();
                engine.Shutdown();

                if (!main.SessionEnded) return;
            }
        }

        private static void OnSignOut(MainForm main, MonitoringEngine engine, ApiClient api)
        {
            engine.Stop();
            api.Logout();
            main.SessionEnded = true;
            main.CloseToTray = false;
            main.Close();
        }

        private static bool HasFlag(string[] args, string flag)
        {
            if (args == null) return false;
            foreach (string arg in args)
            {
                if (string.Equals(arg, flag, StringComparison.OrdinalIgnoreCase)) return true;
            }
            return false;
        }

        /// <summary>Runs the always-on service. Invoked only by the Windows service controller.</summary>
        private static void RunAsService()
        {
            System.ServiceProcess.ServiceBase.Run(new FolioAgentService());
        }

        private static void InstallService()
        {
            string error;
            if (ServiceInstaller.Install(out error))
            {
                MessageBox.Show("FZ FOLIO service installed and started.", "FZ FOLIO",
                    MessageBoxButtons.OK, MessageBoxIcon.Information);
            }
            else
            {
                MessageBox.Show("Could not install the service: " + (error ?? "unknown error") +
                                "\n\nRun this from an elevated (administrator) prompt.",
                    "FZ FOLIO", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }
        }

        private static void UninstallService()
        {
            string error;
            if (!ServiceInstaller.Uninstall(out error))
            {
                MessageBox.Show("Could not remove the service: " + (error ?? "unknown error"),
                    "FZ FOLIO", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }
        }

        /// <summary>Registers the agent under HKCU Run so it launches when the user signs in.</summary>
        private static void EnsureAutoStart(bool enable)
        {
            try
            {
                using (Microsoft.Win32.RegistryKey key =
                    Microsoft.Win32.Registry.CurrentUser.OpenSubKey(
                        "Software\\Microsoft\\Windows\\CurrentVersion\\Run", true))
                {
                    if (key == null) return;
                    if (enable)
                    {
                        string exe = Application.ExecutablePath;
                        key.SetValue("WorkFolioAgent", "\"" + exe + "\" --silent");
                    }
                    else
                    {
                        key.DeleteValue("WorkFolioAgent", false);
                    }
                }
            }
            catch (Exception ex)
            {
                Trace.WriteLine("Autostart registration failed: " + ex.Message);
            }
        }
    }
}
