package com.workfolio.service;

import com.workfolio.model.AgentConfig;
import com.workfolio.model.AppUsage;
import com.workfolio.model.Employee;
import com.workfolio.model.TrackRecord;
import com.workfolio.repository.AgentConfigRepository;
import com.workfolio.repository.AppUsageRepository;
import com.workfolio.repository.TrackRecordRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Optional;

/**
 * Turns the raw window samples streamed by the desktop agent into per-day,
 * per-application / per-website time totals, and answers the report questions
 * ("which apps, which sites, how long on each").
 */
@Service
public class UsageService {

    private final AppUsageRepository usageRepo;
    private final AgentConfigRepository configRepo;
    private final TrackRecordRepository trackRepo;

    /** Longest time a single window sample may claim, so a flapping window cannot inflate totals. */
    private static final long MAX_SAMPLE_SECONDS = 120;

    public UsageService(AppUsageRepository usageRepo, AgentConfigRepository configRepo,
                        TrackRecordRepository trackRepo) {
        this.usageRepo = usageRepo;
        this.configRepo = configRepo;
        this.trackRepo = trackRepo;
    }

    // ------------------------------------------------------------------ config

    public AgentConfig configFor(String email) {
        return configRepo.findByEmail(email).orElseGet(() -> configRepo.save(new AgentConfig(email)));
    }

    public AgentConfig saveConfig(AgentConfig config) {
        return configRepo.save(config);
    }

    public List<AgentConfig> allConfigs() {
        return configRepo.findAll();
    }

    // -------------------------------------------------------------- ingestion

    /**
     * Records time spent on one foreground target.
     *
     * @param seconds how long the employee stayed on this target since the last sample
     */
    @Transactional
    public AppUsage recordSample(String email, String targetKey, String targetType, String displayName,
                                 String processName, String windowTitle, String host, String category,
                                 long seconds, boolean idle) {
        if (targetKey == null || targetKey.isEmpty() || seconds <= 0) return null;

        String date = TrackService.today();
        long capped = Math.min(seconds, MAX_SAMPLE_SECONDS);

        AppUsage row = usageRepo.findByEmailAndWorkDateAndTargetKey(email, date, targetKey)
                .orElseGet(() -> {
                    AppUsage fresh = new AppUsage();
                    fresh.setEmail(email);
                    fresh.setWorkDate(date);
                    fresh.setTargetKey(targetKey);
                    fresh.setFirstSeen(System.currentTimeMillis());
                    return fresh;
                });

        boolean isNew = row.getId() == null;
        row.setTargetType(targetType == null ? AppUsage.TYPE_APP : targetType);
        row.setDisplayName(displayName);
        row.setProcessName(processName);
        row.setWindowTitle(windowTitle);
        row.setHost(host);
        row.setCategory(category == null ? categoryFor(targetType, host, processName) : category);
        row.setSeconds(row.getSeconds() + capped);
        row.setSampleCount(row.getSampleCount() + 1);
        row.setLastSeen(System.currentTimeMillis());
        row.setUpdatedAt(java.time.LocalDateTime.now());
        AppUsage saved = usageRepo.save(row);

        if (isNew) {
            refreshDistinctCounters(email, date);
        }
        return saved;
    }

    /** Recomputes the "N apps / N sites" counters shown on the dashboard and reports. */
    @Transactional
    public void refreshDistinctCounters(String email, String date) {
        trackRepo.findByEmailAndWorkDate(email, date).ifPresent(r -> {
            r.setDistinctApps((int) usageRepo.countDistinctTargets(email, date, AppUsage.TYPE_APP));
            r.setDistinctSites((int) usageRepo.countDistinctTargets(email, date, AppUsage.TYPE_WEB));
            trackRepo.save(r);
        });
    }

    // --------------------------------------------------------------- reporting

    public List<AppUsage> usageFor(String email, String date) {
        return usageRepo.findByEmailAndWorkDateOrderBySecondsDesc(email, date);
    }

    public List<AppUsage> usageFor(String email, String date, String type) {
        return usageRepo.findByEmailAndWorkDateAndTargetTypeOrderBySecondsDesc(email, date, type);
    }

    public List<AppUsage> usageForAll(String date) {
        return usageRepo.findByWorkDateOrderBySecondsDesc(date);
    }

    public long appSeconds(String email, String date) {
        return usageRepo.sumSeconds(email, date, AppUsage.TYPE_APP);
    }

    public long siteSeconds(String email, String date) {
        return usageRepo.sumSeconds(email, date, AppUsage.TYPE_WEB);
    }

    public int appCount(String email, String date) {
        return (int) usageRepo.countDistinctTargets(email, date, AppUsage.TYPE_APP);
    }

    public int siteCount(String email, String date) {
        return (int) usageRepo.countDistinctTargets(email, date, AppUsage.TYPE_WEB);
    }

    public List<String> datesWithUsage(String email) {
        return usageRepo.findDistinctDatesByEmail(email);
    }

    /** host -> seconds, for the "websites used" table. */
    public Map<String, Long> topSites(String email, String date, int limit) {
        Map<String, Long> out = new LinkedHashMap<>();
        for (Object[] row : usageRepo.topHosts(email, date)) {
            if (out.size() >= limit) break;
            out.put(String.valueOf(row[0]), ((Number) row[1]).longValue());
        }
        return out;
    }

    /** process -> seconds, for the "apps used" table. */
    public Map<String, Long> topApps(String email, String date, int limit) {
        Map<String, Long> out = new LinkedHashMap<>();
        for (Object[] row : usageRepo.topProcesses(email, date)) {
            if (out.size() >= limit) break;
            out.put(String.valueOf(row[0]), ((Number) row[1]).longValue());
        }
        return out;
    }

