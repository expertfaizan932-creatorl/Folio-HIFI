using System;
using System.IO;
using System.Text;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Append-only audit trail for anything the agent does on the owner's behalf:
    /// remote commands received and their outcome, service lifecycle, update
    /// attempts. Written under the per-user state folder next to the shift ledger
    /// so support can read one file to see what happened on a machine.
    ///
    /// Best-effort on purpose: an audit that cannot be written must never stop the
    /// work it is describing.
    /// </summary>
    internal static class AuditLog
    {
        public const string FileName = "agent-audit.log";

        public static void Append(string message)
        {
            try
            {
                string path = AgentSettings.StatePathFor(FileName);
                string line = "[" + DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + "] " + message + Environment.NewLine;
                File.AppendAllText(path, line, new UTF8Encoding(false));
            }
            catch (Exception)
            {
                // Never let logging be the reason work fails.
            }
        }

        public static string Path
        {
            get { return AgentSettings.StatePathFor(FileName); }
        }
    }
}
