<?php if (!empty($weekly_refs)): ?>
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 bg-slate-50 flex items-center justify-between">
        <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </span> Reflection History
        </h3>
        <span class="text-xs text-gray-400 font-medium"><?= count($weekly_refs) ?> record(s)</span>
    </div>
    <div class="divide-y divide-slate-100">
        <?php foreach ($weekly_refs as $ref): ?>
        <div class="px-5 py-4 hover:bg-slate-50/50 transition-colors duration-150">
            <div class="flex items-center justify-between mb-3">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-emerald-500 text-white flex items-center justify-center text-xs font-bold">
                        W<?= (int) $ref['week_number'] ?>
                    </div>
                    <div>
                        <span class="text-sm font-semibold text-slate-700">Week <?= (int) $ref['week_number'] ?></span>
                        <p class="text-xs text-gray-400">Weekly Reflection</p>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="bg-slate-50 rounded-xl p-3.5">
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1">What was done?</span>
                    <p class="text-sm text-gray-700 leading-normal"><?= nl2br(htmlspecialchars($ref['what_done'] ?? '')) ?></p>
                </div>
                <div class="bg-slate-50 rounded-xl p-3.5">
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1">How was it done?</span>
                    <p class="text-sm text-gray-700 leading-normal"><?= nl2br(htmlspecialchars($ref['how_done'] ?? '')) ?></p>
                </div>
                <div class="bg-slate-50 rounded-xl p-3.5">
                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider block mb-1">Why was it done?</span>
                    <p class="text-sm text-gray-700 leading-normal"><?= nl2br(htmlspecialchars($ref['why_done'] ?? '')) ?></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-100 bg-slate-50">
        <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-2">
            <span class="w-7 h-7 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </span> Reflection History
        </h3>
    </div>
    <div class="p-10 text-center">
        <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
            <svg class="w-7 h-7 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
        </div>
        <p class="text-sm text-slate-500 font-medium">No weekly reflections yet.</p>
        <p class="text-xs text-slate-400 mt-1">Complete 5 daily logs to unlock the reflection form.</p>
    </div>
</div>
<?php endif; ?>
