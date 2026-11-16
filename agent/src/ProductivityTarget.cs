using System;
using System.Text;

namespace WorkFolio.Agent
{
    /// <summary>How a day's eligible productive time compares with the daily target.</summary>
    internal enum ProductivityStatus
    {
        BelowTarget,
        OnTarget,
        AboveTarget
    }

    /// <summary>
    /// The outcome of comparing a day's eligible productive time with the target.
    /// Deliberately a value object with no IO: the same evaluation runs in the
    /// tray UI, in the background service and in the unit tests, so the number the
    /// employee sees can never disagree with the number the tests verified.
    /// </summary>
    internal sealed class ProductivityResult
    {
        public ProductivityResult(long eligibleSeconds, long targetSeconds,
                                  ProductivityStatus status, double progressPercent, string message)
        {
            EligibleSeconds = eligibleSeconds;
            TargetSeconds = targetSeconds;
            Status = status;
            ProgressPercent = progressPercent;
            Message = message;
        }

        /// <summary>Eligible productive seconds (work only - breaks and away time excluded).</summary>
        public readonly long EligibleSeconds;
        public readonly long TargetSeconds;
        public readonly ProductivityStatus Status;

        /// <summary>eligible / target * 100, kept at full precision; rounded only for display.</summary>
        public readonly double ProgressPercent;

        /// <summary>Personalised, human-readable summary for this result.</summary>
        public readonly string Message;

        /// <summary>Positive when above target, negative when below, zero when exact.</summary>
        public long DifferenceSeconds { get { return EligibleSeconds - TargetSeconds; } }

        public long OvertimeSeconds { get { return Math.Max(0, EligibleSeconds - TargetSeconds); } }

        public long RemainingSeconds { get { return Math.Max(0, TargetSeconds - EligibleSeconds); } }

        public bool ReachedTarget { get { return EligibleSeconds >= TargetSeconds; } }
    }

    /// <summary>A progress milestone that has just been crossed and should be shown once.</summary>
    internal sealed class MilestoneNotice
    {
        public MilestoneNotice(int percent, string message)
        {
            Percent = percent;
            Message = message;
        }

        public readonly int Percent;
        public readonly string Message;
    }

    /// <summary>
    /// The 8-hour productivity target and its messaging rules.
    ///
    /// The target, the milestone percentages and the cooldown are all injected so
    /// an administrator's centrally-configured policy can replace the defaults
    /// without touching this code. All arithmetic is done on whole seconds;
    /// formatting is the only place rounding happens, so the below/on/above
    /// classification is exact to the second.
    /// </summary>
    internal sealed class ProductivityTarget
    {
        /// <summary>8 hours, in seconds. The default daily productive target.</summary>
        public const long DefaultTargetSeconds = 28800;

        public static readonly int[] DefaultMilestones = new int[] { 25, 50, 75, 90 };

        /// <summary>Minimum seconds between two milestone messages, so they cannot nag.</summary>
        public const int DefaultCooldownSeconds = 600;

        private readonly long _targetSeconds;
        private readonly int[] _milestones;
        private readonly int _cooldownSeconds;

        private int _announcedUpTo;
        private DateTime _lastAnnounceUtc = DateTime.MinValue;

        public ProductivityTarget() : this(DefaultTargetSeconds) { }

        public ProductivityTarget(long targetSeconds)
            : this(targetSeconds, DefaultMilestones, DefaultCooldownSeconds) { }

        public ProductivityTarget(long targetSeconds, int[] milestones, int cooldownSeconds)
        {
            _targetSeconds = targetSeconds > 0 ? targetSeconds : DefaultTargetSeconds;
            _milestones = milestones != null && milestones.Length > 0
                ? (int[])milestones.Clone()
                : (int[])DefaultMilestones.Clone();
            Array.Sort(_milestones);
            _cooldownSeconds = cooldownSeconds >= 0 ? cooldownSeconds : DefaultCooldownSeconds;
        }

