package com.workfolio.model;

import javax.persistence.*;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;

@Entity
@Table(name = "track_records", uniqueConstraints = @UniqueConstraint(columnNames = {"email", "workDate"}))
public class TrackRecord {

    public static final String STATUS_OFF = "OFF";
    public static final String STATUS_WORKING = "WORKING";
    public static final String STATUS_BREAK = "ON_BREAK";

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    private String email;
    private String workDate;

    private LocalDateTime checkIn;
    private LocalDateTime checkOut;
    private LocalDateTime breakStart;
    private LocalDateTime lastSeen;

    private long breakSeconds;
    private long activeSeconds;
    private long idleSeconds;
    private int screenshotCount;

    private String status = STATUS_OFF;
    private String lastStatus = "ACTIVE";

    // ---- mouse & keyboard activity (fed by the desktop agent) ----
    private long keystrokes;
    private long mouseClicks;
    private long mouseScrolls;
    private long mouseDistancePx;

    // ---- what the employee is working on right now ----
    private String currentApp;
    private String currentTitle;
    private String currentHost;
    private String currentCategory;

    // ---- counters for the "how many apps / sites" report ----
    private int distinctApps;
    private int distinctSites;

    // ---- agent identity ----
    private String hostName;
    private String agentVersion;
    private String agentIp;
    private LocalDateTime lastShotAt;

    public TrackRecord() {}

    public Long getId() { return id; }
    public void setId(Long id) { this.id = id; }
    public String getEmail() { return email; }
    public void setEmail(String email) { this.email = email; }
    public String getWorkDate() { return workDate; }
    public void setWorkDate(String workDate) { this.workDate = workDate; }
    public LocalDateTime getCheckIn() { return checkIn; }
    public void setCheckIn(LocalDateTime checkIn) { this.checkIn = checkIn; }
    public LocalDateTime getCheckOut() { return checkOut; }
    public void setCheckOut(LocalDateTime checkOut) { this.checkOut = checkOut; }
    public LocalDateTime getBreakStart() { return breakStart; }
    public void setBreakStart(LocalDateTime breakStart) { this.breakStart = breakStart; }
    public LocalDateTime getLastSeen() { return lastSeen; }
    public void setLastSeen(LocalDateTime lastSeen) { this.lastSeen = lastSeen; }
    public long getBreakSeconds() { return breakSeconds; }
    public void setBreakSeconds(long breakSeconds) { this.breakSeconds = breakSeconds; }
    public long getActiveSeconds() { return activeSeconds; }
    public void setActiveSeconds(long activeSeconds) { this.activeSeconds = activeSeconds; }
    public long getIdleSeconds() { return idleSeconds; }
    public void setIdleSeconds(long idleSeconds) { this.idleSeconds = idleSeconds; }
    public int getScreenshotCount() { return screenshotCount; }
    public void setScreenshotCount(int screenshotCount) { this.screenshotCount = screenshotCount; }
    public String getStatus() { return status; }
    public void setStatus(String status) { this.status = status; }
    public String getLastStatus() { return lastStatus; }
    public void setLastStatus(String lastStatus) { this.lastStatus = lastStatus; }

    public long getKeystrokes() { return keystrokes; }
    public void setKeystrokes(long v) { this.keystrokes = v; }
    public long getMouseClicks() { return mouseClicks; }
    public void setMouseClicks(long v) { this.mouseClicks = v; }
    public long getMouseScrolls() { return mouseScrolls; }
    public void setMouseScrolls(long v) { this.mouseScrolls = v; }
    public long getMouseDistancePx() { return mouseDistancePx; }
    public void setMouseDistancePx(long v) { this.mouseDistancePx = v; }

    public String getCurrentApp() { return currentApp; }
    public void setCurrentApp(String v) { this.currentApp = v; }
    public String getCurrentTitle() { return currentTitle; }
    public void setCurrentTitle(String v) { this.currentTitle = v; }
    public String getCurrentHost() { return currentHost; }
    public void setCurrentHost(String v) { this.currentHost = v; }
    public String getCurrentCategory() { return currentCategory; }
    public void setCurrentCategory(String v) { this.currentCategory = v; }

    public int getDistinctApps() { return distinctApps; }
    public void setDistinctApps(int v) { this.distinctApps = v; }
    public int getDistinctSites() { return distinctSites; }
    public void setDistinctSites(int v) { this.distinctSites = v; }

    public String getHostName() { return hostName; }
    public void setHostName(String v) { this.hostName = v; }
    public String getAgentVersion() { return agentVersion; }
    public void setAgentVersion(String v) { this.agentVersion = v; }
    public String getAgentIp() { return agentIp; }
    public void setAgentIp(String v) { this.agentIp = v; }
    public LocalDateTime getLastShotAt() { return lastShotAt; }
    public void setLastShotAt(LocalDateTime v) { this.lastShotAt = v; }

    public boolean isOnDuty() { return TrackRecord.STATUS_OFF.equals(status) ? false : checkIn != null; }

    public String getCheckInText() {
        return checkIn == null ? "-" : checkIn.format(DateTimeFormatter.ofPattern("hh:mm a"));
    }
    public String getCheckOutText() {
        return checkOut == null ? "-" : checkOut.format(DateTimeFormatter.ofPattern("hh:mm a"));
    }
    public String getLastShotText() {
        return lastShotAt == null ? "-" : lastShotAt.format(DateTimeFormatter.ofPattern("hh:mm a"));
    }

    public long workedSeconds() {
        if (checkIn == null) return 0;
        long end = checkOut != null ? java.time.Duration.between(checkIn, checkOut).getSeconds()
                : java.time.Duration.between(checkIn, LocalDateTime.now()).getSeconds();
        if (end < 0) end = 0;
        return end - breakSeconds - (breakStart != null ? java.time.Duration.between(breakStart, LocalDateTime.now()).getSeconds() : 0);
    }

    /** Share of the shift actually spent typing or moving the mouse, 0-100. */
    public int getProductivityPercent() {
        long denom = activeSeconds + idleSeconds;
        if (denom <= 0) return 0;
        return (int) Math.round((activeSeconds * 100.0) / denom);
    }

    public static String fmt(long secs) {
        if (secs < 0) secs = 0;
        long h = secs / 3600, m = (secs % 3600) / 60;
        return String.format("%02dh %02dm", h, m);
    }

    public String getWorkedText() { return fmt(workedSeconds()); }
    public String getActiveText() { return fmt(activeSeconds); }
    public String getIdleText() { return fmt(idleSeconds); }
    public String getBreakText() { return fmt(breakSeconds + (breakStart != null ? java.time.Duration.between(breakStart, LocalDateTime.now()).getSeconds() : 0)); }
    public String getKeystrokeText() { return String.valueOf(keystrokes); }
    public String getClickText() { return String.valueOf(mouseClicks); }
    public String getScrollText() { return String.valueOf(mouseScrolls); }
    public String getMouseDistanceText() { return mouseDistancePx < 1000 ? mouseDistancePx + " px"
            : String.format("%.1f k px", mouseDistancePx / 1000.0); }
}
