using System;
using System.Collections.Generic;
using System.Drawing;
using System.Reflection;
using System.Windows.Forms;
using WorkFolio.Agent;

/// <summary>
/// UI regression checks for the HTML/WebView2 agent window.
///
/// The forms are constructed for real (so the shipping code is what is tested)
/// and inspected. The WebView2 browser is suppressed, so no browser process is
/// started and the suite runs headless; what is asserted is the structure the
/// agent builds and the UI assets it embeds.
/// </summary>
internal static class UiLayoutTest
{
    private static int _pass;
    private static int _fail;

    private static void Check(string label, bool ok, string detail)
    {
        if (ok) _pass++;
        else _fail++;
        Console.WriteLine("  " + (ok ? "PASS" : "FAIL") + "  " + label);
        if (!ok) Console.WriteLine("         " + detail);
    }

    private static void CheckTrue(string label, bool ok)
    {
        Check(label, ok, "expected true");
    }

    private static void CheckEqual(string label, object got, object want)
    {
        Check(label, Equals(got, want), "got: " + got + "  want: " + want);
    }

    public static int Run()
    {
        Console.WriteLine();
        Console.WriteLine("--- UI: WebView2 window + embedded pages ---");

        // No real browser in the test harness.
        WebViewBridge.Suppress = true;

        Type loginType = typeof(Program).Assembly.GetType("WorkFolio.Agent.LoginForm");
        Type mainType = typeof(Program).Assembly.GetType("WorkFolio.Agent.MainForm");

        CheckTrue("LoginForm type is present", loginType != null);
        CheckTrue("MainForm type is present", mainType != null);
        if (loginType == null || mainType == null) return _fail;

        // =====================================================================
        // 1. Window chrome. Both windows are frameless cards: no native caption
        //    bar, DPI scaling, and (for the dashboard) a rounded region so only
        //    the HTML card shows. Moving/closing comes from the page over the bridge.
        // =====================================================================
        Console.WriteLine();
        Console.WriteLine("== frameless card window ==");

        using (Form login = BuildLogin(loginType))
        {
            CheckTrue("LoginForm has no native border", login.FormBorderStyle == FormBorderStyle.None);
            CheckTrue("LoginForm has no native minimise button", !login.MinimizeBox);
            CheckTrue("LoginForm has no native maximise button", !login.MaximizeBox);
            CheckTrue("LoginForm has an opaque card background", login.BackColor.A == 255);
            CheckTrue("LoginForm is not colour-keyed (WebView2 input works)", login.TransparencyKey == Color.Empty);
            CheckTrue("LoginForm declares DPI scaling", login.AutoScaleMode == AutoScaleMode.Dpi);
            CheckTrue("LoginForm hosts one WebView2", CountWebView2(login) == 1);
            CheckTrue("LoginForm WebView2 fills the window", WebView2IsFill(login));
        }

        using (Form main = BuildMain(mainType))
        {
            CheckTrue("MainForm has no native border", main.FormBorderStyle == FormBorderStyle.None);
            CheckTrue("MainForm has no native minimise button", !main.MinimizeBox);
            CheckTrue("MainForm has no native maximise button", !main.MaximizeBox);
            CheckTrue("MainForm paints the widget panel background", main.BackColor == UiTheme.WidgetBackground);
            CheckTrue("MainForm avoids WinForms TransparencyKey (keeps WebView2 input)",
                main.TransparencyKey == Color.Empty);
            CheckTrue("MainForm declares DPI scaling", main.AutoScaleMode == AutoScaleMode.Dpi);
            CheckTrue("MainForm hosts one WebView2", CountWebView2(main) == 1);
            CheckTrue("MainForm WebView2 fills the window", WebView2IsFill(main));
        }

        // =====================================================================
        // 1b. Window sizing keeps the pages at their design size and never lets the
        //     card run off the screen, so it does not clip or need scrolling.
        // =====================================================================
        Console.WriteLine();
        Console.WriteLine("== window sizing ==");

        CheckEqual("portrait dashboard keeps its design size on a large screen",
            UiTheme.FitWindowSize(480, 780, 1.0, 1920, 1020), new Size(480, 780));
        CheckEqual("sign-in keeps its design size on a large screen",
            UiTheme.FitWindowSize(440, 620, 1.0, 1920, 1020), new Size(440, 620));
        CheckEqual("a short screen clamps the height",
            UiTheme.FitWindowSize(480, 780, 1.0, 1280, 600), new Size(480, 584));
        CheckEqual("a narrow work area clamps the widget width",
            UiTheme.FitWindowSize(480, 780, 1.0, 400, 600), new Size(384, 584));

        // =====================================================================
        // 2. The embedded HTML pages exist and carry the right controls.
        // =====================================================================
        Console.WriteLine();
        Console.WriteLine("== embedded UI pages ==");

        string loginHtml = WebUi.LoadLogin();
        string appHtml = WebUi.LoadApp();

        CheckTrue("login page is embedded", loginHtml != null && loginHtml.Length > 500);
        CheckTrue("app page is embedded", appHtml != null && appHtml.Length > 500);

        CheckTrue("login page has the email field", loginHtml.Contains("id=\"email\""));
        CheckTrue("login page has the password field", loginHtml.Contains("id=\"password\""));
        CheckTrue("login page posts a login message", loginHtml.Contains("'login'"));
        CheckTrue("login page has no Sign Up link", !loginHtml.Contains("Sign Up"));
        CheckTrue("login page has no demo credentials", !loginHtml.Contains("admin@gmail.com"));
        CheckTrue("login page can drag the window", loginHtml.Contains("startDrag"));
        CheckTrue("login page has window controls", loginHtml.Contains("'minimize'") && loginHtml.Contains("'close'"));

        CheckTrue("app page has the clock control", appHtml.Contains("clock-btn"));
        CheckTrue("app page has the break control", appHtml.Contains("break-btn"));
        CheckTrue("app page renders today's logs", appHtml.Contains("logs-container"));
        CheckTrue("app page has no return-to-login button", !appHtml.Contains("Return to System Login"));
        CheckTrue("app page posts a clock message", appHtml.Contains("'clockToggle'"));
        CheckTrue("app page posts a break message", appHtml.Contains("'breakToggle'"));
        CheckTrue("app page can drag the window", appHtml.Contains("startDrag"));
        CheckTrue("app page has window controls", appHtml.Contains("'minimize'") && appHtml.Contains("'close'"));

        // The brand logo is injected at load time from the embedded PNG.
        CheckTrue("login page has the logo injected", loginHtml.Contains("data:image/png;base64,"));
        CheckTrue("app page has the logo injected", appHtml.Contains("data:image/png;base64,"));

        // The dashboard is a portrait card: the window is cut to a rounded
        // rectangle, the page paints the slate-100 backdrop behind a white card.
        // The old dark 3D stand is gone.
        CheckTrue("app page paints the portrait widget background",
            appHtml.Contains("#f1f5f9") || appHtml.Contains("bg-slate-100"));
        CheckTrue("app page no longer ships the 3D stand",
            !appHtml.Contains("three-canvas-container") && !appHtml.Contains("initThree")
            && !appHtml.Contains("standGlow"));
        CheckTrue("app page has the portrait card", appHtml.Contains("max-w-md"));
        CheckTrue("app page has the design variant picker",
            appHtml.Contains("variant-modal") && appHtml.Contains("applyVariant"));
        CheckTrue("app page has the end-of-shift summary card",
            appHtml.Contains("summaryModal") && appHtml.Contains("showSummary"));
        CheckTrue("login page does not auto sign in",
            !loginHtml.Contains("autologin") && !loginHtml.Contains("FOLIO_AUTOLOGIN"));

        // =====================================================================
        // 3. Remembered credentials round-trip through DPAPI.
        // =====================================================================
        Console.WriteLine();
        Console.WriteLine("== remembered credentials ==");

        string originalEmail, originalPassword;
        CredentialStore.Load(out originalEmail, out originalPassword);

        CredentialStore.Save("someone@example.com", "s3cret!");
        string email, password;
        CredentialStore.Load(out email, out password);
        CheckEqual("stored email round-trips", email, "someone@example.com");
        CheckEqual("stored password round-trips", password, "s3cret!");

        string onDisk = ReadCredentialFile();
        CheckTrue("password is not stored in plain text",
            onDisk == null || !onDisk.Contains("s3cret!"));

        // Restore whatever the machine already had so the suite has no side effect.
        if (!string.IsNullOrEmpty(originalEmail) || !string.IsNullOrEmpty(originalPassword))
            CredentialStore.Save(originalEmail, originalPassword);
        else
            CredentialStore.Clear();

        Console.WriteLine();
        Console.WriteLine(_fail == 0
            ? "ALL PASS: " + _pass + " UI checks"
            : "UI FAILURES: " + _fail + " of " + (_pass + _fail));
        return _fail;
    }

