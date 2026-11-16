package com.workfolio.repository;

import com.workfolio.model.TrackRecord;
import org.springframework.data.jpa.repository.JpaRepository;

import java.time.LocalDateTime;
import java.util.List;
import java.util.Optional;

public interface TrackRecordRepository extends JpaRepository<TrackRecord, Long> {
    Optional<TrackRecord> findByEmailAndWorkDate(String email, String workDate);
    List<TrackRecord> findByEmailOrderByWorkDateDesc(String email);
    List<TrackRecord> findByWorkDateOrderByCheckInAsc(String workDate);
    List<TrackRecord> findByCheckInBetweenOrderByCheckInAsc(LocalDateTime start, LocalDateTime end);
    List<TrackRecord> findByStatusNot(String status);
}