using System;
using System.IO;
using System.Reflection;
using System.Runtime.InteropServices;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Makes WorkFolioAgent.exe self-contained.
    ///
    /// The built-in updater (Updater.cs) downloads a single EXE and swaps it in,
    /// so it can never carry the three WebView2 files the agent needs. If the
    /// published EXE depended on loose DLLs, an auto-update on a machine that only
    /// had the old build would leave the agent unable to start the UI.
    ///
    /// The build script embeds the two managed WebView2 assemblies and the native
    /// loader inside the EXE. This class hands the runtime those assemblies on
    /// demand:
    ///
    ///   * managed Core/WinForms assemblies are loaded straight from the embedded
    ///     resources through AssemblyResolve, and
    ///   * the native WebView2Loader.dll is dropped into a per-user folder and
    ///     added to the DLL search path before WebView2 is first touched.
    ///
    /// When the three files are already sitting beside the EXE (the installer and
    /// FZ FOLIO package still ship them), the normal probing finds them and none
    /// of this runs, so there is no behaviour change for a fresh install.
    /// </summary>
    internal static class EmbeddedRuntime
    {
        private const string CoreResource = "WorkFolio.Agent.lib.Microsoft.Web.WebView2.Core.dll";
        private const string WinFormsResource = "WorkFolio.Agent.lib.Microsoft.Web.WebView2.WinForms.dll";
        private const string LoaderResource = "WorkFolio.Agent.lib.WebView2Loader.dll";

        private static bool _initialized;
        private static readonly object Gate = new object();

        /// <summary>Call once, before any WebView2 type is first used.</summary>
        public static void EnsureAvailable()
        {
            lock (Gate)
            {
                if (_initialized) return;
                _initialized = true;
                AppDomain.CurrentDomain.AssemblyResolve += Resolve;
                EnsureNativeLoader();
            }
        }

        private static Assembly Resolve(object sender, ResolveEventArgs args)
        {
            string name;
            try { name = new AssemblyName(args.Name).Name; }
            catch (Exception) { return null; }

            if (name == "Microsoft.Web.WebView2.Core") return LoadManaged(CoreResource);
            if (name == "Microsoft.Web.WebView2.WinForms") return LoadManaged(WinFormsResource);
            return null;
        }

        private static Assembly LoadManaged(string resourceName)
        {
            byte[] bytes = Read(resourceName);
            if (bytes == null) return null;
            try { return Assembly.Load(bytes); }
            catch (Exception) { return null; }
        }

        private static void EnsureNativeLoader()
        {
            try
            {
                // A normal install: the loader is already beside the EXE.
                string besideExe = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "WebView2Loader.dll");
                if (File.Exists(besideExe)) return;

                byte[] bytes = Read(LoaderResource);
                if (bytes == null) return;

                string dir = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                    "WorkFolioAgent", "runtime");
                if (!Directory.Exists(dir)) Directory.CreateDirectory(dir);

                string target = Path.Combine(dir, "WebView2Loader.dll");
                if (!File.Exists(target) || new FileInfo(target).Length != bytes.Length)
                    File.WriteAllBytes(target, bytes);

                SetDllDirectory(dir);
            }
            catch (Exception)
            {
                // If this fails the agent still starts; only the browser UI would
                // be unavailable, which surfaces through the normal WebView2 error.
            }
        }

        private static byte[] Read(string resourceName)
        {
            try
            {
                using (Stream stream = Assembly.GetExecutingAssembly().GetManifestResourceStream(resourceName))
                {
                    if (stream == null) return null;
                    using (MemoryStream buffer = new MemoryStream())
                    {
                        stream.CopyTo(buffer);
                        return buffer.ToArray();
                    }
                }
            }
            catch (Exception)
            {
                return null;
            }
        }

        [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
        private static extern bool SetDllDirectory(string lpPathName);
    }
}
