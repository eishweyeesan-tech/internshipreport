<?php if (!empty($weekly_refs)): ?>
<div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white flex items-center justify-between">
        <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
            <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-sm">📊</span> Reflection History
        </h3>
        <span class="text-xs text-slate-400 font-medium"><?= count($weekly_refs) ?> record(s)</span>
    </div>
    <div class="divide-y divide-slate-100">
        <?php foreach ($weekly_refs as $ref): ?>
        <div class="px-6 py-5 hover:bg-slate-50/50 transition-colors duration-150">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white flex items-center justify-center text-sm font-bold shadow-lg shadow-emerald-500/20">
                        W<?= (int) $ref['week_number'] ?>
                    </div>
                    <div>
                        <span class="text-sm font-bold text-slate-700">Week <?= (int) $ref['week_number'] ?></span>
                        <p class="text-[11px] text-slate-400">Weekly Reflection</p>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                <div class="bg-gradient-to-br from-slate-50 to-white border border-slate-100 rounded-xl p-4">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-2">❓ What was done?</span>
                    <p class="text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($ref['what_done'] ?? '')) ?></p>
                </div>
                <div class="bg-gradient-to-br from-slate-50 to-white border border-slate-100 rounded-xl p-4">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-2">⚙️ How was it done?</span>
                    <p class="text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($ref['how_done'] ?? '')) ?></p>
                </div>
                <div class="bg-gradient-to-br from-slate-50 to-white border border-slate-100 rounded-xl p-4">
                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-2">🎯 Why was it done?</span>
                    <p class="text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($ref['why_done'] ?? '')) ?></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="bg-white rounded-2xl border border-slate-200/60 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
        <h3 class="text-sm font-bold text-slate-700 uppercase tracking-wider flex items-center gap-2">
            <span class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-sm">📊</span> Reflection History
        </h3>
    </div>
    <div class="p-12 text-center">
        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-3xl mx-auto mb-4">📭</div>
        <p class="text-sm text-slate-500 font-medium">No weekly reflections yet.</p>
        <p class="text-xs text-slate-400 mt-1">Complete 5 daily logs to unlock the reflection form.</p>
    </div>
</div>
<?php endif; ?>
