using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Threading;
using System.Windows.Forms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Plays a supervisor's voice out of this machine's speakers, for as long as a
    /// supervisor is holding the talk button on the Live Monitor.
    ///
    /// NOT COVERT, and that is the whole point of the banner. Speech arriving out of
    /// an idle laptop is indistinguishable from a malfunction and is exactly what
    /// turns a monitoring tool into a harassment tool. So the employee always sees a
    /// topmost banner naming who is speaking, and always has Mute and Decline. The
    /// supervisor's UI shows them which of the two happened - it cannot pretend the
    /// message was delivered when it was not.
    ///
    /// Transport: poll /api/agent/talk/poll for one chunk, play it, then ack it. The
    /// server hands out chunk N+1 only after chunk N is acked, so a slow link drops
    /// audio instead of accumulating lag. Chunk N is therefore played exactly once.
    ///
    /// Audio is played and forgotten. Nothing is written to disk and nothing is kept.
    /// </summary>
    internal sealed class TalkPlayer : IDisposable
    {
        private const int SampleRate    = 16000;
        private const int Bits          = 16;
        private const int Channels      = 1;

        /// <summary>Fast cadence while a session is live, slow while idle.</summary>
        private const int PollActiveMs  = 120;
        private const int PollIdleMs    = 900;

        private readonly ApiClient _api;
        private readonly Control  _ui;
        private Thread _worker;
        private volatile bool _running;

        /// <summary>Set while the employee has muted; suppresses audio but keeps the banner.</summary>
        private volatile bool _muted;

        /// <summary>True while a supervisor session is up, so the banner can be shown once.</summary>
        private volatile bool _bannerUp;

        private TalkBanner _banner;

        /// <summary>Raised when the server refuses (policy off, on a break, clocked out).</summary>
        public event Action<string> OnRefused;

        public TalkPlayer(ApiClient api, Control ui)
        {
            _api = api;
            _ui = ui;
        }

        public void Start()
        {
            if (_worker != null) return;
            _running = true;
            _worker = new Thread(Loop) { IsBackground = true, Name = "WorkFolioTalk" };
            _worker.Start();
        }

        public void Shutdown()
        {
            _running = false;
            if (_worker != null && !_worker.Join(1500)) Trace.WriteLine("talk thread did not stop in time");
            _worker = null;
            CloseBanner();
        }

        public void Dispose() { Shutdown(); }

        // ---------------------------------------------------------------- loop

        private void Loop()
        {
            while (_running)
            {
                bool live = false;
                try
                {
                    live = Pump();
                }
                catch (Exception ex)
                {
                    Trace.WriteLine("talk loop error: " + ex.Message);
                }
                if (!_running) break;
                try { Thread.Sleep(live ? PollActiveMs : PollIdleMs); }
                catch (ThreadInterruptedException) { break; }
            }
            CloseBanner();
        }

        /// <summary>One poll. Returns true when a session is live (so poll faster).</summary>
        private bool Pump()
        {
            Dictionary<string, object> r = _api.TalkPoll();
            if (r == null) { HideBannerIfStale(); return false; }

            if (!ApiClient.AsBool(r, "ok", false)) return false;

            string refused = ApiClient.AsString(r, "refused");
            if (!string.IsNullOrEmpty(refused))
            {
                if (refused != "on-break" && refused != "off-duty")
                {
                    Action<string> h = OnRefused;
                    if (h != null) h(refused);
                }
                HideBannerIfStale();
                return false;
            }

            bool active = ApiClient.AsBool(r, "active", false);
            if (!active) { HideBannerIfStale(); return false; }

            // The session exists: put the banner up before any sound comes out.
            ShowBanner();

            long seq = ApiClient.AsLong(r, "seq", -1);
            string b64 = ApiClient.AsString(r, "pcm");
            if (seq < 0 || string.IsNullOrEmpty(b64)) return true;   // live, nothing queued yet

            byte[] pcm;
            try { pcm = Convert.FromBase64String(b64); }
            catch (FormatException) { return true; }

            if (!_muted)
            {
                try
                {
                    PlayWav(WrapWav(pcm, SampleRate, Channels, Bits));
                }
                catch (Exception ex)
                {
                    Trace.WriteLine("talk playback failed: " + ex.Message);
                }
            }

            // Ack even when muted: the supervisor should see "muted", not silence.
            _api.TalkAck(seq, _muted ? "muted" : "heard");
            return true;
        }

        // ------------------------------------------------------------ playback

        /// <summary>
        /// A short two-tone chime so the first thing the employee hears is clearly a
        /// deliberate alert, not a stray noise.
        /// </summary>
        private void PlayChime()
        {
            try { PlayWav(Chime()); } catch (Exception) { /* non-fatal */ }
        }

        private static byte[] Chime()
        {
            const int rate = SampleRate;
            int n = rate / 2;                       // half a second
            short[] pcm = new short[n];
            for (int i = 0; i < n; i++)
            {
                double t = (double)i / rate;
                // 880 Hz then 1320 Hz halfway through, with a fade so it does not click.
                double f = t < 0.25 ? 880.0 : 1320.0;
                double env = Math.Min(1.0, t * 40) * Math.Min(1.0, (0.5 - t) * 40);
                pcm[i] = (short)(Math.Sin(2 * Math.PI * f * t) * env * 6000);
            }
            byte[] raw = new byte[pcm.Length * 2];
            Buffer.BlockCopy(pcm, 0, raw, 0, raw.Length);
            return WrapWav(raw, rate, 1, 16);
        }

        /// <summary>Wraps raw signed-16-bit little-endian PCM in a canonical 44-byte WAV header.</summary>
        private static byte[] WrapWav(byte[] pcm, int rate, int channels, int bits)
        {
            int byteRate = rate * channels * bits / 8;
            int blockAlign = channels * bits / 8;
            byte[] wav = new byte[44 + pcm.Length];
            using (MemoryStream ms = new MemoryStream(wav))
            using (BinaryWriter w = new BinaryWriter(ms))
            {
                w.Write(new[] { (byte)'R', (byte)'I', (byte)'F', (byte)'F' });
                w.Write(36 + pcm.Length);
                w.Write(new[] { (byte)'W', (byte)'A', (byte)'V', (byte)'E' });
                w.Write(new[] { (byte)'f', (byte)'m', (byte)'t', (byte)' ' });
                w.Write(16);
                w.Write((short)1);                    // PCM
                w.Write((short)channels);
                w.Write(rate);
                w.Write(byteRate);
                w.Write((short)blockAlign);
                w.Write((short)bits);
                w.Write(new[] { (byte)'d', (byte)'a', (byte)'t', (byte)'a' });
                w.Write(pcm.Length);
                w.Write(pcm);
            }
            return wav;
        }

        /// <summary>
        /// Plays and waits. Blocking on purpose: the watermark only advances when this
        /// returns, which is what keeps playback at-most-once and in order.
        /// </summary>
        private static void PlayWav(byte[] wav)
        {
            using (MemoryStream ms = new MemoryStream(wav))
            {
                using (System.Media.SoundPlayer player = new System.Media.SoundPlayer(ms))
                {
                    player.Load();
                    player.PlaySync();
                }
            }
        }

        // -------------------------------------------------------------- banner

        private void ShowBanner()
        {
            if (_bannerUp) return;
            _bannerUp = true;
            RunOnUi(delegate
            {
                try
                {
                    if (_banner == null)
                    {
                        _banner = new TalkBanner();
                        _banner.MuteToggled += delegate(bool muted) { _muted = muted; };
                        _banner.Declined += delegate { _muted = true; DeclineCurrent(); };
                    }
                    _banner.Show();
                    PlayChime();
                }
                catch (Exception ex) { Trace.WriteLine("banner failed: " + ex.Message); }
            });
        }

        private void HideBannerIfStale()
        {
            if (!_bannerUp) return;
            _bannerUp = false;
            _muted = false;
            CloseBanner();
        }

        private void CloseBanner()
        {
            _bannerUp = false;
            RunOnUi(delegate
            {
                try
                {
                    if (_banner != null) { _banner.Close(); _banner.Dispose(); _banner = null; }
                }
                catch (Exception) { }
            });
        }

        /// <summary>Tells the server to stop, so the supervisor sees "declined" rather than waiting.</summary>
        private void DeclineCurrent()
        {
            try { _api.TalkAck(long.MaxValue, "declined"); }
            catch (Exception) { }
            HideBannerIfStale();
        }

        private void RunOnUi(MethodInvoker action)
        {
            try
            {
                if (_ui != null && _ui.InvokeRequired) _ui.BeginInvoke(action);
                else action();
            }
            catch (Exception) { action(); }
        }
    }
}