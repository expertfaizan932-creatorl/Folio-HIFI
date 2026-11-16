using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Windows.Forms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Paints the Folio spectrum: deep indigo through violet, the same stops the
    /// website uses, so the desktop agent and the web app read as one product.
    ///
    /// The ramp used to run violet, magenta, red, orange, amber, then was pulled
    /// back to indigo/violet when the website moved. What was wrong before this
    /// rewrite was the *veil*: a near-black (#100c22) overlay at up to 255 alpha
    /// was flattened over the gradient. Drawn behind labels it did nothing useful
    /// and, because the labels inherited that near-black BackColor, the two
    /// together read as black shading behind the logo and text.
    ///
    /// Two modes now:
    ///   Strip - a solid brand bar along the top edge of a card.
    ///   Wash  - a gradient that fades to fully transparent downwards, for sitting
    ///            behind a logo and title without ever going opaque.
    /// </summary>
    internal sealed class SpectrumBackdrop : Control
    {
        public enum SpectrumMode { Strip, Wash }

        private static readonly Color[] Stops =
        {
            Color.FromArgb(49, 16, 120),    // #311078 indigo
            Color.FromArgb(85, 34, 176),    // #5522b0
            Color.FromArgb(114, 55, 245),   // #7237f5 violet
        };

        private SpectrumMode _mode = SpectrumMode.Strip;
        private int _stripHeight = 4;
        private int _washStrength = 30;

        public SpectrumBackdrop()
        {
            SetStyle(ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer
                     | ControlStyles.UserPaint | ControlStyles.ResizeRedraw
                     | ControlStyles.SupportsTransparentBackColor, true);
            // Purely decorative: never take focus or intercept a click.
            TabStop = false;
            BackColor = Color.Transparent;
        }

        public SpectrumMode Mode
        {
            get { return _mode; }
            set { _mode = value; Invalidate(); }
        }

        /// <summary>Height of the solid brand strip along the top edge, in pixels.</summary>
        public int StripHeight
        {
            get { return _stripHeight; }
            set { _stripHeight = value < 0 ? 0 : value; Invalidate(); }
        }

        /// <summary>
        /// Peak opacity of the wash, 0-255. Kept low on purpose: the logo and the
        /// sign-in labels sit on top of it and have to stay legible.
        /// </summary>
        public int WashStrength
        {
            get { return _washStrength; }
            set
            {
                _washStrength = value < 0 ? 0 : (value > 255 ? 255 : value);
                Invalidate();
            }
        }

        /// <summary>Legacy setter kept so existing call sites still compile.</summary>
        public int WashAlpha
        {
            get { return _washStrength; }
            set { WashStrength = value; }
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            Graphics g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            int w = Math.Max(1, Width);
            int h = Math.Max(1, Height);

            if (_mode == SpectrumMode.Strip)
            {
                int bar = Math.Min(_stripHeight, h);
                if (bar <= 0) return;
                using (LinearGradientBrush brush = new LinearGradientBrush(
                    new Rectangle(0, 0, w, bar), Stops[0], Stops[Stops.Length - 1],
                    LinearGradientMode.Horizontal))
                {
                    g.FillRectangle(brush, 0, 0, w, bar);
                }
                return;
            }

            // Wash: one gradient from the brand colour at the top down to fully
            // transparent, so it blends into any surface instead of ending in a
            // hard edge or a black box.
            if (_washStrength <= 0) return;
            using (LinearGradientBrush brush = new LinearGradientBrush(
                new Rectangle(0, 0, w, h),
                Color.FromArgb(_washStrength, Stops[0]),
                Color.FromArgb(0, Stops[Stops.Length - 1]),
                LinearGradientMode.Vertical))
            {
                g.FillRectangle(brush, 0, 0, w, h);
            }
        }

        protected override void OnResize(EventArgs e)
        {
            base.OnResize(e);
            Invalidate();
        }
    }
}