package com.workfolio.service;

import com.workfolio.model.Employee;
import com.workfolio.repository.EmployeeRepository;
import org.springframework.security.core.authority.SimpleGrantedAuthority;
import org.springframework.security.core.userdetails.User;
import org.springframework.security.core.userdetails.UserDetails;
import org.springframework.security.core.userdetails.UserDetailsService;
import org.springframework.security.core.userdetails.UsernameNotFoundException;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;

import java.util.Collections;
import java.util.List;

@Service
public class EmployeeService implements UserDetailsService {

    private final EmployeeRepository repository;
    private final PasswordEncoder passwordEncoder;

    public EmployeeService(EmployeeRepository repository, PasswordEncoder passwordEncoder) {
        this.repository = repository;
        this.passwordEncoder = passwordEncoder;
    }

    @Override
    public UserDetails loadUserByUsername(String email) throws UsernameNotFoundException {
        Employee emp = repository.findByEmail(email)
                .orElseThrow(() -> new UsernameNotFoundException("User not found: " + email));
        return new User(emp.getEmail(), emp.getPassword(),
                Collections.singletonList(new SimpleGrantedAuthority("ROLE_" + emp.getRole().name())));
    }

    public List<Employee> findAll() {
        return repository.findAll();
    }

    public List<Employee> findAllByOrderByIdDesc() {
        return repository.findAll();
    }

    public Employee findById(Long id) {
        return repository.findById(id).orElse(null);
    }

    public Employee findByEmail(String email) {
        return repository.findByEmail(email).orElse(null);
    }

    public Employee save(Employee emp) {
        if (emp.getPassword() != null && !emp.getPassword().isEmpty()) {
            emp.setPassword(passwordEncoder.encode(emp.getPassword()));
        }
        return repository.save(emp);
    }

    public Employee update(Employee emp) {
        return repository.save(emp);
    }

    public void delete(Long id) {
        repository.deleteById(id);
    }

    public long countAll() { return repository.count(); }
    public long countByRole(Employee.Role role) { return repository.countByRole(role); }
    public double avgSalary() {
        List<Employee> all = repository.findAll();
        if (all.isEmpty()) return 0;
        double sum = 0;
        for (Employee e : all) if (e.getSalary() != null) sum += e.getSalary();
        return sum / all.size();
    }
}