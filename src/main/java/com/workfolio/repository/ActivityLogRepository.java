package com.workfolio.repository;

import com.workfolio.model.ActivityLog;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.List;

public interface ActivityLogRepository extends JpaRepository<ActivityLog, Long> {
    List<ActivityLog> findAllByOrderByTimestampDesc();
    List<ActivityLog> findByUserRoleOrderByTimestampDesc(String role);
    List<ActivityLog> findByUserEmailOrderByTimestampDesc(String email);
    List<ActivityLog> findByActionOrderByTimestampDesc(String action);
    long countByUserRole(String role);
}