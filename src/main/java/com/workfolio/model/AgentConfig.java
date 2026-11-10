package com.workfolio.model;

import javax.persistence.*;

/**
 * Per-employee monitoring policy. The desktop agent pulls this on login and on every
 * heartbeat, so changing it here takes effect on the next agent cycle without a redeploy.
 */
@Entity
@Table(name = "agent_configs", uniqueConstraints = @UniqueConstraint(columnNames = "email"))
public class AgentConfig {

    @Id
    @Column(name = "email", nullable = false, length = 190)
    private String email;

    /** How often the agent grabs a screenshot, in seconds (custom time period). */
    private int screenshotIntervalSeconds = 300;

    /** How often the agent grabs a low-res live-stream frame, in seconds. 0 = off. */
    private int liveStreamIntervalSeconds = 0;

    /** Idle is declared after this many seconds without mouse/keyboard input. */
    private int idleThresholdSeconds = 180;

    /** Screenshot JPEG quality 1-100. */
    private int imageQuality = 55;

    /** Longest edge, in pixels, of a captured screenshot. */
    private int imageMaxWidth = 1280;

    /** Seconds between window/app samples used for the app & website usage report. */
    private int sampleIntervalSeconds = 10;

    /**
     * Paid break allowance per day, in minutes. Drives the agent's "break time left".
     * Left nullable on purpose: ddl-auto=update adds new NOT NULL columns to an
     * existing table with rows already in it, and H2 rejects that statement. A
     * nullable column with a Java-side default migrates cleanly.
     */
    @Column(name = "break_allowance_minutes")
    private Integer breakAllowanceMinutes = 30;

    private boolean captureScreenshots = true;
    private boolean trackMouseKeyboard = true;
    private boolean trackAppUsage = true;
    private boolean trackWebsites = true;

    /** Comma separated process names never recorded, e.g. "explorer.exe,dwm.exe". */
    @Column(length = 1000)
    private String excludedProcesses = "explorer.exe,dwm.exe,ShellExperienceHost,StartMenuExperienceHost,SearchHost,TextInputHost,LockApp.exe,sihost.exe,RuntimeBroker,SystemSettings.exe";

    /** Comma separated hosts never recorded, e.g. "mail.google.com". */
    @Column(length = 1000)
    private String blockedSites = "";

    public AgentConfig() {}

    public AgentConfig(String email) { this.email = email; }

    public String getEmail() { return email; }
    public void setEmail(String email) { this.email = email; }

    public int getScreenshotIntervalSeconds() { return screenshotIntervalSeconds; }
    public void setScreenshotIntervalSeconds(int v) { this.screenshotIntervalSeconds = clamp(v, 10, 7200, 300); }

    public int getLiveStreamIntervalSeconds() { return liveStreamIntervalSeconds; }
    public void setLiveStreamIntervalSeconds(int v) { this.liveStreamIntervalSeconds = clamp(v, 0, 600, 0); }

    public int getIdleThresholdSeconds() { return idleThresholdSeconds; }
    public void setIdleThresholdSeconds(int v) { this.idleThresholdSeconds = clamp(v, 10, 3600, 180); }

    public int getImageQuality() { return imageQuality; }
    public void setImageQuality(int v) { this.imageQuality = clamp(v, 10, 100, 55); }

    public int getImageMaxWidth() { return imageMaxWidth; }
    public void setImageMaxWidth(int v) { this.imageMaxWidth = clamp(v, 320, 3840, 1280); }

    public int getSampleIntervalSeconds() { return sampleIntervalSeconds; }
    public void setSampleIntervalSeconds(int v) { this.sampleIntervalSeconds = clamp(v, 2, 300, 10); }

    public int getBreakAllowanceMinutes() {
        return breakAllowanceMinutes == null ? 30 : breakAllowanceMinutes;
    }
    public void setBreakAllowanceMinutes(int v) { this.breakAllowanceMinutes = clamp(v, 0, 480, 30); }

    public boolean isCaptureScreenshots() { return captureScreenshots; }
    public void setCaptureScreenshots(boolean v) { this.captureScreenshots = v; }

    public boolean isTrackMouseKeyboard() { return trackMouseKeyboard; }
    public void setTrackMouseKeyboard(boolean v) { this.trackMouseKeyboard = v; }

    public boolean isTrackAppUsage() { return trackAppUsage; }
    public void setTrackAppUsage(boolean v) { this.trackAppUsage = v; }

    public boolean isTrackWebsites() { return trackWebsites; }
    public void setTrackWebsites(boolean v) { this.trackWebsites = v; }

    public String getExcludedProcesses() { return excludedProcesses; }
    public void setExcludedProcesses(String v) { this.excludedProcesses = v; }

    public String getBlockedSites() { return blockedSites; }
    public void setBlockedSites(String v) { this.blockedSites = v; }

    public boolean isExcludedProcess(String processName) {
        return matchesCsv(excludedProcesses, processName);
    }

    public boolean isBlockedHost(String host) {
        return matchesCsv(blockedSites, host);
    }

    private static boolean matchesCsv(String csv, String value) {
        if (csv == null || csv.isEmpty() || value == null || value.isEmpty()) return false;
        String v = value.trim().toLowerCase();
        for (String part : csv.toLowerCase().split(",")) {
            String p = part.trim();
            if (p.isEmpty()) continue;
            if (v.equals(p)) return true;
            if (v.endsWith("." + p) || v.contains("." + p)) return true;
        }
        return false;
    }

    private static int clamp(int value, int min, int max, int fallback) {
        if (value <= 0) return fallback;
        return Math.max(min, Math.min(max, value));
    }
}
