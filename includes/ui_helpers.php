<?php
/**
 * UI Helper Functions for InternReport Management System
 */

if (!function_exists('report_status_badge')) {
    /**
     * Get label and CSS classes for report evaluation status badges.
     */
    function report_status_badge($status) {
        switch ($status) {
            case 'approved_by_instructor':
                return ['Awaiting grade', 'text-amber-600 bg-amber-50 border-amber-200'];
            case 'approved_by_supervisor':
                return ['Graded', 'text-emerald-600 bg-emerald-50 border-emerald-200'];
            case 'rejected':
                return ['Rejected', 'text-red-600 bg-red-50 border-red-200'];
            default:
                return ['Pending', 'text-slate-500 bg-slate-50 border-slate-200'];
        }
    }
}

if (!function_exists('report_status_dot')) {
    /**
     * Get CSS class for report status dot indicator.
     */
    function report_status_dot($status) {
        switch ($status) {
            case 'approved_by_instructor':
                return 'bg-amber-500';
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
    function progress_status_label($status) {
        switch ($status) {
            case 'red':
                return ['Behind Schedule', 'text-red-700 bg-red-50 border-red-200'];
            case 'amber':
                return ['In Progress', 'text-amber-700 bg-amber-50 border-amber-200'];
            case 'green':
                return ['Complete', 'text-emerald-700 bg-emerald-50 border-emerald-200'];
            default:
                return ['Not Started', 'text-slate-500 bg-slate-50 border-slate-200'];
        }
    }
}

if (!function_exists('progress_status_dot')) {
    /**
     * Get CSS class for student progress status dot indicator.
     */
    function progress_status_dot($status) {
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
    function progress_bar_color($pct) {
        if ($pct >= 80) return 'from-emerald-500 to-emerald-600';
        if ($pct >= 40) return 'from-indigo-500 to-purple-600';
        return 'from-amber-500 to-orange-500';
    }
}

if (!function_exists('grade_badge_styles')) {
    /**
     * Get label, text color class, and bg color class for evaluation grades.
     */
    function grade_badge_styles($grade) {
        $map = [
            'excellent'         => ['Excellent',         'text-emerald-600', 'bg-emerald-50'],
            'good'              => ['Good',              'text-blue-600',    'bg-blue-50'],
            'average'           => ['Average',           'text-amber-600',   'bg-amber-50'],
            'needs_improvement' => ['Needs Improvement', 'text-red-600',     'bg-red-50'],
        ];
        return $map[$grade] ?? ['—', 'text-slate-400', 'bg-slate-50'];
    }
}
