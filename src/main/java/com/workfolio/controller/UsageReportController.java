package com.workfolio.controller;

import com.workfolio.model.AgentConfig;
import com.workfolio.model.AppUsage;
import com.workfolio.model.Employee;
import com.workfolio.model.Screenshot;
import com.workfolio.model.TrackRecord;
import com.workfolio.repository.ScreenshotRepository;
import com.workfolio.service.EmployeeService;
import com.workfolio.service.ReportAccessService;
import com.workfolio.service.ScreenshotStorage;
import com.workfolio.service.TrackService;
import com.workfolio.service.UsageService;
import org.springframework.http.HttpHeaders;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestParam;

import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * The report the admin sends on: who worked how long, which apps and sites were used
 * and for how long, plus the mouse/keyboard and screenshot evidence for the period.
 */
@Controller
public class UsageReportController {

    private static final DateTimeFormatter DAY = DateTimeFormatter.ISO_LOCAL_DATE;

    private final EmployeeService employeeService;
    private final TrackService trackService;
    private final UsageService usageService;
    private final ScreenshotRepository shotRepo;
    private final ScreenshotStorage storage;
    private final ReportAccessService access;

    public UsageReportController(EmployeeService employeeService, TrackService trackService,
                                 UsageService usageService, ScreenshotRepository shotRepo,
                                 ScreenshotStorage storage, ReportAccessService access) {
        this.employeeService = employeeService;
        this.trackService = trackService;
        this.usageService = usageService;
        this.shotRepo = shotRepo;
        this.storage = storage;
        this.access = access;
    }

    // ------------------------------------------------------------- report page

