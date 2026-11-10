using System;
using System.Diagnostics;
using System.IO;

namespace WorkFolio.Agent
{
    /// <summary>
    /// The shift's four time buckets, counted on a monotonic clock.
    ///
    /// Why this exists: the old agent computed elapsed time as
    /// <c>DateTime.Now - clockInAt</c>. Fold the lid for an hour, come back, and
    /// the timer jumped forward by that hour as if the employee had been working.
    /// <see cref="Stopwatch"/> cannot be moved by the user or by the network time
    /// service, so it is the only trustworthy source for "how long was this person
    /// actually working".
    ///
    /// It does keep running while the machine is suspended, which is why nothing
    /// here can simply ask how much time has passed: every gap between two ticks
    /// has to be filed deliberately. Get that wrong - book the post-suspend gap
    /// as work - and a folded lid turns into paid overtime. A gap too long to be
    /// ordinary ticking is treated as laptop-away time on that basis alone, so the
    /// numbers stay right even if the machine never told us it was going to sleep.
    ///
    /// The four buckets are kept apart on purpose:
    ///   active - typing / mouse work while the machine is awake and present
    ///   idle   - present but not interacting, within the idle threshold
    ///   break  - explicitly on break; keeps counting even if the lid shuts,
    ///            because the break is genuinely being taken
    ///   paused - lid folded, asleep or session locked; never work, never break
    ///
    /// Persisted so a crash, a reboot or a force-quit does not silently lose an
    /// employee's afternoon.
    /// </summary>
    internal sealed class ShiftClock : IDisposable
    {
        private const int CurrentVersion = 2;

        // The agent ticks once a second, so a gap longer than this is not ordinary
        // ticking. Windows keeps the performance counter running through sleep,
        // hibernation and a frozen process, which means the naive reading of that
        // gap is "the employee worked while the laptop was shut". Anything past
        // this is booked as laptop-away time instead.
        private const long DefaultMaxSweepTicks = TimeSpan.TicksPerSecond * 15;

        private readonly object _gate = new object();
        private readonly Stopwatch _stopwatch = new Stopwatch();
        private readonly string _statePath;
        private readonly long _maxSweepTicks;
        private string _owner;

        private long _activeTicks;
        private long _idleTicks;
        private long _breakTicks;
        private long _pausedTicks;

        // Which day these totals belong to. A state file left behind by yesterday
        // must not be added to today's shift, so the stamp is checked on load and
        // rewritten on every save.
        private string _day = DateTime.Today.ToString("yyyy-MM-dd");

        /// <summary>True when today's banked totals were recovered from disk.</summary>
        public bool RestoredFromDisk { get; private set; }

        // Which bucket the time between two ticks is added to. Set from
        // AttendanceStatus plus the pause flag.
        private ShiftBucket _bucket = ShiftBucket.None;

        private enum ShiftBucket { None, Active, Idle, Break, Paused }

        /// <param name="owner">
        /// The signed-in employee. The ledger is stored per user, so a shared
        /// machine would otherwise hand the previous person's afternoon to
        /// whoever signs in next.
        /// </param>
        public ShiftClock(string statePath, string owner)
            : this(statePath, owner, DefaultMaxSweepTicks)
        {
        }

        /// <param name="maxSweepTicks">
        /// Largest gap still treated as ordinary ticking. The tests lower it so a
        /// simulated sleep can be checked without a real fifteen minute wait.
        /// </param>
        public ShiftClock(string statePath, string owner, long maxSweepTicks)
        {
            _statePath = statePath;
            _maxSweepTicks = maxSweepTicks > 0 ? maxSweepTicks : DefaultMaxSweepTicks;
            _owner = (owner ?? string.Empty).Trim().ToLowerInvariant();
            Load();
            _stopwatch.Start();
        }

        /// <summary>Switch to a bucket. Time already banked is unaffected.</summary>
        public void SetBucket(bool onDuty, bool idle, bool onBreak, bool paused)
        {
            lock (_gate)
            {
                // Pause wins over break: a folded laptop is paused even mid-break,
                // but only for the worked side of the ledger - see Pause().
                ShiftBucket next;
                if (paused) next = onBreak ? ShiftBucket.Break : ShiftBucket.Paused;
                else if (!onDuty) next = ShiftBucket.None;
                else if (onBreak) next = ShiftBucket.Break;
                else next = idle ? ShiftBucket.Idle : ShiftBucket.Active;

                // Bank the gap into the bucket we were in BEFORE switching. This
                // matters most on resume: Windows keeps the performance counter
                // running through sleep, so if the switch came first the entire
                // suspended stretch would be booked as work instead of as the
                // laptop-away time it actually was.
                Flush();
                _bucket = next;
            }
        }

        /// <summary>Adds time to whichever bucket is current.</summary>
        public void Tick()
        {
            // The Stopwatch is never reset, so banked time is the only state we
            // have to trust; Tick simply moves elapsed ticks into a bucket.
            lock (_gate) { FlushAndMaybeSave(); }
        }