        public long TargetSeconds { get { return _targetSeconds; } }

        /// <summary>Classifies eligible productive seconds and builds the matching message.</summary>
        public ProductivityResult Evaluate(long eligibleSeconds)
        {
            if (eligibleSeconds < 0) eligibleSeconds = 0;

            ProductivityStatus status;
            if (eligibleSeconds < _targetSeconds) status = ProductivityStatus.BelowTarget;
            else if (eligibleSeconds == _targetSeconds) status = ProductivityStatus.OnTarget;
            else status = ProductivityStatus.AboveTarget;

            double percent = _targetSeconds > 0
                ? (eligibleSeconds / (double)_targetSeconds) * 100.0
                : 0.0;

            return new ProductivityResult(eligibleSeconds, _targetSeconds, status, percent,
                                          BuildMessage(eligibleSeconds, status));
        }

        /// <summary>
        /// Returns the next milestone the employee has just reached, or null when
        /// there is nothing new to say. Once a milestone is announced it is not
        /// repeated, and a cooldown keeps two crossings from firing back to back.
        /// </summary>
        public MilestoneNotice NextMilestone(long eligibleSeconds)
        {
            return NextMilestone(eligibleSeconds, DateTime.UtcNow);
        }

        public MilestoneNotice NextMilestone(long eligibleSeconds, DateTime nowUtc)
        {
            if (eligibleSeconds < 0) eligibleSeconds = 0;

            double percent = _targetSeconds > 0
                ? (eligibleSeconds / (double)_targetSeconds) * 100.0
                : 0.0;

            int reached = 0;
            foreach (int milestone in _milestones)
            {
                if (percent >= milestone) reached = milestone;
                else break;
            }

            if (reached <= _announcedUpTo) return null;

            // The first crossing always shows. After that, respect the cooldown so
            // a burst of ticks at a boundary cannot spam the employee.
            if (_announcedUpTo > 0 && (nowUtc - _lastAnnounceUtc).TotalSeconds < _cooldownSeconds)
                return null;

            _announcedUpTo = reached;
            _lastAnnounceUtc = nowUtc;
            return new MilestoneNotice(reached, MilestoneMessage(reached));
        }

        /// <summary>Forgets announced milestones. Called when a new shift/day begins.</summary>
        public void Reset()
        {
            _announcedUpTo = 0;
            _lastAnnounceUtc = DateTime.MinValue;
        }

        /// <summary>
        /// The full post-clock-out result: clock-in, clock-out, total attendance,
        /// eligible productive time, the target, the difference and the status.
        /// The personalised message is appended so the closing dialog and the
        /// stored history entry are built from exactly the same figures.
        /// </summary>
        public string BuildSummary(DateTime clockInLocal, DateTime clockOutLocal,
                                   long attendanceSeconds, long eligibleSeconds)
        {
            ProductivityResult result = Evaluate(eligibleSeconds);

            string difference;
            if (result.DifferenceSeconds == 0) difference = "0m (on target)";
            else if (result.DifferenceSeconds > 0) difference = "+" + FormatSpoken(result.OvertimeSeconds) + " over";
            else difference = FormatSpoken(result.RemainingSeconds) + " short";

            StringBuilder sb = new StringBuilder();
            sb.AppendLine("Date: " + clockOutLocal.ToString("ddd, d MMM yyyy"));
            sb.AppendLine("Clock in:  " + clockInLocal.ToString("hh:mm tt"));
            sb.AppendLine("Clock out: " + clockOutLocal.ToString("hh:mm tt"));
            sb.AppendLine("Attendance: " + FormatDuration(attendanceSeconds));
            sb.AppendLine("Eligible productive time: " + FormatDuration(eligibleSeconds));
            sb.AppendLine("Daily target: " + FormatTarget(_targetSeconds));
            sb.AppendLine("Difference: " + difference);
            sb.AppendLine("Status: " + StatusText(result.Status));
            sb.AppendLine();
            sb.Append(result.Message);
            return sb.ToString().TrimEnd();
        }