    // -------------------------------------------------------------- helpers

    private static string ReadCredentialFile()
    {
        try
        {
            string path = AgentSettings.StatePathFor("credentials.dat");
            return System.IO.File.Exists(path) ? System.IO.File.ReadAllText(path) : null;
        }
        catch (Exception)
        {
            return null;
        }
    }

    private static int CountWebView2(Control root)
    {
        int count = 0;
        foreach (Control c in Descendants(root))
        {
            if (c.GetType().Name == "WebView2") count++;
        }
        return count;
    }

    private static bool WebView2IsFill(Control root)
    {
        foreach (Control c in Descendants(root))
        {
            if (c.GetType().Name == "WebView2") return c.Dock == DockStyle.Fill;
        }
        return false;
    }

    private static IEnumerable<Control> Descendants(Control root)
    {
        foreach (Control c in root.Controls)
        {
            yield return c;
            foreach (Control d in Descendants(c)) yield return d;
        }
    }

    private static Form BuildLogin(Type type)
    {
        AgentSettings settings = new AgentSettings();
        Type apiType = typeof(Program).Assembly.GetType("WorkFolio.Agent.ApiClient");
        object api = MakeApi(apiType, settings);
        ConstructorInfo ctor = type.GetConstructor(new[] { typeof(AgentSettings), apiType });
        if (ctor == null) throw new InvalidOperationException("LoginForm constructor not found");
        return (Form)ctor.Invoke(new object[] { settings, api });
    }

