package com.workfolio.service;

import com.workfolio.model.ActivityLog;
import com.workfolio.model.Employee;
import com.workfolio.model.Screenshot;
import com.workfolio.model.TrackRecord;
import com.workfolio.repository.ActivityLogRepository;
import com.workfolio.repository.ScreenshotRepository;
import com.workfolio.repository.TrackRecordRepository;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Service;

import java.time.Duration;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;

@Service
public class TrackService {

    private static final Logger log = LoggerFactory.getLogger(TrackService.class);

    private final TrackRecordRepository repo;
    private final ScreenshotRepository shotRepo;
    private final ScreenshotStorage storage;
    private final ActivityLogRepository activityLogRepo;

    public TrackService(TrackRecordRepository repo, ScreenshotRepository shotRepo,
                        ScreenshotStorage storage, ActivityLogRepository activityLogRepo) {
        this.repo = repo;
        this.shotRepo = shotRepo;
        this.storage = storage;
        this.activityLogRepo = activityLogRepo;
    }

    public static String today() {
        return LocalDate.now().format(DateTimeFormatter.ISO_DATE);
    }

    public synchronized TrackRecord recordFor(String email) {
        return recordFor(email, today());
    }

    public synchronized TrackRecord recordFor(String email, String date) {
        Optional<TrackRecord> o = repo.findByEmailAndWorkDate(email, date);
        if (o.isPresent()) return o.get();
        TrackRecord r = new TrackRecord();
        r.setEmail(email);
        r.setWorkDate(date);
        return repo.save(r);
    }

    /**
     * Rolls elapsed time into active/idle buckets. Called before every state change so
     * no second is lost or double counted, even if the agent's heartbeat is irregular.
     */
    private TrackRecord settle(TrackRecord r) {
        LocalDateTime now = LocalDateTime.now();
        LocalDateTime seen = r.getLastSeen();
        if (r.getCheckIn() == null || seen == null) {
            r.setLastSeen(now);
            return r;
        }
        if (TrackRecord.STATUS_OFF.equals(r.getStatus())) return r;
        long elapsed = Duration.between(seen, now).getSeconds();
        if (elapsed <= 0) return r;
        if (TrackRecord.STATUS_BREAK.equals(r.getStatus()) && r.getBreakStart() != null) {
            r.setBreakSeconds(r.getBreakSeconds() + Duration.between(r.getBreakStart(), now).getSeconds());
            r.setBreakStart(null);
            r.setStatus(TrackRecord.STATUS_WORKING);
        }
        if ("IDLE".equalsIgnoreCase(r.getLastStatus())) {
            r.setIdleSeconds(r.getIdleSeconds() + elapsed);
        } else {
            r.setActiveSeconds(r.getActiveSeconds() + elapsed);
        }
        r.setLastSeen(now);
        return r;
    }

    public Map<String, Object> checkIn(Employee emp) {
        TrackRecord r = settle(recordFor(emp.getEmail()));
        if (r.getCheckIn() == null) {
            r.setCheckIn(LocalDateTime.now());
            r.setLastStatus("ACTIVE");
        }
        r.setStatus(TrackRecord.STATUS_WORKING);
        r.setLastSeen(LocalDateTime.now());
        r.setCheckOut(null);
        repo.save(r);
        logAction(emp, "CLOCK_IN", "Clocked in via agent at " + LocalDateTime.now().format(DateTimeFormatter.ofPattern("hh:mm a")));
        return summary(emp, today());
    }

    public Map<String, Object> checkOut(Employee emp) {
        TrackRecord r = settle(recordFor(emp.getEmail()));
        long worked = r.workedSeconds();
        r.setCheckOut(LocalDateTime.now());
        r.setStatus(TrackRecord.STATUS_OFF);
        r.setBreakStart(null);
        r.setLastSeen(LocalDateTime.now());
        repo.save(r);
        logAction(emp, "CLOCK_OUT", "Clocked out after " + fmt(worked) + " of work");
        return summary(emp, today());
    }

    public Map<String, Object> breakStart(Employee emp) {
        TrackRecord r = settle(recordFor(emp.getEmail()));
        if (r.getCheckIn() == null) {
            r.setCheckIn(LocalDateTime.now());
        }
        if (r.getBreakStart() == null) {
            r.setBreakStart(LocalDateTime.now());
            r.setStatus(TrackRecord.STATUS_BREAK);
            r.setLastSeen(LocalDateTime.now());
            repo.save(r);
            logAction(emp, "BREAK_START", "Break started at " + LocalDateTime.now().format(DateTimeFormatter.ofPattern("hh:mm a")));
        }
        return summary(emp, today());
    }

