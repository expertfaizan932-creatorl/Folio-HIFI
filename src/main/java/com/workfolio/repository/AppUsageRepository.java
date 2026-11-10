package com.workfolio.repository;

import com.workfolio.model.AppUsage;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;

import java.util.List;
import java.util.Optional;

public interface AppUsageRepository extends JpaRepository<AppUsage, Long> {

    Optional<AppUsage> findByEmailAndWorkDateAndTargetKey(String email, String workDate, String targetKey);

    List<AppUsage> findByEmailAndWorkDateOrderBySecondsDesc(String email, String workDate);

    List<AppUsage> findByEmailAndWorkDateAndTargetTypeOrderBySecondsDesc(String email, String workDate, String targetType);

    List<AppUsage> findByWorkDateOrderBySecondsDesc(String workDate);

    List<AppUsage> findByEmailOrderByWorkDateDesc(String email);

    @Query("select distinct u.workDate from AppUsage u where u.email = :email order by u.workDate desc")
    List<String> findDistinctDatesByEmail(@Param("email") String email);

    @Query("select coalesce(sum(u.seconds),0) from AppUsage u where u.email = :email and u.workDate = :date and u.targetType = :type")
    long sumSeconds(@Param("email") String email, @Param("date") String date, @Param("type") String type);

    @Query("select count(distinct u.targetKey) from AppUsage u where u.email = :email and u.workDate = :date and u.targetType = :type")
    long countDistinctTargets(@Param("email") String email, @Param("date") String date, @Param("type") String type);

    @Query("select u.targetKey from AppUsage u where u.email = :email and u.workDate = :date and u.targetType in :types order by u.seconds desc")
    List<String> findTopTargets(@Param("email") String email, @Param("date") String date,
                                @Param("types") List<String> types);

    @Query("select coalesce(sum(u.seconds),0) from AppUsage u where u.workDate = :date and u.targetType = :type")
    long sumSecondsForDate(@Param("date") String date, @Param("type") String type);

    @Query("select u.host, coalesce(sum(u.seconds),0) from AppUsage u "
            + "where u.email = :email and u.workDate = :date and u.targetType = 'WEB' and u.host is not null "
            + "group by u.host order by coalesce(sum(u.seconds),0) desc")
    List<Object[]> topHosts(@Param("email") String email, @Param("date") String date);

    @Query("select u.processName, coalesce(sum(u.seconds),0) from AppUsage u "
            + "where u.email = :email and u.workDate = :date and u.targetType = 'APP' and u.processName is not null "
            + "group by u.processName order by coalesce(sum(u.seconds),0) desc")
    List<Object[]> topProcesses(@Param("email") String email, @Param("date") String date);
}
