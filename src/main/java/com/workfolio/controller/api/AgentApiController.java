package com.workfolio.controller.api;

import com.workfolio.model.AgentConfig;
import com.workfolio.model.AppUsage;
import com.workfolio.model.Employee;
import com.workfolio.model.Screenshot;
import com.workfolio.model.TrackRecord;
import com.workfolio.service.AgentAuthService;
import com.workfolio.service.EmployeeService;
import com.workfolio.service.TrackService;
import com.workfolio.service.UsageService;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.web.bind.annotation.*;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/**
 * Endpoints consumed by the WorkFolio desktop agent (WorkFolioAgent.exe).
 * Auth is a bearer token obtained from /api/agent/login - the agent is not a
 * browser session, so it does not use Spring Security form login.
 */
@RestController
@RequestMapping("/api/agent")
public class AgentApiController {

    private final EmployeeService employeeService;
    private final AgentAuthService authService;
    private final TrackService trackService;
    private final UsageService usageService;
    private final PasswordEncoder passwordEncoder;

    public AgentApiController(EmployeeService employeeService, AgentAuthService authService,
                              TrackService trackService, UsageService usageService,
                              PasswordEncoder passwordEncoder) {
        this.employeeService = employeeService;
        this.authService = authService;
        this.trackService = trackService;
        this.usageService = usageService;
        this.passwordEncoder = passwordEncoder;
    }

    // ------------------------------------------------------------------ auth

    @PostMapping("/login")
    public Map<String, Object> login(@RequestBody Map<String, String> body) {
        Map<String, Object> res = new HashMap<>();
        String email = body.get("email");
        Employee emp = email == null ? null : employeeService.findByEmail(email.trim().toLowerCase());
        if (emp == null || !passwordEncoder.matches(body.getOrDefault("password", ""), emp.getPassword())) {
            res.put("ok", false);
            res.put("error", "Invalid email or password");
            return res;
        }
        res.put("ok", true);
        res.put("token", authService.createToken(emp.getEmail()));
        res.put("name", emp.getFullName());
        res.put("email", emp.getEmail());
        res.put("role", emp.getRole().name());
        res.put("config", configPayload(usageService.configFor(emp.getEmail())));
        res.put("today", trackService.summary(emp, TrackService.today()));
        return res;
    }

    @PostMapping("/logout")
    public Map<String, Object> logout(@RequestBody(required = false) Map<String, String> body) {
        if (body != null) authService.revoke(body.get("token"));
        Map<String, Object> res = new HashMap<>();
        res.put("ok", true);
        return res;
    }
    // --------------------------------------------------------------- settings

