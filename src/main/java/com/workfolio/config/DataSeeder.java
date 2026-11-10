package com.workfolio.config;

import com.workfolio.model.Employee;
import com.workfolio.repository.EmployeeRepository;
import org.springframework.boot.CommandLineRunner;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.security.crypto.password.PasswordEncoder;

@Configuration
public class DataSeeder {

    @Bean
    public CommandLineRunner seed(EmployeeRepository repository, PasswordEncoder encoder) {
        return args -> {
            if (repository.count() == 0) {
                Employee admin = new Employee();
                admin.setFirstName("Admin");
                admin.setLastName("Root");
                admin.setEmail("admin@workfolio.com");
                admin.setPassword(encoder.encode("admin123"));
                admin.setRole(Employee.Role.ADMIN);
                admin.setDepartment("Administration");
                admin.setDesignation("System Administrator");
                admin.setPhone("9876543210");
                admin.setSalary(120000.0);
                admin.setJoinDate("2020-01-15");
                admin.setAddress("New Delhi, India");
                repository.save(admin);

                Employee hr = new Employee();
                hr.setFirstName("Riya");
                hr.setLastName("Sharma");
                hr.setEmail("hr@workfolio.com");
                hr.setPassword(encoder.encode("hr123"));
                hr.setRole(Employee.Role.HR);
                hr.setDepartment("Human Resources");
                hr.setDesignation("HR Manager");
                hr.setPhone("9123456780");
                hr.setSalary(80000.0);
                hr.setJoinDate("2021-03-10");
                hr.setAddress("Mumbai, India");
                repository.save(hr);

                Employee emp = new Employee();
                emp.setFirstName("Rahul");
                emp.setLastName("Verma");
                emp.setEmail("rahul@workfolio.com");
                emp.setPassword(encoder.encode("emp123"));
                emp.setRole(Employee.Role.EMPLOYEE);
                emp.setDepartment("Engineering");
                emp.setDesignation("Software Engineer");
                emp.setPhone("9988776655");
                emp.setSalary(65000.0);
                emp.setJoinDate("2022-07-01");
                emp.setAddress("Bengaluru, India");
                repository.save(emp);

                Employee emp2 = new Employee();
                emp2.setFirstName("Sneha");
                emp2.setLastName("Patil");
                emp2.setEmail("sneha@workfolio.com");
                emp2.setPassword(encoder.encode("emp123"));
                emp2.setRole(Employee.Role.EMPLOYEE);
                emp2.setDepartment("Engineering");
                emp2.setDesignation("Frontend Developer");
                emp2.setPhone("9004567890");
                emp2.setSalary(60000.0);
                emp2.setJoinDate("2023-02-20");
                emp2.setAddress("Pune, India");
                repository.save(emp2);
            }
        };
    }

    /** Guarantees every employee has a monitoring policy row so the agent can start immediately. */
    @Bean
    public CommandLineRunner seedAgentConfigs(EmployeeRepository employees,
                                             com.workfolio.repository.AgentConfigRepository configs) {
        return args -> {
            for (Employee e : employees.findAll()) {
                if (!configs.existsById(e.getEmail())) {
                    configs.save(new com.workfolio.model.AgentConfig(e.getEmail()));
                }
            }
        };
    }
}