<?php
/**
 * config/internship_progress.php
 *
 * Shared internship progress/week calculations — single source of truth
 * for every page that reports a student's internship progress.
 *
 * Total weeks are derived from the student's actual internship dates
 * (student_profiles.internship_start_date / internship_end_date) using the
 * project's Sun→Sat week convention from config/week_helper.php
 * (getWeekRange / buildInternshipWeeks): week 1 starts on the internship
 * start date and ends on the following Saturday, subsequent weeks are 7-day
 * blocks.
 *
 * Completed weeks are the distinct weeks for which the student submitted a
 * weekly report (a row in report_evaluations that is not 'rejected').
 * A rejected report is not counted as completed because the student must
 * redo it. Multiple daily logs within a single week count as ONE completed
 * week — the unit of progress is weeks, not log entries.
 *
 * Pure functions — no DB connection, no session, safe to require from any
 * page. PDO instances are passed in where a query is required.
 */

if (!function_exists('internship_total_weeks')) {

    /**
     * Total required internship weeks derived from the internship dates.
     *
     * @param string|null $start student_profiles.internship_start_date (Y-m-d)
     * @param string|null $end   student_profiles.internship_end_date (Y-m-d)
     * @return int  Total weeks. 0 = no start date. Falls back to the existing
     *              default duration (12 weeks) when the end date is missing.
     */
    function internship_total_weeks(?string $start, ?string $end): int
    {
        if (!$start) {
            return 0;
        }
        try {
            $startDt = new DateTime($start);
        } catch (Exception $e) {
            return 0;
        }
        if (!$end) {
            return 12;
        }
        try {
            $endDt = new DateTime($end);
        } catch (Exception $e) {
            return 12;
        }
        if ($endDt < $startDt) {
            return 1;
        }

        // Week 1 ends on the next Saturday on/after the start date.
        $dayOfWeek   = (int) $startDt->format('N'); // 1=Mon … 6=Sat, 7=Sun
        $daysToSat   = $dayOfWeek === 6 ? 0 : (6 - $dayOfWeek + 7) % 7;
        $endOfWeek1  = (clone $startDt)->modify("+{$daysToSat} days");

        if ($endDt <= $endOfWeek1) {
            return 1;
        }

        $diff = (int) $endDt->diff($endOfWeek1)->days;
        return 1 + (int) ceil($diff / 7);
    }

    /**
     * Current calendar week of the internship for a given date.
     * Mirrors config/week_helper.php getInternshipWeekNumber() and clamps the
     * result to the internship's total weeks so an ended internship never
     * reports a week past its own duration.
     *
     * @param string|null    $start Y-m-d
     * @param string|null    $end   Y-m-d
     * @param DateTime|null  $today Defaults to now.
     * @return int
     */
    function internship_current_week(?string $start, ?string $end, ?DateTime $today = null): int
    {
        $today = $today ?: new DateTime();
        if (!$start) {
            return 1;
        }
        try {
            $startDt = new DateTime($start);
        } catch (Exception $e) {
            return 1;
        }

        $total = internship_total_weeks($start, $end);
        if ($today < $startDt) {
            return 1;
        }

        $dayOfWeek  = (int) $startDt->format('N');
        $daysToSat  = $dayOfWeek === 6 ? 0 : (6 - $dayOfWeek + 7) % 7;
        $endOfWeek1 = (clone $startDt)->modify("+{$daysToSat} days");

        if ($today <= $endOfWeek1) {
            return 1;
        }

        $diff = (int) $today->diff($endOfWeek1)->days;
        $week = 1 + (int) ceil($diff / 7);

        return $total > 0 ? max(1, min($week, $total)) : $week;
    }

    /**
     * Number of weeks the student actually completed.
     * A week counts as completed when a weekly report exists in
     * report_evaluations (student_id = users.id) and is not 'rejected'.
     * Distinct weeks only — daily-log volume inside a week never inflates it.
     *
     * @param mysqli|mixed  $db
     * @param int           $student_user_id users.id of the student
     * @return int
     */
    function internship_completed_weeks($db, int $student_user_id): int
    {
        $stmt = $db->prepare(
            "SELECT COUNT(DISTINCT week_number) FROM report_evaluations
             WHERE student_id = ? AND report_status <> 'rejected'"
        );
        $stmt->bind_param("i", $student_user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_row() : null;
        return (int) ($row[0] ?? 0);
    }

    /**
     * Full progress summary for one student.
     *
     * @param mysqli|mixed  $db
     * @param int           $student_user_id users.id of the student
     * @param string|null   $start internship_start_date
     * @param string|null   $end   internship_end_date
     * @return array{total:int, completed:int, pct:int}
     *   pct = round(completed / total * 100), 0 when the duration is unknown.
     */
    function internship_progress($db, int $student_user_id, ?string $start, ?string $end): array
    {
        $total     = internship_total_weeks($start, $end);
        $completed = $total > 0 ? internship_completed_weeks($db, $student_user_id) : 0;
        $pct       = $total > 0 ? min(100, (int) round(($completed / $total) * 100)) : 0;

        return ['total' => $total, 'completed' => $completed, 'pct' => $pct];
    }

    /**
     * Attendance summary for a student's internship (or a single week).
     *
     * Shared by every supervisor-facing page that reports attendance so the
     * calculation is identical everywhere. Attendance is recorded per day in
     * daily_logs (one row per date — unique index on (internship_id, log_date))
     * with attendance_status present/leave/absent. Expected days are the days
     * that have an attendance record; days with no record are not counted and
     * 'leave' counts as absent.
     *
     * @param mysqli|mixed $db
     * @param int          $internship_id daily_logs.internship_id (= the student's users.id)
     * @param string|null  $start Optional Y-m-d lower bound (inclusive).
     *                            Passed together with $end to scope to one week.
     * @param string|null  $end   Optional Y-m-d upper bound (inclusive).
     * @return array{present:int, absent:int, expected:int, rate:int}
     *   rate = round(present / expected * 100), 0 when there are no records.
     */
    function internship_attendance($db, int $internship_id, ?string $start = null, ?string $end = null): array
    {
        if ($start && $end) {
            $stmt = $db->prepare(
                "SELECT
                    SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) AS present_cnt,
                    SUM(CASE WHEN attendance_status IN ('leave','absent') THEN 1 ELSE 0 END) AS absent_cnt
                FROM daily_logs
                WHERE internship_id = ? AND log_date BETWEEN ? AND ?"
            );
            $stmt->bind_param("iss", $internship_id, $start, $end);
        } else {
            $stmt = $db->prepare(
                "SELECT
                    SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) AS present_cnt,
                    SUM(CASE WHEN attendance_status IN ('leave','absent') THEN 1 ELSE 0 END) AS absent_cnt
                FROM daily_logs
                WHERE internship_id = ?"
            );
            $stmt->bind_param("i", $internship_id);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : [];

        $present  = (int) ($row['present_cnt'] ?? 0);
        $absent   = (int) ($row['absent_cnt'] ?? 0);
        $expected = $present + $absent;

        return [
            'present'  => $present,
            'absent'   => $absent,
            'expected' => $expected,
            'rate'     => $expected > 0 ? (int) round(($present / $expected) * 100) : 0,
        ];
    }
}
