<?php
/**
 * PdfDocument.php
 * -------------------------------------------------------
 * Minimal PDF 1.4 writer used by the customer dossier.
 *
 * Deliberately dependency free (no FPDF/TCPDF): it emits
 * standard Helvetica text plus JPEG images and is limited to
 * what a dossier needs — headings, label/value rows, flowing
 * paragraphs and full-width document images.
 *
 * All pages are A4, content streams are deflated, and JPEG
 * bytes are embedded as DCTDecode so the PDF stays small.
 * -------------------------------------------------------
 */

class PdfDocument
{
    private const PAGE_WIDTH  = 595.28;   // A4 portrait, points
    private const PAGE_HEIGHT = 841.89;
    private const MARGIN      = 40.0;
    private const FOOTER      = 26.0;
    private const CONTENT_W   = self::PAGE_WIDTH - (self::MARGIN * 2);

    /** Helvetica character widths (1/1000 em) for printable ASCII. */
    private const WIDTHS = [
        32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667,
        39 => 191, 40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333,
        46 => 278, 47 => 278, 48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556,
        53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556, 58 => 278, 59 => 278,
        60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015, 65 => 667, 66 => 667,
        67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278,
        74 => 500, 75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667,
        81 => 778, 82 => 722, 83 => 667, 84 => 611, 85 => 722, 86 => 667, 87 => 944,
        88 => 667, 89 => 667, 90 => 611, 91 => 278, 92 => 278, 93 => 278, 94 => 469,
        95 => 556, 96 => 333, 97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556,
        102 => 278, 103 => 556, 104 => 556, 105 => 222, 106 => 222, 107 => 500, 108 => 222,
        109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556, 114 => 333, 115 => 500,
        116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500,
        123 => 334, 124 => 260, 125 => 334, 126 => 584,
    ];

    private string $title;
    /** @var string[] content streams, one per page */
    private array $pages = [];
    /** @var array<int,array{bytes:string,w:int,h:int}> */
    private array $images = [];
    private int $pageIndex = -1;
    private float $y = 0.0;
    private string $footer = '';

    public function __construct(string $title = '')
    {
        $this->title = $title;
        $this->addPage();
    }

    public function setFooter(string $footer): void
    {
        $this->footer = $this->sanitise($footer);
    }

    /** Start a new page (the first page is created automatically). */
    public function addPage(): void
    {
        $this->pages[] = '';
        $this->pageIndex = count($this->pages) - 1;
        $this->y = self::PAGE_HEIGHT - self::MARGIN;
    }

    public function getPageCount(): int
    {
        return count($this->pages);
    }

    /** Big coloured title at the top of the current flow. */
    public function title(string $text): void
    {
        $this->ensureSpace(34);
        $this->write($this->sanitise($text), 17, true, self::MARGIN, $this->y, '0.05 0.43 0.36');
        $this->y -= 22;
        $this->rule('0.05 0.43 0.36', 1.0);
        $this->y -= 12;
    }

    public function subtitle(string $text): void
    {
        $this->ensureSpace(20);
        $this->write($this->sanitise($text), 10, false, self::MARGIN, $this->y, '0.35 0.35 0.35');
        $this->y -= 16;
    }

    public function heading(string $text, float $size = 12): void
    {
        $this->ensureSpace($size + 22);
        $this->y -= 6;
        $this->write($this->sanitise($text), $size, true, self::MARGIN, $this->y, '0.05 0.43 0.36');
        $this->y -= 6;
        $this->rule('0.82 0.89 0.86', 0.7);
        $this->y -= 10;
    }

    /** Simple label/value row. */
    public function row(string $label, string $value, float $labelWidth = 150.0): void
    {
        $size = 9.5;
        $lineHeight = $size + 4.5;
        $lines = $this->wrap($value, $size, self::CONTENT_W - $labelWidth);

        $this->ensureSpace($lineHeight * count($lines));

        $this->write($this->sanitise($label), $size, true, self::MARGIN, $this->y, '0.30 0.30 0.30');

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $this->y -= $lineHeight;
            }

