<?php

/**
 * UI Helper Functions for InternReport Management System
 */

if (!function_exists('report_status_badge')) {
    /**
     * Get label and CSS classes for report evaluation status badges.
     */
    function report_status_badge($status)
    {
        switch ($status) {
            case 'approved_by_instructor':
                $label = 'Instructor Approved';
                $classes = 'text-blue-700 bg-blue-50 border-blue-200';
                break;
            case 'approved_by_supervisor':
                $label = 'Supervisor Approved';
                $classes = 'text-emerald-700 bg-emerald-50 border-emerald-200';
                break;
            case 'rejected':
                $label = 'Rejected';
                $classes = 'text-red-700 bg-red-50 border-red-200';
                break;
            default:
                $label = 'Waiting for Instructor';
                $classes = 'text-slate-600 bg-slate-50 border-slate-200';
                break;
        }
        return [
            0 => $label,
            1 => $classes,
            'label' => $label,
            'classes' => $classes,
        ];
    }
}

if (!function_exists('report_status_dot')) {
    /**
     * Get CSS class for report status dot indicator.
     */
    function report_status_dot($status)
    {
        switch ($status) {
            case 'approved_by_instructor':
                return 'bg-blue-500 animate-pulse';
            case 'approved_by_supervisor':
                return 'bg-emerald-500';
            case 'rejected':
                return 'bg-red-500';
            default:
                return 'bg-slate-400';
        }
    }
}


if (!function_exists('progress_status_label')) {
    /**
     * Get label and CSS classes for student weekly progress status.
     */
    function progress_status_label($status)
    {
        switch ($status) {
            case 'red':
                $label = 'Behind Schedule';
                $classes = 'text-red-700 bg-red-50 border-red-200';
                break;
            case 'amber':
                $label = 'In Progress';
                $classes = 'text-amber-700 bg-amber-50 border-amber-200';
                break;
            case 'green':
                $label = 'Complete';
                $classes = 'text-emerald-700 bg-emerald-50 border-emerald-200';
                break;
            default:
                $label = 'Not Started';
                $classes = 'text-slate-500 bg-slate-50 border-slate-200';
                break;
        }
        return [
            0 => $label,
            1 => $classes,
            'label' => $label,
            'classes' => $classes,
        ];
    }
}

if (!function_exists('progress_status_dot')) {
    /**
     * Get CSS class for student progress status dot indicator.
     */
    function progress_status_dot($status)
    {
        switch ($status) {
            case 'red':
                return 'bg-red-500 animate-pulse';
            case 'amber':
                return 'bg-amber-500';
            case 'green':
                return 'bg-emerald-500';
            default:
                return 'bg-slate-400';
        }
    }
}

if (!function_exists('progress_bar_color')) {
    /**
     * Get progress bar color gradient based on percentage completed.
     */
    function progress_bar_color($pct)
    {
        if ($pct >= 80) return 'from-emerald-500 to-teal-600';
        if ($pct >= 40) return 'from-teal-500 to-teal-600';
        return 'from-amber-500 to-orange-500';
    }
}

if (!function_exists('grade_badge_styles')) {
    /**
     * Get label and CSS classes for student grade badges.
     */
    function grade_badge_styles($grade)
    {
        $map = [
            'excellent'         => ['Excellent',         'text-emerald-600', 'bg-emerald-50'],
            'good'              => ['Good',              'text-teal-600',    'bg-teal-50'],
            'average'           => ['Average',           'text-amber-600',   'bg-amber-50'],
            'needs_improvement' => ['Needs Improvement', 'text-red-600',     'bg-red-50'],
        ];
        $item = $map[$grade] ?? ['—', 'text-slate-400', 'bg-slate-50'];
        return [
            0 => $item[0],
            1 => $item[1],
            2 => $item[2],
            'label' => $item[0],
            'text'  => $item[1],
            'bg'    => $item[2],
        ];
    }
}


if (!function_exists('format_supervisor_name')) {
    /**
     * Format a supervisor's username for clean UI display by removing
     * the "sup_" or "sup-" prefix (e.g. sup_dawmya -> Daw Mya, sup_mgmg -> Mg Mg).
     *
     * @param string|null $name
     * @return string Formatted display name
     */
    function format_supervisor_name($name)
    {
        if ($name === null || $name === '' || $name === '—' || $name === 'Unassigned') {
            return $name ?: 'Supervisor';
        }

        $trimmed = trim((string) $name);
        // Remove leading sup_, sup-, sup., sup (case-insensitive)
        $clean = preg_replace('/^sup[_\-\.\s]+/i', '', $trimmed);
        if ($clean === null || $clean === '') {
            return 'Supervisor';
        }

        // Replace underscores, hyphens, and dots with spaces
        $clean = str_replace(['_', '-', '.'], ' ', $clean);

        // If it's a single word without spaces, split common Myanmar / English honorific prefixes
        if (strpos($clean, ' ') === false) {
            // e.g. dawmya -> daw mya, mgmg -> mg mg, kothant -> ko thant, mathuzar -> ma thuzar, draung -> dr aung, profwin -> prof win
            $clean = preg_replace('/^(daw|mg|ko|ma|dr|prof)([a-z]+)$/i', '$1 $2', $clean);
            // e.g. uhlatun -> u hlatun
            $clean = preg_replace('/^u([bcdfghjklmnpqrstvwxyz][a-z]+)$/i', 'u $1', $clean);
        }

        return ucwords($clean);
    }
}

