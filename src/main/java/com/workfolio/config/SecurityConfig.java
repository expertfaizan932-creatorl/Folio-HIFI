package com.workfolio.config;

import com.workfolio.service.EmployeeService;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.security.authentication.dao.DaoAuthenticationProvider;
import org.springframework.security.config.annotation.authentication.builders.AuthenticationManagerBuilder;
import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.config.annotation.web.configuration.EnableWebSecurity;
import org.springframework.security.config.annotation.web.configuration.WebSecurityConfigurerAdapter;
import org.springframework.security.crypto.password.PasswordEncoder;
@Configuration
@EnableWebSecurity
public class SecurityConfig extends WebSecurityConfigurerAdapter {

    private final EmployeeService employeeService;
    private final PasswordEncoder passwordEncoder;
    private final LoginSuccessHandler loginSuccessHandler;

    public SecurityConfig(EmployeeService employeeService, PasswordEncoder passwordEncoder,
                          LoginSuccessHandler loginSuccessHandler) {
        this.employeeService = employeeService;
        this.passwordEncoder = passwordEncoder;
        this.loginSuccessHandler = loginSuccessHandler;
    }

    @Bean
    public DaoAuthenticationProvider authenticationProvider() {
        DaoAuthenticationProvider provider = new DaoAuthenticationProvider();
        provider.setUserDetailsService(employeeService);
        provider.setPasswordEncoder(passwordEncoder);
        return provider;
    }

    @Override
    protected void configure(AuthenticationManagerBuilder auth) {
        auth.authenticationProvider(authenticationProvider());
    }

    @Override
    protected void configure(HttpSecurity http) throws Exception {
        http
            .authorizeRequests()
                // Static assets. The PWA entries (manifest, service worker, icons) must be
                // reachable while signed out, otherwise the browser can never fetch them
                // from the login page and the "Install this app" prompt never appears.
                .antMatchers("/css/**", "/js/**", "/icons/**", "/login", "/favicon.ico",
                             "/manifest.webmanifest", "/sw.js").permitAll()
                .antMatchers("/api/agent/**").permitAll()
                .antMatchers("/api/monitor/**", "/monitor", "/portal/history/**",
                             "/admin/usage", "/admin/usage.csv", "/admin/usage-targets.csv",
                             "/admin/agent-config", "/admin/agent-config/**").hasAnyRole("ADMIN", "HR")
                // The H2 console is a full SQL shell. It used to be permitAll, which
                // exposed it to anyone who could reach the port; in a real deployment it
                // is disabled outright (spring.h2.console.enabled=false in the mysql and
                // prod profiles). Signed-in admins keep it for local debugging.
                .antMatchers("/h2-console/**").hasRole("ADMIN")
                .antMatchers("/admin/**").hasRole("ADMIN")
                .antMatchers("/portal/users/**").hasRole("ADMIN")
                .antMatchers("/employees/**").hasAnyRole("ADMIN", "HR")
                .anyRequest().authenticated()
            .and()
                .formLogin()
                .loginPage("/login")
                .successHandler(loginSuccessHandler)
                .failureUrl("/login?error=true")
                .permitAll()
            .and()
                .logout().logoutSuccessUrl("/login?logout=true").permitAll()
            .and()
                .csrf().ignoringAntMatchers("/api/**", "/h2-console/**")
            .and()
                .headers().frameOptions().sameOrigin();
    }
}