    @GetMapping("/admin/usage")
    public String usageReport(@RequestParam(required = false) String email,
                              @RequestParam(required = false) String from,
                              @RequestParam(required = false) String to,
                              @RequestParam(defaultValue = "all") String scope,
                              @RequestParam(required = false) String purged,
                              org.springframework.security.core.Authentication auth,
                              Model model) {

        Employee viewer = employeeService.findByEmail(auth.getName());
        model.addAttribute("currentUser", viewer);
        if (purged != null) model.addAttribute("purged", purged);
        LocalDate endDay = parseDay(to, LocalDate.now());
        LocalDate startDay;
        String scopeLabel;
        switch (scope == null ? "all" : scope) {
            case "day":
                startDay = endDay; scopeLabel = endDay.format(DateTimeFormatter.ofPattern("dd MMM yyyy")); break;
            case "week":
                startDay = endDay.minusDays(6); scopeLabel = "Last 7 days"; break;
            case "month":
                startDay = endDay.minusDays(29); scopeLabel = "Last 30 days"; break;
            default:
                startDay = parseDay(from, endDay.minusDays(6));
                scopeLabel = "Custom range"; break;
        }
        if (startDay.isAfter(endDay)) {
            LocalDate swap = startDay; startDay = endDay; endDay = swap;
        }

        // Rows and the employee dropdown both come from the access-filtered list,
        // so an HR never sees an HR colleague or the admin offered for selection.
        List<Employee> employees = access.visibleEmployees(viewer);
        // The email parameter is user input: asking for someone you may not see
        // collapses to "everyone you can see" rather than being honoured.
        String selected = access.resolveSelection(viewer, email);
        List<LocalDate> days = new ArrayList<>();
        for (LocalDate d = startDay; !d.isAfter(endDay); d = d.plusDays(1)) {
            days.add(d);
        }

        model.addAttribute("employees", employees);
        model.addAttribute("selEmail", selected == null ? "" : selected);
        model.addAttribute("from", startDay.format(DAY));
        model.addAttribute("to", endDay.format(DAY));
        model.addAttribute("scope", scope == null ? "all" : scope);
        model.addAttribute("scopeLabel", scopeLabel);
        model.addAttribute("dayCount", days.size());

        long totalWorked = 0, totalActive = 0, totalIdle = 0, totalBreak = 0, totalApp = 0, totalSite = 0;
        long totalKeys = 0, totalClicks = 0, totalScrolls = 0, totalShots = 0;
        int maxShots = 0;
        Map<String, long[]> perEmployee = new LinkedHashMap<>();

        for (LocalDate d : days) {
            String day = d.format(DAY);
            for (Employee e : employees) {
                if (selected != null && !selected.equalsIgnoreCase(e.getEmail())) continue;
                TrackRecord r = trackService.recordFor(e.getEmail(), day);
                long appSec = usageService.appSeconds(e.getEmail(), day);
                long siteSec = usageService.siteSeconds(e.getEmail(), day);
                if (r.workedSeconds() == 0 && appSec == 0 && r.getScreenshotCount() == 0) continue;

                totalWorked += r.workedSeconds();
                totalActive += r.getActiveSeconds();
                totalIdle += r.getIdleSeconds();
                totalBreak += r.getBreakSeconds();
                totalApp += appSec;
                totalSite += siteSec;
                totalKeys += r.getKeystrokes();
                totalClicks += r.getMouseClicks();
                totalScrolls += r.getMouseScrolls();
                totalShots += r.getScreenshotCount();

                long[] bucket = perEmployee.computeIfAbsent(e.getEmail(), k -> new long[8]);
                bucket[0] += r.workedSeconds();
                bucket[1] += r.getActiveSeconds();
                bucket[2] += r.getIdleSeconds();
                bucket[3] += r.getBreakSeconds();
                bucket[4] += appSec + siteSec;
                bucket[5] += r.getScreenshotCount();
                bucket[6] += r.getKeystrokes();
                bucket[7] += r.getMouseClicks();
                maxShots = Math.max(maxShots, (int) bucket[5]);
            }
        }

        model.addAttribute("totalWorked", TrackService.fmt(totalWorked));
        model.addAttribute("totalActive", TrackService.fmt(totalActive));
        model.addAttribute("totalIdle", TrackService.fmt(totalIdle));
        model.addAttribute("totalBreak", TrackService.fmt(totalBreak));
        model.addAttribute("totalAppTime", TrackService.fmt(totalApp));
        model.addAttribute("totalSiteTime", TrackService.fmt(totalSite));
        model.addAttribute("totalTracked", TrackService.fmt(totalApp + totalSite));
        model.addAttribute("productivity", percent(totalActive, totalActive + totalIdle));
        model.addAttribute("totalKeystrokes", totalKeys);
        model.addAttribute("totalClicks", totalClicks);
        model.addAttribute("totalScrolls", totalScrolls);
        model.addAttribute("totalShots", totalShots);
        model.addAttribute("storageUsed", storage.storageBytes());

        List<Map<String, Object>> perEmployeeRows = new ArrayList<>();
        for (Map.Entry<String, long[]> en : perEmployee.entrySet()) {
            Employee e = employeeService.findByEmail(en.getKey());
            long[] b = en.getValue();
            Map<String, Object> row = new HashMap<>();
            row.put("email", en.getKey());
            row.put("name", e == null ? en.getKey() : e.getFullName());
            row.put("department", e == null ? "-" : e.getDepartment());
            row.put("worked", TrackService.fmt(b[0]));
            row.put("workedSec", b[0]);
            row.put("active", TrackService.fmt(b[1]));
            row.put("idle", TrackService.fmt(b[2]));
            row.put("break", TrackService.fmt(b[3]));
            row.put("tracked", TrackService.fmt(b[4]));
            row.put("screenshots", b[5]);
            row.put("keystrokes", b[6]);
            row.put("clicks", b[7]);
            row.put("productivity", percent(b[1], b[1] + b[2]));
            perEmployeeRows.add(row);
        }
        perEmployeeRows.sort((a, b) -> Long.compare((Long) b.get("workedSec"), (Long) a.get("workedSec")));
        model.addAttribute("perEmployee", perEmployeeRows);
        model.addAttribute("maxShots", Math.max(maxShots, 1));

        // App + site breakdown for the selected employee (or company-wide roll-up).
        Map<String, Long> appTotals = new LinkedHashMap<>();
        Map<String, Long> siteTotals = new LinkedHashMap<>();
        List<Map<String, Object>> perDayRows = new ArrayList<>();
        Map<String, Integer> distinctApps = new HashMap<>();
        Map<String, Integer> distinctSites = new HashMap<>();

        for (LocalDate d : days) {
            String day = d.format(DAY);
            long dayWorked = 0, dayActive = 0, dayIdle = 0;
            for (Employee e : employees) {
                if (selected != null && !selected.equalsIgnoreCase(e.getEmail())) continue;
                TrackRecord r = trackService.recordFor(e.getEmail(), day);
                dayWorked += r.workedSeconds();
                dayActive += r.getActiveSeconds();
                dayIdle += r.getIdleSeconds();
                merge(appTotals, usageService.topApps(e.getEmail(), day, 200));
                merge(siteTotals, usageService.topSites(e.getEmail(), day, 200));
                distinctApps.merge(e.getEmail(), usageService.appCount(e.getEmail(), day), Integer::sum);
                distinctSites.merge(e.getEmail(), usageService.siteCount(e.getEmail(), day), Integer::sum);
            }
            if (dayWorked == 0) continue;
            Map<String, Object> row = new HashMap<>();
            row.put("date", day);
            row.put("worked", TrackService.fmt(dayWorked));
            row.put("active", TrackService.fmt(dayActive));
            row.put("idle", TrackService.fmt(dayIdle));
            row.put("productivity", percent(dayActive, dayActive + dayIdle));
            perDayRows.add(row);
        }
        model.addAttribute("appTotals", ranked(appTotals));
        model.addAttribute("siteTotals", ranked(siteTotals));
        model.addAttribute("perDay", perDayRows);
        model.addAttribute("distinctApps", selected != null ? orZero(distinctApps.get(selected)) : sum(distinctApps));
        model.addAttribute("distinctSites", selected != null ? orZero(distinctSites.get(selected)) : sum(distinctSites));

        // Latest screenshots in the window for the gallery strip.
        LocalDateTime fromTs = startDay.atStartOfDay();
        LocalDateTime toTs = endDay.plusDays(1).atStartOfDay();
        List<Screenshot> shots = selected == null
                ? shotRepo.findByCapturedAtBetweenOrderByCapturedAtDesc(fromTs, toTs)
                : shotRepo.findByEmailAndCapturedAtBetweenOrderByCapturedAtDesc(selected, fromTs, toTs);
        if (shots.size() > 24) shots = shots.subList(0, 24);
        model.addAttribute("shots", shots);
        return "portal/usage";
    }