    private static Form BuildMain(Type type)
    {
        AgentSettings settings = new AgentSettings();
        Type apiType = typeof(Program).Assembly.GetType("WorkFolio.Agent.ApiClient");
        Type engineType = typeof(Program).Assembly.GetType("WorkFolio.Agent.MonitoringEngine");
        Type queueType = typeof(Program).Assembly.GetType("WorkFolio.Agent.OfflineQueue");
        string dataFolder = System.IO.Path.Combine(System.IO.Path.GetTempPath(),
            "wf-ui-test-" + Guid.NewGuid().ToString("N"));
        System.IO.Directory.CreateDirectory(dataFolder);

        object api = MakeApi(apiType, settings);
        object queue = Activator.CreateInstance(queueType, new object[] { dataFolder });
        object engine = Activator.CreateInstance(engineType, new object[] { settings, api, queue });
        ConstructorInfo ctor = type.GetConstructor(
            new[] { typeof(AgentSettings), apiType, engineType, queueType });
        if (ctor == null) throw new InvalidOperationException("MainForm constructor not found");
        return (Form)ctor.Invoke(new object[] { settings, api, engine, queue });
    }

    /// <summary>An ApiClient pointed at an unroutable host: no traffic, no side effects.</summary>
    private static object MakeApi(Type apiType, AgentSettings settings)
    {
        settings.ServerUrl = "https://127.0.0.1:9";
        return Activator.CreateInstance(apiType, new object[] { settings, null });
    }
}
