package com.workfolio.repository;

import com.workfolio.model.AgentConfig;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Optional;

public interface AgentConfigRepository extends JpaRepository<AgentConfig, String> {
    Optional<AgentConfig> findByEmail(String email);
}
