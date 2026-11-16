using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.IO;
using System.Reflection;

namespace WorkFolio.Agent
{
    /// <summary>
    /// WorkFolio brand icon. Embedded into the EXE at build time, so the tray
    /// icon and login screen logo work no matter which folder the agent runs from.
    /// </summary>
    internal static class AgentIcons
    {
        private const string ResourceName = "WorkFolio.Agent.app.ico";
        private const string LogoResourceName = "WorkFolio.Agent.logo";
        private static readonly object Sync = new object();
        private static Icon _icon;
        private static Image _logoSource;

        /// <summary>The app icon (tray, taskbar, dialogs). Never dispose the shared instance.</summary>
        public static Icon AppIcon
        {
            get
            {
                if (_icon != null) return _icon;
                lock (Sync)
                {
                    if (_icon != null) return _icon;
                    _icon = LoadEmbedded();
                    if (_icon == null) _icon = SystemIcons.Application;
                }
                return _icon;
            }
        }

        /// <summary>
        /// The square brand logo rendered at a given pixel size, for the sign-in
        /// screen. Renders from the embedded high-resolution logo (512px), never
        /// from an upscaled icon frame, so the result stays crisp.
        /// </summary>
        public static Image Logo(int size)
        {
            Bitmap bmp = new Bitmap(size, size);
            using (Graphics g = Graphics.FromImage(bmp))
            {
                g.SmoothingMode = SmoothingMode.HighQuality;
                g.InterpolationMode = InterpolationMode.HighQualityBicubic;
                g.PixelOffsetMode = PixelOffsetMode.HighQuality;
                g.DrawImage(LogoSource, 0, 0, size, size);
            }
            return bmp;
        }

        private static Image LogoSource
        {
            get
            {
                if (_logoSource != null) return _logoSource;
                lock (Sync)
                {
                    if (_logoSource != null) return _logoSource;
                    _logoSource = LoadLogoSource();
                    if (_logoSource == null) _logoSource = AppIcon.ToBitmap();
                }
                return _logoSource;
            }
        }

        private static Image LoadLogoSource()
        {
            try
            {
                Assembly assembly = Assembly.GetExecutingAssembly();
                using (Stream stream = assembly.GetManifestResourceStream(LogoResourceName))
                {
                    if (stream == null) return null;
                    return Image.FromStream(stream);
                }
            }
            catch (Exception)
            {
                return null;
            }
        }

        private static Icon LoadEmbedded()
        {
            try
            {
                Assembly assembly = Assembly.GetExecutingAssembly();
                using (Stream stream = assembly.GetManifestResourceStream(ResourceName))
                {
                    if (stream == null) return null;
                    return new Icon(stream);
                }
            }
            catch (Exception)
            {
                return null;
            }
        }
    }
}