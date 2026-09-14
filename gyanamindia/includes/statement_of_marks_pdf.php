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
    $courseDisplay = str_replace(["\r\n", "\r"], "\n", trim($courseDisplay));
    $contents = preg_replace('/\s+/u', ' ', trim($contents)) ?? $contents;

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

    // One body font everywhere (MCCE sample uses equal size)
    $FONT = 10.0;
    $LINE = 4.0;

    $cell = static function (
        float $cx, float $cy, float $cw, float $ch, string $text, bool $fill,
        string $align = 'C', float $size = 10, string $style = '', bool $wrap = false
    ) use ($pdf, $LINE) {
        if ($fill) {
            $pdf->SetFillColor(210, 210, 210);
        }
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect($cx, $cy, $cw, $ch, $fill ? 'DF' : 'D');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Times', $style, $size);
        $lineH = $LINE;
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $padX = 1.5;
        $innerW = max(1.0, $cw - 2 * $padX);
        $needsWrap = $wrap || (strpos($text, "\n") !== false) || ($pdf->GetStringWidth($text) > $innerW + 0.2);
        if ($needsWrap) {
            $lines = 0;
            foreach (explode("\n", $text) as $para) {
                $para = ($para === '') ? ' ' : $para;
                $w = $pdf->GetStringWidth($para);
                $lines += max(1, (int)ceil($w / $innerW));
            }
            $blockH = $lines * $lineH;
            $ty = $cy + max(0.8, ($ch - $blockH) / 2);
            $pdf->SetXY($cx + $padX, $ty);
            $pdf->MultiCell($innerW, $lineH, $text, 0, $align === 'L' ? 'L' : 'C');
            return;
        }
        $pdf->SetXY($cx + $padX, $cy + ($ch - $lineH) / 2);
        $pdf->Cell($innerW, $lineH, $text, 0, 0, $align);
    };

    $barH = 9.0;
    $legHdrH = 8.5;
    $legValH = 10.5;
    $legendTotal = $legHdrH + $legValH;

    // GIIT header — full width, natural aspect, breathing room from top border
    $headerTopPad = 3.0;
    $headerGap = 2.5;
    if ($brand === 'abacus') {
        $headerImgW = 78.0;
        $headerImgH = 26.0;
        $headerImgX = $x + ($tw - $headerImgW) / 2;
        $headerImgPath = $abacusLogoPath;
    } else {
        $headerImgW = $tw * 0.65;
        $headerImgH = $headerImgW * (520.0 / 1280.0); // keep banner aspect
        $headerImgX = $x + ($tw - $headerImgW) / 2;
        $headerImgPath = $headerBannerPath;
    }
    $headerBodyH = $headerTopPad + $headerImgH + $headerGap;

    $gridStart = $top + $headerBodyH + $barH;
    $gridEnd = $bottom - $legendTotal;
    $stretchH = max(130.0, $gridEnd - $gridStart);

    /*
     * MCCE proportions: each particular row ≈ Name of Student row height.
     * Leftover is shared across sections — never dumped only into marks.
     */
    if ($isTyping) {
        // metaHdr1 + metaVal1.1 + stu1.2 + atc1.2 + course1.45 + content1.45 + marksHdr1 + 6*1.2
        $u = $stretchH / 15.6;
        $metaHdrH = 1.0 * $u;
        $metaValH = 1.1 * $u;
        $infoH = 1.2 * $u;
        $courseH = 1.45 * $u;
        $contentH = 1.45 * $u;
        $marksHdrH = 1.0 * $u;
        $marksBodyH = 7.2 * $u; // 6 × 1.2
    } else {
        $u = $stretchH / 11.4;
        $metaHdrH = 1.0 * $u;
        $metaValH = 1.1 * $u;
        $infoH = 1.25 * $u;
        $courseH = 1.25 * $u;
        $contentH = 1.5 * $u;
        $marksHdrH = 1.0 * $u;
        $marksBodyH = 3.1 * $u;
    }

    $planned = $metaHdrH + $metaValH + (2 * $infoH) + $courseH + $contentH + $marksHdrH + $marksBodyH;
    $delta = $stretchH - $planned;
    if (abs($delta) > 0.05) {
        $metaHdrH += $delta * 0.08;
        $metaValH += $delta * 0.09;
        $infoH += $delta * 0.11;   // ×2 rows ≈ 0.22
        $courseH += $delta * 0.12;
        $contentH += $delta * 0.12;
        $marksHdrH += $delta * 0.08;
        $marksBodyH += $delta * 0.29;
    }

    $colW = $tw / 4;
    $unit = $tw / 28;
    $gw = 4 * $unit;
    $labelW = 7 * $unit;
    $valW = $tw - $labelW;
    $pW = $labelW;

    if (is_file($headerImgPath)) {
        try {
            $pdf->Image($headerImgPath, $headerImgX, $top + $headerTopPad, $headerImgW, $headerImgH);
        } catch (Exception $e) {
        }
    }

    $barY = $top + $headerBodyH;
    // Title row — border only, no grey background
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->Rect($x, $barY, $tw, $barH, 'D');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Times', 'B', $FONT);
    $pdf->SetXY($x, $barY + ($barH - $LINE) / 2);
    $pdf->Cell($tw, $LINE, 'Statement of Marks', 0, 0, 'C');

    $infoY = $barY + $barH;
    $headers = ['Month & Year of Exam', 'Course Duration', 'Center Code', 'Student ID'];
    $values = [$monthYear, $duration, $centerCode, $studentId];
    for ($i = 0; $i < 4; $i++) {
        $cell($x + $i * $colW, $infoY, $colW, $metaHdrH, $headers[$i], true, 'C', $FONT, 'B', true);
        $cell($x + $i * $colW, $infoY + $metaHdrH, $colW, $metaValH, $values[$i], false, 'C', $FONT, 'B');
    }

    $rowsY = $infoY + $metaHdrH + $metaValH;
    // All values centered in their cells (incl. long multi-line course title)
    $infoRows = [
        ['Name of Student', $fullName, $infoH, 'B'],
        ['Name of ATC', $atcName, $infoH, 'B'],
        ['Name of the Course', $courseDisplay, $courseH, 'B'],
    ];
    $ry = $rowsY;
    foreach ($infoRows as $pair) {
        [$lab, $val, $h, $sty] = $pair;
        $cell($x, $ry, $labelW, $h, $lab, false, 'C', $FONT, 'B', true);
        $cell($x + $labelW, $ry, $valW, $h, $val, false, 'C', $FONT, $sty, true);
        $ry += $h;
    }

    $contentY = $ry;
    $cell($x, $contentY, $labelW, $contentH, "Course\nContents", false, 'C', $FONT, 'B', true);
    $cell($x + $labelW, $contentY, $valW, $contentH, $contents, false, 'C', $FONT, '', true);

    $marksY = $contentY + $contentH;
    $mW = 5 * $unit;
    $pctW = 4 * $unit;
    $gW = 4 * $unit;
    $rightW = 8 * $unit;
    $leftW = $pW + $mW + $pctW + $gW;

    $cell($x, $marksY, $pW, $marksHdrH, 'Particulars', true, 'C', $FONT, 'B');
    $cell($x + $pW, $marksY, $mW, $marksHdrH, 'Marks', true, 'C', $FONT, 'B');
    $cell($x + $pW + $mW, $marksY, $pctW, $marksHdrH, 'Percentage', true, 'C', $FONT, 'B');
    $cell($x + $pW + $mW + $pctW, $marksY, $gW, $marksHdrH, 'Grade', true, 'C', $FONT, 'B');

    if ($isTyping) {
        $parts = typingMarksheetParticulars($wpm, $kph);
        $maxes = array_map(static fn($p) => (int)$p['max'], $parts);
        $obtained = allocateScoreAcrossMaxes($score, $maxes);
        $n = count($parts);
        $rh = $marksBodyH / max(1, $n);
        for ($i = 0; $i < $n; $i++) {
            $py = $marksY + $marksHdrH + $i * $rh;
            $obt = (int)($obtained[$i] ?? 0);
            $max = (int)$parts[$i]['max'];
            // Same font size as rest of sheet
            $cell($x, $py, $pW, $rh, (string)$parts[$i]['label'], false, 'L', $FONT, '', true);
            $cell($x + $pW, $py, $mW, $rh, sprintf('%02d/%02d', $obt, $max), false, 'C', $FONT, 'B');
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
    $cell($x + $pW + $mW, $marksY + $marksHdrH, $pctW, $marksBodyH, (string)$score, false, 'C', $FONT, 'B');
    $cell($x + $pW + $mW + $pctW, $marksY + $marksHdrH, $gW, $marksBodyH, $grade, false, 'C', $FONT, 'B');

    $sx = $x + $leftW;
    $sy = $marksY;
    $sh = $marksHdrH + $marksBodyH;
    $pdf->Rect($sx, $sy, $rightW, $sh, 'D');

    $sigH = 10.0;
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
    $sigY = $sy + ($isTyping ? 3.0 : 4.0);
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

    // Typing marksheet: push signatory label down (signatures stay top, seal stays bottom)
    if ($isTyping) {
        $textY = $sy + max($sigY + $sigH + 6.0, $sh * 0.48);
    } else {
        $textY = $sigY + $sigH + 2.0;
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Times', 'B', $FONT);
    $pdf->SetXY($sx + 1, $textY);
    $pdf->Cell($rightW - 2, 4.2, $signatory, 0, 0, 'C');
    $pdf->SetFont('Times', '', 8.5);
    $pdf->SetXY($sx + 1, $textY + 4.5);
    $pdf->Cell($rightW - 2, 4.0, 'Gyanam India Educational Services', 0, 0, 'C');

    $textBottom = $textY + 4.5 + 4.0;
    $boxBottom = $sy + $sh;
    $sealPadTop = $isTyping ? 1.5 : 1.0;
    $sealPadBottom = 1.5;
    $sealAvailH = max(12.0, $boxBottom - $textBottom - $sealPadTop - $sealPadBottom);
    $sealSize = min($isTyping ? 28.0 : 32.0, $rightW - 5.0, $sealAvailH);
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
