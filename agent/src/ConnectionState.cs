using System;

namespace WorkFolio.Agent
{
    /// <summary>
    /// Tracks whether the server is currently reachable and computes the delay
    /// before the next attempt.
    ///
    /// The agent must keep recording while it is offline, so this state exists
    /// only to describe the situation to the employee and to space out retries.
    /// It never gates time tracking: an offline agent still counts every second
    /// locally and uploads when the link returns.
    ///
    /// The retry delay grows exponentially (base, 2x, 4x ...) and is capped, with
    /// a random jitter so a network that comes back does not get hit by every
    /// machine on the site at the same instant.
    /// </summary>
    internal sealed class ConnectionState
    {
        public const int DefaultBaseSeconds = 5;
        public const int DefaultCapSeconds = 300;
        public const double DefaultJitterFraction = 0.2;

        private readonly int _baseSeconds;
        private readonly int _capSeconds;
        private readonly double _jitterFraction;
        private readonly Random _random;

        public ConnectionState()
            : this(new Random(), DefaultBaseSeconds, DefaultCapSeconds, DefaultJitterFraction) { }

        public ConnectionState(Random random)
            : this(random, DefaultBaseSeconds, DefaultCapSeconds, DefaultJitterFraction) { }

        public ConnectionState(Random random, int baseSeconds, int capSeconds, double jitterFraction)
        {
            _random = random ?? new Random();
            _baseSeconds = baseSeconds < 1 ? 1 : baseSeconds;
            _capSeconds = capSeconds < _baseSeconds ? _baseSeconds : capSeconds;
            _jitterFraction = jitterFraction < 0 ? 0 : (jitterFraction > 1 ? 1 : jitterFraction);

            Online = true;
            ConsecutiveFailures = 0;
            LastError = null;
        }

        /// <summary>True until the first failure; assumed reachable so the UI starts optimistic.</summary>
        public bool Online { get; private set; }

        public int ConsecutiveFailures { get; private set; }

        public string LastError { get; private set; }

        public DateTime? LastSuccessUtc { get; private set; }

        public DateTime? LastFailureUtc { get; private set; }

        /// <summary>Raised only on an actual change of reachability, not on every call.</summary>
        public event Action<bool, string> Changed;

        public void MarkSuccess()
        {
            bool wasOffline = !Online;
            Online = true;
            ConsecutiveFailures = 0;
            LastError = null;
            LastSuccessUtc = DateTime.UtcNow;
            if (wasOffline) Raise(true);
        }

        public void MarkFailure(string reason)
        {
            bool wasOnline = Online;
            Online = false;
            ConsecutiveFailures++;
            LastError = string.IsNullOrEmpty(reason) ? "the server could not be reached" : reason;
            LastFailureUtc = DateTime.UtcNow;
            if (wasOnline) Raise(false);
        }

        private void Raise(bool online)
        {
            Action<bool, string> handler = Changed;
            if (handler != null) handler(online, StatusText);
        }

        /// <summary>Seconds to wait before the next attempt, from the failure count.</summary>
        public int NextRetrySeconds()
        {
            return Backoff.Seconds(ConsecutiveFailures, _baseSeconds, _capSeconds, _jitterFraction, _random);
        }

        /// <summary>A line the employee (or support) can act on.</summary>
        public string StatusText
        {
            get
            {
                if (Online)
                {
                    return LastSuccessUtc.HasValue ? "Connected" : "Connected (no calls yet)";
                }
                string since = LastFailureUtc.HasValue
                    ? " Last attempt " + LastFailureUtc.Value.ToLocalTime().ToString("HH:mm:ss") + "."
                    : string.Empty;
                return "Offline - still recording, will upload automatically. (" + LastError + ")" + since;
            }
        }
    }

    /// <summary>
    /// Pure retry-delay maths, kept separate so it can be tested without a clock
    /// or a network. attempt 0 means "no failures yet" and returns 0.
    /// </summary>
    internal static class Backoff
    {
        public static int Seconds(int attempt, int baseSeconds, int capSeconds, double jitterFraction, Random random)
        {
            if (attempt <= 0) return 0;
            if (baseSeconds < 1) baseSeconds = 1;
            if (capSeconds < baseSeconds) capSeconds = baseSeconds;
            if (jitterFraction < 0) jitterFraction = 0;
            if (jitterFraction > 1) jitterFraction = 1;

            // 2^(attempt-1) doubles each time; clamp before jitter so a long
            // outage cannot overflow the shift into a negative wait.
            double growth = Math.Pow(2, Math.Min(attempt - 1, 30));
            double raw = baseSeconds * growth;
            if (raw > capSeconds) raw = capSeconds;

            double jitter = 1.0;
            if (jitterFraction > 0 && random != null)
            {
                jitter = 1.0 + (random.NextDouble() * 2.0 - 1.0) * jitterFraction;
            }

            int result = (int)Math.Round(raw * jitter);
            if (result < 1) result = 1;
            if (result > capSeconds) result = capSeconds;
            return result;
        }
    }
}
