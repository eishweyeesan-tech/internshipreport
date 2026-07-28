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
    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📋</span>Daily Log History
            </h3>
            <span class="text-label font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full"><?= count($log_by_date) ?> / <?= count($week_dates) ?> day(s)</span>
        </div>
        <button onclick="window.print()" class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-slate-600 bg-slate-50 border border-slate-100 rounded-lg hover:bg-emerald-50 hover:border-emerald-200 hover:text-emerald-600 transition group cursor-pointer">
            <span class="group-hover:scale-110 transition-transform">🖨️</span>
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
                    <th class="px-5 py-3 text-right">လုပ်ဆောင်ချက်များ</th>
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
                            <td class="px-5 py-4 font-medium text-slate-700 whitespace-nowrap">
                                <div class="text-xs font-bold text-slate-800"><?= $date_display ?></div>
                                <div class="text-label text-slate-400 font-semibold"><?= $day_name ?></div>
                            </td>
                            <td class="px-5 py-4 whitespace-nowrap">
                                <?php
                                $att = $log['attendance_status'] ?? 'present';
                                $reason = $log['reason_for_absence'] ?? '';
                                $is_holiday = ($att === 'leave' || $att === 'absent') && stripos($reason, 'Public Holiday') === 0;
                                ?>
                                <?php if ($is_holiday): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-amber-600 bg-amber-50 px-2.5 py-1 rounded-lg border border-amber-200/60" title="<?= htmlspecialchars($reason) ?>">🇲🇲 Public Holiday</span>
                                <?php elseif ($att === 'present'): ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200/60">✅ Present</span>
                                <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold text-red-600 bg-red-50 px-2.5 py-1 rounded-lg border border-red-200/60" title="<?= htmlspecialchars($reason) ?>">❌ Absent</span>
                                <?php endif; ?>
                            </td>
                            <?php $is_absent = ($log['attendance_status'] ?? 'present') === 'absent' || (($log['attendance_status'] ?? '') === 'leave' && stripos($log['reason_for_absence'] ?? '', 'Public Holiday') === 0); ?>
                            <td class="px-5 py-4 text-slate-600 max-w-[160px] truncate font-medium" title="<?= $is_absent ? htmlspecialchars($log['reason_for_absence'] ?? '') : htmlspecialchars($log['task_title'] ?? '') ?>"><?= $is_absent ? htmlspecialchars($log['reason_for_absence'] ?: '-') : htmlspecialchars($log['task_title'] ?? '-') ?></td>
                            <td class="px-5 py-4 text-slate-600 max-w-[200px] truncate font-medium" title="<?= $is_absent ? '' : htmlspecialchars($log['tasks_performed'] ?? '') ?>"><?= $is_absent ? '-' : htmlspecialchars($log['tasks_performed'] ?? '-') ?></td>
                            <td class="px-5 py-4 text-slate-600 font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['tools_used'] ?? '-') ?></td>
                            <td class="px-5 py-4 text-slate-600 font-medium"><?= $is_absent ? '-' : htmlspecialchars($log['learnt_skills'] ?? '-') ?></td>
                            <td class="px-5 py-4 font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($log['calculated_duration']) ?></td>
                            <td class="px-5 py-4 whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <?php if (!empty($student_signed)): ?>
                                        <span class="px-2.5 py-1 text-sm font-bold text-slate-400 bg-slate-100 border border-slate-200 rounded-lg cursor-not-allowed">🔒 Signed</span>
                                    <?php else: ?>
                                        <a href="?edit=<?= $log['id'] ?>&week=<?= $selected_week ?>" class="px-2.5 py-1 text-sm font-bold text-indigo-600 bg-indigo-50 border border-indigo-200 rounded-lg hover:bg-indigo-100 transition">✏️ Edit</a>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this log entry for <?= htmlspecialchars($log['log_date']) ?>? This cannot be undone.')">
                                            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                            <button type="submit" name="delete_log" class="px-2.5 py-1 text-sm font-bold text-red-600 bg-red-50 border border-red-200 rounded-lg hover:bg-red-100 transition cursor-pointer">🗑️ Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <tr class="bg-slate-50/30 hover:bg-slate-50/60 transition-colors duration-150">
                            <td class="px-5 py-4 font-medium text-slate-700 whitespace-nowrap">
                                <div class="text-xs font-bold text-slate-800"><?= $date_display ?></div>
                                <div class="text-label text-slate-400 font-semibold"><?= $day_name ?></div>
                            </td>
                            <td class="px-5 py-4" colspan="6">
                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-400 bg-slate-100 px-2.5 py-1 rounded-lg border border-slate-200/60">📝 No log yet — click Save Daily Log above</span>
                            </td>
                            <td class="px-5 py-4 whitespace-nowrap text-right">
                                <span class="text-label font-bold text-slate-300">—</span>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