    public Map<String, Object> breakEnd(Employee emp) {
        TrackRecord r = recordFor(emp.getEmail());
        LocalDateTime now = LocalDateTime.now();
        if (r.getBreakStart() != null) {
            long secs = Duration.between(r.getBreakStart(), now).getSeconds();
            r.setBreakSeconds(r.getBreakSeconds() + Math.max(0, secs));
            r.setBreakStart(null);
            logAction(emp, "BREAK_END", "Break of " + fmt(secs) + " ended");
        }
        r.setStatus(TrackRecord.STATUS_WORKING);
        r.setLastStatus("ACTIVE");
        r.setLastSeen(now);
        repo.save(r);
        return summary(emp, today());
    }

    public Map<String, Object> heartbeat(Employee emp, String status) {
        TrackRecord r = settle(recordFor(emp.getEmail()));
        if (r.getCheckIn() != null && !TrackRecord.STATUS_OFF.equals(r.getStatus())) {
            r.setLastStatus("IDLE".equalsIgnoreCase(status) ? "IDLE" : "ACTIVE");
            r.setStatus(TrackRecord.STATUS_BREAK.equals(r.getStatus()) ? r.getStatus() : TrackRecord.STATUS_WORKING);
            r.setLastSeen(LocalDateTime.now());
            repo.save(r);
        }
        return summary(emp, today());
    }

    /**
     * Applies one telemetry batch from the desktop agent: input counters plus what the
     * employee currently has in front of them. Counters are absolute for the shift, so
     * they simply overwrite the stored values.
     */
    public synchronized Map<String, Object> recordTelemetry(Employee emp, Telemetry t) {
        TrackRecord r = settle(recordFor(emp.getEmail()));
        if (r.getCheckIn() != null && !TrackRecord.STATUS_OFF.equals(r.getStatus())) {
            r.setLastStatus("IDLE".equalsIgnoreCase(t.status) ? "IDLE" : "ACTIVE");
            if (!TrackRecord.STATUS_BREAK.equals(r.getStatus())) {
                r.setStatus(TrackRecord.STATUS_WORKING);
            }
            r.setLastSeen(LocalDateTime.now());
        }
        r.setKeystrokes(Math.max(r.getKeystrokes(), t.keystrokes));
        r.setMouseClicks(Math.max(r.getMouseClicks(), t.mouseClicks));
        r.setMouseScrolls(Math.max(r.getMouseScrolls(), t.mouseScrolls));
        r.setMouseDistancePx(Math.max(r.getMouseDistancePx(), t.mouseDistancePx));
        if (t.app != null) r.setCurrentApp(t.app);
        if (t.title != null) r.setCurrentTitle(t.title);
        if (t.host != null) r.setCurrentHost(t.host);
        if (t.category != null) r.setCurrentCategory(t.category);
        if (t.hostName != null) r.setHostName(t.hostName);
        if (t.agentVersion != null) r.setAgentVersion(t.agentVersion);
        if (t.ip != null) r.setAgentIp(t.ip);
        repo.save(r);
        return summary(emp, today());
    }

    public synchronized void saveScreenshot(String email, String base64) {
        saveScreenshot(email, base64, Screenshot.TRIGGER_AUTO, null, null, null, false);
    }

    public synchronized Screenshot saveScreenshot(String email, String base64, String trigger,
                                                 String app, String title, String host, boolean live) {
        Screenshot saved = storage.store(email, base64, trigger, app, title, host, live);
        if (saved == null) return null;
        TrackRecord r = recordFor(email);
        r.setScreenshotCount(r.getScreenshotCount() + 1);
        r.setLastShotAt(LocalDateTime.now());
        repo.save(r);
        return saved;
    }

