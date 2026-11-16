Folio Agent - Employee time tracker
===================================

WHAT THIS IS
    Folio Agent records your working hours automatically and shows you a live
    timer. It runs quietly in the notification area (the small icons next to the
    clock) and can be opened at any time.

FIRST SIGN-IN
    1. Open Folio from the Start menu or the desktop shortcut.
    2. Enter the work email and password your manager gave you.
    3. Tick "Remember my email" if you do not want to type it every day.
    Your sign-in is remembered per Windows user, so nobody else's address can
    appear here.

THE WINDOW
    * Clock In    - start your shift. Your timer starts counting.
    * Clock Out   - end your shift.
    * Take a Break - pause the timer while you are away from your desk. Break
      time is tracked separately and is not counted as work.
    The window has normal minimise, maximise and restore buttons, and you can
    drag it bigger or smaller. Closing the window does NOT stop the agent: it
    keeps running in the notification area. Use the tray icon, right click and
    Exit, to actually quit.

WHAT IS RECORDED
    * Time worked, split into active and idle. Sitting still for three minutes
      counts as idle, so a coffee break does not read as work.
    * Which applications you used and which websites you visited.
    * Screenshots at the interval your manager configured.
    Time is recorded per day and is never reset by a restart, a crash or a
    lunch break. If the network is down, totals are stored on the machine and
    uploaded as soon as it comes back.

SUSPECTING A MISCOUNT?
    The window shows your shift timeline for today, and the website shows the
    same figures plus the raw segments. If they disagree, that is a bug worth
    reporting - please send your email address and the time it happened.

TROUBLESHOOTING
    Agent icon not in the notification area?
        Windows sometimes hides new icons behind the little up-arrow. Click it
        and drag Folio down.
    "Cannot reach the Folio server"?
        The agent keeps recording while offline. Nothing is lost. Fix the
        network and it uploads on its own.
    Program stopped responding?
        Details are written to %LOCALAPPDATA%\WorkFolioAgent\agent-crash.log.
        Send that file to IT.
    Reinstalling
        Run FolioSetup again. It upgrades in place and keeps your settings.