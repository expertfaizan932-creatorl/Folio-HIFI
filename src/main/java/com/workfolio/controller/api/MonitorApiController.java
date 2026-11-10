package com.workfolio.controller.api;

import com.workfolio.model.AppUsage;
import com.workfolio.model.Employee;
import com.workfolio.model.Screenshot;
import com.workfolio.model.TrackRecord;
import com.workfolio.service.EmployeeService;
import com.workfolio.service.ReportAccessService;
import com.workfolio.service.TrackService;
import com.workfolio.service.UsageService;
import org.springframework.http.CacheControl;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.security.core.Authentication;
import org.springframework.web.bind.annotation.*;

import java.time.Duration;
import java.time.LocalDateTime;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.concurrent.TimeUnit;

@RestController
@RequestMapping("/api/monitor")
public class MonitorApiController {

    private final EmployeeService employeeService;
    private final TrackService trackService;
    private final UsageService usageService;
    private final ReportAccessService access;

    public MonitorApiController(EmployeeService employeeService, TrackService trackService,
                                UsageService usageService, ReportAccessService access) {
        this.employeeService = employeeService;
        this.trackService = trackService;
        this.usageService = usageService;
        this.access = access;
    }

    /**
     * Live grid data: one row per employee with duty status, the window they are
     * working in, the site/app they are on, and a screenshot id the grid can
     * pull over /api/monitor/shot/{id}.
     */
    @GetMapping("/live")
    public List<Map<String, Object>> live(Authentication auth) {
        Employee viewer = employeeService.findByEmail(auth.getName());
        String date = TrackService.today();
        List<Map<String, Object>> out = new ArrayList<>();
        // Only the people this viewer may supervise. An HR must not see the
        // admin's row, or another HR's, in the live grid.
        for (Employee e : access.visibleEmployees(viewer)) {
            TrackRecord r = trackService.recordFor(e.getEmail(), date);
            Optional<Screenshot> shot = trackService.latestLiveScreenshotEntity(e.getEmail());
            if (!shot.isPresent()) shot = trackService.latestScreenshotEntity(e.getEmail());

            Map<String, Object> m = new HashMap<>();
            m.put("email", e.getEmail());
            m.put("name", e.getFullName());
            m.put("designation", e.getDesignation());
            m.put("department", e.getDepartment());
            m.put("onDuty", r.isOnDuty());
            m.put("idle", "IDLE".equalsIgnoreCase(r.getLastStatus()));
            m.put("status", r.getStatus());
            m.put("checkIn", r.getCheckInText());
            m.put("checkOut", r.getCheckOutText());
            m.put("worked", TrackService.fmt(r.workedSeconds()));
            m.put("workedSec", r.workedSeconds());
            m.put("active", TrackService.fmt(r.getActiveSeconds()));
            m.put("idleTime", TrackService.fmt(r.getIdleSeconds()));
            m.put("breakTime", TrackService.fmt(r.getBreakSeconds()));
            m.put("productivity", r.getProductivityPercent());
            m.put("screenshots", r.getScreenshotCount());
            m.put("lastShot", r.getLastShotText());
            m.put("keystrokes", r.getKeystrokes());
            m.put("mouseClicks", r.getMouseClicks());
            m.put("currentApp", r.getCurrentApp());
            m.put("currentTitle", r.getCurrentTitle());
            m.put("currentHost", r.getCurrentHost());
            m.put("currentCategory", r.getCurrentCategory());
            m.put("appCount", usageService.appCount(e.getEmail(), date));
            m.put("siteCount", usageService.siteCount(e.getEmail(), date));
            m.put("hostName", r.getHostName());
            m.put("agentVersion", r.getAgentVersion());
            m.put("lastSeenAgo", secondsAgo(r.getLastSeen()));
            m.put("shotId", shot.map(Screenshot::getId).orElse(null));
            m.put("shotAt", shot.map(Screenshot::getCapturedAt).orElse(null));
            out.add(m);
        }
        out.sort((a, b) -> Boolean.compare((Boolean) b.get("onDuty"), (Boolean) a.get("onDuty")));
        return out;
    }

