<?php
/**
 * Corporate PDF layout for Clarenzon reports.
 * Draws real tables (wrapping, repeating headers, page numbers) with the
 * existing hand-built PDF 1.4 writer. Embeds Arial when it can draw the peso sign.
 */

function clarenzonPdfMoney(float $amount): string
{
    $mark = clarenzonPdfSupportsPeso() ? '₱' : 'PHP ';
    return $mark . number_format($amount, 2);
}

function clarenzonPdfSupportsPeso(): bool
{
    static $ok = null;
    if ($ok === null) {
        $font = clarenzonPdfFont();
        $ok = $font !== null && $font->glyph('₱') > 0;
    }
    return $ok;
}

function clarenzonPdfFont(): ?ClarenzonPdfFont
{
    static $font = false;
    if ($font === false) {
        $path = 'C:\\Windows\\Fonts\\arial.ttf';
        $font = (is_file($path)) ? ClarenzonPdfFont::open($path) : null;
    }
    return $font instanceof ClarenzonPdfFont ? $font : null;
}

function clarenzonPdfLogoJpeg(): ?array
{
    static $logo = false;
    if ($logo !== false) {
        return $logo;
    }
    $logo = null;
    if (!function_exists('imagecreatefrompng') || !function_exists('imagejpeg')) {
        return null;
    }
    foreach ([__DIR__ . '/images/clarenzon-removebg.png', __DIR__ . '/images/clarenzon3-removebg.png'] as $path) {
        if (!is_file($path)) {
            continue;
        }
        $src = @imagecreatefrompng($path);
        if (!$src) {
            continue;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $flat = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 2, 132, 199);
        imagefilledrectangle($flat, 0, 0, $w, $h, $white);
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($flat, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($src);
        imagedestroy($flat);
        if (is_string($bytes) && $bytes !== '') {
            $logo = ['bytes' => $bytes, 'w' => $w, 'h' => $h];
            break;
        }
    }
    return $logo;
}

function clarenzonPdfDocument(array $spec): string
{
    $pdf = new ClarenzonPdfDocument();
    return $pdf->render($spec);
}

final class ClarenzonPdfFont
{
    public string $bytes;
    public int $unitsPerEm = 1000;
    /** @var array<int,int> */
    public array $advances = [];
    /** @var array<int,array{0:int,1:int,2:int,3:int}> */
    private array $segments = [];
    /** @var array<int,int> */
    private array $glyphIdArray = [];

    public static function open(string $path): ?self
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) < 100 || substr($bytes, 0, 4) !== "\x00\x01\x00\x00") {
            return null;
        }
        $font = new self();
        $font->bytes = $bytes;
        $tables = [];
        $num = self::u16($bytes, 4);
        for ($i = 0; $i < $num; $i++) {
            $rec = 12 + ($i * 16);
            $tag = substr($bytes, $rec, 4);
            $tables[$tag] = [self::u32($bytes, $rec + 8), self::u32($bytes, $rec + 12)];
        }
        if (!isset($tables['head'], $tables['hhea'], $tables['maxp'], $tables['hmtx'], $tables['cmap'])) {
            return null;
        }
        $font->unitsPerEm = max(1, self::u16($bytes, $tables['head'][0] + 18));
        $numH = self::u16($bytes, $tables['hhea'][0] + 34);
        $numGlyphs = self::u16($bytes, $tables['maxp'][0] + 4);
        $hmtx = $tables['hmtx'][0];
        $last = 500;
        for ($g = 0; $g < $numGlyphs; $g++) {
            if ($g < $numH) {
                $last = self::u16($bytes, $hmtx + ($g * 4));
            }
            $font->advances[$g] = $last;
        }
        if (!$font->loadCmap($bytes, $tables['cmap'][0])) {
            return null;
        }
        return $font->glyph('A') > 0 ? $font : null;
    }

    public function glyph(string $char): int
    {
        $cp = self::codepoint($char);
        if ($cp < 0) {
            return 0;
        }
        foreach ($this->segments as $i => $seg) {
            if ($cp < $seg[0] || $cp > $seg[1]) {
                continue;
            }
            if ($seg[3] === 0) {
                return ($seg[2] + $cp) & 0xFFFF;
            }
            $idx = (int)(($seg[3] / 2) - (count($this->segments) - $i) + ($cp - $seg[0]));
            $gid = $this->glyphIdArray[$idx] ?? 0;
            if ($gid === 0) {
                return 0;
            }
            return ($gid + $seg[2]) & 0xFFFF;
        }
        return 0;
    }

    public function widthUnits(string $text): int
    {
        $sum = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $gid = $this->glyph($ch);
            $adv = $this->advances[$gid] ?? ($this->advances[0] ?? 500);
            $sum += (int)round($adv * 1000 / $this->unitsPerEm);
        }
        return $sum;
    }

    private function loadCmap(string $bytes, int $start): bool
    {
        $count = self::u16($bytes, $start + 2);
        $chosen = null;
        for ($i = 0; $i < $count; $i++) {
            $rec = $start + 4 + ($i * 8);
            $plat = self::u16($bytes, $rec);
            $enc = self::u16($bytes, $rec + 2);
            $off = self::u32($bytes, $rec + 4);
            if (($plat === 3 && $enc === 1) || ($plat === 0 && $enc === 3)) {
                $chosen = $start + $off;
                if ($plat === 3) {
                    break;
                }
            }
        }
        if ($chosen === null || self::u16($bytes, $chosen) !== 4) {
            return false;
        }
        $segCount = (int)(self::u16($bytes, $chosen + 6) / 2);
        $endPos = $chosen + 14;
        $startPos = $endPos + ($segCount * 2) + 2;
        $deltaPos = $startPos + ($segCount * 2);
        $offsetPos = $deltaPos + ($segCount * 2);
        $glyphPos = $offsetPos + ($segCount * 2);
        $segments = [];
        for ($i = 0; $i < $segCount; $i++) {
            $segments[] = [
                self::u16($bytes, $startPos + ($i * 2)),
                self::u16($bytes, $endPos + ($i * 2)),
                self::i16($bytes, $deltaPos + ($i * 2)),
                self::u16($bytes, $offsetPos + ($i * 2)),
            ];
        }
        $glyphCount = max(0, (int)(($chosen + self::u16($bytes, $chosen + 2) - $glyphPos) / 2));
        $glyphs = [];
        for ($i = 0; $i < $glyphCount; $i++) {
            $glyphs[] = self::u16($bytes, $glyphPos + ($i * 2));
        }
        $this->segments = $segments;
        $this->glyphIdArray = $glyphs;
        return $segments !== [];
    }

    private static function codepoint(string $char): int
    {
        if (function_exists('mb_ord')) {
            $cp = mb_ord($char, 'UTF-8');
            return $cp === false ? -1 : $cp;
        }
        $u = unpack('N', mb_convert_encoding($char, 'UCS-4BE', 'UTF-8'));
        return isset($u[1]) ? (int)$u[1] : -1;
    }

    private static function u16(string $b, int $o): int
    {
        $v = unpack('n', substr($b, $o, 2));
        return (int)($v[1] ?? 0);
    }

    private static function i16(string $b, int $o): int
    {
        $v = self::u16($b, $o);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private static function u32(string $b, int $o): int
    {
        $v = unpack('N', substr($b, $o, 4));
        return (int)($v[1] ?? 0);
    }
}

