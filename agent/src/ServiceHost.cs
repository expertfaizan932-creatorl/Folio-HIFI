using System;
using System.Diagnostics;
using System.IO;
using System.ServiceProcess;
using System.Threading;

namespace WorkFolio.Agent
{
    /// <summary>
    /// The always-on half of the hybrid design. Runs as a Windows service in
    /// Session 0, where it survives sign-out, and keeps the interactive agent
    /// alive and reachable:
    ///
    ///   - if no agent process is running, it launches one into the signed-in
    ///     user's session, so remote commands keep being polled even after the
    ///     employee closes the tray window;
    ///   - it answers the tray's control pipe (status / restart);
    ///   - it writes a heartbeat to the audit log.
    ///
    /// It deliberately does NOT capture the screen or keyboard: a Session-0
    /// service cannot see the user's desktop, and pretending otherwise is how
    /// monitoring software ends up recording nothing while looking installed.
    /// </summary>
    internal sealed class FolioAgentService : ServiceBase
    {
        private ServiceRuntime _runtime;

        public FolioAgentService()
        {
            ServiceName = ServiceInstaller.ServiceName;
            CanStop = true;
            CanShutdown = true;
        }

        protected override void OnStart(string[] args)
        {
            _runtime = new ServiceRuntime();
            _runtime.Start();
        }

        protected override void OnStop()
        {
            if (_runtime != null)
            {
                _runtime.Shutdown();
                _runtime = null;
            }
        }

        protected override void OnShutdown()
        {
            OnStop();
            base.OnShutdown();
        }

        /// <summary>The agent binary sits next to the service binary in the install folder.</summary>
        public static string ResolveAgentPath()
        {
            string dir = AppDomain.CurrentDomain.BaseDirectory;
            return Path.Combine(dir, "WorkFolioAgent.exe");
        }

        public static bool AgentProcessRunning()
        {
            try
            {
                return Process.GetProcessesByName("WorkFolioAgent").Length > 0;
            }
            catch (Exception)
            {
                return false;
            }
        }
    }

    /// <summary>The service body, separated from the ServiceBase plumbing so the
    /// maintenance loop can be reasoned about (and later tested) on its own.</summary>
    internal sealed class ServiceRuntime : IDisposable
    {
        private const int TickSeconds = 30;
        private const int HeartbeatTicks = 20;

        private AgentIpcServer _ipc;
        private Thread _maintenance;
        private volatile bool _stop;
        private string _agentPath;

        public string AgentPath { get { return _agentPath; } }

        public void Start()
        {
            _agentPath = FolioAgentService.ResolveAgentPath();
            AuditLog.Append("Service started. Agent path: " + _agentPath);
            _ipc = new AgentIpcServer(AgentIpc.DefaultPipeName, Handle);
            _maintenance = new Thread(Maintain);
            _maintenance.IsBackground = true;
            _maintenance.Name = "service-maintenance";
            _maintenance.Start();
        }

        /// <summary>Answers one control-pipe request.</summary>
        public string Handle(string request)
        {
            string verb = (request ?? string.Empty).Trim().ToUpperInvariant();
            if (verb == AgentIpc.Ping) return "PONG";
            if (verb == AgentIpc.Status)
            {
                return "OK;agentRunning=" + FolioAgentService.AgentProcessRunning() + ";path=" + _agentPath;
            }
            if (verb == AgentIpc.Restart)
            {
                AuditLog.Append("Service restart requested over IPC.");
                TryLaunch("(ipc)");
                return "OK;restarting";
            }
            return "ERR;unknown request";
        }

        private void Maintain()
        {
            long ticks = 0;
            while (!_stop)
            {
                try
                {
                    if (!FolioAgentService.AgentProcessRunning())
                    {
                        AuditLog.Append("Agent not running; relaunching into the active session.");
                        TryLaunch("(watchdog)");
                    }
                    else if (ticks % HeartbeatTicks == 0)
                    {
                        AuditLog.Append("Service heartbeat; agentRunning=True.");
                    }
                }
                catch (Exception ex)
                {
                    AuditLog.Append("Service maintenance error: " + ex.Message);
                }

                ticks++;
                for (int second = 0; second < TickSeconds && !_stop; second++) Thread.Sleep(1000);
            }
        }

        private void TryLaunch(string why)
        {
            try
            {
                if (string.IsNullOrEmpty(_agentPath) || !File.Exists(_agentPath))
                {
                    AuditLog.Append("Launch skipped " + why + ": no agent at " + _agentPath);
                    return;
                }
                if (!SessionLauncher.LaunchInActiveSession(_agentPath, "--silent"))
                {
                    AuditLog.Append("Launch failed " + why + ".");
                }
            }
            catch (Exception ex)
            {
                AuditLog.Append("Launch error " + why + ": " + ex.Message);
            }
        }

        public void Shutdown()
        {
            _stop = true;
            if (_ipc != null)
            {
                _ipc.Dispose();
                _ipc = null;
            }
            AuditLog.Append("Service stopped.");
        }

        public void Dispose()
        {
            Shutdown();
        }
    }
}
