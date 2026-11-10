package com.workfolio.model;

import javax.persistence.*;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;

/**
 * One row = how long an employee spent on a single application / website on a single day.
 * The agent sends a "window sample" every few seconds; consecutive samples for the same
 * target are merged into this row and its seconds are accumulated.
 */
@Entity
@Table(name = "app_usages", indexes = {
        @Index(name = "idx_usage_email_date", columnList = "email,workDate")
})
public class AppUsage {

    public static final String TYPE_APP = "APP";
    public static final String TYPE_WEB = "WEB";
    public static final String TYPE_IDLE = "IDLE";
    public static final String TYPE_LOCK = "LOCK";

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    private String email;
    private String workDate;

    /** Stable grouping key, e.g. "app:chrome.exe" or "web:github.com". */
    @Column(nullable = false, length = 400)
    private String targetKey;

    private String targetType = TYPE_APP;
    private String displayName;
    private String processName;
    private String windowTitle;
    private String host;
    private String category;

    private long seconds;
    private int sampleCount;
    private long firstSeen;
    private long lastSeen;

    private LocalDateTime updatedAt;

    public AppUsage() {}

    public Long getId() { return id; }
    public void setId(Long id) { this.id = id; }

    public String getEmail() { return email; }
    public void setEmail(String email) { this.email = email; }

    public String getWorkDate() { return workDate; }
    public void setWorkDate(String workDate) { this.workDate = workDate; }

    public String getTargetKey() { return targetKey; }
    public void setTargetKey(String targetKey) { this.targetKey = targetKey; }

    public String getTargetType() { return targetType; }
    public void setTargetType(String targetType) { this.targetType = targetType; }

    public String getDisplayName() { return displayName; }
    public void setDisplayName(String displayName) { this.displayName = displayName; }

    public String getProcessName() { return processName; }
    public void setProcessName(String processName) { this.processName = processName; }

    public String getWindowTitle() { return windowTitle; }
    public void setWindowTitle(String windowTitle) { this.windowTitle = windowTitle; }

    public String getHost() { return host; }
    public void setHost(String host) { this.host = host; }

    public String getCategory() { return category; }
    public void setCategory(String category) { this.category = category; }

    public long getSeconds() { return seconds; }
    public void setSeconds(long seconds) { this.seconds = seconds; }

    public int getSampleCount() { return sampleCount; }
    public void setSampleCount(int sampleCount) { this.sampleCount = sampleCount; }

    public long getFirstSeen() { return firstSeen; }
    public void setFirstSeen(long firstSeen) { this.firstSeen = firstSeen; }

    public long getLastSeen() { return lastSeen; }
    public void setLastSeen(long lastSeen) { this.lastSeen = lastSeen; }

    public LocalDateTime getUpdatedAt() { return updatedAt; }
    public void setUpdatedAt(LocalDateTime updatedAt) { this.updatedAt = updatedAt; }

    public String getTimeText() {
        long s = seconds < 0 ? 0 : seconds;
        long h = s / 3600, m = (s % 3600) / 60, sec = s % 60;
        return h > 0 ? String.format("%dh %02dm", h, m) : String.format("%dm %02ds", m, sec);
    }

    public String getShortTimeText() {
        long s = seconds < 0 ? 0 : seconds;
        long h = s / 3600, m = (s % 3600) / 60;
        return h > 0 ? String.format("%dh %02dm", h, m) : String.format("%dm", m);
    }

    public String getUpdatedText() {
        return updatedAt == null ? "-" : updatedAt.format(DateTimeFormatter.ofPattern("dd MMM hh:mm a"));
    }
}
