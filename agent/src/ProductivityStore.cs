using System;
using System.IO;
using System.Text;
using System.Web.Script.Serialization;

namespace WorkFolio.Agent
{
    /// <summary>
    /// A clocked-out day's result, kept on disk so the summary is still there to
    /// show if the app closes the instant the shift ends. <see cref="Shown"/>
    /// tracks whether the employee has actually seen it, so the pending result
    /// is delivered exactly once.
    /// </summary>
    internal sealed class ProductivityReport
    {
        public string Date = "";
        public DateTime ClockIn;
        public DateTime ClockOut;
        public long AttendanceSeconds;
        public long EligibleSeconds;
        public long TargetSeconds;
        public string StatusText = "";
        public string Summary = "";
        public bool Shown;
    }

    /// <summary>
    /// Disk storage for the last productivity result. One small JSON file,
    /// per-user (via <see cref="AgentSettings.StatePathFor"/>) so a shared machine
    /// keeps each employee's result separate and a read-only install folder falls
    /// back to the user profile instead of failing.
    /// </summary>
    internal static class ProductivityStore
    {
        private static readonly JavaScriptSerializer Json = new JavaScriptSerializer();

        private static string DefaultPath
        {
            get { return AgentSettings.StatePathFor("productivity-result.json"); }
        }

        public static void Save(ProductivityReport report)
        {
            SaveTo(DefaultPath, report);
        }

        public static ProductivityReport Load()
        {
            return LoadFrom(DefaultPath);
        }

        public static void Clear()
        {
            try
            {
                string path = DefaultPath;
                if (File.Exists(path)) File.Delete(path);
            }
            catch (Exception)
            {
            }
        }

        /// <summary>Path-injected save, so the store can be unit-tested without touching the profile.</summary>
        public static void SaveTo(string path, ProductivityReport report)
        {
            if (report == null || string.IsNullOrEmpty(path)) return;
            try
            {
                string dir = Path.GetDirectoryName(path);
                if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir)) Directory.CreateDirectory(dir);
                File.WriteAllText(path, Json.Serialize(report), Encoding.UTF8);
            }
            catch (Exception)
            {
                // A result that cannot be persisted must never break the clock-out.
            }
        }

        public static ProductivityReport LoadFrom(string path)
        {
            try
            {
                if (string.IsNullOrEmpty(path) || !File.Exists(path)) return null;
                return Json.Deserialize<ProductivityReport>(File.ReadAllText(path, Encoding.UTF8));
            }
            catch (Exception)
            {
                return null;
            }
        }
    }
}
