package com.workfolio.service;

import com.workfolio.model.Employee;
import org.springframework.stereotype.Service;

import java.util.Map;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Bearer tokens for the desktop agent.
 *
 * The agent is not a browser session, so it authenticates with an opaque token
 * instead of a Spring Security form login. Tokens are held in memory: if the
 * server restarts, employees sign in to the agent again.
 */
@Service
public class AgentAuthService {

    private final Map<String, String> tokens = new ConcurrentHashMap<>();

    public String createToken(String email) {
        String token = UUID.randomUUID().toString().replace("-", "");
        tokens.put(token, email);
        return token;
    }

    public String emailFor(String token) {
        return token == null ? null : tokens.get(token);
    }

    /**
     * Validates a token and returns the employee behind it, or null when the token
     * is unknown. This is the single entry point the agent controllers use.
     */
    public Employee requireEmployee(String token, EmployeeService employees) {
        String email = emailFor(token);
        if (email == null) return null;
        return employees.findByEmail(email);
    }

    public void revoke(String token) {
        if (token != null) tokens.remove(token);
    }

    public void revokeAllFor(String email) {
        if (email == null) return;
        tokens.values().remove(email);
    }

    public int activeSessions() {
        return tokens.size();
    }
}