final class ClarenzonPdfDocument
{
    private const W = 842.0;
    private const H = 595.0;
    private const M = 28.0;

    private string $ops = '';
    /** @var list<string> */
    private array $pages = [];
    private float $y = 560;
    /** @var list<array{label:string,width:float,align:string}> */
    private array $repeatCols = [];
    private string $repeatTitle = '';
    private ?ClarenzonPdfFont $font;
    /** @var array<int,string> */
    private array $used = [];

    public function __construct()
    {
        $this->font = clarenzonPdfFont();
    }

    public function render(array $spec): string
    {
        $title = trim((string)($spec['title'] ?? 'Report'));
        $this->startPage(true, $title, $spec);
        $blocks = $spec['blocks'] ?? [];
        if (!$blocks) {
            $this->text(self::M, $this->y, (string)($spec['empty'] ?? 'No records found for the selected filters.'), 10, [0.06, 0.09, 0.16], false);
        }
        foreach ($blocks as $block) {
            $this->drawBlock($title, $block);
        }
        if ($this->ops !== '') {
            $this->pages[] = $this->ops;
        }
        return $this->compile($title);
    }

    private function startPage(bool $first, string $title, array $spec): void
    {
        if ($this->ops !== '') {
            $this->pages[] = $this->ops;
        }
        $this->ops = '';
        $this->repeatCols = [];
        $this->fill(0, self::H - ($first ? 58 : 32), self::W, $first ? 58 : 32, [0.008, 0.518, 0.780]);
        $logo = $first ? clarenzonPdfLogoJpeg() : null;
        $textX = self::M;
        if ($logo) {
            $this->ops .= "q 34 0 0 34 " . self::M . " " . (self::H - 48) . " cm /Im1 Do Q\n";
            $textX = self::M + 42;
        }
        if ($first) {
            $this->text($textX, self::H - 28, 'CLARENZON MEDICAL SUPPLIES', 13, [1, 1, 1], true);
            $this->text($textX, self::H - 44, 'Inventory & Operations System', 8, [0.878, 0.949, 0.996], false);
            $this->y = self::H - 78;
            $this->text(self::M, $this->y, $title, 14, [0.012, 0.412, 0.631], true);
            $this->y -= 16;
            foreach ($spec['details'] ?? [] as $line) {
                $line = trim((string)$line);
                if ($line === '') {
                    continue;
                }
                $this->text(self::M, $this->y, $line, 8, [0.20, 0.25, 0.33], false);
                $this->y -= 12;
            }
            $summary = $spec['summary'] ?? [];
            if ($summary) {
                $this->y -= 4;
                $x = self::M;
                $boxW = 118;
                foreach ($summary as $item) {
                    if ($x + $boxW > self::W - self::M) {
                        $x = self::M;
                        $this->y -= 32;
                    }
                    $this->fill($x, $this->y - 22, $boxW - 8, 28, [0.941, 0.976, 1]);
                    $this->text($x + 6, $this->y - 2, (string)($item['label'] ?? ''), 7, [0.012, 0.412, 0.631], false);
                    $this->text($x + 6, $this->y - 16, (string)($item['value'] ?? ''), 9, [0.06, 0.09, 0.16], true);
                    $x += $boxW;
                }
                $this->y -= 36;
            }
            $this->y -= 6;
            return;
        }
        $this->text(self::M, self::H - 20, 'CLARENZON MEDICAL SUPPLIES  ·  ' . $title, 9, [1, 1, 1], true);
        $this->y = self::H - 48;
    }

