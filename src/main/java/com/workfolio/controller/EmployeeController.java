package com.workfolio.controller;

import com.workfolio.model.ActivityLog;
import com.workfolio.model.Employee;
import com.workfolio.service.ActivityService;
import com.workfolio.service.EmployeeService;
import org.springframework.security.core.Authentication;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.*;

import java.util.List;
import java.util.stream.Collectors;

@Controller
public class EmployeeController {

    private final EmployeeService service;
    private final ActivityService activityService;

    public EmployeeController(EmployeeService service, ActivityService activityService) {
        this.service = service;
        this.activityService = activityService;
    }

    @GetMapping("/employees")
    public String list(Model model, Authentication auth) {
        Employee current = service.findByEmail(auth.getName());
        model.addAttribute("currentUser", current);
        model.addAttribute("employees", service.findAll());
        model.addAttribute("totalCount", service.countAll());
        return "employees/employees";
    }

    @GetMapping("/employees/new")
    public String newForm(Model model, Authentication auth) {
        model.addAttribute("employee", new Employee());
        model.addAttribute("roles", Employee.Role.values());
        model.addAttribute("mode", "new");
        return "employees/employee-form";
    }

    @GetMapping("/admin/employees/edit/{id}")
    public String editForm(@PathVariable Long id, Model model, Authentication auth) {
        model.addAttribute("employee", service.findById(id));
        model.addAttribute("roles", Employee.Role.values());
        model.addAttribute("mode", "edit");
        return "employees/employee-form";
    }

    @PostMapping("/employees/save")
    public String save(@ModelAttribute("employee") Employee emp, Authentication auth) {
        Employee actor = service.findByEmail(auth.getName());
        service.save(emp);
        activityService.logAction(actor, "CREATE_EMPLOYEE",
            "Added " + emp.getFullName() + " (" + emp.getEmail() + ") as " + emp.getRole() + " in " + emp.getDepartment());
        return "redirect:/employees";
    }

    @PostMapping("/employees/update")
    public String update(@ModelAttribute("employee") Employee emp, Authentication auth) {
        Employee actor = service.findByEmail(auth.getName());
        service.update(emp);
        activityService.logAction(actor, "UPDATE_EMPLOYEE",
            "Updated details of " + emp.getFullName() + " (" + emp.getEmail() + ")");
        return "redirect:/employees";
    }

    @GetMapping("/admin/employees/delete/{id}")
    public String delete(@PathVariable Long id, Authentication auth) {
        Employee actor = service.findByEmail(auth.getName());
        Employee victim = service.findById(id);
        service.delete(id);
        activityService.logAction(actor, "DELETE_EMPLOYEE",
            "Deleted " + (victim == null ? "employee #" + id : victim.getFullName() + " (" + victim.getEmail() + ")"));
        return "redirect:/employees";
    }

    @GetMapping("/admin/reports")
    public String reports(Model model) {
        model.addAttribute("totalEmployees", service.countAll());
        model.addAttribute("totalAdmins", service.countByRole(Employee.Role.ADMIN));
        model.addAttribute("totalHR", service.countByRole(Employee.Role.HR));
        model.addAttribute("totalStaff", service.countByRole(Employee.Role.EMPLOYEE));
        model.addAttribute("avgSalary", Math.round(service.avgSalary() * 100.0) / 100.0);
        return "reports";
    }

    @GetMapping("/admin/activity")
    public String activity(@RequestParam(required = false) String role,
                           @RequestParam(required = false) String email,
                           Model model) {
        List<ActivityLog> logs;
        if (email != null && !email.isEmpty()) {
            logs = activityService.byEmail(email);
        } else if (role != null && !role.isEmpty()) {
            logs = activityService.byRole(role);
        } else {
            logs = activityService.all();
        }
        model.addAttribute("logs", logs);
        model.addAttribute("role", role == null ? "" : role);
        model.addAttribute("email", email == null ? "" : email);
        model.addAttribute("totalLogs", ((List<ActivityLog>) logs).size());
        model.addAttribute("emails", service.findAll().stream().map(Employee::getEmail).collect(Collectors.toList()));
        return "activity";
    }
}