        public static string StatusText(ProductivityStatus status)
        {
            switch (status)
            {
                case ProductivityStatus.OnTarget: return "Target met";
                case ProductivityStatus.AboveTarget: return "Target exceeded";
                default: return "Below target";
            }
        }

        private string BuildMessage(long eligible, ProductivityStatus status)
        {
            string target = FormatTarget(_targetSeconds);
            switch (status)
            {
                case ProductivityStatus.BelowTarget:
                    long remaining = _targetSeconds - eligible;
                    return "\u23F3 Your productive time today was " + FormatDuration(eligible) + ". " +
                           "Your daily target is " + target + ". " +
                           "You are " + FormatSpoken(remaining) + " below your goal. " +
                           "Keep improving your consistency and aim to reach your target tomorrow! \U0001F4AA";

                case ProductivityStatus.OnTarget:
                    return "\U0001F3C6 Outstanding work! You've completed your " + target +
                           " productivity target today. Excellent consistency! \U0001F31F";

                default:
                    long overtime = eligible - _targetSeconds;
                    if (overtime < 3600)
                        return "\U0001F31F Excellent work! You've completed your daily target and contributed an extra " +
                               FormatSpoken(overtime) + "!";
                    if (overtime < 2 * 3600)
                        return "\U0001F680 Outstanding effort! You've exceeded your target by " +
                               FormatSpoken(overtime) + "!";
                    return "\U0001F3C6 Exceptional dedication! You've completed " +
                           FormatSpoken(eligible) + " of eligible productive time today!";
            }
        }

        private string MilestoneMessage(int percent)
        {
            switch (percent)
            {
                case 25: return "\U0001F680 Great start! Keep building your momentum.";
                case 50: return "\U0001F4AA You're making progress. Keep your focus on today's target.";
                case 75: return "\U0001F525 You're getting closer to your " + FormatTarget(_targetSeconds) + " goal. Keep going!";
                case 90: return "\U0001F3AF Almost there! You're close to completing your daily target.";
                default: return "\U0001F44D " + percent + "% of your daily target reached. Keep going!";
            }
        }

        /// <summary>Compact duration for summaries: "7h 20m", "8h", "45m".</summary>
        public static string FormatDuration(long seconds)
        {
            if (seconds < 0) seconds = 0;
            long hours = seconds / 3600;
            long minutes = (seconds % 3600) / 60;
            if (hours > 0 && minutes > 0) return hours + "h " + minutes + "m";
            if (hours > 0) return hours + "h";
            if (minutes > 0) return minutes + "m";
            return seconds + "s";
        }

        /// <summary>Whole-hour targets read as "8 hours"; anything else falls back to FormatDuration.</summary>
        public static string FormatTarget(long seconds)
        {
            if (seconds > 0 && seconds % 3600 == 0)
            {
                long hours = seconds / 3600;
                return hours + (hours == 1 ? " hour" : " hours");
            }
            return FormatDuration(seconds);
        }

        /// <summary>Natural-language duration for messages: "40 minutes", "15 minutes", "1 hour", "9 hours".</summary>
        public static string FormatSpoken(long seconds)
        {
            if (seconds < 0) seconds = 0;
            if (seconds == 0) return "0 minutes";
            if (seconds < 60) return seconds + (seconds == 1 ? " second" : " seconds");
            if (seconds < 3600)
            {
                long minutes = seconds / 60;
                return minutes + (minutes == 1 ? " minute" : " minutes");
            }
            long hours = seconds / 3600;
            long remainder = (seconds % 3600) / 60;
            if (remainder == 0) return hours + (hours == 1 ? " hour" : " hours");
            return hours + (hours == 1 ? " hour " : " hours ") +
                   remainder + (remainder == 1 ? " minute" : " minutes");
        }
    }
}