    /** Company-wide host totals for the given day. */
    public Map<String, Long> topSitesAcrossCompany(String date, int limit) {
        Map<String, Long> totals = new HashMap<>();
        for (AppUsage u : usageRepo.findByWorkDateOrderBySecondsDesc(date)) {
            if (!AppUsage.TYPE_WEB.equals(u.getTargetType()) || u.getHost() == null) continue;
            totals.merge(u.getHost(), u.getSeconds(), Long::sum);
        }
        List<String> keys = new ArrayList<>(totals.keySet());
        keys.sort((a, b) -> Long.compare(totals.get(b), totals.get(a)));
        Map<String, Long> out = new LinkedHashMap<>();
        for (String k : keys) {
            if (out.size() >= limit) break;
            out.put(k, totals.get(k));
        }
        return out;
    }

    public Map<String, Long> topAppsAcrossCompany(String date, int limit) {
        Map<String, Long> totals = new HashMap<>();
        for (AppUsage u : usageRepo.findByWorkDateOrderBySecondsDesc(date)) {
            if (!AppUsage.TYPE_APP.equals(u.getTargetType()) || u.getProcessName() == null) continue;
            totals.merge(u.getProcessName(), u.getSeconds(), Long::sum);
        }
        List<String> keys = new ArrayList<>(totals.keySet());
        keys.sort((a, b) -> Long.compare(totals.get(b), totals.get(a)));
        Map<String, Long> out = new LinkedHashMap<>();
        for (String k : keys) {
            if (out.size() >= limit) break;
            out.put(k, totals.get(k));
        }
        return out;
    }

    /** Sum of tracked time per employee for the day, used by the admin overview. */
    public Map<String, Long> secondsByEmployee(String date) {
        Map<String, Long> out = new HashMap<>();
        for (AppUsage u : usageRepo.findByWorkDateOrderBySecondsDesc(date)) {
            out.merge(u.getEmail(), u.getSeconds(), Long::sum);
        }
        return out;
    }

    public long totalTrackedSeconds(String date) {
        long total = 0;
        for (AppUsage u : usageRepo.findByWorkDateOrderBySecondsDesc(date)) {
            total += u.getSeconds();
        }
        return total;
    }

    public Optional<AppUsage> find(Long id) {
        return usageRepo.findById(id);
    }

    // ---------------------------------------------------------------- helpers

    /** Categorises a target so the report can group apps/sites sensibly. */
    public static String categoryFor(String targetType, String host, String processName) {
        if (AppUsage.TYPE_IDLE.equals(targetType)) return "Idle";
        if (AppUsage.TYPE_LOCK.equals(targetType)) return "Locked";
        if (AppUsage.TYPE_WEB.equals(targetType)) return webCategory(host);
        return appCategory(processName);
    }

    public static String webCategory(String host) {
        if (host == null || host.isEmpty()) return "Web";
        String h = host.toLowerCase(Locale.ROOT);
        if (h.contains("github") || h.contains("gitlab") || h.contains("bitbucket")) return "Development";
        if (h.contains("stackoverflow") || h.contains("w3schools") || h.contains("mdn") || h.contains("geeksforgeeks")) return "Learning";
        if (h.contains("mail.")) return "Email";
        if (h.contains("docs.google") || h.contains("drive.google") || h.contains("office.com") || h.contains("notion.so") || h.contains("slack")) return "Productivity";
        if (h.contains("youtube") || h.contains("netflix") || h.contains("instagram") || h.contains("facebook") || h.contains("twitter") || h.contains("x.com") || h.contains("reddit")) return "Social / Entertainment";
        if (h.contains("chat.openai") || h.contains("claude.ai") || h.contains("gemini")) return "AI Tools";
        if (h.contains("jira") || h.contains("trello") || h.contains("asana") || h.contains("monday")) return "Project Management";
        if (h.contains("amazon") || h.contains("flipkart") || h.contains("myntra")) return "Shopping";
        return "Web";
    }

    public static String appCategory(String processName) {
        if (processName == null || processName.isEmpty()) return "Application";
        String p = processName.toLowerCase(Locale.ROOT);
        if (contains(p, "code", "idea", "studio", "pycharm", "webstorm", "eclipse", "netbeans", "notepad++", "sublime")) return "Development";
        if (contains(p, "chrome", "firefox", "msedge", "edge", "opera", "brave", "browser")) return "Web Browser";
        if (contains(p, "word", "excel", "powerpnt", "outlook", "onenote", "notepad", "libreoffice")) return "Office";
        if (contains(p, "slack", "teams", "zoom", "skype", "discord", "whatsapp", "telegram")) return "Communication";
        if (contains(p, "figma", "photoshop", "illustrator", "xd", "sketch", "canva")) return "Design";
        if (contains(p, "postman", "insomnia", "putty", "winscp", "xshell", "moba")) return "Tools";
        if (contains(p, "sqlserver", "mysql", "pgadmin", "dbeaver", "mongodb", "redis")) return "Database";
        if (contains(p, "cmd", "powershell", "terminal", "windowsterminal", "bash")) return "Terminal";
        if (contains(p, "spotify", "vlc", "media", "itunes", "music", "netflix", "primevideo")) return "Media";
        return "Application";
    }

    private static boolean contains(String value, String... needles) {
        for (String n : needles) {
            if (value.contains(n)) return true;
        }
        return false;
    }

    /** True when a sample should be ignored entirely (system shells, agent itself, blocked host). */
    public boolean isIgnorable(AgentConfig cfg, String processName, String host) {
        if (cfg.isExcludedProcess(processName)) return true;
        if (host != null && !host.isEmpty() && cfg.isBlockedHost(host)) return true;
        return false;
    }
}
