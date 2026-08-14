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
<div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 bg-slate-50 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </span>Daily Log History
            </h3>
            <span class="text-label font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full"><?= count($log_by_date) ?> / <?= count($week_dates) ?> day(s)</span>
        </div>
        <button onclick="window.print()" class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-emerald-50 hover:border-emerald-200 hover:text-emerald-600 transition cursor-pointer">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-16-5V9a2 2 0 012-2h12a2 2 0 012 2v4m-12 9h8a2 2 0 002-2v-3a2 2 0 00-2-2H8a2 2 0 00-2 2v3a2 2 0 002 2z"/></svg>
            Print
        </button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-sm">
                    <th class="px-5 py-3 text-left">ရက်စွဲ / နေ့</th>
                    <th class="px-5 py-3 text-left">တက်ရောက်မှုအခြေအနေ</th>
                    <th class="px-5 py-3 text-left">ဆောင်ရွက်မည့်လုပ်ငန်း</th>
                    <th class="px-5 py-3 text-left">အမှန်တကယ် လုပ်ဆောင်ဖြစ်သော လုပ်ငန်းစဉ်များ</th>
                    <th class="px-5 py-3 text-left">အသုံးပြုသောပစ္စည်းများ</th>
                    <th class="px-5 py-3 text-left">လေ့လာသိရှိသော အသိပညာ</th>
                    <th class="px-5 py-3 text-left">ကြာချိန်</th>
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
                            <td class="px-5 py-4 font-medium text-slate-700">
                                <div class="text-xs font-bold text-slate-800"><?= $date_display ?></div>
                                <div class="text-label text-slate-400 font-semibold"><?= $day_name ?></div>
                            </td>
                            <td class="px-5 py-4">
                                <?php
                                $att = $log['attendance_status'] ?? 'present';
                                $reason = $log['reason_for_absence'] ?? '';
                                $is_holiday = ($att === 'leave' || $att === 'absent') && stripos($reason, 'Public Holiday') === 0;
                                ?>
                                <?php if ($is_holiday): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-amber-600 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200/60" title="<?= htmlspecialchars($reason) ?>">Public Holiday</span>
                                <?php elseif ($att === 'present'): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200/60">Present</span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-red-600 bg-red-50 px-2.5 py-1 rounded-lg border border-red-200/60" title="<?= htmlspecialchars($reason) ?>">Absent</span>
                                <?php endif; ?>
                            </td>
                            <?php $is_absent = ($log['attendance_status'] ?? 'present') === 'absent' || (($log['attendance_status'] ?? '') === 'leave' && stripos($log['reason_for_absence'] ?? '', 'Public Holiday') === 0); ?>
                            <td class="px-5 py-4 text-slate-600 align-top break-words font-medium"><?= $is_absent ? htmlspecialchars($log['reason_for_absence'] ?: '-') : htmlspecialchars($log['task_title'] ?? '-') ?></td>
                            <td class="px-5 py-4 text-slate-600 align-top break-words font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['tasks_performed'] ?? '-') ?></td>
                            <td class="px-5 py-4 text-slate-600 align-top break-words font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['tools_used'] ?? '-') ?></td>
                            <td class="px-5 py-4 text-slate-600 align-top break-words font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['learnt_skills'] ?? '-') ?></td>
                            <td class="px-5 py-4 font-mono text-blue-600 font-bold"><?= htmlspecialchars($log['calculated_duration']) ?></td>
                        </tr>
                        <?php else: ?>
                        <tr class="bg-slate-50/30 hover:bg-slate-50/60 transition-colors duration-150">
                            <td class="px-5 py-4 font-medium text-slate-700">
                                <div class="text-xs font-bold text-slate-800"><?= $date_display ?></div>
                                <div class="text-label text-slate-400 font-semibold"><?= $day_name ?></div>
                            </td>
                            <td class="px-5 py-4" colspan="6">
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200/60">No log yet — click Save Daily Log above</span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