        /// <summary>
        /// Banks and writes the ledger immediately, ignoring the 30s save interval.
        /// Called when the shift ends so a restart resumes from the real figure.
        /// </summary>
        public void FlushToDisk()
        {
            lock (_gate) { Flush(); Save(); }
        }

        public long ActiveSeconds { get { lock (_gate) { Flush(); return Ticks(_activeTicks); } } }
        public long IdleSeconds { get { lock (_gate) { Flush(); return Ticks(_idleTicks); } } }
        public long BreakSeconds { get { lock (_gate) { Flush(); return Ticks(_breakTicks); } } }
        public long PausedSeconds { get { lock (_gate) { Flush(); return Ticks(_pausedTicks); } } }

        /// <summary>Worked time = active + idle. Paused and break are excluded.</summary>
        public long WorkedSeconds { get { lock (_gate) { Flush(); return Ticks(_activeTicks + _idleTicks); } } }

        /// <summary>Zero every bucket and re-stamp the day. Called when a shift begins.</summary>
        public void Reset()
        {
            lock (_gate)
            {
                _activeTicks = 0;
                _idleTicks = 0;
                _breakTicks = 0;
                _pausedTicks = 0;
                _bucket = ShiftBucket.None;
                _day = DateTime.Today.ToString("yyyy-MM-dd");
                Flush();
            }
        }

        /// <summary>
        /// Binds the ledger to the signed-in employee, reloading it if the stored
        /// totals belong to them.
        ///
        /// The agent is constructed before the sign-in dialog is shown, so the
        /// owner is only known once somebody has logged in. Until then the clock
        /// holds nothing, which is why this cannot simply happen in the
        /// constructor.
        /// </summary>
        public void AdoptOwner(string owner)
        {
            string next = (owner ?? string.Empty).Trim().ToLowerInvariant();
            if (next.Length == 0) return;

            lock (_gate)
            {
                if (next.Equals(_owner, StringComparison.OrdinalIgnoreCase)) return;

                // A different person: whatever is on disk is not theirs, so start
                // from empty and let Load decide whether they have a ledger of
                // their own to pick up.
                _owner = next;
                _activeTicks = 0;
                _idleTicks = 0;
                _breakTicks = 0;
                _pausedTicks = 0;
                RestoredFromDisk = false;
                Load();
            }
        }

        /// <summary>
        /// Seeds the ledger from the totals the server already holds for today.
        ///
        /// Used once, on sign-in, and only when the local ledger could not be
        /// read back - a wiped profile, a corrupt file, a first run on a new
        /// machine. Without it the panel shows 00:00:00 for a whole afternoon
        /// while the server correctly still remembers the morning, which reads to
        /// the employee exactly like the timer resetting.
        ///
        /// Only ever raises a bucket. The ledger is monotonic, so this must never
        /// talk a number back down.
        /// </summary>
        public void AdoptBaseline(long activeSeconds, long idleSeconds,
                                  long breakSeconds, long pausedSeconds)
        {
            lock (_gate)
            {
                // The disk copy is the employee's own most recent reading, so there
                // is nothing to reconcile.
                if (RestoredFromDisk) return;

                if (activeSeconds < 0) activeSeconds = 0;
                if (idleSeconds < 0) idleSeconds = 0;
                if (breakSeconds < 0) breakSeconds = 0;
                if (pausedSeconds < 0) pausedSeconds = 0;

                _activeTicks = Math.Max(_activeTicks, activeSeconds * TimeSpan.TicksPerSecond);
                _idleTicks = Math.Max(_idleTicks, idleSeconds * TimeSpan.TicksPerSecond);
                _breakTicks = Math.Max(_breakTicks, breakSeconds * TimeSpan.TicksPerSecond);
                _pausedTicks = Math.Max(_pausedTicks, pausedSeconds * TimeSpan.TicksPerSecond);
                Save();
            }
        }

        /// <summary>
        /// Force the ledger to match the server's numbers for today, even if they
        /// are lower. Used when a SUPER_ADMIN manually edits clock-in/out or
        /// buckets - the edited values become the real values everywhere, and the
        /// local monotonic timer must follow them.
        /// </summary>
        public void ForceBaseline(long activeSeconds, long idleSeconds,
                                  long breakSeconds, long pausedSeconds)
        {
            lock (_gate)
            {
                if (activeSeconds < 0) activeSeconds = 0;
                if (idleSeconds < 0) idleSeconds = 0;
                if (breakSeconds < 0) breakSeconds = 0;
                if (pausedSeconds < 0) pausedSeconds = 0;

                _activeTicks = activeSeconds * TimeSpan.TicksPerSecond;
                _idleTicks = idleSeconds * TimeSpan.TicksPerSecond;
                _breakTicks = breakSeconds * TimeSpan.TicksPerSecond;
                _pausedTicks = pausedSeconds * TimeSpan.TicksPerSecond;
                Save();
            }
        }

