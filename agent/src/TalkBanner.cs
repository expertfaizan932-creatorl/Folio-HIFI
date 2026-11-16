using System;
using System.Drawing;
using System.Windows.Forms;

namespace WorkFolio.Agent
{
    /// <summary>
    /// The always-on-top notice an employee sees while a supervisor is speaking.
    ///
    /// This control is the reason the feature is acceptable to ship. It is
    /// deliberately impossible to miss and deliberately impossible to dismiss by
    /// accident: no title bar, no close button, and it sits above everything. It
    /// names what is happening and offers the two things the person actually needs,
    /// which are Mute and Decline.
    ///
    /// Decline ends the whole session, not just the current sentence. Mute silences
    /// the audio but leaves the banner up so the employee is never left wondering
    /// whether someone is still on the line.
    /// </summary>
    internal sealed class TalkBanner : Form
    {
        private readonly Button _mute;
        private readonly Panel _swatch;

        public event Action<bool> MuteToggled;
        public event Action Declined;

        public TalkBanner()
        {
            FormBorderStyle = FormBorderStyle.None;
            StartPosition = FormStartPosition.Manual;
            ShowInTaskbar = false;
            TopMost = true;
            MinimizeBox = false;
            MaximizeBox = false;
            ControlBox = false;
            BackColor = Color.FromArgb(24, 26, 33);
            ClientSize = new Size(430, 104);
            Opacity = 0.97;

            _swatch = new Panel { BackColor = Color.FromArgb(220, 53, 69), Dock = DockStyle.Left, Width = 6 };

            Label headline = new Label
            {
                Text = "A supervisor is speaking to you",
                ForeColor = Color.White,
                Font = new Font("Segoe UI", 11f, FontStyle.Bold),
                AutoSize = false,
                Location = new Point(22, 14),
                Size = new Size(300, 22)
            };

            Label detail = new Label
            {
                Text = "Their voice is coming from this computer's speakers.",
                ForeColor = Color.FromArgb(176, 182, 196),
                Font = new Font("Segoe UI", 8.5f),
                AutoSize = false,
                Location = new Point(22, 38),
                Size = new Size(300, 30)
            };

            _mute = MakeButton("Mute", Color.FromArgb(52, 58, 74), new Point(22, 70), 108);
            _mute.Click += delegate
            {
                bool muted = !_mute.Tag.Equals("off");
                _mute.Tag = muted ? "on" : "off";
                _mute.Text = muted ? "Muted" : "Mute";
                _mute.ForeColor = muted ? Color.FromArgb(255, 212, 59) : Color.White;
                Action<bool> h = MuteToggled;
                if (h != null) h(muted);
            };

            Button decline = MakeButton("Decline", Color.FromArgb(198, 40, 40), new Point(140, 70), 108);
            decline.Click += delegate
            {
                Action h = Declined;
                if (h != null) h();
            };

            Controls.Add(_swatch);
            Controls.Add(headline);
            Controls.Add(detail);
            Controls.Add(_mute);
            Controls.Add(decline);

            PositionBottomRight();
        }

        private static Button MakeButton(string text, Color back, Point at, int width)
        {
            Button b = new Button
            {
                Text = text,
                Tag = "off",
                FlatStyle = FlatStyle.Flat,
                BackColor = back,
                ForeColor = Color.White,
                Font = new Font("Segoe UI", 9f),
                Size = new Size(width, 26),
                Location = at,
                Cursor = Cursors.Hand,
                UseVisualStyleBackColor = false
            };
            b.FlatAppearance.BorderColor = Color.FromArgb(90, 98, 120);
            b.FlatAppearance.MouseOverBackColor = ControlPaint.Light(back, 0.08f);
            return b;
        }

        private void PositionBottomRight()
        {
            Rectangle area = Screen.PrimaryScreen.WorkingArea;
            Location = new Point(area.Right - Width - 18, area.Bottom - Height - 18);
        }

        /// <summary>Steals no focus: it appears over the employee's work without
        /// interrupting what they are typing. Classic getter syntax because the agent
        /// builds with the .NET Framework C# 5 compiler.</summary>
        protected override bool ShowWithoutActivation { get { return true; } }

        protected override CreateParams CreateParams
        {
            get
            {
                const int WS_EX_TOOLWINDOW = 0x80;
                const int WS_EX_NOACTIVATE = 0x08000000;
                CreateParams cp = base.CreateParams;
                cp.ExStyle |= WS_EX_TOOLWINDOW | WS_EX_NOACTIVATE;
                return cp;
            }
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            base.OnPaint(e);
            using (Pen border = new Pen(Color.FromArgb(70, 76, 94)))
                e.Graphics.DrawRectangle(border, 0, 0, Width - 1, Height - 1);
        }

        /// <summary>The banner must not vanish because the employee clicked it.</summary>
        protected override void OnMouseDown(MouseEventArgs e) { Focus(); }
    }
}