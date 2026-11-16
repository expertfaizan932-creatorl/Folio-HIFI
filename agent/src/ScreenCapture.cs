using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Windows.Forms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Grabs the desktop (all monitors) as a scaled JPEG. Screenshots are resized
    /// before encoding so a 4K screen does not become a multi-megabyte upload.
    /// </summary>
    internal static class ScreenCapture
    {
        public static byte[] CaptureJpeg(int maxWidth, int quality)
        {
            try
            {
                Rectangle bounds = SystemInformation.VirtualScreen;
                if (bounds.Width <= 0 || bounds.Height <= 0) return null;

                using (Bitmap full = new Bitmap(bounds.Width, bounds.Height, PixelFormat.Format24bppRgb))
                using (Graphics g = Graphics.FromImage(full))
                {
                    g.CopyFromScreen(bounds.Left, bounds.Top, 0, 0, bounds.Size,
                        CopyPixelOperation.SourceCopy);

                    Bitmap output = Downscale(full, maxWidth);
                    try
                    {
                        ImageCodecInfo encoder = FindJpegEncoder();
                        if (encoder == null) return EncodePng(full);

                        using (EncoderParameters parameters = new EncoderParameters(1))
                        {
                            long q = Math.Max(10, Math.Min(95, quality));
                            parameters.Param[0] = new EncoderParameter(System.Drawing.Imaging.Encoder.Quality, q);
                            using (MemoryStream ms = new MemoryStream())
                            {
                                output.Save(ms, encoder, parameters);
                                return ms.ToArray();
                            }
                        }
                    }
                    finally
                    {
                        if (output != full) output.Dispose();
                    }
                }
            }
            catch (Exception)
            {
                return null;
            }
        }

        private static Bitmap Downscale(Bitmap source, int maxWidth)
        {
            if (maxWidth <= 0 || source.Width <= maxWidth) return source;

            int width = maxWidth;
            int height = (int)Math.Round((double)source.Height * width / source.Width);
            if (height < 1) height = 1;

            Bitmap scaled = new Bitmap(width, height, PixelFormat.Format24bppRgb);
            using (Graphics g = Graphics.FromImage(scaled))
            {
                g.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
                g.PixelOffsetMode = System.Drawing.Drawing2D.PixelOffsetMode.HighQuality;
                g.DrawImage(source, 0, 0, width, height);
            }
            return scaled;
        }

        private static ImageCodecInfo FindJpegEncoder()
        {
            foreach (ImageCodecInfo info in ImageCodecInfo.GetImageEncoders())
            {
                if (info.FormatID == System.Drawing.Imaging.ImageFormat.Jpeg.Guid) return info;
            }
            return null;
        }

        private static byte[] EncodePng(Bitmap bitmap)
        {
            using (MemoryStream ms = new MemoryStream())
            {
                bitmap.Save(ms, ImageFormat.Png);
                return ms.ToArray();
            }
        }

        public static string ToBase64(byte[] data)
        {
            return data == null ? null : Convert.ToBase64String(data);
        }
    }
}