        private void Flush()
        {
            long now = _stopwatch.Elapsed.Ticks;
            long since = now - _banked;
            _banked = now;

            // A new calendar day is a new shift. Yesterday's buckets must never
            // become today's work, and an agent left running through midnight
            // never reloads from disk, so the rollover has to happen here rather
            // than in Load().
            if (RollOverIfNewDay()) return;

            if (since <= 0) return;

            // A gap this long means the machine went away without telling us:
            // suspend, hibernation, or the process itself being frozen while the
            // counter kept running. The power notifications normally bank this
            // stretch on the way back, but if they never arrive - a registration
            // that failed, a message pump that was never built, a resume the
            // machine swallowed - the whole sleep would be added to work. That is
            // precisely the "folded the lid for an hour and the timer jumped an
            // hour" bug, so the gap is booked as laptop-away time here as well.
            // A short fold mid-break still keeps counting as break: only a gap too
            // long to be ticking is diverted.
            if (since > _maxSweepTicks &&
                _bucket != ShiftBucket.Paused && _bucket != ShiftBucket.None)
            {
                _pausedTicks += since;
                return;
            }

            switch (_bucket)
            {
                case ShiftBucket.Active: _activeTicks += since; break;
                case ShiftBucket.Idle: _idleTicks += since; break;
                case ShiftBucket.Break: _breakTicks += since; break;
                case ShiftBucket.Paused: _pausedTicks += since; break;
            }
        }

        /// <summary>Zeros the ledger when the date changed. Returns true if it rolled over.</summary>
        private bool RollOverIfNewDay()
        {
            string today = DateTime.Today.ToString("yyyy-MM-dd");
            if (today == _day) return false;
            _activeTicks = 0;
            _idleTicks = 0;
            _breakTicks = 0;
            _pausedTicks = 0;
            _day = today;
            RestoredFromDisk = false;
            Save();
            return true;
        }

        private long _banked;

        private static long Ticks(long t) { return t / TimeSpan.TicksPerSecond; }

        /* ---------------------------------------------------------- storage --- */

private void Load()
        {
            try
            {
                if (!File.Exists(_statePath)) return;
                string[] parts = File.ReadAllText(_statePath).Split('|');
                if (parts.Length < 7 || parts[0] != CurrentVersion.ToString()) return;
                string day = parts[5];
                // Totals from a previous day are not today's shift. Starting fresh
                // is the safe reading: carrying them over would inflate worked time.
                if (day != DateTime.Today.ToString("yyyy-MM-dd")) return;

                // The file is per user, not per machine. On a shared PC the totals
                // in it belong to whoever was signed in before, and handing those
                // to the next employee would credit them with time they never
                // worked. An unowned file (older build) is not adopted either.
                string owner = (parts.Length > 6 ? parts[6] : string.Empty).Trim().ToLowerInvariant();
                if (owner.Length == 0 || !owner.Equals(_owner, StringComparison.OrdinalIgnoreCase)) return;

                _day = day;
                _activeTicks = long.Parse(parts[1]) * TimeSpan.TicksPerSecond;
                _idleTicks = long.Parse(parts[2]) * TimeSpan.TicksPerSecond;
                _breakTicks = long.Parse(parts[3]) * TimeSpan.TicksPerSecond;
                _pausedTicks = long.Parse(parts[4]) * TimeSpan.TicksPerSecond;
                RestoredFromDisk = true;
            }
            catch (Exception)
            {
                // A corrupt state file must never stop the agent; start fresh.
                _activeTicks = _idleTicks = _breakTicks = _pausedTicks = 0;
            }
        }

        private void Save()
        {
            try
            {
                string dir = Path.GetDirectoryName(_statePath);
                if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir))
                    Directory.CreateDirectory(dir);
                string text = string.Join("|", new string[]
                {
                    CurrentVersion.ToString(),
                    Ticks(_activeTicks).ToString(),
                    Ticks(_idleTicks).ToString(),
                    Ticks(_breakTicks).ToString(),
                    Ticks(_pausedTicks).ToString(),
                    DateTime.Today.ToString("yyyy-MM-dd"),
                    _owner,
                });
                File.WriteAllText(_statePath, text);
            }
            catch (Exception)
            {
                Trace.WriteLine("Folio clock: could not persist shift state");
            }
        }

        // Saves at most every 30 seconds: cheap enough to be unmissable, rare
        // enough not to hammer the disk on a 1 Hz tick.
        private long _lastSaveTicks;
        private void FlushAndMaybeSave()
        {
            Flush();
            long now = _stopwatch.ElapsedTicks;
            if (now - _lastSaveTicks < TimeSpan.TicksPerSecond * 30) return;
            _lastSaveTicks = now;
            Save();
        }

        public void Dispose()
        {
            lock (_gate) { FlushAndMaybeSave(); Save(); }
        }
    }
}