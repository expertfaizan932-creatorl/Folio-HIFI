package com.workfolio.repository;

import com.workfolio.model.Screenshot;
import org.springframework.data.domain.Pageable;
import org.springframework.data.jpa.repository.JpaRepository;

import java.time.LocalDateTime;
import java.util.List;
import java.util.Optional;

public interface ScreenshotRepository extends JpaRepository<Screenshot, Long> {
    List<Screenshot> findTop20ByEmailOrderByCapturedAtDesc(String email);
    Optional<Screenshot> findTop1ByEmailOrderByCapturedAtDesc(String email);
    Optional<Screenshot> findTop1ByEmailAndLiveTrueOrderByCapturedAtDesc(String email);
    List<Screenshot> findByEmailOrderByCapturedAtDesc(String email);
    List<Screenshot> findByEmailAndCapturedAtBetweenOrderByCapturedAtDesc(String email, LocalDateTime from, LocalDateTime to);
    List<Screenshot> findByCapturedAtBetweenOrderByCapturedAtDesc(LocalDateTime from, LocalDateTime to);
    List<Screenshot> findByEmailOrderByCapturedAtDesc(String email, Pageable pageable);
    long countByEmail(String email);
    long countByEmailAndLive(String email, boolean live);
    long countByCapturedAtBetween(LocalDateTime from, LocalDateTime to);
    void deleteByCapturedAtBefore(LocalDateTime cutoff);
}
