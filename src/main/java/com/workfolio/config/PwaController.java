package com.workfolio.config;

import org.springframework.core.io.ClassPathResource;
import org.springframework.core.io.Resource;
import org.springframework.http.CacheControl;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.stereotype.Controller;
import org.springframework.web.bind.annotation.GetMapping;

import java.time.Duration;

/**
 * Serves the PWA manifest with the right content type.
 *
 * Why this exists: Spring Boot serves files from {@code static/} through the
 * servlet container, and Tomcat 9 has no default MIME mapping for
 * {@code .webmanifest}. The file is therefore delivered as
 * {@code application/octet-stream}, and Chrome then refuses the manifest and the
 * "Install this app" prompt never appears. Renaming the file to .json would
 * dodge the issue but loses the conventional extension, so the content type is
 * pinned here instead.
 *
 * The body is still the file in {@code static/}, which keeps one copy of the
 * manifest rather than a string literal that can drift.
 */
@Controller
public class PwaController {

    private static final String MANIFEST_PATH = "static/manifest.webmanifest";

    @GetMapping(value = "/manifest.webmanifest", produces = "application/manifest+json")
    public ResponseEntity<Resource> manifest() {
        // Re-read on each request: in development the file is edited in place and
        // the browser must see the change. max-age=0 keeps the ETag doing the work.
        return ResponseEntity.ok()
                .contentType(MediaType.parseMediaType("application/manifest+json"))
                .cacheControl(CacheControl.maxAge(Duration.ZERO).cachePrivate())
                .body(new ClassPathResource(MANIFEST_PATH));
    }
}