    // ------------------------------------------------------------- CSV export

    @GetMapping("/admin/usage.csv")
    public ResponseEntity<byte[]> exportCsv(@RequestParam(required = false) String email,
                                            @RequestParam(required = false) String from,
                                            @RequestParam(required = false) String to,
                                            org.springframework.security.core.Authentication auth) {
        Employee viewer = employeeService.findByEmail(auth.getName());
        String selected = access.resolveSelection(viewer, email);
        LocalDate endDay = parseDay(to, LocalDate.now());
        LocalDate startDay = parseDay(from, endDay.minusDays(6));
        if (startDay.isAfter(endDay)) {
            LocalDate swap = startDay; startDay = endDay; endDay = swap;
        }

        StringBuilder csv = new StringBuilder();
        csv.append("Date,Employee,Email,Department,Clock In,Clock Out,Worked,Active,Idle,Break,")
                .append("Apps Used,App Time,Apps,Sites Used,Site Time,Sites,Screenshots,")
                .append("Keystrokes,Mouse Clicks,Productivity %\n");

        for (LocalDate d = startDay; !d.isAfter(endDay); d = d.plusDays(1)) {
            String day = d.format(DAY);
            for (Employee e : access.visibleEmployees(viewer)) {
                if (selected != null && !selected.equalsIgnoreCase(e.getEmail())) continue;
                TrackRecord r = trackService.recordFor(e.getEmail(), day);
                long appSec = usageService.appSeconds(e.getEmail(), day);
                long siteSec = usageService.siteSeconds(e.getEmail(), day);
                if (r.workedSeconds() == 0 && appSec == 0 && siteSec == 0) continue;

                csv.append(day).append(',')
                        .append(csvCell(e.getFullName())).append(',')
                        .append(csvCell(e.getEmail())).append(',')
                        .append(csvCell(e.getDepartment())).append(',')
                        .append(r.getCheckIn() == null ? "" : r.getCheckIn().format(DateTimeFormatter.ofPattern("HH:mm"))).append(',')
                        .append(r.getCheckOut() == null ? "" : r.getCheckOut().format(DateTimeFormatter.ofPattern("HH:mm"))).append(',')
                        .append(TrackService.fmt(r.workedSeconds())).append(',')
                        .append(TrackService.fmt(r.getActiveSeconds())).append(',')
                        .append(TrackService.fmt(r.getIdleSeconds())).append(',')
                        .append(TrackService.fmt(r.getBreakSeconds())).append(',')
                        .append(usageService.appCount(e.getEmail(), day)).append(',')
                        .append(TrackService.fmt(appSec)).append(',')
                        .append(usageService.appCount(e.getEmail(), day)).append(',')
                        .append(usageService.siteCount(e.getEmail(), day)).append(',')
                        .append(TrackService.fmt(siteSec)).append(',')
                        .append(usageService.siteCount(e.getEmail(), day)).append(',')
                        .append(r.getScreenshotCount()).append(',')
                        .append(r.getKeystrokes()).append(',')
                        .append(r.getMouseClicks()).append(',')
                        .append(r.getProductivityPercent())
                        .append('\n');
            }
        }

        byte[] body = ("\uFEFF" + csv).getBytes(java.nio.charset.StandardCharsets.UTF_8);
        String name = "workfolio-report-" + startDay.format(DAY) + "-to-" + endDay.format(DAY) + ".csv";
        return ResponseEntity.ok()
                .header(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=\"" + name + "\"")
                .contentType(new MediaType("text", "csv", java.nio.charset.StandardCharsets.UTF_8))
                .body(body);
    }