    public Map<String, Object> summary(Employee emp, String date) {
        TrackRecord r = recordFor(emp.getEmail(), date);
        long breakNow = r.getBreakSeconds() + (r.getBreakStart() != null
                ? Duration.between(r.getBreakStart(), LocalDateTime.now()).getSeconds() : 0);
        Map<String, Object> m = new HashMap<>();
        m.put("status", r.getStatus());
        m.put("onDuty", r.isOnDuty());
        m.put("checkIn", r.getCheckInText());
        m.put("checkOut", r.getCheckOutText());
        m.put("worked", fmt(r.workedSeconds()));
        m.put("active", fmt(r.getActiveSeconds()));
        m.put("idle", fmt(r.getIdleSeconds()));
        m.put("break", fmt(breakNow));
        m.put("screenshots", r.getScreenshotCount());
        m.put("date", r.getWorkDate());
        m.put("workedSec", r.workedSeconds());
        m.put("activeSec", r.getActiveSeconds());
        m.put("idleSec", r.getIdleSeconds());
        m.put("breakSec", breakNow);
        m.put("productivity", r.getProductivityPercent());
        m.put("keystrokes", r.getKeystrokes());
        m.put("mouseClicks", r.getMouseClicks());
        m.put("mouseScrolls", r.getMouseScrolls());
        m.put("mouseDistancePx", r.getMouseDistancePx());
        m.put("currentApp", r.getCurrentApp());
        m.put("currentTitle", r.getCurrentTitle());
        m.put("currentHost", r.getCurrentHost());
        m.put("currentCategory", r.getCurrentCategory());
        m.put("distinctApps", r.getDistinctApps());
        m.put("distinctSites", r.getDistinctSites());
        m.put("lastShot", r.getLastShotText());
        m.put("hostName", r.getHostName());
        m.put("agentVersion", r.getAgentVersion());
        m.put("offline", r.getLastSeen() == null || !r.isOnDuty());
        return m;
    }

    public TrackRecord todayRecord(String email) {
        return recordFor(email, today());
    }

    public List<TrackRecord> history(String email) {
        return repo.findByEmailOrderByWorkDateDesc(email);
    }

    public List<TrackRecord> sessionLog() {
        return repo.findByStatusNot(TrackRecord.STATUS_OFF);
    }

    public List<TrackRecord> range(String email, LocalDateTime start, LocalDateTime end) {
        return repo.findByCheckInBetweenOrderByCheckInAsc(start, end);
    }

    public List<TrackRecord> allToday() {
        return repo.findByWorkDateOrderByCheckInAsc(today());
    }

    public List<TrackRecord> allOnDate(String date) {
        return repo.findByWorkDateOrderByCheckInAsc(date);
    }

    public Optional<Screenshot> latestScreenshotEntity(String email) {
        return shotRepo.findTop1ByEmailOrderByCapturedAtDesc(email);
    }

    public Optional<Screenshot> latestLiveScreenshotEntity(String email) {
        return shotRepo.findTop1ByEmailAndLiveTrueOrderByCapturedAtDesc(email);
    }

    public String latestScreenshot(String email) {
        Optional<Screenshot> s = latestScreenshotEntity(email);
        return s.isPresent() ? storage.readAsBase64(s.get()) : null;
    }

    public byte[] screenshotBytes(Long id) {
        return shotRepo.findById(id).flatMap(storage::read).orElse(null);
    }

    /**
     * The screenshot row behind an id, without reading the image bytes.
     *
     * Callers that serve a screenshot by id need to know who it belongs to before
     * handing it out, and loading the payload first would mean decoding an image
     * only to then refuse it.
     */
    public Optional<Screenshot> screenshotEntity(Long id) {
        return id == null ? Optional.empty() : shotRepo.findById(id);
    }

    public long shotCount(String email) {
        return shotRepo.countByEmail(email);
    }

    public TrackRecord adjust(String email, String date, long activeDelta, long idleDelta, long breakDelta) {
        TrackRecord r = recordFor(email, date);
        if (r.getCheckIn() == null) {
            r.setCheckIn(LocalDateTime.now().withHour(9).withMinute(0).withSecond(0));
            r.setStatus(TrackRecord.STATUS_OFF);
        }
        if (r.getCheckOut() == null) {
            r.setCheckOut(LocalDateTime.now());
        }
        r.setActiveSeconds(Math.max(0, r.getActiveSeconds() + activeDelta));
        r.setIdleSeconds(Math.max(0, r.getIdleSeconds() + idleDelta));
        r.setBreakSeconds(Math.max(0, r.getBreakSeconds() + breakDelta));
        repo.save(r);
        return r;
    }

    private void logAction(Employee emp, String action, String details) {
        try {
            activityLogRepo.save(ActivityLog.of(emp.getEmail(), emp.getFullName(), emp.getRole().name(),
                    action, details, "agent"));
        } catch (Exception e) {
            log.debug("Activity log skipped: {}", e.getMessage());
        }
    }

    public static String fmt(long secs) {
        if (secs < 0) secs = 0;
        long h = secs / 3600, m = (secs % 3600) / 60;
        return String.format("%02dh %02dm", h, m);
    }

    /** Immutable carrier for one agent telemetry batch. */
    public static class Telemetry {
        public String status = "ACTIVE";
        public long keystrokes;
        public long mouseClicks;
        public long mouseScrolls;
        public long mouseDistancePx;
        public String app;
        public String title;
        public String host;
        public String category;
        public String hostName;
        public String agentVersion;
        public String ip;
    }
}
