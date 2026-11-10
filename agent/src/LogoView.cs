using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Windows.Forms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Draws the brand logo with correct alpha, so the transparent margin of the
    /// PNG disappears instead of filling in as a block.
    ///
    /// The sign-in screen used to host the logo in a PictureBox. PictureBox sets
    /// ControlStyles.Opaque, which means GDI+ fills the whole rectangle with its
    /// BackColor before blitting the bitmap. The logo is mostly transparent around
    /// the mark, so on the dark canvas that produced a large black square behind
    /// the logo - the "black shade" behind it. Painting the bitmap ourselves with
    /// PixelOffsetMode/PixelFormat set for alpha leaves the surrounding pixels
    /// untouched, whatever colour is behind them.
    /// </summary>
    internal sealed class LogoView : Control
    {
        private Image _image;
        private int _padding;

        public LogoView()
        {
            // No Opaque flag: let whatever is behind show through.
            SetStyle(ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer
                     | ControlStyles.UserPaint | ControlStyles.ResizeRedraw | ControlStyles.SupportsTransparentBackColor,
                     true);
            TabStop = false;
            BackColor = Color.Transparent;
        }

        public Image LogoImage
        {
            get { return _image; }
            set { _image = value; Invalidate(); }
        }

        /// <summary>Transparent breathing room kept around the mark, in pixels.</summary>
        public new int Padding
        {
            get { return _padding; }
            set { _padding = value < 0 ? 0 : value; Invalidate(); }
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            base.OnPaint(e);
            if (_image == null) return;

            Graphics g = e.Graphics;
            g.SmoothingMode = SmoothingMode.AntiAlias;
            g.InterpolationMode = InterpolationMode.HighQualityBicubic;
            // Half-pixel offset keeps the edges from shimmering as the window resizes.
            g.PixelOffsetMode = PixelOffsetMode.HighQuality;
            g.CompositingQuality = CompositingQuality.HighQuality;
            g.Clear(BackColor);

            int side = Math.Max(1, Math.Min(Width, Height) - _padding * 2);
            int x = (Width - side) / 2;
            int y = (Height - side) / 2;
            g.DrawImage(_image, new Rectangle(x, y, side, side));
        }

        protected override void Dispose(bool disposing)
        {
            // The bitmap is produced per size by AgentIcons and cached by the
            // caller, so the view does not own it here.
            if (disposing) _image = null;
            base.Dispose(disposing);
        }
    }
}