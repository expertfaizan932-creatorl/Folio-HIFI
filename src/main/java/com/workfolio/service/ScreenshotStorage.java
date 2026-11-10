package com.workfolio.service;

import com.workfolio.model.Screenshot;
import com.workfolio.repository.ScreenshotRepository;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;

import javax.annotation.PostConstruct;
import java.io.File;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.Base64;
import java.util.Optional;

/**
 * Writes agent screenshots as JPEG files under a configurable root so that the
 * relational store stays small and the report gallery can stream images directly.
 */
@Service
public class ScreenshotStorage {

    private static final Logger log = LoggerFactory.getLogger(ScreenshotStorage.class);
    private static final DateTimeFormatter STAMP = DateTimeFormatter.ofPattern("yyyyMMdd-HHmmss-SSS");

    private final ScreenshotRepository repository;

    @Value("${workfolio.storage.root:./data}")
    private String root;

    public ScreenshotStorage(ScreenshotRepository repository) {
        this.repository = repository;
    }

    @PostConstruct
    public void init() {
        try {
            Files.createDirectories(rootPath().resolve("screenshots"));
        } catch (IOException e) {
            log.warn("Could not create screenshot storage: {}", e.getMessage());
        }
    }

    public Path rootPath() {
        return Paths.get(root).toAbsolutePath().normalize();
    }

    private Path dayFolder(String email, LocalDate day) {
        String safeEmail = (email == null ? "unknown" : email).replaceAll("[^A-Za-z0-9._-]", "_");
        return rootPath().resolve("screenshots").resolve(day.toString()).resolve(safeEmail);
    }

    /** Persists a new capture. Returns the stored entity, or null when the payload was unusable. */
    public Screenshot store(String email, String base64, String trigger, String appName,
                            String windowTitle, String host, boolean live) {
        if (base64 == null || base64.isEmpty()) return null;
        byte[] bytes = decode(base64);
        if (bytes == null || bytes.length == 0) return null;

        LocalDate day = LocalDate.now();
        Path folder = dayFolder(email, day);
        String fileName = LocalDateTime.now().format(STAMP) + (live ? "-live" : "") + ".jpg";
        try {
            Files.createDirectories(folder);
            Files.write(folder.resolve(fileName), bytes);
        } catch (IOException e) {
            log.warn("Screenshot write failed for {}: {}", email, e.getMessage());
            return null;
        }

        Screenshot shot = new Screenshot();
        shot.setEmail(email);
        shot.setCapturedAt(LocalDateTime.now());
        shot.setFileName(fileName);
        shot.setTrigger(trigger == null ? Screenshot.TRIGGER_AUTO : trigger);
        shot.setAppName(trim(appName, 200));
        shot.setWindowTitle(trim(windowTitle, 500));
        shot.setHost(trim(host, 200));
        shot.setSizeBytes(bytes.length);
        shot.setLive(live);
        return repository.save(shot);
    }

    public Optional<byte[]> read(Screenshot shot) {
        if (shot == null) return Optional.empty();
        if (shot.getFileName() != null) {
            Path p = dayFolder(shot.getEmail(), shot.getCapturedAt().toLocalDate()).resolve(shot.getFileName());
            if (Files.exists(p)) {
                try {
                    return Optional.of(Files.readAllBytes(p));
                } catch (IOException e) {
                    log.warn("Could not read screenshot {}: {}", p, e.getMessage());
                }
            }
        }
        byte[] inline = decode(shot.getImage());
        return inline == null ? Optional.empty() : Optional.of(inline);
    }

    /** Base64 JPEG for endpoints that need to inline the image in JSON. */
    public String readAsBase64(Screenshot shot) {
        Optional<byte[]> data = read(shot);
        return data.isPresent() ? Base64.getEncoder().encodeToString(data.get()) : null;
    }

    public void delete(Screenshot shot) {
        if (shot == null) return;
        if (shot.getFileName() != null && shot.getCapturedAt() != null) {
            try {
                Files.deleteIfExists(dayFolder(shot.getEmail(), shot.getCapturedAt().toLocalDate()).resolve(shot.getFileName()));
            } catch (IOException ignored) {
            }
        }
        repository.delete(shot);
    }

    /** Removes captures older than the retention window. */
    public int purgeOlderThan(int days) {
        if (days <= 0) return 0;
        java.time.LocalDateTime cutoff = LocalDateTime.now().minusDays(days);
        java.util.List<Screenshot> old = repository.findByCapturedAtBetweenOrderByCapturedAtDesc(
                LocalDateTime.of(2000, 1, 1, 0, 0), cutoff);
        for (Screenshot s : old) {
            delete(s);
        }
        return old.size();
    }

    public long storageBytes() {
        long total = 0;
        try {
            File dir = rootPath().resolve("screenshots").toFile();
            if (!dir.exists()) return 0;
            File[] all = dir.listFiles();
            if (all == null) return 0;
            for (File f : all) {
                File[] sub = f.isDirectory() ? f.listFiles() : new File[]{f};
                if (sub == null) continue;
                for (File s : sub) {
                    if (s.isDirectory()) {
                        File[] leaves = s.listFiles();
                        if (leaves != null) for (File l : leaves) total += l.length();
                    } else {
                        total += s.length();
                    }
                }
            }
        } catch (Exception ignored) {
        }
        return total;
    }

    private static byte[] decode(String base64) {
        try {
            String payload = base64.trim();
            int comma = payload.indexOf(",");
            if (payload.startsWith("data:") && comma > 0) payload = payload.substring(comma + 1);
            return Base64.getDecoder().decode(payload);
        } catch (IllegalArgumentException e) {
            return null;
        }
    }

    private static String trim(String value, int max) {
        if (value == null) return null;
        String v = value.trim();
        return v.length() <= max ? v : v.substring(0, max);
    }
}
