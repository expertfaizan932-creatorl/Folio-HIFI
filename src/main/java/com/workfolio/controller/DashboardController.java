package com.workfolio.controller;

import com.workfolio.model.Employee;
import com.workfolio.service.EmployeeService;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.GetMapping;

@Controller
public class DashboardController {

    private final EmployeeService service;

    /**
     * Whether the login screen should print the seeded sign-in credentials.
     * Defaults to false so a real deployment does not advertise its passwords.
     */
    @Value("${workfolio.demo.show-accounts:false}")
    private boolean showDemoAccounts;

    public DashboardController(EmployeeService service) {
        this.service = service;
    }

    @GetMapping("/")
    public String root() {
        return "redirect:/dashboard";
    }

    @GetMapping("/login")
    public String login(Model model) {
        model.addAttribute("showDemoAccounts", showDemoAccounts);
        return "login";
    }

    @GetMapping("/dashboard")
    public String dashboard(Model model, Authentication auth) {
        Employee current = service.findByEmail(auth.getName());
        model.addAttribute("currentUser", current);
        model.addAttribute("totalEmployees", service.countAll());
        model.addAttribute("totalAdmins", service.countByRole(Employee.Role.ADMIN));
        model.addAttribute("totalHR", service.countByRole(Employee.Role.HR));
        model.addAttribute("avgSalary", Math.round(service.avgSalary() * 100.0) / 100.0);
        return "index";
    }

    @GetMapping("/profile")
    public String profile(Model model, Authentication auth) {
        Employee current = service.findByEmail(auth.getName());
        model.addAttribute("currentUser", current);
        return "profile";
    }
}