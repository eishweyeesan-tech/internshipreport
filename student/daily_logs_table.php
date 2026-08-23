<?php
// Build an associative array of existing logs keyed by date
$log_by_date = [];
if (!empty($recent_logs)) {
    foreach ($recent_logs as $log) {
        $log_by_date[$log['log_date']] = $log;
    }
}

// Generate all dates for the selected week
$week_dates = [];
if (!empty($weeks[$selected_week])) {
    $ws = new DateTime($weeks[$selected_week]['start']);
    $we = new DateTime($weeks[$selected_week]['end']);
    $ws->setTime(0, 0);
    $we->setTime(0, 0);
    $cursor = clone $ws;
    while ($cursor <= $we) {
        $day_num = (int)$cursor->format('N');
        if ($day_num < 6) {
            $week_dates[] = $cursor->format('Y-m-d');
        }
        $cursor->modify('+1 day');
    }
}
?>
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-2">
                <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                </span>Daily Log History
            </h3>
            <span class="text-xs font-medium text-gray-500 bg-slate-100 px-2.5 py-0.5 rounded-full"><?= count($log_by_date) ?> / <?= count($week_dates) ?> day(s)</span>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50 text-gray-500 font-medium uppercase text-xs">
                    <th class="px-4 py-2.5 text-left">ရက်စွဲ / နေ့</th>
                    <th class="px-4 py-2.5 text-left">တက်ရောက်မှုအခြေအနေ</th>
                    <th class="px-4 py-2.5 text-left">ဆောင်ရွက်မည့်လုပ်ငန်း</th>
                    <th class="px-4 py-2.5 text-left">အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</th>
                    <th class="px-4 py-2.5 text-left">အသုံးပြုသောပစ္စည်းများ</th>
                    <th class="px-4 py-2.5 text-left">လေ့လာသိရှိသော အသိပညာ</th>
                    <th class="px-4 py-2.5 text-left">ကြာချိန်</th>
                    <?php if (empty($weekly_report_submitted)): ?>
                    <th class="px-4 py-2.5 text-right">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if (!empty($week_dates)): ?>
                    <?php foreach ($week_dates as $date): ?>
                        <?php $log = $log_by_date[$date] ?? null; ?>
                        <?php
                        $dt = new DateTime($date);
                        $day_name = $dt->format('l');
                        $date_display = $dt->format('d M Y');
                        ?>
                        <?php if ($log): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal">
                                    <div class="text-xs font-bold text-slate-800"><?= $date_display ?></div>
                                    <div class="text-xs text-gray-400"><?= $day_name ?></div>
                                </td>
                                <td class="px-4 py-3">
                                    <?php
                                    $att = $log['attendance_status'] ?? 'present';
                                    $reason = $log['reason_for_absence'] ?? '';
                                    $is_holiday = ($att === 'leave' || $att === 'absent') && stripos($reason, 'Public Holiday') === 0;
                                    ?>
                                    <?php if ($is_holiday): ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-600 bg-amber-50 px-2.5 py-0.5 rounded border border-amber-200/60" title="<?= htmlspecialchars($reason) ?>">Public Holiday</span>
                                    <?php elseif ($att === 'present'): ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded border border-emerald-200/60">Present</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-red-600 bg-red-50 px-2.5 py-0.5 rounded border border-red-200/60" title="<?= htmlspecialchars($reason) ?>">Absent</span>
                                    <?php endif; ?>
                                </td>
                                <?php $is_absent = ($log['attendance_status'] ?? 'present') === 'absent' || (($log['attendance_status'] ?? '') === 'leave' && stripos($log['reason_for_absence'] ?? '', 'Public Holiday') === 0); ?>
                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent ? htmlspecialchars($log['reason_for_absence'] ?: '-') : htmlspecialchars($log['task_title'] ?? '-') ?></td>
                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent ? '-' : htmlspecialchars($log['tasks_performed'] ?? '-') ?></td>
                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent ? '-' : htmlspecialchars($log['tools_used'] ?? '-') ?></td>
                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal align-top break-words"><?= $is_absent ? '-' : htmlspecialchars($log['learnt_skills'] ?? '-') ?></td>
                                <td class="px-4 py-3 font-mono text-blue-600 text-sm font-semibold whitespace-nowrap"><?= htmlspecialchars($log['calculated_duration'] ?? '') ?></td>
                                <?php if (empty($weekly_report_submitted)): ?>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <a href="student-dashboard.php?tab=daily-log&week=<?= $selected_week ?>&edit=<?= $log['id'] ?>" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 px-2.5 py-1 rounded-lg border border-indigo-200 transition">
                                        ✏️ Edit
                                    </a>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php else: ?>
                            <tr class="bg-slate-50/30 hover:bg-slate-50/60 transition-colors duration-150">
                                <td class="px-4 py-3 text-sm text-gray-700 leading-normal">
                                    <div class="text-xs font-bold text-slate-800"><?= $date_display ?></div>
                                    <div class="text-xs text-gray-400"><?= $day_name ?></div>
                                </td>
                                <td class="px-4 py-3" colspan="<?= empty($weekly_report_submitted) ? 7 : 6 ?>">
                                    <?php if (!empty($weekly_report_submitted)): ?>
                                        <span class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-400 bg-slate-100 px-2.5 py-0.5 rounded border border-slate-200/60">No log entry (Locked - Weekly Report Submitted)</span>
                                    <?php else: ?>
                                        <span class="text-xs text-slate-400 italic">Not logged yet</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?= empty($weekly_report_submitted) ? 8 : 7 ?>" class="px-4 py-8 text-center text-slate-400">
                            <div class="flex flex-col items-center justify-center">
                                <svg class="w-10 h-10 text-slate-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6M9 8h6M5 4h14a1 1 0 011 1v16a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1z" />
                                </svg>
                                <p class="text-sm font-semibold text-slate-500">No daily logs recorded yet.</p>
                                <p class="text-xs text-slate-400 mt-1">Select a week or enter a date above to start logging your daily internship activities.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>