    private function drawBlock(string $docTitle, array $block): void
    {
        $heading = trim((string)($block['heading'] ?? ''));
        $columns = $block['columns'] ?? [];
        $rows = array_values($block['rows'] ?? []);
        $total = $block['total'] ?? null;
        $empty = (string)($block['empty'] ?? 'No records found for the selected filters.');
        if ($this->y < 90) {
            $this->startPage(false, $docTitle, []);
        }
        if ($heading !== '') {
            $this->text(self::M, $this->y, $heading, 11, [0.06, 0.09, 0.16], true);
            $this->y -= 8;
            $this->ops .= sprintf("0.008 0.518 0.780 RG 1.2 w %.2f %.2f m %.2f %.2f l S\n", self::M, $this->y, self::W - self::M, $this->y);
            $this->y -= 12;
        }
        if (!$rows && $total === null) {
            $this->text(self::M, $this->y, $empty, 9, [0.28, 0.33, 0.40], false);
            $this->y -= 22;
            return;
        }
        $cols = $this->normalizeColumns($columns, count($rows[0] ?? $total ?? []));
        $this->repeatCols = $cols;
        $this->repeatTitle = $docTitle;
        $this->drawColumnHeader($cols);
        foreach ($rows as $i => $row) {
            $this->drawDataRow($docTitle, $cols, array_values($row), $i % 2 === 1, false);
        }
        if (is_array($total)) {
            $this->drawDataRow($docTitle, $cols, array_values($total), false, true);
        }
        $this->y -= 14;
        $this->repeatCols = [];
    }

    /** @param list<array{label:string,width:float,align:string}> $cols */
    private function drawColumnHeader(array $cols): void
    {
        $lines = [];
        $max = 1;
        foreach ($cols as $i => $col) {
            $wrapped = $this->wrap((string)$col['label'], $col['width'] - 6, 7.5, true);
            $lines[$i] = $wrapped;
            $max = max($max, count($wrapped));
        }
        $h = ($max * 9) + 6;
        if ($this->y - $h < 40) {
            $this->startPage(false, $this->repeatTitle, []);
        }
        $this->fill(self::M, $this->y - $h + 2, self::W - (self::M * 2), $h, [0.012, 0.412, 0.631]);
        $x = self::M;
        foreach ($cols as $i => $col) {
            $lineY = $this->y - 9;
            foreach ($lines[$i] as $line) {
                $this->drawAligned($x, $lineY, $col['width'], $line, 7.5, [1, 1, 1], true, $col['align']);
                $lineY -= 9;
            }
            $x += $col['width'];
        }
        $this->y -= $h;
    }

