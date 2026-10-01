<?php
/**
 * Printable exam-day attendance sheet.
 * Columns: Roll No, Photo, Institute, Signature.
 */

function attendanceSheetTitle(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    if ($text === '' || $text === '—') {
        return $text;
    }
    return mb_convert_case(mb_strtolower($text, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function attendanceSheetSampleRows(string $institute): array
{
    $rolls = ['6IIT28265', '6IIT28266', 'GYANAM6', '6AB28401', '6VM11022'];
    $rows = [];
    foreach ($rolls as $roll) {
        $rows[] = [
            'roll' => $roll,
            'photo' => '',
            'institute' => $institute,
        ];
    }
    return $rows;
}

function renderAttendanceSheetDocument(array $opts): void
{
    $dateLabel = (string)($opts['date_label'] ?? '');
    $institute = (string)($opts['institute'] ?? '');
    $code = (string)($opts['code'] ?? '');
    $rows = is_array($opts['rows'] ?? null) ? $opts['rows'] : [];
    $autoPrint = !empty($opts['print']);
    $showToolbar = !empty($opts['toolbar']);
    $count = count($rows);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Attendance Sheet — <?= htmlspecialchars($dateLabel) ?></title>
<style>
    @page { size: A4 portrait; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e5e7eb; color: #111; font-family: "Times New Roman", Times, serif; }
    .as-toolbar { position: sticky; top: 0; z-index: 2; display: flex; gap: .6rem; align-items: center; justify-content: flex-end; padding: .7rem 1rem; background: #fff; border-bottom: 1px solid #e5e7eb; }
    .as-toolbar button, .as-toolbar a { height: 36px; padding: 0 .9rem; border-radius: 8px; border: 1px solid #d1d5db; background: #fff; font: 700 13px/36px Arial, sans-serif; text-decoration: none; color: #111; cursor: pointer; }
    .as-toolbar .primary { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
    .as-hint { margin: 0 auto 0 0; font: 500 12px/1.4 Arial, sans-serif; color: #4b5563; }
    .as-page { width: 210mm; height: 297mm; margin: 16px auto; background: #fff; padding: 12mm; box-shadow: 0 8px 28px rgba(0,0,0,.12); display: flex; flex-direction: column; overflow: hidden; }
    .as-head { text-align: center; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
    .as-brand { font-size: 22px; font-weight: 700; line-height: 1.25; }
    .as-title { font-size: 15px; font-weight: 700; margin: 4px 0 6px; }
    .as-meta { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 10px; }
    .as-meta td { padding: 2px 0; }
    .as-meta .k { width: 110px; font-weight: 700; }
    table.as-grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.as-grid th, table.as-grid td { border: 1px solid #111; vertical-align: middle; }
    table.as-grid th { font-size: 13px; font-weight: 700; text-transform: none; padding: 6px 8px; background: #f3f4f6; }
    table.as-grid td { padding: 6px 8px; font-size: 14px; }
    td.roll { width: 24%; font-weight: 700; font-family: Arial, Helvetica, sans-serif; font-size: 15px; text-align: center; white-space: nowrap; letter-spacing: 0; }
    td.photo { width: 16%; text-align: center; }
    td.photo img, .as-nophoto { width: 72px; height: 90px; object-fit: cover; border: 1px solid #111; display: inline-block; background: #f9fafb; }
    .as-nophoto { line-height: 90px; font: 11px Arial, sans-serif; color: #6b7280; }
    td.inst { width: 28%; }
    td.sign { width: 32%; height: 108px; }
    .as-foot { display: flex; justify-content: space-between; margin-top: auto; padding-top: 18px; font-size: 13px; }
    .as-signline { margin-top: 36px; border-top: 1px solid #111; width: 180px; padding-top: 4px; text-align: center; }
    .as-empty { padding: 28px 8px; text-align: center; font-size: 14px; }
    @media print {
        body { background: #fff; }
        .as-toolbar { display: none !important; }
        .as-page { width: 210mm; height: 297mm; margin: 0; box-shadow: none; page-break-after: always; }
        .as-page:last-child { page-break-after: auto; }
        td.roll { white-space: nowrap; }
        table.as-grid tr { break-inside: avoid; page-break-inside: avoid; }
        table.as-grid thead { display: table-header-group; }
    }
</style>
</head>
<body>
<?php if ($showToolbar): ?>
<div class="as-toolbar">
    <p class="as-hint">In the print dialog choose <b>Save as PDF</b> and turn off headers and footers.</p>
    <button type="button" onclick="window.close()">Close</button>
    <button type="button" class="primary" onclick="window.print()">Download PDF</button>
</div>
<?php endif; ?>
<div class="as-page">
    <div class="as-head">
        <div class="as-brand"><?= htmlspecialchars(attendanceSheetTitle('Gyanam India Educational Services')) ?></div>
        <div class="as-title"><?= htmlspecialchars(attendanceSheetTitle('Examination Attendance Sheet')) ?></div>
    </div>
    <table class="as-meta">
        <tr><td class="k">Date</td><td><?= htmlspecialchars(attendanceSheetTitle($dateLabel)) ?></td></tr>
        <tr><td class="k">Institute</td><td><?= htmlspecialchars($institute !== '' ? attendanceSheetTitle($institute) : '—') ?><?= $code !== '' ? ' (' . htmlspecialchars($code) . ')' : '' ?></td></tr>
        <?php if (!empty($opts['course_label'])): ?>
        <tr><td class="k">Course</td><td><?= htmlspecialchars(attendanceSheetTitle((string)$opts['course_label'])) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($opts['slot_label'])): ?>
        <tr><td class="k">Slot</td><td><?= htmlspecialchars(attendanceSheetTitle((string)$opts['slot_label'])) ?></td></tr>
        <?php endif; ?>
        <tr><td class="k">Candidates</td><td><?= (int)$count ?></td></tr>
    </table>
    <table class="as-grid">
        <thead>
            <tr>
                <th>Roll No</th>
                <th>Photo</th>
                <th>Institute</th>
                <th>Sign</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($count === 0): ?>
            <tr><td colspan="4" class="as-empty">No students are scheduled on this date.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="roll"><?= htmlspecialchars((string)($row['roll'] ?? '—')) ?></td>
                <td class="photo">
                    <?php if (!empty($row['photo'])): ?>
                        <img src="<?= htmlspecialchars((string)$row['photo']) ?>" alt="">
                    <?php else: ?>
                        <span class="as-nophoto">Photo</span>
                    <?php endif; ?>
                </td>
                <td class="inst"><?= htmlspecialchars(attendanceSheetTitle((string)($row['institute'] ?? $institute))) ?></td>
                <td class="sign"></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="as-foot">
        <div>Total Present: ________ &nbsp;&nbsp; Total Absent: ________</div>
        <div class="as-signline">Invigilator Signature</div>
    </div>
</div>
<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
    <?php
}
