using System;
using System.Drawing;
using System.Runtime.InteropServices;
using System.Windows.Forms;
using Microsoft.Web.WebView2.WinForms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// One place for every colour and every sizing decision in the agent UI.
    ///
    /// Two bugs made the old window look broken and both are fixed by design
    /// rather than by patching call sites:
    ///
    /// 1. Black boxes behind the logo and text. WinForms makes an unset
    ///    Control.BackColor inherit the *parent's* BackColor. Both forms were
    ///    built on a near-black canvas (#100c22 / #0e0d10), so every label that
    ///    did not set its own BackColor painted a solid black rectangle and hid
    ///    the spectrum wash behind it. PictureBox is worse: it is opaque, so the
    ///    transparent margin of the logo PNG filled in as a black square.
    ///    Fix: light surfaces, and every control states its BackColor explicitly.
    ///    Use <see cref="On"/> for controls that must show the surface beneath.
    ///
    /// 2. Text clipped at the bottom edge. No form set AutoScaleMode, so on a
    ///    125% or 150% display the fonts scaled up while the hardcoded pixel
    ///    heights did not, and every label with AutoSize=false cut off its
    ///    descenders. Fix: AutoScaleMode.Dpi plus <see cref="FitHeight"/>, which
    ///    sizes a label from real font metrics instead of a magic number.
    ///
    /// The palette is taken from the brand logo, which is monochrome charcoal on
    /// a light ground, with the indigo/violet accent the website uses for primary
    /// actions so the desktop app and the web app read as one product.
    /// </summary>
    internal static class UiTheme
    {
        // ---- surfaces ---------------------------------------------------------
        /// <summary>Window canvas. Light, so nothing has to fight a dark backdrop.</summary>
        public static readonly Color AppBg = Color.FromArgb(244, 243, 247);
        /// <summary>Card / panel surface.</summary>
        public static readonly Color Surface = Color.White;
        /// <summary>Quietly recessed surface, for rows and inputs.</summary>
        public static readonly Color SurfaceAlt = Color.FromArgb(250, 249, 252);
        public static readonly Color Border = Color.FromArgb(230, 228, 236);
        public static readonly Color BorderStrong = Color.FromArgb(214, 211, 222);

        // ---- text -------------------------------------------------------------
        /// <summary>Warm near-black, matching the charcoal in the logo.</summary>
        public static readonly Color TextPrimary = Color.FromArgb(28, 26, 32);
        public static readonly Color TextMuted = Color.FromArgb(110, 106, 120);
        public static readonly Color TextSubtle = Color.FromArgb(154, 150, 166);

        /// <summary>Sign-in card colour (#0b1020), matching login.html.</summary>
        public static readonly Color LoginCard = Color.FromArgb(11, 16, 32);

        // ---- brand ------------------------------------------------------------
        /// <summary>Deep indigo, the website's primary action colour.</summary>
        public static readonly Color Accent = Color.FromArgb(49, 16, 120);
        /// <summary>Violet, the lighter end of the brand gradient.</summary>
        public static readonly Color AccentBright = Color.FromArgb(114, 55, 245);
        public static readonly Color AccentPressed = Color.FromArgb(37, 12, 90);
        public static readonly Color AccentSoft = Color.FromArgb(239, 235, 255);
        public static readonly Color AccentRing = Color.FromArgb(206, 197, 250);

        // ---- states -----------------------------------------------------------
        /// <summary>Break is a state, not an error, so it stays amber not red.</summary>
        public static readonly Color Amber = Color.FromArgb(180, 83, 9);
        public static readonly Color AmberSoft = Color.FromArgb(253, 243, 227);
        public static readonly Color AmberRing = Color.FromArgb(252, 227, 192);
        public static readonly Color Danger = Color.FromArgb(190, 42, 42);
        public static readonly Color Success = Color.FromArgb(5, 150, 105);

        // ---- metrics ----------------------------------------------------------
        public const int Pad = 28;
        public const int CardRadius = 12;
        public const int RowRadius = 8;

        /// <summary>Call from Main before any window exists.</summary>
        public static void EnableDpiAwareness()
        {
            // Per-Monitor V2 keeps text crisp when a laptop is dragged between a
            // 150% panel and a 100% external screen. Older Windows falls back to
            // plain SetProcessDPIAware; if even that is unavailable the OS just
            // bitmap-stretches the app, which is what we live with.
            try
            {
                if (SetProcessDpiAwarenessContext(new IntPtr(-4))) return;
            }
            catch (Exception)
            {
            }
            try
            {
                // Pre-10.20 systems, or a context value the OS rejected.
                SetProcessDPIAware();
            }
            catch (Exception)
            {
            }
        }

        /// <summary>Declares this form as DPI-scaled so its bounds scale with the display.</summary>
        public static void ApplyDpi(Form form)
        {
            form.AutoScaleMode = AutoScaleMode.Dpi;
            form.AutoScaleDimensions = new SizeF(96F, 96F);
        }

        /// <summary>
        /// Sizes a frameless window to the page's design pixels and centres it, so
        /// the window is exactly the size the HTML was authored for instead of being
        /// stretched up by the display's DPI. The work area is still respected as a
        /// ceiling: on a small screen the window shrinks rather than run off the
        /// edge. The page is zoomed back by <see cref="ApplyPageZoom"/> so its CSS
        /// viewport still measures the design size.
        /// </summary>
        public static void FitWindow(Form form, int logicalWidth, int logicalHeight)
        {
            if (form == null || !form.IsHandleCreated) return;
            try
            {
                Rectangle wa = Screen.FromControl(form).WorkingArea;
                Size size = FitWindowSize(logicalWidth, logicalHeight, 1.0, wa.Width, wa.Height);
                form.MinimumSize = Size.Empty;
                form.ClientSize = size;
                form.Location = new Point(
                    wa.Left + Math.Max(0, (wa.Width - size.Width) / 2),
                    wa.Top + Math.Max(0, (wa.Height - size.Height) / 2));
            }
            catch (Exception)
            {
            }
        }

        /// <summary>The monitor scale (1.0 at 96 dpi, 1.5 at 150%).</summary>
        public static double WindowScale(Form form)
        {
            if (form == null) return 1.0;
            try
            {
                int dpi = 0;
                try { dpi = (int)GetDpiForWindow(form.Handle); }
                catch (Exception) { }
                if (dpi <= 0) dpi = form.DeviceDpi;
                if (dpi <= 0) dpi = 96;
                return dpi / 96.0;
            }
            catch (Exception)
            {
                return 1.0;
            }
        }

        /// <summary>
        /// Counteracts the monitor DPI on a docked WebView2. The window is sized to
        /// the design pixels, but WebView2 would render the page scaled up by the
        /// display DPI; dividing the zoom by that scale keeps the CSS viewport equal
        /// to the design size, so the layout is identical on 100% and 150% screens.
        /// </summary>
        public static void ApplyPageZoom(WebView2 view, Form form)
        {
            if (view == null || form == null) return;
            try
            {
                double scale = WindowScale(form);
                if (scale > 0.1) view.ZoomFactor = (float)(1.0 / scale);
            }
            catch (Exception)
            {
            }
        }

        /// <summary>
        /// Pure part of <see cref="FitWindow"/>: the logical size scaled by the
        /// monitor DPI, shrunk to the work area when it would not fit. Kept apart so
        /// the arithmetic can be tested without a desktop.
        /// </summary>
        public static Size FitWindowSize(int logicalWidth, int logicalHeight,
                                         double scale, int workWidth, int workHeight)
        {
            if (scale <= 0) scale = 1;
            int w = (int)Math.Round(logicalWidth * scale);
            int h = (int)Math.Round(logicalHeight * scale);
            int maxW = workWidth - 16;
            int maxH = workHeight - 16;
            if (maxW < 200) maxW = workWidth;
            if (maxH < 200) maxH = workHeight;
            if (w > maxW) w = maxW;
            if (h > maxH) h = maxH;
            return new Size(w, h);
        }

        /// <summary>
        /// Height a label needs for its current text and font, plus a little room
        /// so nothing touches the bottom edge. This is what actually stops the
        /// clipping: the height follows the font instead of a hardcoded pixel count.
        /// </summary>
        public static int FitHeight(Control owner, string text)
        {
            if (owner == null) return 0;
            using (Graphics g = owner.CreateGraphics())
            {
                // TextRenderer is used elsewhere in this UI, so measure the same way:
                // it respects the same font metrics WinForms will actually draw with.
                Size s = TextRenderer.MeasureText(g, text ?? string.Empty, owner.Font,
                    new Size(int.MaxValue, int.MaxValue),
                    TextFormatFlags.NoPadding | TextFormatFlags.SingleLine);
                return s.Height + 4;
            }
        }

        /// <summary>Gives a single-line label the height its font really needs.</summary>
        public static void FitLine(Label label, int width)
        {
            if (label == null) return;
            label.AutoSize = false;
            label.Width = Math.Max(1, width);
            label.Height = Math.Max(label.Height, FitHeight(label, label.Text));
        }

        /// <summary>Keeps a single-line label vertically centred in its slot.</summary>
        public static void CenterIn(Label label, Rectangle slot)
        {
            int h = Math.Max(slot.Height, FitHeight(label, label.Text));
            label.SetBounds(slot.X, slot.Y + Math.Max(0, (slot.Height - h) / 2), slot.Width, h);
        }

        // ------------------------------------------------------------------ chrome

        /// <summary>
        /// Gives a window a proper title bar: minimise, maximise/restore, and a
        /// double-click on the caption to toggle. The old forms had MaximizeBox
        /// and MinimizeBox switched off, so the window could not be resized at all.
        /// </summary>
        public static void EnableWindowChrome(Form form, bool resizable)
        {
            form.MinimizeBox = true;
            form.MaximizeBox = resizable;

            if (resizable)
            {
                form.FormBorderStyle = FormBorderStyle.Sizable;
                form.AutoScaleMode = AutoScaleMode.Dpi;
            }
            else
            {
                // Still give it a caption bar and a working minimise button, just
                // no resize grip.
                form.FormBorderStyle = FormBorderStyle.FixedDialog;
            }

            // Double-clicking the caption maximises a resizable window and restores
            // a maximised one, which is what every other window on the desktop does.
            if (resizable)
            {
                form.DoubleClick += delegate
                {
                    if ((form.Tag as string) == "NoCaptionToggle") return;
                    form.WindowState = form.WindowState == FormWindowState.Maximized
                        ? FormWindowState.Normal
                        : FormWindowState.Maximized;
                };
            }
        }

        /// <summary>
        /// Turns a window into a frameless card: no title bar and no border, with
        /// the requested card colour as its background. The HTML page fills the
        /// window and dragging/minimise/close come from the page over the WebView2
        /// bridge.
        ///
        /// Deliberately NOT colour-keyed/transparent: a layered WinForms window
        /// stops WebView2 receiving mouse input, so every button, drag and close
        /// in the page went dead. Windows' own rounded corners (below) give the
        /// card its shape without that cost.
        /// </summary>
        public static void MakeFramelessCard(Form form, Color cardColor)
        {
            form.FormBorderStyle = FormBorderStyle.None;
            form.MinimizeBox = false;
            form.MaximizeBox = false;
            form.ShowInTaskbar = true;
            form.BackColor = cardColor;
            form.AutoScaleMode = AutoScaleMode.Dpi;
            // The handle does not exist yet, so round the corners once it does.
            form.HandleCreated += delegate { ApplyRoundedCorners(form); };
        }

        /// <summary>
        /// Asks the desktop compositor for rounded corners and no border line.
        /// Supported on Windows 11; on Windows 10 the call is a no-op and the card
        /// simply has square corners.
        /// </summary>
        public static void ApplyRoundedCorners(Form form)
        {
            if (form == null || !form.IsHandleCreated) return;
            try
            {
                int round = DWMWCP_ROUND;
                DwmSetWindowAttribute(form.Handle, DWMWA_WINDOW_CORNER_PREFERENCE, ref round, sizeof(int));
                int none = DWMWA_COLOR_NONE;
                DwmSetWindowAttribute(form.Handle, DWMWA_BORDER_COLOR, ref none, sizeof(int));
            }
            catch (Exception)
            {
            }
        }

        private const int DWMWA_WINDOW_CORNER_PREFERENCE = 33;
        private const int DWMWA_BORDER_COLOR = 34;
        private const int DWMWCP_ROUND = 2;
        private const int DWMWA_COLOR_NONE = unchecked((int)0xFFFFFFFE);

        /// <summary>Corner radius of the rounded widget window, in design pixels.</summary>
        public const int WidgetCornerRadius = 28;

        /// <summary>Design width of the portrait widget card, in pixels.</summary>
        public const int WidgetCardWidth = 480;

        /// <summary>Design height of the portrait widget card, in pixels.</summary>
        public const int WidgetCardHeight = 780;

        /// <summary>Page colour painted behind the HTML widget (bg-slate-100).</summary>
        public static readonly Color WidgetBackground = Color.FromArgb(241, 245, 249);

        /// <summary>
        /// Turns the main window into the rounded widget.
        ///
        /// A colour key cannot be used here: WebView2 renders into a child window that
        /// the compositor draws on top of the form, so the form's LWA_COLORKEY never
        /// reaches the page and a magenta background shows as a solid block. Instead
        /// the window is clipped to a rounded rectangle with SetWindowRgn, so the real
        /// desktop shows around the widget while WebView2 keeps receiving mouse input.
        /// </summary>
        public static void MakeRoundedWidget(Form form)
        {
            form.FormBorderStyle = FormBorderStyle.None;
            form.MinimizeBox = false;
            form.MaximizeBox = false;
            form.ShowInTaskbar = true;
            form.BackColor = WidgetBackground;
            form.AutoScaleMode = AutoScaleMode.Dpi;

            EventHandler apply = delegate { ApplyRoundRegion(form); };
            if (form.IsHandleCreated) apply(form, EventArgs.Empty);
            else form.HandleCreated += apply;
            form.Resize += delegate { ApplyRoundRegion(form); };
        }

        /// <summary>Clips the window to a rounded rectangle covering its client area.</summary>
        public static void ApplyRoundRegion(Form form)
        {
            if (form == null || !form.IsHandleCreated) return;
            try
            {
                int w = form.ClientSize.Width;
                int h = form.ClientSize.Height;
                if (w <= 0 || h <= 0) return;
                int radius = (int)Math.Round(WidgetCornerRadius * (w / (double)WidgetCardWidth));
                IntPtr rgn = CreateRoundRectRgn(0, 0, w + 1, h + 1, radius * 2, radius * 2);
                if (rgn != IntPtr.Zero && SetWindowRgn(form.Handle, rgn, true) == 0) DeleteObject(rgn);
            }
            catch (Exception)
            {
            }
        }

        /// <summary>
        /// Starts a native window drag. The HTML posts a 'drag' message from its
        /// title area; handing the click to the non-client caption makes Windows
        /// move the borderless window exactly as it would a normal one.
        /// </summary>
        public static void BeginDrag(Form form)
        {
            if (form == null || !form.IsHandleCreated) return;
            try
            {
                ReleaseCapture();
                SendMessage(form.Handle, WM_NCLBUTTONDOWN, HTCAPTION, IntPtr.Zero);
            }
            catch (Exception)
            {
            }
        }

        private const int WM_NCLBUTTONDOWN = 0xA1;
        private static readonly IntPtr HTCAPTION = (IntPtr)0x2;

        /// <summary>Paints a 1px rounded border for a flat button.</summary>
        public static void StyleFlatButton(Button b, Color back, Color fore, Color ring,
                                          Color hover, Color pressed)
        {
            b.FlatStyle = FlatStyle.Flat;
            b.FlatAppearance.BorderSize = ring.IsEmpty ? 1 : 1;
            b.FlatAppearance.BorderColor = ring.IsEmpty ? back : ring;
            b.BackColor = back;
            b.ForeColor = fore;
            b.FlatAppearance.MouseOverBackColor = hover;
            b.FlatAppearance.MouseDownBackColor = pressed;
            b.UseVisualStyleBackColor = false;
            b.Cursor = Cursors.Hand;
        }

        // ------------------------------------------------------------------- P/Invoke

        [DllImport("user32.dll", SetLastError = true)]
        private static extern bool SetProcessDpiAwarenessContext(IntPtr value);

        [DllImport("user32.dll", SetLastError = true)]
        private static extern bool SetProcessDPIAware();

        [DllImport("user32.dll")]
        private static extern bool ReleaseCapture();

        [DllImport("user32.dll", CharSet = CharSet.Auto)]
        private static extern IntPtr SendMessage(IntPtr hWnd, int msg, IntPtr wParam, IntPtr lParam);

        [DllImport("user32.dll")]
        private static extern uint GetDpiForWindow(IntPtr hwnd);

        [DllImport("dwmapi.dll")]
        private static extern int DwmSetWindowAttribute(IntPtr hwnd, int attribute, ref int value, int size);

        [DllImport("gdi32.dll")]
        private static extern IntPtr CreateRoundRectRgn(int nLeftRect, int nTopRect, int nRightRect, int nBottomRect, int nWidthEllipse, int nHeightEllipse);

        [DllImport("user32.dll")]
        private static extern int SetWindowRgn(IntPtr hWnd, IntPtr hRgn, bool bRedraw);

        [DllImport("gdi32.dll")]
        private static extern bool DeleteObject(IntPtr hObject);
    }
}