    /** @param list<array{label:string,width:float,align:string}> $cols @param list<string> $cells */
    private function drawDataRow(string $docTitle, array $cols, array $cells, bool $alt, bool $total): void
    {
        $wrapped = [];
        $max = 1;
        foreach ($cols as $i => $col) {
            $text = (string)($cells[$i] ?? '');
            $lines = $this->wrap($text, $col['width'] - 6, 7.5, $total);
            if (count($lines) > 4) {
                $lines = array_slice($lines, 0, 4);
                $lines[3] = rtrim($lines[3], '.') . '...';
            }
            $wrapped[$i] = $lines;
            $max = max($max, count($lines));
        }
        $h = ($max * 9) + 5;
        if ($this->y - $h < 40) {
            $this->startPage(false, $docTitle, []);
            $this->repeatCols = $cols;
            $this->drawColumnHeader($cols);
        }
        $fill = $total ? [0.878, 0.949, 0.996] : ($alt ? [0.941, 0.976, 1] : [1, 1, 1]);
        $this->fill(self::M, $this->y - $h + 2, self::W - (self::M * 2), $h, $fill);
        $this->ops .= sprintf("0.80 0.88 0.93 RG 0.4 w %.2f %.2f m %.2f %.2f l S\n", self::M, $this->y - $h + 2, self::W - self::M, $this->y - $h + 2);
        $x = self::M;
        $color = [0.06, 0.09, 0.16];
        foreach ($cols as $i => $col) {
            $lineY = $this->y - 9;
            foreach ($wrapped[$i] as $line) {
                $this->drawAligned($x, $lineY, $col['width'], $line, 7.5, $color, $total, $col['align']);
                $lineY -= 9;
            }
            $x += $col['width'];
        }
        $this->y -= $h;
    }

    /** @return list<array{label:string,width:float,align:string}> */
    private function normalizeColumns(array $columns, int $fallbackCount): array
    {
        $usable = self::W - (self::M * 2);
        if (!$columns) {
            $columns = [];
            for ($i = 0; $i < max(1, $fallbackCount); $i++) {
                $columns[] = ['label' => '', 'width' => 1, 'align' => 'left'];
            }
        }
        $weight = 0.0;
        foreach ($columns as $col) {
            $weight += max(0.2, (float)($col['width'] ?? 1));
        }
        $weight = $weight > 0 ? $weight : 1;
        $out = [];
        foreach ($columns as $col) {
            $w = max(0.2, (float)($col['width'] ?? 1));
            $out[] = [
                'label' => (string)($col['label'] ?? ''),
                'width' => $usable * ($w / $weight),
                'align' => (string)($col['align'] ?? 'left'),
            ];
        }
        return $out;
    }

