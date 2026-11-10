using System;
using System.Diagnostics;
using System.Text.RegularExpressions;
using System.Threading;
using System.Windows.Automation;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Reads the address bar of the foreground browser through UI Automation.
    ///
    /// UI Automation is the only supported way to learn a Chrome/Edge tab URL from
    /// outside the process. Every call is wrapped in a timeout guard because a
    /// browser that is busy or hung must never freeze the agent.
    /// </summary>
    internal sealed class BrowserUrlReader
    {
        private const int ProbeTimeoutMs = 1200;

        /// <summary>Document address bar, in priority order. Works for Edge and IE.</summary>
        private static readonly string[] DocumentAddressNames =
        {
            "AddressEditControl", "Address and Search Bar", "Address and search bar",
            "Search or enter web address", "Address Bar"
        };

        /// <summary>Chrome exposes the omnibox as a bare Edit control on the tab strip.</summary>
        private static readonly string[] EditFallbackNames =
        {
            "Omnibox", "Search Bar", "Location", "url"
        };

        private static readonly Regex ValidHost = new Regex(
            @"^([a-z0-9]([a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}$", RegexOptions.IgnoreCase);

        public string ReadHost(IntPtr hwnd)
        {
            string url = Probe(hwnd);
            return HostFrom(url);
        }

        public static string HostFrom(string url)
        {
            if (string.IsNullOrEmpty(url)) return null;
            string text = url.Trim();

            if (text.StartsWith("view-source:", StringComparison.OrdinalIgnoreCase))
            {
                text = text.Substring("view-source:".Length);
            }

            if (text.StartsWith("http://", StringComparison.OrdinalIgnoreCase) ||
                text.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
            {
                try
                {
                    return new Uri(text).Host.ToLowerInvariant();
                }
                catch (Exception)
                {
                    return null;
                }
            }

            if (text.StartsWith("chrome://", StringComparison.OrdinalIgnoreCase) ||
                text.StartsWith("edge://", StringComparison.OrdinalIgnoreCase) ||
                text.StartsWith("about:", StringComparison.OrdinalIgnoreCase) ||
                text.StartsWith("brave://", StringComparison.OrdinalIgnoreCase) ||
                text.StartsWith("devtools://", StringComparison.OrdinalIgnoreCase))
            {
                int slash = text.IndexOf('/');
                return (slash > 0 ? text.Substring(0, slash) : text).ToLowerInvariant();
            }

            // Bare host, possibly with a path.
            int slashIndex = text.IndexOf('/');
            string hostPart = slashIndex > 0 ? text.Substring(0, slashIndex) : text;
            hostPart = hostPart.Split('?')[0];
            if (ValidHost.IsMatch(hostPart)) return hostPart.ToLowerInvariant();
            return null;
        }

        private string Probe(IntPtr hwnd)
        {
            string result = null;
            Thread worker = new Thread(new ThreadStart(delegate { result = ProbeCore(hwnd); }));
            worker.IsBackground = true;
            worker.Start();
            if (!worker.Join(ProbeTimeoutMs))
            {
                Trace.WriteLine("BrowserUrlReader: UI Automation probe timed out, skipping this cycle.");
                return null;
            }
            return result;
        }

        private string ProbeCore(IntPtr hwnd)
        {
            try
            {
                AutomationElement root = AutomationElement.FromHandle(hwnd);
                if (root == null) return null;

                // 1. Preferred: the address control on the document.
                foreach (string name in DocumentAddressNames)
                {
                    string url = ReadValue(root, new PropertyCondition(AutomationElement.NameProperty, name));
                    if (!string.IsNullOrEmpty(url)) return url;
                }

                // 2. Chrome/Firefox keep the omnibox as a plain Edit near the top of the
                //    window; take the highest one that looks like an address.
                AutomationElement edit = FindTopmostEdit(root);
                if (edit != null)
                {
                    string url = ReadValue(edit, null);
                    if (!string.IsNullOrEmpty(url)) return url;
                }

                return null;
            }
            catch (Exception ex)
            {
                Trace.WriteLine("BrowserUrlReader.ProbeCore: " + ex.Message);
                return null;
            }
        }

        private static string ReadValue(AutomationElement root, Condition condition)
        {
            try
            {
                if (root == null) return null;
                AutomationElement target = condition == null
                    ? root
                    : root.FindFirst(TreeScope.Descendants, condition);
                if (target == null) return null;

                object value = target.GetCurrentPropertyValue(ValuePattern.ValueProperty);
                if (value == null) return null;

                string text = value.ToString();
                return string.IsNullOrEmpty(text) ? null : text;
            }
            catch (Exception)
            {
                return null;
            }
        }

        private static AutomationElement FindTopmostEdit(AutomationElement root)
        {
            try
            {
                Condition condition =
                    new AndCondition(
                        new PropertyCondition(AutomationElement.ControlTypeProperty, ControlType.Edit),
                        new PropertyCondition(AutomationElement.IsOffscreenProperty, false));

                AutomationElement found = root.FindFirst(TreeScope.Descendants, condition);
                if (found != null) return found;

                foreach (string name in EditFallbackNames)
                {
                    AutomationElement named = root.FindFirst(TreeScope.Descendants,
                        new PropertyCondition(AutomationElement.NameProperty, name));
                    if (named != null) return named;
                }
            }
            catch (Exception)
            {
            }
            return null;
        }
    }
}
