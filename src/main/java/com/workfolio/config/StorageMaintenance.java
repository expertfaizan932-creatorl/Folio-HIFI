package com.workfolio.config;

import com.workfolio.service.ScreenshotStorage;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

/** Nightly housekeeping: drop screenshot captures past the retention window. */
@Component
public class StorageMaintenance {

    private static final Logger log = LoggerFactory.getLogger(StorageMaintenance.class);

    private final ScreenshotStorage storage;

    @Value("${workfolio.storage.retention-days:30}")
    private int retentionDays;

    public StorageMaintenance(ScreenshotStorage storage) {
        this.storage = storage;
    }

    /** Runs at 03:15 server time. */
    @Scheduled(cron = "0 15 3 * * *")
    public void purgeOldScreenshots() {
        try {
            int removed = storage.purgeOlderThan(retentionDays);
            if (removed > 0) {
                log.info("Retention sweep removed {} screenshot(s) older than {} days", removed, retentionDays);
            }
        } catch (Exception e) {
            log.warn("Retention sweep failed: {}", e.getMessage());
        }
    }
}