    /**
     * Screenshot image bytes. Thumbnails are small enough to poll aggressively.
     *
     * The id is a direct reference to someone's screen, so ownership is checked
     * before the bytes are read: ids are sequential, and without this check any
     * HR could walk the id space and pull another HR's or the admin's captures.
     */
    @GetMapping("/shot/{id}")
    public ResponseEntity<byte[]> shot(@PathVariable Long id,
                                       @RequestParam(defaultValue = "true") boolean cache,
                                       Authentication auth) {
        Employee viewer = employeeService.findByEmail(auth.getName());
        Optional<Screenshot> meta = trackService.screenshotEntity(id);
        if (!meta.isPresent()) return ResponseEntity.notFound().build();
        if (!access.canView(viewer, meta.get().getEmail())) {
            // 403, not 404: the id really does exist, and the caller is
            // authenticated to this endpoint already, so pretending otherwise
            // would only confuse. What must not leak is the owner or the image.
            return ResponseEntity.status(HttpStatus.FORBIDDEN).build();
        }
        byte[] data = trackService.screenshotBytes(id);
        if (data == null) return ResponseEntity.notFound().build();
        return ResponseEntity.ok()
                .contentType(MediaType.IMAGE_JPEG)
                .cacheControl(cache ? CacheControl.maxAge(10, TimeUnit.SECONDS)
                        : CacheControl.noCache())
                .body(data);
    }

    /**
     * Top apps and sites for one employee today, for the drill-down panel.
     *
     * {@code email} comes straight off the query string, so it is checked against
     * the viewer rather than trusted - otherwise the grid could ask for anyone.
     */
    @GetMapping("/usage")
    public ResponseEntity<Map<String, Object>> usage(@RequestParam String email,
                                                     @RequestParam(required = false) String date,
                                                     Authentication auth) {
        Employee viewer = employeeService.findByEmail(auth.getName());
        if (!access.canView(viewer, email)) {
            return ResponseEntity.status(HttpStatus.FORBIDDEN).build();
        }
        String day = date == null || date.isEmpty() ? TrackService.today() : date;
        Map<String, Object> res = new HashMap<>();
        res.put("email", email);
        res.put("date", day);
        res.put("apps", usageRows(usageService.usageFor(email, day, AppUsage.TYPE_APP), 12));
        res.put("sites", usageRows(usageService.usageFor(email, day, AppUsage.TYPE_WEB), 12));
        res.put("appSeconds", usageService.appSeconds(email, day));
        res.put("siteSeconds", usageService.siteSeconds(email, day));
        res.put("appCount", usageService.appCount(email, day));
        res.put("siteCount", usageService.siteCount(email, day));
        TrackRecord r = trackService.recordFor(email, day);
        res.put("workedSec", r.workedSeconds());
        res.put("activeSec", r.getActiveSeconds());
        res.put("idleSec", r.getIdleSeconds());
        res.put("screenshotCount", r.getScreenshotCount());
        res.put("keystrokes", r.getKeystrokes());
        res.put("mouseClicks", r.getMouseClicks());
        res.put("mouseScrolls", r.getMouseScrolls());
        res.put("productivity", r.getProductivityPercent());
        return ResponseEntity.ok(res);
    }

    private List<Map<String, Object>> usageRows(List<AppUsage> list, int limit) {
        List<Map<String, Object>> out = new ArrayList<>();
        for (int i = 0; i < list.size() && i < limit; i++) {
            AppUsage u = list.get(i);
            Map<String, Object> m = new HashMap<>();
            m.put("name", u.getDisplayName());
            m.put("key", u.getTargetKey());
            m.put("category", u.getCategory());
            m.put("title", u.getWindowTitle());
            m.put("seconds", u.getSeconds());
            m.put("time", u.getTimeText());
            out.add(m);
        }
        return out;
    }

    private static String secondsAgo(LocalDateTime then) {
        if (then == null) return "-";
        long s = Duration.between(then, LocalDateTime.now()).getSeconds();
        if (s < 0) s = 0;
        if (s < 60) return s + "s ago";
        if (s < 3600) return (s / 60) + "m ago";
        return (s / 3600) + "h ago";
    }
}