    /** Per-target app/site CSV - answers "how long on each app or site". */
    @GetMapping("/admin/usage-targets.csv")
    public ResponseEntity<byte[]> targetsCsv(@RequestParam String email,
                                             @RequestParam(required = false) String date,
                                             org.springframework.security.core.Authentication auth) {
        Employee viewer = employeeService.findByEmail(auth.getName());
        // Required parameter, so there is no "everyone" fallback here - refuse
        // outright rather than exporting somebody else's app and site history.
        if (!access.canView(viewer, email)) {
            return ResponseEntity.status(org.springframework.http.HttpStatus.FORBIDDEN).build();
        }
        String day = parseDay(date, LocalDate.now()).format(DAY);
        StringBuilder csv = new StringBuilder();
        csv.append("Date,Employee,Type,Name,Host / Process,Category,Seconds,Time,Samples,Window Title\n");
        for (AppUsage u : usageService.usageFor(email, day)) {
            csv.append(u.getWorkDate()).append(',')
                    .append(csvCell(email)).append(',')
                    .append(u.getTargetType()).append(',')
                    .append(csvCell(u.getDisplayName())).append(',')
                    .append(csvCell(u.getHost() != null ? u.getHost() : u.getProcessName())).append(',')
                    .append(csvCell(u.getCategory())).append(',')
                    .append(u.getSeconds()).append(',')
                    .append(csvCell(u.getTimeText())).append(',')
                    .append(u.getSampleCount()).append(',')
                    .append(csvCell(u.getWindowTitle()))
                    .append('\n');
        }
        byte[] body = ("\uFEFF" + csv).getBytes(java.nio.charset.StandardCharsets.UTF_8);
        return ResponseEntity.ok()
                .header(HttpHeaders.CONTENT_DISPOSITION,
                        "attachment; filename=\"workfolio-usage-" + email + "-" + day + ".csv\"")
                .contentType(new MediaType("text", "csv", java.nio.charset.StandardCharsets.UTF_8))
                .body(body);
    }

    // --------------------------------------------------------- agent settings

    @GetMapping("/admin/agent-config")
    public String agentConfig(@RequestParam(required = false) String email,
                              @RequestParam(required = false) String purged,
                              org.springframework.security.core.Authentication auth,
                              Model model) {
        Employee viewer = employeeService.findByEmail(auth.getName());
        model.addAttribute("currentUser", viewer);
        if (purged != null) model.addAttribute("purged", purged);
        List<Employee> employees = access.visibleEmployees(viewer);
        String requested = access.resolveSelection(viewer, email);
        String sel = requested != null ? requested
                : (employees.isEmpty() ? "" : employees.get(0).getEmail());
        model.addAttribute("employees", employees);
        model.addAttribute("selEmail", sel);
        model.addAttribute("config", usageService.configFor(sel));
        // Trimmed to the same set, otherwise the page would still list the
        // admin's and other HR's policies in the summary table.
        List<AgentConfig> all = new ArrayList<>();
        for (AgentConfig c : usageService.allConfigs()) {
            if (access.canView(viewer, c.getEmail())) all.add(c);
        }
        model.addAttribute("allConfigs", all);
        model.addAttribute("storageUsed", storage.storageBytes());
        return "portal/agent-config";
    }