            $this->write($line, $size, false, self::MARGIN + $labelWidth, $this->y, '0.08 0.08 0.08');
        }

        $this->y -= $lineHeight;
    }

    /** Flowing paragraph with automatic line wrapping. */
    public function paragraph(string $text, float $size = 9.5, float $lineHeight = 13.0): void
    {
        foreach ($this->wrap($text, $size, self::CONTENT_W) as $line) {
            $this->ensureSpace($lineHeight);
            $this->write($line, $size, false, self::MARGIN, $this->y, '0.15 0.15 0.15');
            $this->y -= $lineHeight;
        }
    }

    public function gap(float $points = 10.0): void
    {
        $this->y -= $points;
    }

    /** Start a new page when less than $height points remain. */
    public function keepTogether(float $height): void
    {
        $this->ensureSpace($height);
    }

    /**
     * Draw a JPEG image scaled to fit the box, centred horizontally.
     * The caller supplies raw JPEG bytes; non-JPEG images must be
     * converted first (see CustomerDossierService::jpegBytes()).
     */
    public function image(string $jpegBytes, int $width, int $height, float $maxWidth, float $maxHeight): void
    {
        if ($width <= 0 || $height <= 0 || $jpegBytes === '') {
            return;
        }

        $scale = min($maxWidth / $width, $maxHeight / $height, 1.0);
        $drawW = $width * $scale;
        $drawH = $height * $scale;

        // Keep the image on one page when it fits a page at all.
        if ($drawH + 12 <= self::PAGE_HEIGHT - (self::MARGIN * 2)) {
            $this->ensureSpace($drawH + 12);
        }

        $name = 'Im' . (count($this->images) + 1);
        $this->images[] = ['bytes' => $jpegBytes, 'w' => $width, 'h' => $height, 'name' => $name];

        $x = self::MARGIN + ((self::CONTENT_W - $drawW) / 2);
        $bottom = $this->y - $drawH;

        $this->append(sprintf(
            "q\n%s 0 0 %s %s %s cm\n/%s Do\nQ\n",
            $this->num($drawW),
            $this->num($drawH),
            $this->num($x),
            $this->num($bottom),
            $name
        ));

        $this->y = $bottom - 8;
    }

    /** Finish the document and return the raw PDF bytes. */
    public function toBytes(): string
    {
        $total = count($this->pages);

        foreach ($this->pages as $index => $content) {
            $footer = trim($this->footer);
            $left   = $footer !== '' ? $footer . '   -   ' : '';
            $label  = $left . 'Page ' . ($index + 1) . ' of ' . $total;

            $this->pages[$index] = $content
                . "0.60 0.60 0.60 rg\n"
                . $this->drawText($label, 8, false, self::MARGIN, self::FOOTER, '0.60 0.60 0.60');
        }

        // Object ids: 1 catalog, 2 page tree, 3 regular font, 4 bold font,
        // 5.. images, then page/content pairs.
        $next = 5;
        $imageIds = [];

        foreach ($this->images as $image) {
            $imageIds[$image['name']] = $next++;
        }

        $pageIds = [];
        $contentIds = [];

        foreach ($this->pages as $_) {
            $pageIds[] = $next++;
            $contentIds[] = $next++;
        }

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        foreach ($this->images as $image) {
            $objects[$imageIds[$image['name']]] = "<< /Type /XObject /Subtype /Image /Width {$image['w']} "
                . "/Height {$image['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 "
                . "/Filter /DCTDecode /Length " . strlen($image['bytes']) . " >>\nstream\n"
                . $image['bytes'] . "\nendstream";
        }

        $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds));
        $objects[2] = "<< /Type /Pages /Count " . count($pageIds) . " /Kids [{$kids}] >>";

        $xobjects = '';

        foreach ($imageIds as $name => $id) {
            $xobjects .= "/{$name} {$id} 0 R ";
        }

        foreach ($this->pages as $index => $content) {
            $compressed = gzcompress($content, 6);

            $objects[$contentIds[$index]] = "<< /Length " . strlen($compressed) . " /Filter /FlateDecode >>\nstream\n"
                . $compressed . "\nendstream";

            $objects[$pageIds[$index]] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] "
                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << %s >> >> "
                . "/Contents %d 0 R >>",
                $this->num(self::PAGE_WIDTH),
                $this->num(self::PAGE_HEIGHT),
                $xobjects,
                $contentIds[$index]
            );
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $infoId = 0;

        if ($this->title !== '') {
            $infoId = max(array_keys($objects)) + 1;
            $objects[$infoId] = "<< /Title (" . $this->escape($this->sanitise($this->title))
                . ") /Producer (Loan Manager) >>";
            ksort($objects);
        }

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefAt = strlen($pdf);
        $count = max(array_keys($objects)) + 1;

        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";

        for ($id = 1; $id < $count; $id++) {
            $pdf .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                : "0000000000 65535 f \n";
        }

        $info = $infoId > 0 ? " /Info {$infoId} 0 R" : '';

        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R{$info} >>\nstartxref\n{$xrefAt}\n%%EOF\n";

        return $pdf;
    }

    // ---------------------------------------------------------------- internals

    private function ensureSpace(float $height): void
    {
        if ($this->y - $height < self::MARGIN + self::FOOTER) {
            $this->addPage();
        }
    }

    private function append(string $ops): void
    {
        $this->pages[$this->pageIndex] .= $ops;
    }

    private function write(string $text, float $size, bool $bold, float $x, float $y, string $rgb): void
    {
        $this->append($this->drawText($text, $size, $bold, $x, $y, $rgb));
    }

    private function drawText(string $text, float $size, bool $bold, float $x, float $y, string $rgb): string
    {
        $font = $bold ? '/F2' : '/F1';

        return $rgb . " rg\n"
            . "BT\n{$font} " . $this->num($size) . " Tf\n"
            . "1 0 0 1 " . $this->num($x) . ' ' . $this->num($y) . " Tm\n"
            . '(' . $this->escape($text) . ") Tj\nET\n";
    }

    private function rule(string $rgb, float $width): void
    {
        $this->append(sprintf(
            "%s RG\n%s w\n%s %s m\n%s %s l\nS\n",
            $rgb,
            $this->num($width),
            $this->num(self::MARGIN),
            $this->num($this->y),
            $this->num(self::MARGIN + self::CONTENT_W),
            $this->num($this->y)
        ));
    }

    /** @return string[] */
    private function wrap(string $text, float $size, float $maxWidth): array
    {
        $text = $this->sanitise($text);
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;

            if ($this->textWidth($candidate, $size) <= $maxWidth || $line === '') {
                $line = $candidate;
                continue;
            }

            $lines[] = $line;
            $line = $word;
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines ?: [''];
    }

    private function textWidth(string $text, float $size): float
    {
        $total = 0;

        foreach (str_split($text) as $char) {
            $total += self::WIDTHS[ord($char)] ?? 556;
        }

        return ($total / 1000) * $size;
    }

    /** Convert UTF-8 to Windows-1252 (PDF standard-font safe). */
    private function sanitise(string $text): string
    {
        $text = strtr($text, [
            "\xE2\x80\x94" => '-',    // em dash
            "\xE2\x80\x93" => '-',    // en dash
            "\xE2\x80\xA6" => '...',  // ellipsis
            "\xE2\x80\x98" => "'",
            "\xE2\x80\x99" => "'",
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
        ]);

        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        if ($converted === false) {
            $converted = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return str_replace(["\r", "\n", "\t"], ' ', $converted);
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
