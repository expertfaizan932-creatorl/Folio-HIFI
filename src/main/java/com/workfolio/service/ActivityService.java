package com.workfolio.service;

import com.workfolio.model.ActivityLog;
import com.workfolio.model.Employee;
import com.workfolio.repository.ActivityLogRepository;
import org.springframework.stereotype.Service;

import javax.servlet.http.HttpServletRequest;
import java.util.List;
import java.util.Map;

@Service
public class ActivityService {

    private final ActivityLogRepository repository;

    public ActivityService(ActivityLogRepository repository) {
        this.repository = repository;
    }

    public void log(HttpServletRequest request, Employee user, String action, String details) {
        if (user == null) return;
        String ip = request == null || request.getRemoteAddr() == null ? "unknown"
                : "0:0:0:0:0:0:0:1".equals(request.getRemoteAddr()) ? "localhost" : request.getRemoteAddr();
        repository.save(ActivityLog.of(user.getEmail(), user.getFullName(), user.getRole().name(),
                action, details, ip));
    }

    public void logLogin(HttpServletRequest request, Employee user) {
        log(request, user, "LOGIN", "Signed in successfully");
    }

    public void logLogout(String userEmail) {
        Employee user = null;
        if (userEmail != null) {
            // best-effort: we don't have the entity reference here, log with email only
        }
    }

    public void logAction(Employee user, String action, String details) {
        log(null, user, action, details);
    }

    public List<ActivityLog> all() {
        return repository.findAllByOrderByTimestampDesc();
    }

    public List<ActivityLog> byRole(String role) {
        return repository.findByUserRoleOrderByTimestampDesc(role);
    }

    public List<ActivityLog> byEmail(String email) {
        return repository.findByUserEmailOrderByTimestampDesc(email);
    }

    public Map<String, Long> actionCounts() {
        List<ActivityLog> all = repository.findAll();
        java.util.Map<String, Long> m = new java.util.HashMap<>();
        for (ActivityLog a : all) {
            m.put(a.getAction(), m.getOrDefault(a.getAction(), 0L) + 1);
        }
        return m;
    }
}