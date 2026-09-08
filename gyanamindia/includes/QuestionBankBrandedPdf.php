<?php
/**
 * Branded landscape FPDF for ATC question-bank downloads.
 * Requires FPDF to be loaded first (fpdi_autoload.php).
 */

if (class_exists('QuestionBankBrandedPdf', false)) {
    return;
}

class QuestionBankBrandedPdf extends FPDF
{
    public ?string $letterheadPath = null;
    public ?string $logoPath = null;
    public string $docTitle = 'Question Bank';
    public string $atcCode = '';
    public string $subject = '';
    public bool $includeAnswers = true;
    public bool $drawTableHeaderNext = false;

    /** @var list<float> */
    public array $colWidths = [];

    public function Header(): void
    {
        $left = $this->lMargin;
        $usable = $this->GetPageWidth() - $this->lMargin - $this->rMargin;

        if ($this->PageNo() === 1 && $this->letterheadPath && is_file($this->letterheadPath)) {
            // Full GIIT letterhead (image.png) on first page only
            $bannerW = $usable;
            $bannerH = $bannerW * (542.0 / 1280.0);
            $this->Image($this->letterheadPath, $left, 6, $bannerW, $bannerH);
            $this->SetY(6 + $bannerH + 3);
        } else {
            // GIIT logo on every continuation page
            $logoH = 16.0;
            if ($this->logoPath && is_file($this->logoPath)) {
                $this->Image($this->logoPath, $left, 8, $logoH, $logoH);
            }
            $this->SetXY($left + $logoH + 3, 9);
            $this->SetFont('Arial', 'B', 11);
            $this->SetTextColor(197, 32, 38);
            $this->Cell($usable - $logoH - 3, 5, questionBankPdfText('Gyanam Institute of Information Technology'), 0, 1, 'L');
            $this->SetX($left + $logoH + 3);
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(29, 62, 83);
            $this->Cell($usable - $logoH - 3, 4, questionBankPdfText('A Unit of IT Training - Gyanam India (ISO 9001 : 2015)'), 0, 1, 'L');
            $this->SetTextColor(0, 0, 0);
            $this->SetDrawColor(197, 32, 38);
            $this->SetLineWidth(0.4);
            $yLine = max(8 + $logoH, $this->GetY()) + 1.5;
            $this->Line($left, $yLine, $left + $usable, $yLine);
            $this->SetY($yLine + 3);
        }

        // Small GIIT logo stamp on every page (including page 1), top-right
        if ($this->logoPath && is_file($this->logoPath)) {
            $stamp = 11.0;
            $this->Image(
                $this->logoPath,
                $this->GetPageWidth() - $this->rMargin - $stamp,
                4.5,
                $stamp,
                $stamp
            );
        }

        if ($this->drawTableHeaderNext && !empty($this->colWidths)) {
            $this->renderTableHeader();
        }
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(100, 100, 100);
        $left = 'GIIT Question Bank';
        if ($this->atcCode !== '') {
            $left .= '  |  ATC ' . $this->atcCode;
        }
        $this->Cell(90, 5, questionBankPdfText($left), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    public function NbLines(float $w, string $txt): int
    {
        if (!isset($this->CurrentFont['cw'])) {
            $this->SetFont('Arial', '', 7.5);
        }
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c === ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 500;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    public function renderTableHeader(): void
    {
        $w = $this->colWidths;
        $headers = $this->includeAnswers
            ? ['No.', 'Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Ans']
            : ['No.', 'Question', 'Option A', 'Option B', 'Option C', 'Option D'];

        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(29, 62, 83);
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(29, 62, 83);
        $this->SetLineWidth(0.2);
        $h = 7;
        foreach ($headers as $i => $label) {
            $this->Cell($w[$i], $h, questionBankPdfText($label), 1, 0, 'C', true);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 8);
    }

    /**
     * @param list<string> $cells
     */
    public function drawDataRow(array $cells, bool $fill): void
    {
        $w = $this->colWidths;
        $this->SetFont('Arial', '', 7.5);
        $lineH = 3.8;
        $nb = 1;
        foreach ($cells as $i => $txt) {
            $nb = max($nb, $this->NbLines($w[$i], (string)$txt));
        }
        $h = max(8.0, $nb * $lineH + 1.5);

        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->drawTableHeaderNext = true;
            $this->AddPage($this->CurOrientation);
            $this->drawTableHeaderNext = false;
        }

        $x = $this->GetX();
        $y = $this->GetY();
        $this->SetFillColor($fill ? 245 : 255, $fill ? 248 : 255, $fill ? 250 : 255);
        $this->SetDrawColor(180, 190, 200);
        $lastIdx = count($cells) - 1;

        foreach ($cells as $i => $txt) {
            $this->Rect($x, $y, $w[$i], $h, 'DF');
            $this->SetXY($x + 0.8, $y + 0.8);
            $align = ($i === 0 || ($this->includeAnswers && $i === $lastIdx)) ? 'C' : 'L';
            if ($i === 0) {
                $this->SetFont('Arial', 'B', 8);
                $this->SetTextColor(0, 0, 0);
            } elseif ($this->includeAnswers && $i === $lastIdx) {
                $this->SetFont('Arial', 'B', 9);
                $this->SetTextColor(0, 120, 60);
            } else {
                $this->SetFont('Arial', '', 7.5);
                $this->SetTextColor(0, 0, 0);
            }
            $this->MultiCell($w[$i] - 1.6, $lineH, (string)$txt, 0, $align);
            $this->SetTextColor(0, 0, 0);
            $x += $w[$i];
            $this->SetXY($x, $y);
        }
        $this->SetY($y + $h);
    }
}