    /** Pushed to the agent on login and on every heartbeat, so admin edits apply live. */
    @GetMapping("/config")
    public Map<String, Object> config(@RequestParam String token) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = tokenEmployee(token, res);
        if (emp == null) return res;
        res.put("ok", true);
        res.put("config", configPayload(usageService.configFor(emp.getEmail())));
        return res;
    }

    private Map<String, Object> configPayload(AgentConfig c) {
        Map<String, Object> m = new HashMap<>();
        m.put("screenshotIntervalSeconds", c.getScreenshotIntervalSeconds());
        m.put("liveStreamIntervalSeconds", c.getLiveStreamIntervalSeconds());
        m.put("idleThresholdSeconds", c.getIdleThresholdSeconds());
        m.put("imageQuality", c.getImageQuality());
        m.put("imageMaxWidth", c.getImageMaxWidth());
        m.put("sampleIntervalSeconds", c.getSampleIntervalSeconds());
        m.put("breakAllowanceMinutes", c.getBreakAllowanceMinutes());
        m.put("captureScreenshots", c.isCaptureScreenshots());
        m.put("trackMouseKeyboard", c.isTrackMouseKeyboard());
        m.put("trackAppUsage", c.isTrackAppUsage());
        m.put("trackWebsites", c.isTrackWebsites());
        m.put("excludedProcesses", c.getExcludedProcesses());
        m.put("blockedSites", c.getBlockedSites());
        return m;
    }

    // -------------------------------------------------------------- attendance

    @PostMapping("/check-in")
    public Map<String, Object> checkIn(@RequestBody(required = false) Map<String, String> body) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = bodyEmployee(body, res);
        if (emp == null) return res;
        res.put("ok", true);
        res.put("today", trackService.checkIn(emp));
        return res;
    }

    @PostMapping("/check-out")
    public Map<String, Object> checkOut(@RequestBody(required = false) Map<String, String> body) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = bodyEmployee(body, res);
        if (emp == null) return res;
        res.put("ok", true);
        res.put("today", trackService.checkOut(emp));
        return res;
    }

    @PostMapping("/break/start")
    public Map<String, Object> breakStart(@RequestBody(required = false) Map<String, String> body) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = bodyEmployee(body, res);
        if (emp == null) return res;
        res.put("ok", true);
        res.put("today", trackService.breakStart(emp));
        return res;
    }

    @PostMapping("/break/end")
    public Map<String, Object> breakEnd(@RequestBody(required = false) Map<String, String> body) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = bodyEmployee(body, res);
        if (emp == null) return res;
        res.put("ok", true);
        res.put("today", trackService.breakEnd(emp));
        return res;
    }

    @PostMapping("/heartbeat")
    public Map<String, Object> heartbeat(@RequestBody Map<String, String> body) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = bodyEmployee(body, res);
        if (emp == null) return res;
        res.put("ok", true);
        res.put("today", trackService.heartbeat(emp, body.getOrDefault("status", "ACTIVE")));
        res.put("config", configPayload(usageService.configFor(emp.getEmail())));
        return res;
    }

    /**
     * One batched call carrying everything the agent observed since the last cycle:
     * active/idle state, mouse+keyboard counters and the foreground window.
     * This keeps network chatter to a single request per sample interval.
     */
    @PostMapping("/telemetry")
    public Map<String, Object> telemetry(@RequestBody Map<String, Object> body) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = bodyEmployee(body, res);
        if (emp == null) return res;

        TrackService.Telemetry t = new TrackService.Telemetry();
        t.status = str(body.get("status"), "ACTIVE");
        t.keystrokes = num(body.get("keystrokes"));
        t.mouseClicks = num(body.get("mouseClicks"));
        t.mouseScrolls = num(body.get("mouseScrolls"));
        t.mouseDistancePx = num(body.get("mouseDistancePx"));
        t.app = str(body.get("app"), null);
        t.title = str(body.get("title"), null);
        t.host = str(body.get("host"), null);
        t.category = str(body.get("category"), null);
        t.hostName = str(body.get("hostName"), null);
        t.agentVersion = str(body.get("agentVersion"), null);
        t.ip = str(body.get("ip"), null);

        res.put("ok", true);
        res.put("today", trackService.recordTelemetry(emp, t));
        res.put("config", configPayload(usageService.configFor(emp.getEmail())));
        return res;
    }

    /**
     * App / website usage sample. Sent once per configured sample interval with the
     * number of seconds the window stayed in the foreground.
     */
    @PostMapping("/usage")
    public Map<String, Object> usage(@RequestBody Map<String, Object> body) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = bodyEmployee(body, res);
        if (emp == null) return res;

        AgentConfig cfg = usageService.configFor(emp.getEmail());
        boolean idle = Boolean.parseBoolean(String.valueOf(body.get("idle")));
        String process = str(body.get("process"), null);
        String host = str(body.get("host"), null);

        if (usageService.isIgnorable(cfg, process, host)) {
            res.put("ok", true);
            res.put("skipped", "excluded");
            return res;
        }

        long seconds = num(body.get("seconds"));
        if (seconds <= 0) {
            res.put("ok", true);
            res.put("skipped", "no-time");
            return res;
        }

        String targetKey = str(body.get("targetKey"), null);
        String targetType = str(body.get("targetType"), AppUsage.TYPE_APP);
        if (targetKey == null) {
            targetKey = AppUsage.TYPE_WEB.equals(targetType) && host != null
                    ? "web:" + host.toLowerCase()
                    : "app:" + (process == null ? "unknown" : process.toLowerCase());
        }
        if (AppUsage.TYPE_WEB.equals(targetType) && !cfg.isTrackWebsites()) {
            res.put("ok", true);
            res.put("skipped", "websites-disabled");
            return res;
        }
        if (!AppUsage.TYPE_WEB.equals(targetType) && !cfg.isTrackAppUsage()) {
            res.put("ok", true);
            res.put("skipped", "apps-disabled");
            return res;
        }

        String displayName = str(body.get("displayName"), null);
        if (displayName == null) {
            displayName = AppUsage.TYPE_WEB.equals(targetType) && host != null ? host : process;
        }
        String category = str(body.get("category"), null);
        if (category == null) {
            category = UsageService.categoryFor(idle ? AppUsage.TYPE_IDLE : targetType, host, process);
        }

        AppUsage saved = usageService.recordSample(emp.getEmail(), targetKey, targetType, displayName,
                process, str(body.get("title"), null), host, category, seconds, idle);
        res.put("ok", true);
        res.put("target", saved == null ? null : saved.getTargetKey());
        return res;
    }

    // ------------------------------------------------------------ screenshots

    /**
     * @param trigger AUTO (scheduled), LIVE (live-stream frame) or MANUAL (employee button)
     * @param image   raw JPEG bytes (multipart) or a base64 string (JSON)
     */
    @PostMapping("/screenshot")
    public Map<String, Object> screenshot(@RequestBody(required = false) Map<String, String> body) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = bodyEmployee(body, res);
        if (emp == null) return res;

        String trigger = body.getOrDefault("trigger", Screenshot.TRIGGER_AUTO);
        boolean live = Screenshot.TRIGGER_LIVE.equalsIgnoreCase(trigger);
        Screenshot saved = trackService.saveScreenshot(emp.getEmail(), body.get("image"), trigger.toUpperCase(),
                body.get("app"), body.get("title"), body.get("host"), live);
        res.put("ok", true);
        res.put("saved", saved != null);
        if (saved != null) {
            res.put("id", saved.getId());
            res.put("capturedAt", saved.getCapturedAt().toString());
        }
        return res;
    }

    // ------------------------------------------------------------------ state

    @GetMapping("/today")
    public Map<String, Object> today(@RequestParam String token) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = tokenEmployee(token, res);
        if (emp == null) return res;
        res.put("ok", true);
        res.put("today", trackService.summary(emp, TrackService.today()));
        List<AppUsage> apps = usageService.usageFor(emp.getEmail(), TrackService.today(), AppUsage.TYPE_APP);
        List<Map<String, Object>> appRows = new ArrayList<>();
        for (int i = 0; i < Math.min(apps.size(), 8); i++) {
            appRows.add(appRow(apps.get(i)));
        }
        List<AppUsage> sites = usageService.usageFor(emp.getEmail(), TrackService.today(), AppUsage.TYPE_WEB);
        List<Map<String, Object>> siteRows = new ArrayList<>();
        for (int i = 0; i < Math.min(sites.size(), 8); i++) {
            siteRows.add(appRow(sites.get(i)));
        }
        res.put("apps", appRows);
        res.put("sites", siteRows);
        return res;
    }

    /**
     * Today's shift segments for the agent's own timeline. The portal report is
     * per employee per day, so this returns that record broken into the clock
     * in / clock out / worked figures the desktop panel renders.
     */
    @GetMapping("/day")
    public Map<String, Object> day(@RequestParam String token) {
        Map<String, Object> res = new HashMap<>();
        Employee emp = tokenEmployee(token, res);
        if (emp == null) return res;

        res.put("ok", true);
        res.put("date", TrackService.today());

        List<Map<String, Object>> segments = new ArrayList<>();
        long totalWorked = 0;
        long totalBreak = 0;
        for (TrackRecord r : trackService.allOnDate(TrackService.today())) {
            if (!emp.getEmail().equalsIgnoreCase(r.getEmail())) continue;
            Map<String, Object> m = new HashMap<>();
            m.put("checkIn", r.getCheckInText());
            m.put("checkOut", r.getCheckOutText());
            m.put("workedSec", r.workedSeconds());
            m.put("breakSec", r.getBreakSeconds());
            m.put("status", r.getStatus());
            m.put("onDuty", r.isOnDuty());
            segments.add(m);
            totalWorked += r.workedSeconds();
            totalBreak += r.getBreakSeconds();
        }

        AgentConfig cfg = usageService.configFor(emp.getEmail());
        res.put("segments", segments);
        res.put("totalWorkedSec", totalWorked);
        res.put("totalBreakSec", totalBreak);
        res.put("breakAllowanceSec", cfg.getBreakAllowanceMinutes() * 60L);
        return res;
    }

    private Map<String, Object> appRow(AppUsage u) {
        Map<String, Object> m = new HashMap<>();
        m.put("name", u.getDisplayName());
        m.put("key", u.getTargetKey());
        m.put("type", u.getTargetType());
        m.put("category", u.getCategory());
        m.put("time", u.getTimeText());
        m.put("seconds", u.getSeconds());
        return m;
    }

    // ---------------------------------------------------------------- helpers

    /** Resolves the employee for a bearer token, writing the error into the response. */
    private Employee tokenEmployee(String token, Map<String, Object> res) {
        Employee emp = authService.requireEmployee(token, employeeService);
        if (emp == null) {
            res.put("ok", false);
            res.put("error", "Unauthorized - please login again");
        }
        return emp;
    }

    /**
     * Resolves the employee from a JSON request body carrying a "token" field.
     * Accepts both the string-typed bodies of the attendance calls and the wider
     * telemetry/usage payload shape.
     */
    private Employee bodyEmployee(Map<String, ?> body, Map<String, Object> res) {
        Object token = body == null ? null : body.get("token");
        return tokenEmployee(token == null ? null : token.toString(), res);
    }

    private static String str(Object value, String fallback) {
        if (value == null) return fallback;
        String s = String.valueOf(value).trim();
        return s.isEmpty() ? fallback : s;
    }

    private static long num(Object value) {
        if (value == null) return 0;
        if (value instanceof Number) return ((Number) value).longValue();
        try {
            return Long.parseLong(String.valueOf(value).trim());
        } catch (NumberFormatException e) {
            return 0;
        }
    }
}
