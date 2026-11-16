package com.workfolio.config;

import com.workfolio.model.Employee;
import com.workfolio.service.ActivityService;
import com.workfolio.service.EmployeeService;
import org.springframework.security.core.Authentication;
import org.springframework.security.core.context.SecurityContextHolder;
import org.springframework.stereotype.Component;
import org.springframework.web.servlet.HandlerInterceptor;

import javax.servlet.http.HttpServletRequest;
import javax.servlet.http.HttpServletResponse;
import java.util.LinkedHashMap;
import java.util.Map;

@Component
public class ActivityInterceptor implements HandlerInterceptor {

    private static final Map<String, String> PAGE_NAMES = new LinkedHashMap<>();
    static {
        PAGE_NAMES.put("/dashboard", "Viewed Dashboard");
        PAGE_NAMES.put("/employees", "Viewed Employee Directory");
        PAGE_NAMES.put("/employees/", "Opened Employee Form");
        PAGE_NAMES.put("/profile", "Viewed Own Profile");
        PAGE_NAMES.put("/admin/reports", "Viewed Analytics & Reports");
        PAGE_NAMES.put("/admin/activity", "Viewed Activity Tracker");
        PAGE_NAMES.put("/admin/employees", "Managed Employee Records");
        PAGE_NAMES.put("/h2-console", "Accessed H2 Console");
    }

    private final EmployeeService employeeService;
    private final ActivityService activityService;

    public ActivityInterceptor(EmployeeService employeeService, ActivityService activityService) {
        this.employeeService = employeeService;
        this.activityService = activityService;
    }

    @Override
    public boolean preHandle(HttpServletRequest request, HttpServletResponse response, Object handler) {
        try {
            Authentication auth = SecurityContextHolder.getContext().getAuthentication();
            if (auth != null && auth.isAuthenticated()
                    && !"anonymousUser".equals(auth.getPrincipal())
                    && auth.getPrincipal() instanceof org.springframework.security.core.userdetails.User) {
                String uri = request.getRequestURI();
                if (uri.startsWith("/css") || uri.startsWith("/js") || uri.startsWith("/images")
                        || uri.equals("/login") || uri.startsWith("/error") || uri.startsWith("/favicon")
                        || uri.equals("/") || request.getMethod().equals("POST")) {
                    return true;
                }
                String pageName = "Browsed " + uri;
                for (Map.Entry<String, String> e : PAGE_NAMES.entrySet()) {
                    if (uri.equals(e.getKey()) || uri.startsWith(e.getKey())) {
                        pageName = e.getValue();
                        break;
                    }
                }
                Employee user = employeeService.findByEmail(auth.getName());
                if (user != null) {
                    activityService.logAction(user, "PAGE_VIEW", pageName);
                }
            }
        } catch (Exception ignored) {
        }
        return true;
    }
}