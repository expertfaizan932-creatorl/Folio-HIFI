package com.workfolio.model;

import javax.persistence.*;

/**
 * Screenshots are written to disk (see ScreenshotStorage) and only the file name is
 * kept here, so the database never grows by megabytes per capture.
 */
@Entity
@Table(name = "screenshots", indexes = @Index(name = "idx_shot_email", columnList = "email,capturedAt"))
public class Screenshot {

    public static final String TRIGGER_AUTO = "AUTO";
    public static final String TRIGGER_LIVE = "LIVE";
    public static final String TRIGGER_MANUAL = "MANUAL";

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    private String email;
    private java.time.LocalDateTime capturedAt;

    /** File name inside the per-employee day folder. Null for legacy in-db rows. */
    @Column(length = 300)
    private String fileName;

    private String trigger = TRIGGER_AUTO;

    private String appName;
    private String windowTitle;
    private String host;
    private long sizeBytes;

    private boolean live;

    /** Legacy inline payload - only populated for rows created before disk storage. */
    @Lob
    @Column(length = 2000000)
    private String image;

    public Screenshot() {}

    public Screenshot(String email, java.time.LocalDateTime capturedAt, String image) {
        this.email = email;
        this.capturedAt = capturedAt;
        this.image = image;
    }

    public Long getId() { return id; }
    public void setId(Long id) { this.id = id; }
    public String getEmail() { return email; }
    public void setEmail(String email) { this.email = email; }
    public java.time.LocalDateTime getCapturedAt() { return capturedAt; }
    public void setCapturedAt(java.time.LocalDateTime capturedAt) { this.capturedAt = capturedAt; }
    public String getFileName() { return fileName; }
    public void setFileName(String fileName) { this.fileName = fileName; }
    public String getTrigger() { return trigger; }
    public void setTrigger(String trigger) { this.trigger = trigger; }
    public String getAppName() { return appName; }
    public void setAppName(String appName) { this.appName = appName; }
    public String getWindowTitle() { return windowTitle; }
    public void setWindowTitle(String windowTitle) { this.windowTitle = windowTitle; }
    public String getHost() { return host; }
    public void setHost(String host) { this.host = host; }
    public long getSizeBytes() { return sizeBytes; }
    public void setSizeBytes(long sizeBytes) { this.sizeBytes = sizeBytes; }
    public boolean isLive() { return live; }
    public void setLive(boolean live) { this.live = live; }
    public String getImage() { return image; }
    public void setImage(String image) { this.image = image; }

    public String getTimeText() {
        return capturedAt == null ? "-" : capturedAt.format(java.time.format.DateTimeFormatter.ofPattern("hh:mm:ss a"));
    }
}
