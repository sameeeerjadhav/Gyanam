<?php
/**
 * Shared Statement of Marks PDF renderer (MCCE-style grid, GIIT / Gyanam branding).
 * Typing courses use a detailed particulars table matching the official typing marksheet.
 *
 * @param array{
 *   student_id:string, full_name:string, atc_name:string, course_name:string,
 *   course_contents:string, duration:string, month_year:string, center_code:string,
 *   score:int, grade:string, brand:string, is_typing?:bool, wpm?:int, kph?:int,
 *   preview?:bool, filename?:string
 * } $d
 */
function outputStatementOfMarksPdf(array $d): void
{
    if (!class_exists(\setasign\Fpdi\Fpdi::class, false)) {
        $autoload = __DIR__ . '/../assets/fpdi/fpdi_autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    $studentId = (string)($d['student_id'] ?? '—');
    $fullName = formatPersonNameTitleCase((string)($d['full_name'] ?? ''));
    $atcName = (string)($d['atc_name'] ?? '—');
    $courseName = (string)($d['course_name'] ?? '—');
    $contents = (string)($d['course_contents'] ?? '—');
    $duration = (string)($d['duration'] ?? '—');
    $monthYear = (string)($d['month_year'] ?? '—');
    $centerCode = (string)($d['center_code'] ?? '—');
    $score = (int)($d['score'] ?? 0);
    $grade = (string)($d['grade'] ?? '');
    $brand = (($d['brand'] ?? 'it') === 'abacus') ? 'abacus' : 'it';
    $isTyping = !empty($d['is_typing']);
    $wpm = max(1, (int)($d['wpm'] ?? 30));
    $kph = max(1, (int)($d['kph'] ?? 9000));
    $preview = !empty($d['preview']);
    $filename = (string)($d['filename'] ?? ('Marksheet_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $studentId) . '.pdf'));

    if ($isTyping) {
        // Use stored multi-line course name as-is when speed line is already included
        if (preg_match('/\b(wpm|kph|typing\s*speed|key\s*depressed)\b/i', $courseName)) {
            $courseDisplay = $courseName;
        } else {
            $courseDisplay = $courseName . "\nTyping Speed - {$wpm} WPM Key depressed per hour - {$kph} KPH";
        }
        if (trim($contents) === '' || $contents === '—') {
            $contents = typingMarksheetDefaultContents($wpm);
        }
    } else {
        $courseDisplay = $courseName;
    }

    $signatory = $brand === 'abacus'
        ? 'Authorized Signatory For Gyanam Abacus'
        : 'Authorized Signatory For GIIT';

    $headerBannerPath = __DIR__ . '/../assets/templates/giit_marksheet_header_bw.png';
    if (!is_file($headerBannerPath)) {
        $headerBannerPath = __DIR__ . '/../assets/templates/giit_marksheet_header.png';
    }
    $abacusLogoPath = __DIR__ . '/../assets/templates/abacus_marksheet_logo_bw.png';
    if (!is_file($abacusLogoPath)) {
        $abacusLogoPath = __DIR__ . '/../assets/templates/abacus_marksheet_logo.png';
    }
    if (!is_file($abacusLogoPath) && function_exists('admissionFormBrandLogoPath')) {
        $abacusLogoPath = admissionFormBrandLogoPath('abacus');
    }
    $signPljPath = __DIR__ . '/../assets/templates/marksheet_sign_plj.png';
    $signRpsPath = __DIR__ . '/../assets/templates/marksheet_sign_rps.png';
    $sealPath = __DIR__ . '/../assets/templates/marksheet_seal_giit.png';
    if (!is_file($sealPath)) {
        $sealPath = __DIR__ . '/../assets/templates/marksheet_seal_giit.jpg';
    }

    $pdf = new \setasign\Fpdi\Fpdi();
    $pdf->SetTitle('Statement of Marks — ' . $studentId);
    $pdf->SetAuthor('Gyanam India Educational Services');
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage('P', 'A4');

    $W = 210.0;
    $H = 297.0;
    $pad = 8.0;
    $x = $pad;
    $tw = $W - 2 * $pad;
    $top = $pad;
    $bottom = $H - $pad;

    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.4);
    $pdf->Rect($pad, $pad, $tw, $bottom - $top);

    $cell = static function (
        float $cx, float $cy, float $cw, float $ch, string $text, bool $fill,
        string $align = 'C', float $size = 10, string $style = '', bool $wrap = false
    ) use ($pdf) {
        if ($fill) {
            $pdf->SetFillColor(210, 210, 210);
        }
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect($cx, $cy, $cw, $ch, $fill ? 'DF' : 'D');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Times', $style, $size);
        $lineH = max(3.4, min(4.2, $ch / 2.2));
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $innerW = max(1.0, $cw - 2.0);
        $needsWrap = $wrap || (strpos($text, "\n") !== false) || ($pdf->GetStringWidth($text) > $innerW);
        if ($needsWrap) {
            $lines = 0;
            foreach (explode("\n", $text) as $para) {
                $para = ($para === '') ? ' ' : $para;
                $w = $pdf->GetStringWidth($para);
                $lines += max(1, (int)ceil($w / $innerW));
            }
            $blockH = $lines * $lineH;
            $ty = $cy + max(0.4, ($ch - $blockH) / 2);
            $pdf->SetXY($cx + 1.0, $ty);
            $pdf->MultiCell($innerW, $lineH, $text, 0, $align === 'L' ? 'L' : 'C');
            return;
        }
        $pdf->SetXY($cx + 1.0, $cy + ($ch - $lineH) / 2);
        $pdf->Cell($innerW, $lineH, $text, 0, 0, $align);
    };

    $FONT = 10.0;
    $barH = 11.0;
    $legHdrH = 12.0;
    $legValH = 14.0;
    $legendTotal = $legHdrH + $legValH;

    $headerGap = 2.0;
    if ($brand === 'abacus') {
        $headerImgW = 72.0;
        $headerImgH = 24.0;
        $headerImgX = $x + ($tw - $headerImgW) / 2;
        $headerImgPath = $abacusLogoPath;
    } else {
        $headerImgW = $tw * 0.65;
        $headerImgH = $headerImgW * (520.0 / 1280.0);
        $headerImgX = $x + ($tw - $headerImgW) / 2;
        $headerImgPath = $headerBannerPath;
    }
    $headerBodyH = $headerImgH + $headerGap;

    $gridStart = $top + $headerBodyH + $barH;
    $gridEnd = $bottom - $legendTotal;
    $stretchH = max(100.0, $gridEnd - $gridStart);

    $wMetaHdr = 0.08;
    $wMetaVal = 0.09;
    $wRow = 0.10;
    $wContent = 0.16;
    $wMarksHd = 0.08;
    $wMarksBd = 0.28;

    $metaHdrH = $stretchH * $wMetaHdr;
    $metaValH = $stretchH * $wMetaVal;
    $rowH = $stretchH * $wRow;
    $contentH = $stretchH * $wContent;
    $marksHdrH = $stretchH * $wMarksHd;
    $marksBodyH = $stretchH * $wMarksBd;
    $planned = $metaHdrH + $metaValH + ($rowH * 3) + $contentH + $marksHdrH + $marksBodyH;
    $marksBodyH += ($stretchH - $planned);

    $colW = $tw / 4;
    $unit = $tw / 28;
    $gw = 4 * $unit;
    $labelW = $colW;
    $valW = $tw - $labelW;
    $pW = $labelW;

    if (is_file($headerImgPath)) {
        try {
            $pdf->Image($headerImgPath, $headerImgX, $top + 0.5, $headerImgW, $headerImgH);
        } catch (Exception $e) {
        }
    }

    $barY = $top + $headerBodyH;
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->Rect($x, $barY, $tw, $barH, 'DF');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Times', 'B', $FONT);
    $pdf->SetXY($x, $barY + ($barH - 4.2) / 2);
    $pdf->Cell($tw, 4.2, 'Statement of Marks', 0, 0, 'C');

    $infoY = $barY + $barH;
    $headers = ['Month & Year of Exam', 'Course Duration', 'Center Code', 'Student ID'];
    $values = [$monthYear, $duration, $centerCode, $studentId];
    for ($i = 0; $i < 4; $i++) {
        $cell($x + $i * $colW, $infoY, $colW, $metaHdrH, $headers[$i], true, 'C', $FONT, 'B', true);
        $cell($x + $i * $colW, $infoY + $metaHdrH, $colW, $metaValH, $values[$i], false, 'C', $FONT, 'B');
    }

    $rowsY = $infoY + $metaHdrH + $metaValH;
    $infoRows = [
        ['Name of Student', $fullName],
        ["Name of ATC\n(Authorized Training Center)", $atcName],
        ['Name of the Course', $courseDisplay],
    ];
    foreach ($infoRows as $i => $pair) {
        $ry = $rowsY + $i * $rowH;
        $cell($x, $ry, $labelW, $rowH, $pair[0], false, 'C', $FONT, 'B', true);
        $cell($x + $labelW, $ry, $valW, $rowH, $pair[1], false, 'C', $FONT, 'B', true);
    }

    $contentY = $rowsY + 3 * $rowH;
    $cell($x, $contentY, $labelW, $contentH, "Course\nContents", false, 'C', $FONT, 'B', true);
    $cell($x + $labelW, $contentY, $valW, $contentH, $contents, false, 'C', $FONT, 'B', true);

    $marksY = $contentY + $contentH;
    $mW = 5 * $unit;
    $pctW = 4 * $unit;
    $gW = 4 * $unit;
    $rightW = 8 * $unit;
    $leftW = $pW + $mW + $pctW + $gW;

    $cell($x, $marksY, $pW, $marksHdrH, 'Particulars', false, 'C', $FONT, 'B');
    $cell($x + $pW, $marksY, $mW, $marksHdrH, 'Marks', false, 'C', $FONT, 'B');
    $cell($x + $pW + $mW, $marksY, $pctW, $marksHdrH, 'Percentage', false, 'C', $FONT, 'B');
    $cell($x + $pW + $mW + $pctW, $marksY, $gW, $marksHdrH, 'Grade', false, 'C', $FONT, 'B');

    if ($isTyping) {
        $parts = typingMarksheetParticulars($wpm, $kph);
        $maxes = array_map(static fn($p) => (int)$p['max'], $parts);
        $obtained = allocateScoreAcrossMaxes($score, $maxes);
        $n = count($parts);
        $rh = $marksBodyH / max(1, $n);
        $partSize = 8.0;
        for ($i = 0; $i < $n; $i++) {
            $ry = $marksY + $marksHdrH + $i * $rh;
            $obt = (int)($obtained[$i] ?? 0);
            $max = (int)$parts[$i]['max'];
            $cell($x, $ry, $pW, $rh, (string)$parts[$i]['label'], false, 'L', $partSize, 'B', true);
            $cell($x + $pW, $ry, $mW, $rh, sprintf('%02d/%02d', $obt, $max), false, 'C', $FONT, 'B');
        }
    } else {
        $rh = $marksBodyH / 2;
        $cell($x, $marksY + $marksHdrH, $pW, $rh, 'Maximum Marks', false, 'C', $FONT, 'B');
        $cell($x + $pW, $marksY + $marksHdrH, $mW, $rh, '100', false, 'C', $FONT, 'B');
        $cell($x, $marksY + $marksHdrH + $rh, $pW, $rh, 'Marks Obtained', false, 'C', $FONT, 'B');
        $cell($x + $pW, $marksY + $marksHdrH + $rh, $mW, $rh, (string)$score, false, 'C', $FONT, 'B');
    }

    $pdf->Rect($x + $pW + $mW, $marksY + $marksHdrH, $pctW, $marksBodyH, 'D');
    $pdf->Rect($x + $pW + $mW + $pctW, $marksY + $marksHdrH, $gW, $marksBodyH, 'D');
    $cell($x + $pW + $mW, $marksY + $marksHdrH, $pctW, $marksBodyH, (string)$score, false, 'C', 14, 'B');
    $cell($x + $pW + $mW + $pctW, $marksY + $marksHdrH, $gW, $marksBodyH, $grade, false, 'C', 14, 'B');

    $sx = $x + $leftW;
    $sy = $marksY;
    $sh = $marksHdrH + $marksBodyH;
    $pdf->Rect($sx, $sy, $rightW, $sh, 'D');

    $sigH = 10.5;
    $sigW1 = $sigH * (223.0 / 118.0);
    $sigW2 = $sigH * (280.0 / 118.0);
    $sigGap = 2.0;
    $sigTotalW = $sigW1 + $sigGap + $sigW2;
    $maxSigW = max(20.0, $rightW - 4.0);
    if ($sigTotalW > $maxSigW) {
        $scale = $maxSigW / $sigTotalW;
        $sigH *= $scale;
        $sigW1 *= $scale;
        $sigW2 *= $scale;
        $sigTotalW = $sigW1 + $sigGap + $sigW2;
    }
    $sigY = $sy + 3.5;
    $sigX = $sx + ($rightW - $sigTotalW) / 2;
    if (is_file($signPljPath)) {
        try {
            $pdf->Image($signPljPath, $sigX, $sigY, $sigW1, $sigH);
        } catch (Exception $e) {
        }
    }
    if (is_file($signRpsPath)) {
        try {
            $pdf->Image($signRpsPath, $sigX + $sigW1 + $sigGap, $sigY, $sigW2, $sigH);
        } catch (Exception $e) {
        }
    }
    $textY = $sigY + $sigH + 2.0;
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Times', 'B', $FONT);
    $pdf->SetXY($sx + 1, $textY);
    $pdf->Cell($rightW - 2, 4.5, $signatory, 0, 0, 'C');
    $pdf->SetFont('Times', '', $FONT);
    $pdf->SetXY($sx + 1, $textY + 4.8);
    $pdf->Cell($rightW - 2, 4.5, 'Gyanam India Educational Services', 0, 0, 'C');

    $textBottom = $textY + 4.8 + 4.5;
    $boxBottom = $sy + $sh;
    $sealPadTop = 0.8;
    $sealPadBottom = 1.2;
    $sealAvailH = max(12.0, $boxBottom - $textBottom - $sealPadTop - $sealPadBottom);
    $sealSize = min(29.0, $rightW - 4.0, $sealAvailH);
    $sealX = $sx + ($rightW - $sealSize) / 2;
    $sealY = $textBottom + $sealPadTop + max(0.0, ($sealAvailH - $sealSize) / 2);
    if (is_file($sealPath)) {
        try {
            $pdf->Image($sealPath, $sealX, $sealY, $sealSize, $sealSize);
        } catch (Exception $e) {
        }
    }

    $legY = $bottom - $legendTotal;
    $grades = ['A++', 'A+', 'A', 'B', 'C', 'Fail', 'AB'];
    $bands = ['90 & Above', '80 to 89', '66 to 79', '55 to 65', '40 to 54', 'Below 40', 'Absent'];
    for ($i = 0; $i < 7; $i++) {
        $cell($x + $i * $gw, $legY, $gw, $legHdrH, $grades[$i], true, 'C', $FONT, 'B');
        $cell($x + $i * $gw, $legY + $legHdrH, $gw, $legValH, $bands[$i], false, 'C', $FONT, '');
    }

    if (ob_get_level()) {
        ob_end_clean();
    }
    $pdf->Output($preview ? 'I' : 'D', $filename);
    exit;
}
