<?php
/**
 * Render DLC Analytics Overview (pie / bar / line).
 * Expects vars from _load_analytics_charts.php
 */
$__pieOk = !empty($dlcPieData) && array_sum($dlcPieData) > 0;
$__barOk = !empty($dlcBarData) && array_sum($dlcBarData) > 0;
$__lineOk = !empty($dlcLineLabels);
?>
<div class="dlc-analytics-head">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
    Analytics Overview
</div>
<div class="dlc-analytics-grid">
    <div class="dlc-analytics-card">
        <h3><?= htmlspecialchars($dlcPieTitle ?? 'ATC Center Types') ?></h3>
        <div class="dlc-analytics-sub"><?= htmlspecialchars($dlcPieSub ?? '') ?></div>
        <?php if ($__pieOk): ?>
        <div class="dlc-analytics-canvas"><canvas id="dlcPieChart"></canvas></div>
        <?php else: ?>
        <div class="dlc-analytics-empty">No ATC type data yet</div>
        <?php endif; ?>
    </div>
    <div class="dlc-analytics-card span-2">
        <h3><?= htmlspecialchars($dlcBarTitle ?? 'Top ATCs') ?></h3>
        <div class="dlc-analytics-sub"><?= htmlspecialchars($dlcBarSub ?? '') ?></div>
        <?php if ($__barOk): ?>
        <div class="dlc-analytics-canvas"><canvas id="dlcBarChart"></canvas></div>
        <?php else: ?>
        <div class="dlc-analytics-empty">No ATC performance data yet</div>
        <?php endif; ?>
    </div>
    <div class="dlc-analytics-card span-full">
        <h3>Monthly Trend</h3>
        <div class="dlc-analytics-sub">Admissions &amp; DLC share paid — last 6 months</div>
        <?php if ($__lineOk): ?>
        <div class="dlc-analytics-canvas tall"><canvas id="dlcLineChart"></canvas></div>
        <?php else: ?>
        <div class="dlc-analytics-empty">No monthly trend data yet</div>
        <?php endif; ?>
    </div>
</div>
