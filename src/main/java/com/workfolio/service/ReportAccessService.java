package com.workfolio.service;

import com.workfolio.model.Employee;
import org.springframework.stereotype.Service;

import java.util.ArrayList;
import java.util.List;

/**
 * Row-level access to per-employee tracking data.
 *
 * URL security answers "may this role open the report at all" - and that is not
 * enough. Once inside, the report iterates over every employee, so without this
 * class an HR would see other HR staff and the admin's own activity. The same
 * applies to any endpoint that takes an email or a record id as a parameter:
 * those are direct references that bypass the page-level filter entirely.
 *
 * The rule:
 * <ul>
 *   <li>ADMIN sees everyone, including other admins and themselves.</li>
 *   <li>HR sees employees, plus their own record.</li>
 *   <li>EMPLOYEE sees only their own record.</li>
 * </ul>
 *
 * Every "which rows may this person see" decision belongs here so the rule is
 * defined once. Adding a screen means calling {@link #visibleEmployees} or
 * {@link #canView}, never re-deriving the hierarchy.
 */
@Service
public class ReportAccessService {

    private final EmployeeService employees;

    public ReportAccessService(EmployeeService employees) {
        this.employees = employees;
    }

    /** May {@code viewer} read the tracking data of {@code target}? */
    public boolean canView(Employee viewer, Employee target) {
        if (viewer == null || target == null) return false;
        // Everyone can always see themselves, whatever their role.
        if (sameEmail(viewer.getEmail(), target.getEmail())) return true;
        if (viewer.getRole() == Employee.Role.ADMIN) return true;
        if (viewer.getRole() == Employee.Role.HR) {
            // HR supervises employees. Another HR colleague and the admin are
            // not part of that, which is what keeps the admin's own report
            // outside HR's reach.
            return target.getRole() == Employee.Role.EMPLOYEE;
        }
        return false;
    }

    /**
     * Overload for callers that hold only the target's email, such as a
     * screenshot looked up by id.
     *
     * Fails closed: an unknown email is denied rather than allowed, so records
     * left behind by a deleted employee cannot be reached by id alone.
     */
    public boolean canView(Employee viewer, String targetEmail) {
        if (viewer == null || targetEmail == null || targetEmail.trim().isEmpty()) return false;
        if (sameEmail(viewer.getEmail(), targetEmail)) return true;
        Employee target = employees.findByEmail(targetEmail.trim());
        return target != null && canView(viewer, target);
    }

    /**
     * The employees whose reports {@code viewer} may read, in the same order the
     * caller passed them in. Use this instead of {@code employeeService.findAll()}
     * for anything that renders or exports per-employee rows.
     */
    public List<Employee> visibleEmployees(Employee viewer) {
        List<Employee> out = new ArrayList<>();
        for (Employee e : employees.findAll()) {
            if (canView(viewer, e)) out.add(e);
        }
        return out;
    }

    /**
     * Sanitises a {@code ?email=} selection before it is used to filter rows.
     *
     * Returns the email when the viewer is allowed to see that person, and null
     * otherwise. Null means "no single-person filter", i.e. every visible
     * employee - so asking for someone you may not see quietly yields the
     * broadest permitted view instead of an error, and nothing about the hidden
     * row is revealed either way.
     */
    public String resolveSelection(Employee viewer, String requestedEmail) {
        if (requestedEmail == null || requestedEmail.trim().isEmpty()) return null;
        String wanted = requestedEmail.trim();
        Employee target = employees.findByEmail(wanted);
        if (target == null) return null;
        return canView(viewer, target) ? target.getEmail() : null;
    }

    private static boolean sameEmail(String a, String b) {
        return a != null && b != null && a.equalsIgnoreCase(b);
    }
}