    @PostMapping("/admin/agent-config/save")
    public String saveAgentConfig(@RequestParam String email,
                                  @RequestParam(defaultValue = "300") int screenshotIntervalSeconds,
                                  @RequestParam(defaultValue = "0") int liveStreamIntervalSeconds,
                                  @RequestParam(defaultValue = "180") int idleThresholdSeconds,
                                  @RequestParam(defaultValue = "55") int imageQuality,
                                  @RequestParam(defaultValue = "1280") int imageMaxWidth,
                                  @RequestParam(defaultValue = "10") int sampleIntervalSeconds,
                                  @RequestParam(defaultValue = "true") boolean captureScreenshots,
                                  @RequestParam(defaultValue = "true") boolean trackMouseKeyboard,
                                  @RequestParam(defaultValue = "true") boolean trackAppUsage,
                                  @RequestParam(defaultValue = "true") boolean trackWebsites,
                                  @RequestParam(required = false) String excludedProcesses,
                                  @RequestParam(required = false) String blockedSites,
                                  org.springframework.security.core.Authentication auth) {
        Employee viewer = employeeService.findByEmail(auth.getName());
        // Without this an HR could post the admin's email and switch off his
        // screenshots, or set excludedProcesses wide enough to hide a whole
        // working day - which would make the admin's report unfalsifiable.
        if (!access.canView(viewer, email)) {
            // The rejected email is not echoed back into the redirect: it is user
            // input, and the page has no reason to repeat it.
            return "redirect:/admin/agent-config?denied=1";
        }
        AgentConfig c = usageService.configFor(email);
        c.setScreenshotIntervalSeconds(screenshotIntervalSeconds);
        c.setLiveStreamIntervalSeconds(liveStreamIntervalSeconds);
        c.setIdleThresholdSeconds(idleThresholdSeconds);
        c.setImageQuality(imageQuality);
        c.setImageMaxWidth(imageMaxWidth);
        c.setSampleIntervalSeconds(sampleIntervalSeconds);
        c.setCaptureScreenshots(captureScreenshots);
        c.setTrackMouseKeyboard(trackMouseKeyboard);
        c.setTrackAppUsage(trackAppUsage);
        c.setTrackWebsites(trackWebsites);
        if (excludedProcesses != null) c.setExcludedProcesses(excludedProcesses.trim());
        if (blockedSites != null) c.setBlockedSites(blockedSites.trim());
        usageService.saveConfig(c);
        return "redirect:/admin/agent-config?email=" + email;
    }

    @PostMapping("/admin/agent-config/purge")
    public String purgeShots(@RequestParam(defaultValue = "30") int keepDays) {
        int removed = storage.purgeOlderThan(keepDays);
        return "redirect:/admin/agent-config?purged=" + removed;
    }

    // ---------------------------------------------------------------- helpers

    private static void merge(Map<String, Long> into, Map<String, Long> from) {
        for (Map.Entry<String, Long> e : from.entrySet()) {
            into.merge(e.getKey(), e.getValue(), Long::sum);
        }
    }

    private static List<Map<String, Object>> ranked(Map<String, Long> totals) {
        List<Map.Entry<String, Long>> entries = new ArrayList<>(totals.entrySet());
        entries.sort((a, b) -> Long.compare(b.getValue(), a.getValue()));
        long max = entries.isEmpty() ? 0 : entries.get(0).getValue();
        List<Map<String, Object>> rows = new ArrayList<>();
        for (Map.Entry<String, Long> e : entries) {
            if (rows.size() >= 25) break;
            Map<String, Object> row = new HashMap<>();
            row.put("name", e.getKey());
            row.put("seconds", e.getValue());
            row.put("time", TrackService.fmt(e.getValue()));
            row.put("minutes", e.getValue() / 60);
            row.put("percent", max == 0 ? 0 : Math.round((e.getValue() * 100.0) / max));
            rows.add(row);
        }
        return rows;
    }

    private static int percent(long part, long whole) {
        if (whole <= 0) return 0;
        return (int) Math.round((part * 100.0) / whole);
    }

    private static int sum(Map<String, Integer> map) {
        int t = 0;
        for (Integer v : map.values()) if (v != null) t += v;
        return t;
    }

    private static int orZero(Integer v) {
        return v == null ? 0 : v;
    }

    private static LocalDate parseDay(String value, LocalDate fallback) {
        if (value == null || value.trim().isEmpty()) return fallback;
        try {
            return LocalDate.parse(value.trim(), DAY);
        } catch (Exception e) {
            return fallback;
        }
    }

    private static String csvCell(String value) {
        if (value == null) return "";
        String v = value.replace("\"", "'").replace("\n", " ").replace("\r", " ");
        return v.contains(",") || v.contains(";") ? "\"" + v + "\"" : v;
    }
}
