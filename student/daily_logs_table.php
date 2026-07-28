<?php if (!empty($recent_logs)): ?>
<div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
        <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
            <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📋</span> Log History
        </h3>
        <span class="text-xs text-slate-400 font-medium"><?= count($recent_logs) ?> record(s)</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="bg-slate-50/80 text-slate-500 font-semibold uppercase tracking-wider text-[11px]">
                    <th class="px-5 py-3 text-left">Date</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Intended Task</th>
                    <th class="px-5 py-3 text-left">Actual Task</th>
                    <th class="px-5 py-3 text-left">Tools</th>
                    <th class="px-5 py-3 text-left">Knowledge</th>
                    <th class="px-5 py-3 text-left">Duration</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recent_logs as $log): ?>
                <tr class="hover:bg-slate-50/50 transition-colors duration-150">
                    <td class="px-5 py-4 font-medium text-slate-700 whitespace-nowrap"><?= htmlspecialchars($log['log_date']) ?></td>
                    <td class="px-5 py-4 whitespace-nowrap">
                        <?php if (($log['attendance_status'] ?? 'present') === 'present'): ?>
                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-lg border border-emerald-200/60">✅ Present</span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1.5 text-xs font-bold text-red-600 bg-red-50 px-2.5 py-1 rounded-lg border border-red-200/60" title="<?= htmlspecialchars($log['reason_for_absence'] ?? '') ?>">❌ Absent</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-4 text-slate-600 max-w-[160px] truncate font-medium" title="<?= htmlspecialchars($log['task_title'] ?? '') ?>"><?= htmlspecialchars($log['task_title'] ?? '--') ?></td>
                    <td class="px-5 py-4 text-slate-600 max-w-[200px] truncate font-medium" title="<?= htmlspecialchars($log['tasks_performed'] ?? '') ?>"><?= htmlspecialchars($log['tasks_performed'] ?? '--') ?></td>
                    <td class="px-5 py-4 text-slate-600 font-medium"><?= htmlspecialchars($log['tools_used'] ?? '--') ?></td>
                    <td class="px-5 py-4 text-slate-600 font-medium"><?= htmlspecialchars($log['learnt_skills'] ?? '--') ?></td>
                    <td class="px-5 py-4 font-mono text-blue-600 font-bold whitespace-nowrap"><?= htmlspecialchars($log['calculated_duration']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
        <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
            <span class="w-8 h-8 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">📋</span> Log History
        </h3>
    </div>
    <div class="p-12 text-center">
        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
        <p class="text-sm text-slate-500 font-medium">No daily logs yet.</p>
        <p class="text-xs text-slate-400 mt-1">Start by submitting your first daily log above.</p>
    </div>
</div>
<?php endif; ?>
