using System;
using System.Globalization;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Compares the computer's wall clock with the server's authoritative time.
    ///
    /// This never touches the shift ledger: elapsed time is always measured on a
    /// monotonic <see cref="System.Diagnostics.Stopwatch"/>, so a wrong system
    /// clock cannot inflate or shrink worked time. What it can do is make the
    /// displayed clock-in/clock-out look wrong and skew milestone timing, so a
    /// large gap is detected, reported to the local log, and left for the
    /// administrator to act on - never silently "corrected" by moving the clock.
    /// </summary>
    internal sealed class ClockSync
    {
        public static readonly TimeSpan DefaultTolerance = TimeSpan.FromMinutes(2);

        private readonly TimeSpan _tolerance;
        private readonly object _gate = new object();

        private long _driftTicks;
        private bool _observed;
        private bool _significant;

        public ClockSync() : this(DefaultTolerance) { }

        public ClockSync(TimeSpan tolerance)
        {
            _tolerance = tolerance > TimeSpan.Zero ? tolerance : DefaultTolerance;
        }

        public bool Observed { get { lock (_gate) { return _observed; } } }

        public bool Significant { get { lock (_gate) { return _significant; } } }

        /// <summary>Server time minus local time. Positive means the local clock is behind.</summary>
        public TimeSpan Drift { get { lock (_gate) { return TimeSpan.FromTicks(_driftTicks); } } }

        public long DriftSeconds { get { lock (_gate) { return _driftTicks / TimeSpan.TicksPerSecond; } } }

        /// <summary>Records a server reading. Returns true when the drift is significant.</summary>
        public bool Observe(DateTime serverUtc, DateTime localUtc)
        {
            TimeSpan drift = serverUtc.ToUniversalTime() - localUtc.ToUniversalTime();
            lock (_gate)
            {
                _observed = true;
                _driftTicks = drift.Ticks;
                _significant = drift.Duration() > _tolerance;
                return _significant;
            }
        }

        /// <summary>Parses an ISO-8601 serverTime value and observes it against local UTC now.</summary>
        public bool ObserveIso(string serverTimeIso)
        {
            if (string.IsNullOrEmpty(serverTimeIso)) return false;
            DateTime parsed;
            if (!DateTime.TryParse(serverTimeIso, CultureInfo.InvariantCulture,
                    DateTimeStyles.AdjustToUniversal | DateTimeStyles.AssumeUniversal, out parsed))
                return false;
            return Observe(parsed, DateTime.UtcNow);
        }

        /// <summary>Human-readable status for the diagnostics log.</summary>
        public string Describe()
        {
            lock (_gate)
            {
                if (!_observed) return "Server time not yet observed.";
                long seconds = Math.Abs(_driftTicks / TimeSpan.TicksPerSecond);
                if (!_significant)
                    return "This computer's clock agrees with the server (within "
                        + (long)_tolerance.TotalSeconds + "s).";
                string direction = _driftTicks > 0 ? "behind" : "ahead of";
                return "This computer's clock is about " + seconds + "s " + direction
                    + " the server (" + seconds / 60 + "m). Durations are unaffected - "
                    + "they use a monotonic clock - but display times may differ.";
            }
        }
    }
}
