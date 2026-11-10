package com.workfolio.model;

import javax.persistence.*;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;

@Entity
@Table(name = "activity_logs")
public class ActivityLog {

    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    private String userEmail;
    private String userName;
    private String userRole;
    private String action;
    private String details;
    private String ipAddress;
    private LocalDateTime timestamp;

    public ActivityLog() {}

    public static ActivityLog of(String userEmail, String userName, String userRole,
                                 String action, String details, String ipAddress) {
        ActivityLog log = new ActivityLog();
        log.userEmail = userEmail;
        log.userName = userName;
        log.userRole = userRole;
        log.action = action;
        log.details = details;
        log.ipAddress = ipAddress;
        log.timestamp = LocalDateTime.now();
        return log;
    }

    public Long getId() { return id; }
    public void setId(Long id) { this.id = id; }

    public String getUserEmail() { return userEmail; }
    public void setUserEmail(String userEmail) { this.userEmail = userEmail; }

    public String getUserName() { return userName; }
    public void setUserName(String userName) { this.userName = userName; }

    public String getUserRole() { return userRole; }
    public void setUserRole(String userRole) { this.userRole = userRole; }

    public String getAction() { return action; }
    public void setAction(String action) { this.action = action; }

    public String getDetails() { return details; }
    public void setDetails(String details) { this.details = details; }

    public String getIpAddress() { return ipAddress; }
    public void setIpAddress(String ipAddress) { this.ipAddress = ipAddress; }

    public LocalDateTime getTimestamp() { return timestamp; }
    public void setTimestamp(LocalDateTime timestamp) { this.timestamp = timestamp; }

    public String getFriendlyTime() {
        if (timestamp == null) return "";
        return timestamp.format(DateTimeFormatter.ofPattern("dd-MMM-yyyy hh:mm a"));
    }
}