package com.workfolio.controller;

import com.workfolio.model.Employee;
import com.workfolio.model.TrackRecord;
import com.workfolio.repository.ScreenshotRepository;
import com.workfolio.service.EmployeeService;
import com.workfolio.service.ReportAccessService;
import com.workfolio.service.TrackService;
import org.springframework.stereotype.Controller;
import org.springframework.ui.Model;
import org.springframework.web.bind.annotation.*;

import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

@Controller
public class PortalController {

    private final EmployeeService employeeService;
    private final TrackService trackService;
    private final ScreenshotRepository shotRepo;
    private final ReportAccessService access;

    public PortalController(EmployeeService employeeService, TrackService trackService,
                            ScreenshotRepository shotRepo, ReportAccessService access) {
        this.employeeService = employeeService;
        this.trackService = trackService;
        this.shotRepo = shotRepo;
        this.access = access;
    }

    /**
     * "My Time" defaults to the signed-in user. Admins and HR may pass ?email= to
     * view someone else's sheet (that is what the login-as link uses). Falling back
     * to the caller keeps the page from rendering a null user and throwing.
     */
    @GetMapping("/time")
    public String time(Model model, @RequestParam(required = false) String email,
                       org.springframework.security.core.Authentication auth) {
        Employee viewer = auth == null ? null : employeeService.findByEmail(auth.getName());
        Employee emp = null;
        // This is the "login as" style ?email=, and it is user input. An HR passing
        // the admin's address must land back on their own sheet, not the admin's.
        if (email != null && !email.isEmpty() && access.canView(viewer, email)) {
            emp = employeeService.findByEmail(email);
        }
        if (emp == null) {
            // Unknown ?email=, not permitted, or none supplied - fall back to the
            // signed-in user so the page always renders against a real record.
            emp = viewer;
        }
        model.addAttribute("currentUser", emp);
        model.addAttribute("viewer", viewer);
        model.addAttribute("summary", emp == null ? new ConcurrentHashMap<>() : trackService.summary(emp, TrackService.today()));
        model.addAttribute("history", emp == null ? new ArrayList<>() : trackService.history(emp.getEmail()));
        return "portal/time";
    }

    @GetMapping("/monitor")
    public String monitor(Model model, org.springframework.security.core.Authentication auth) {
        Employee current = employeeService.findByEmail(auth.getName());
        model.addAttribute("currentUser", current);
        model.addAttribute("employees", access.visibleEmployees(current));
        return "portal/monitor";
    }

    @GetMapping("/portal/users")
    public String users(Model model, org.springframework.security.core.Authentication auth) {
        Employee current = employeeService.findByEmail(auth.getName());
        model.addAttribute("currentUser", current);
        model.addAttribute("employees", employeeService.findAll());
        return "portal/users";
    }

    @PostMapping("/portal/users/save")
    public String createUser(@ModelAttribute Employee emp, @RequestParam String password) {
        emp.setPassword(password);
        employeeService.save(emp);
        return "redirect:/portal/users";
    }

    @PostMapping("/portal/users/reset")
    public String resetPassword(@RequestParam Long id, @RequestParam String password) {
        Employee emp = employeeService.findById(id);
        if (emp != null) {
            emp.setPassword(password);
            employeeService.save(emp);
        }
        return "redirect:/portal/users";
    }

    @GetMapping("/portal/history")
    public String history(@RequestParam(required = false) String email,
                          @RequestParam(defaultValue = "day") String range,
                          Model model, org.springframework.security.core.Authentication auth) {
        Employee current = employeeService.findByEmail(auth.getName());
        model.addAttribute("currentUser", current);
        List<Employee> employees = access.visibleEmployees(current);
        model.addAttribute("employees", employees);
        model.addAttribute("range", range);
        String selEmail = access.resolveSelection(current, email);
        model.addAttribute("selEmail", selEmail == null ? "" : selEmail);

        List<TrackRecord> records = new ArrayList<>();
        LocalDateTime start;
        LocalDateTime end = LocalDateTime.now();
        switch (range) {
            case "week": start = end.minusDays(7); break;
            case "month": start = end.minusDays(30); break;
            case "year": start = end.minusDays(365); break;
            default: start = LocalDate.now().atStartOfDay(); break;
        }
        if (selEmail != null) {
            records = trackService.range(selEmail, start, end);
        } else {
            for (Employee e : employees) {
                records.addAll(trackService.range(e.getEmail(), start, end));
            }
            records.sort((a, b) -> {
                LocalDateTime ca = a.getCheckIn() == null ? LocalDateTime.MIN : a.getCheckIn();
                LocalDateTime cb = b.getCheckIn() == null ? LocalDateTime.MIN : b.getCheckIn();
                return cb.compareTo(ca);
            });
        }
        model.addAttribute("records", records);
        long ws = 0, as = 0, is = 0, bs = 0;
        for (TrackRecord tr : records) { ws += tr.workedSeconds(); as += tr.getActiveSeconds(); is += tr.getIdleSeconds(); bs += tr.getBreakSeconds(); }
        model.addAttribute("totalWorked", TrackService.fmt(ws));
        model.addAttribute("totalActive", TrackService.fmt(as));
        model.addAttribute("totalIdle", TrackService.fmt(is));
        model.addAttribute("totalBreak", TrackService.fmt(bs));
        model.addAttribute("rangeLabel", range.equals("week") ? "Last 7 Days" : range.equals("month") ? "Last 30 Days" : range.equals("year") ? "Last 365 Days" : "Today");
        return "portal/history";
    }

    @PostMapping("/portal/history/adjust")
    public String adjust(@RequestParam String email, @RequestParam String date,
                         @RequestParam long activeDelta, @RequestParam long idleDelta,
                         @RequestParam long breakDelta, @RequestParam(required = false) String back) {
        trackService.adjust(email, date, activeDelta, idleDelta, breakDelta);
        return back != null ? "redirect:" + back : "redirect:/portal/history";
    }

    @PostMapping("/admin/login-as")
    public String loginAs(@RequestParam String email) {
        return "redirect:/time?email=" + email;
    }
}