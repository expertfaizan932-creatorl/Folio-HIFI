using System;
using System.IO;
using System.IO.Pipes;
using System.Text;
using System.Threading;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Named-pipe link between the always-on service (Session 0) and the per-user
    /// tray agent. One short line in, one short line out - enough to ask the
    /// service for its status, tell it to restart the session agent, or have the
    /// tray report that it is alive.
    ///
    /// Kept deliberately tiny. It is a control channel, not a data channel: the
    /// monitoring data itself always goes straight to the server from the tray.
    /// </summary>
    internal static class AgentIpc
    {
        public const string DefaultPipeName = "FolioAgentControl";
        public const string Status = "STATUS";
        public const string Restart = "RESTART";
        public const string Ping = "PING";
    }

    /// <summary>Serves one request at a time on a well-known pipe name.</summary>
    internal sealed class AgentIpcServer : IDisposable
    {
        private readonly string _pipeName;
        private readonly Func<string, string> _handler;
        private readonly Thread _thread;
        private volatile bool _stop;

        public AgentIpcServer(string pipeName, Func<string, string> handler)
        {
            _pipeName = pipeName;
            _handler = handler;
            _thread = new Thread(Loop);
            _thread.IsBackground = true;
            _thread.Name = "agent-ipc";
            _thread.Start();
        }

        private void Loop()
        {
            while (!_stop)
            {
                try
                {
                    using (NamedPipeServerStream server = new NamedPipeServerStream(
                        _pipeName, PipeDirection.InOut, 1, PipeTransmissionMode.Byte, PipeOptions.None))
                    {
                        server.WaitForConnection();
                        // leaveOpen: the pipe itself is disposed by the outer using.
                        // Letting both the reader and the writer close it as well
                        // makes the second close throw during unwinding.
                        using (StreamReader reader = new StreamReader(server, Encoding.UTF8, false, 1024, true))
                        using (StreamWriter writer = new StreamWriter(server, new UTF8Encoding(false), 1024, true))
                        {
                            string request = reader.ReadLine();
                            string response = _handler == null ? string.Empty : (_handler(request) ?? string.Empty);
                            writer.WriteLine(response);
                            writer.Flush();
                        }
                    }
                }
                catch (Exception)
                {
                    // A pipe can vanish between iterations; back off briefly and
                    // create a fresh one rather than ending the channel for good.
                    if (_stop) break;
                    Thread.Sleep(200);
                }
            }
        }

        public void Dispose()
        {
            _stop = true;
        }
    }

    /// <summary>Talks to a running <see cref="AgentIpcServer"/>.</summary>
    internal static class AgentIpcClient
    {
        /// <summary>Sends one line and returns the answer, or null if nobody is listening.</summary>
        public static string Send(string pipeName, string request, int timeoutMs)
        {
            try
            {
                using (NamedPipeClientStream client = new NamedPipeClientStream(
                    ".", pipeName, PipeDirection.InOut, PipeOptions.None))
                {
                    client.Connect(timeoutMs);
                    using (StreamWriter writer = new StreamWriter(client, new UTF8Encoding(false), 1024, true))
                    using (StreamReader reader = new StreamReader(client, Encoding.UTF8, false, 1024, true))
                    {
                        writer.WriteLine(request);
                        writer.Flush();
                        return reader.ReadLine();
                    }
                }
            }
            catch (Exception)
            {
                return null;
            }
        }
    }
}