    /** @return list<string> */
    private function wrap(string $text, float $maxWidth, float $size, bool $bold): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '' || $maxWidth < 8) {
            return [$text];
        }
        $words = preg_split('/ /u', $text) ?: [$text];
        $lines = [];
        $cur = '';
        $pushWord = function (string $word) use (&$lines, &$cur, $maxWidth, $size, $bold): void {
            while ($word !== '' && $this->measure($word, $size, $bold) > $maxWidth) {
                $cut = $this->cutToWidth($word, $maxWidth, $size, $bold);
                if ($cut === '' || $cut === $word) {
                    break;
                }
                if ($cur !== '') {
                    $lines[] = $cur;
                    $cur = '';
                }
                $lines[] = $cut;
                $word = substr($word, strlen($cut));
            }
            if ($word === '') {
                return;
            }
            $trial = $cur === '' ? $word : $cur . ' ' . $word;
            if ($this->measure($trial, $size, $bold) <= $maxWidth) {
                $cur = $trial;
                return;
            }
            if ($cur !== '') {
                $lines[] = $cur;
            }
            $cur = $word;
        };
        foreach ($words as $word) {
            $pushWord($word);
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines ?: [''];
    }

    private function cutToWidth(string $word, float $maxWidth, float $size, bool $bold): string
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $built = '';
        foreach ($chars as $ch) {
            $next = $built . $ch;
            if ($this->measure($next, $size, $bold) > $maxWidth) {
                return $built;
            }
            $built = $next;
        }
        return $built;
    }

    private function measure(string $text, float $size, bool $bold): float
    {
        if ($this->font) {
            return $this->font->widthUnits($text) * $size / 1000;
        }
        $units = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $units += $this->helveticaWidth(ord($text[$i]));
        }
        $width = $units * $size / 1000;
        return $bold ? $width * 1.06 : $width;
    }

    private function helveticaWidth(int $code): int
    {
        static $map = [
            32 => 278, 33 => 278, 34 => 355, 35 => 556, 36 => 556, 37 => 889, 38 => 667, 39 => 191,
            40 => 333, 41 => 333, 42 => 389, 43 => 584, 44 => 278, 45 => 333, 46 => 278, 47 => 278,
            48 => 556, 49 => 556, 50 => 556, 51 => 556, 52 => 556, 53 => 556, 54 => 556, 55 => 556, 56 => 556, 57 => 556,
            58 => 278, 59 => 278, 60 => 584, 61 => 584, 62 => 584, 63 => 556, 64 => 1015,
            65 => 667, 66 => 667, 67 => 722, 68 => 722, 69 => 667, 70 => 611, 71 => 778, 72 => 722, 73 => 278, 74 => 500,
            75 => 667, 76 => 556, 77 => 833, 78 => 722, 79 => 778, 80 => 667, 81 => 778, 82 => 722, 83 => 667, 84 => 611,
            85 => 722, 86 => 667, 87 => 944, 88 => 667, 89 => 667, 90 => 611,
            97 => 556, 98 => 556, 99 => 500, 100 => 556, 101 => 556, 102 => 278, 103 => 556, 104 => 556, 105 => 222,
            106 => 222, 107 => 500, 108 => 222, 109 => 833, 110 => 556, 111 => 556, 112 => 556, 113 => 556, 114 => 333,
            115 => 500, 116 => 278, 117 => 556, 118 => 500, 119 => 722, 120 => 500, 121 => 500, 122 => 500,
        ];
        return $map[$code] ?? 556;
    }

    private function drawAligned(float $x, float $y, float $width, string $text, float $size, array $color, bool $bold, string $align): void
    {
        $tw = $this->measure($text, $size, $bold);
        $dx = $x + 3;
        if ($align === 'right') {
            $dx = $x + $width - 3 - $tw;
        } elseif ($align === 'center') {
            $dx = $x + (($width - $tw) / 2);
        }
        $this->text($dx, $y, $text, $size, $color, $bold);
    }

    /** @param array{0:float,1:float,2:float} $color */
    private function text(float $x, float $y, string $text, float $size, array $color, bool $bold): void
    {
        if ($text === '') {
            return;
        }
        $font = (!$this->font && $bold) ? 'F2' : 'F1';
        $show = $this->encode($text);
        $this->ops .= sprintf(
            "BT /%s %.2f Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm %s Tj ET\n",
            $font,
            $size,
            $color[0],
            $color[1],
            $color[2],
            $x,
            $y,
            $show
        );
    }

    private function encode(string $text): string
    {
        if (!$this->font) {
            $text = preg_replace('/[^\x20-\x7E]/', ' ', $text) ?? '';
            return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text) . ')';
        }
        $hex = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $gid = $this->font->glyph($ch);
            if ($gid <= 0) {
                $gid = $this->font->glyph(' ');
            }
            $this->used[$gid] = $ch;
            $hex .= sprintf('%04X', $gid & 0xFFFF);
        }
        return '<' . $hex . '>';
    }

    /** @param array{0:float,1:float,2:float} $color */
    private function fill(float $x, float $y, float $w, float $h, array $color): void
    {
        $this->ops .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n", $color[0], $color[1], $color[2], $x, $y, $w, $h);
    }

    private function compile(string $title): string
    {
        $pageCount = max(1, count($this->pages));
        $logo = clarenzonPdfLogoJpeg();
        $font = $this->font;
        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds = [];
        $contentIds = [];
        $next = 3;
        for ($i = 0; $i < $pageCount; $i++) {
            $pageIds[] = $next;
            $contentIds[] = $next + 1;
            $next += 2;
        }
        $fontId = $next;
        $next++;
        $boldId = 0;
        if (!$font) {
            $boldId = $next;
            $next++;
        }
        $cidId = 0;
        $fdId = 0;
        $ffId = 0;
        $tuId = 0;
        if ($font) {
            $cidId = $next++;
            $fdId = $next++;
            $ffId = $next++;
            $tuId = $next++;
        }
        $imageId = 0;
        if ($logo) {
            $imageId = $next++;
        }
        $kids = [];
        foreach ($pageIds as $id) {
            $kids[] = $id . ' 0 R';
        }
        $objects[] = '<< /Type /Pages /Count ' . $pageCount . ' /Kids [' . implode(' ', $kids) . '] >>';
        foreach ($this->pages as $i => $ops) {
            $footer = 'Clarenzon Medical Supplies  ·  ' . $title . '  ·  Page ' . ($i + 1) . ' of ' . $pageCount;
            $footerShow = $this->encode($footer);
            $ops .= "0.80 0.88 0.93 RG 0.6 w 28 34 m 814 34 l S\n";
            $ops .= sprintf("BT /F1 8 Tf 0.28 0.33 0.40 rg 1 0 0 1 28 22 Tm %s Tj ET\n", $footerShow);
            $resources = '<< /Font << /F1 ' . $fontId . ' 0 R';
            if ($boldId) {
                $resources .= ' /F2 ' . $boldId . ' 0 R';
            }
            $resources .= ' >>';
            if ($imageId && $i === 0) {
                $resources .= ' /XObject << /Im1 ' . $imageId . ' 0 R >>';
            }
            $resources .= ' >>';
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Contents ' . $contentIds[$i] . ' 0 R /Resources ' . $resources . ' >>';
            $objects[] = '<< /Length ' . strlen($ops) . " >>\nstream\n" . $ops . "endstream";
        }
        if ($font) {
            $widths = [];
            foreach ($this->used as $gid => $char) {
                $adv = $font->advances[$gid] ?? 500;
                $widths[] = $gid . ' [' . (int)round($adv * 1000 / $font->unitsPerEm) . ']';
            }
            $toUni = $this->toUnicode();
            $compressed = gzcompress($font->bytes);
            $objects[] = '<< /Type /Font /Subtype /Type0 /BaseFont /Arial /Encoding /Identity-H /DescendantFonts [' . $cidId . ' 0 R] /ToUnicode ' . $tuId . ' 0 R >>';
            $objects[] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /Arial /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor ' . $fdId . ' 0 R /DW 500 /W [' . implode(' ', $widths) . '] /CIDToGIDMap /Identity >>';
            $objects[] = '<< /Type /FontDescriptor /FontName /Arial /Flags 32 /FontBBox [-665 -325 2000 1040] /ItalicAngle 0 /Ascent 905 /Descent -210 /CapHeight 716 /StemV 80 /FontFile2 ' . $ffId . ' 0 R >>';
            $objects[] = '<< /Length ' . strlen($compressed) . ' /Length1 ' . strlen($font->bytes) . " /Filter /FlateDecode >>\nstream\n" . $compressed . "endstream";
            $objects[] = '<< /Length ' . strlen($toUni) . " >>\nstream\n" . $toUni . "endstream";
        } else {
            $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
            $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        }
        if ($logo && $imageId) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . (int)$logo['w'] . ' /Height ' . (int)$logo['h']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($logo['bytes']) . " >>\nstream\n" . $logo['bytes'] . "endstream";
        }
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private function toUnicode(): string
    {
        $chunks = [];
        $pairs = '';
        $n = 0;
        foreach ($this->used as $gid => $char) {
            $cp = function_exists('mb_ord') ? mb_ord($char, 'UTF-8') : 32;
            if ($cp === false) {
                $cp = 32;
            }
            $pairs .= sprintf("<%04X> <%04X>\n", $gid & 0xFFFF, $cp & 0xFFFF);
            $n++;
            if ($n === 100) {
                $chunks[] = "100 beginbfchar\n" . $pairs . "endbfchar\n";
                $pairs = '';
                $n = 0;
            }
        }
        if ($n > 0) {
            $chunks[] = $n . " beginbfchar\n" . $pairs . "endbfchar\n";
        }
        if (!$chunks) {
            $chunks[] = "1 beginbfchar\n<0000> <0000>\nendbfchar\n";
        }
        return "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . implode('', $chunks)
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
    }
}
