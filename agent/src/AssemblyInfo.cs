using System.Reflection;
using System.Resources;
using System.Runtime.InteropServices;

// The agent shipped with no assembly attributes at all, so Windows showed
// "0.0.0.0" in File > Properties and the task manager offered no version to
// quote when an employee reported a problem. These attributes make the EXE
// self-describing; keep them in step with Program.Version.
[assembly: AssemblyTitle("Folio Agent")]
[assembly: AssemblyDescription("Workfolio time tracking agent")]
[assembly: AssemblyCompany("Workfolio")]
[assembly: AssemblyProduct("Folio Agent")]
[assembly: AssemblyCopyright("Workfolio")]
[assembly: AssemblyTrademark("")]
[assembly: AssemblyCulture("")]

[assembly: ComVisible(false)]
[assembly: NeutralResourcesLanguage("en-US")]

[assembly: AssemblyVersion("1.5.1.0")]
[assembly: AssemblyFileVersion("1.5.1.0")]
[assembly: AssemblyInformationalVersion("1.5.1")]