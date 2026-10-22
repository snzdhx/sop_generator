<?php

namespace App\Services;

/**
 * Menghitung tata letak alur (flowchart) pada kolom "Pelaksana" tabel SOP:
 *  - perkiraan tinggi tiap baris dari panjang teks (lebar huruf Arial 10 pt),
 *  - pembagian halaman (baris tidak dipotong, header tabel berulang),
 *  - letak simbol (kotak / oval / belah ketupat) dan panah antar langkah,
 *  - konektor halaman (A, B, ...) jika panah melewati batas halaman.
 *
 * Hasilnya: $rows (tinggi & halaman tiap baris) dan $shapes[baris][kolom] = daftar XML
 * bentuk DrawingML (wps) yang dijangkarkan pada paragraf sel pelaksana.
 *
 * Satuan: twips (1/20 pt). 1 twip = 635 EMU.
 */
class FlowchartLayout
{
    private const EMU = 635;
    private const CELL_M = 70;       // margin sel
    private const LINE_H = 230;      // tinggi satu baris Arial 10 pt
    private const SYM_W_MAX = 1000;
    private const SYM_H = 520;
    private const DIA_H = 680;
    private const PAD = 150;         // jarak simbol ke tepi atas/bawah baris
    private const TOP_BAND = 300;    // ruang tambahan di baris pertama tiap halaman
    private const BOTTOM_BAND = 420; // ruang di bawah baris terakhir tiap halaman
    private const PAGE_H = 10640;    // tinggi konten halaman landscape 20160 x 12240 (12240 - 2 x 800)
    private const BUS_GAP = 120;    // jarak bus pembagi ke atas simbol tujuan
    private const MERGE_GAP = 120;  // jarak bus pengumpul ke bawah simbol sumber
    private const CONN_W = 246;
    private const CONN_H = 268;
    private const FONT_PT = 10;
    private const SAFE = 1.08;      // faktor aman lebar teks (baris dikunci exact, jadi teks tidak boleh terpotong)

    /** Lebar huruf Arial (per 1000 em) untuk ASCII 32..126. */
    private const ARIAL = [
        278,
        278,
        355,
        556,
        556,
        889,
        667,
        191,
        333,
        333,
        389,
        584,
        278,
        333,
        278,
        278,
        556,
        556,
        556,
        556,
        556,
        556,
        556,
        556,
        556,
        556,
        278,
        278,
        584,
        584,
        584,
        556,
        1015,
        667,
        667,
        722,
        722,
        667,
        611,
        778,
        722,
        278,
        500,
        667,
        556,
        833,
        722,
        778,
        667,
        778,
        722,
        667,
        611,
        722,
        667,
        944,
        667,
        667,
        611,
        278,
        278,
        278,
        469,
        556,
        333,
        556,
        556,
        500,
        556,
        556,
        278,
        556,
        556,
        222,
        222,
        500,
        222,
        833,
        556,
        556,
        556,
        556,
        333,
        500,
        278,
        556,
        500,
        722,
        500,
        500,
        500,
        334,
        260,
        334,
        584,
    ];

    public int $n;
    public int $wNo;
    public int $wKeg;
    public int $wSyr;
    public int $wWkt;
    public int $wOut;
    public int $wKet;
    public int $wPel;
    /** @var int[] lebar tiap kolom pelaksana (boleh beda-beda, seperti dokumen asli) */
    public array $pw = [];
    public int $symW;
    public int $hHead = 0;
    public int $pages = 1;

    /** @var int[] tinggi baris header (3 baris) */
    public array $headH = [];
    /** @var array[] ['page','top','h','extra','brk'] per langkah */
    public array $rows = [];
    /** @var string[][][] $shapes[baris][kolom] = daftar XML bentuk */
    public array $shapes = [];

    private array $pel;
    private array $steps = [];
    private array $colLeft = [];
    private array $cx = [];
    private int $docId = 1000;
    private int $z = 251658240;
    private int $connLetter = 0;

    public function __construct(array $pelaksana, array $steps, int $totalW = 18715, array $widths = [438, 2997, 2250, 1080, 2250, 2160], array $pelWidths = [])
    {
        $this->pel = array_values($pelaksana) ?: ['Pelaksana'];
        $this->n = count($this->pel);
        [$this->wNo, $this->wKeg, $this->wSyr, $this->wWkt, $this->wOut, $this->wKet] = $widths;
        $pelTotal = $totalW - array_sum($widths);

        // Lebar kolom pelaksana: dokumen asli (5 pelaksana) tidak sama lebar; selain itu dibagi rata.
        if (count($pelWidths) === $this->n && array_sum($pelWidths) === $pelTotal) {
            $this->pw = array_values($pelWidths);
        } elseif ($this->n === 5 && $pelTotal === 7540) {
            $this->pw = [1380, 1295, 1331, 1331, 2203];
        } else {
            $each = intdiv($pelTotal, $this->n);
            $this->pw = array_fill(0, $this->n, $each);
            $this->pw[$this->n - 1] += $pelTotal - $each * $this->n;
        }
        $this->wPel = intdiv($pelTotal, $this->n);

        $left = $this->wNo + $this->wKeg;
        for ($k = 0; $k < $this->n; $k++) {
            $this->colLeft[$k] = $left;
            $this->cx[$k] = $left + intdiv($this->pw[$k], 2);
            $left += $this->pw[$k];
        }
        $this->symW = max(200, min(self::SYM_W_MAX, min($this->pw) - 200));

        $this->normalizeSteps($steps);
        $this->computeRows();
        $this->routeAll();
    }

    // ------------------------------------------------------------------ data

    private function normalizeSteps(array $steps): void
    {
        $steps = array_values($steps);
        $cnt = count($steps);
        foreach ($steps as $i => $st) {
            $st = (array) $st;
            $sim = (string) ($st['simbol'] ?? '');
            if (!in_array($sim, ['proses', 'mulai', 'selesai', 'keputusan'], true)) {
                if ($cnt > 1 && $i === 0) {
                    $sim = 'mulai';
                } elseif ($cnt > 1 && $i === $cnt - 1) {
                    $sim = 'selesai';
                } else {
                    $sim = 'proses';
                }
            }
            $cols = [];
            foreach ((array) ($st['pelaksana'] ?? []) as $c) {
                $c = (int) $c;
                if ($c >= 0 && $c < $this->n) {
                    $cols[$c] = $c;
                }
            }
            ksort($cols);
            $st['pelaksana'] = array_values($cols);
            $st['simbol'] = $sim;
            $this->steps[] = $st;
        }
    }

    private function hasSym(array $st): bool
    {
        return count($st['pelaksana']) > 0;
    }

    private function symHOf(int $i): int
    {
        return $this->steps[$i]['simbol'] === 'keputusan' ? self::DIA_H : self::SYM_H;
    }

    // ------------------------------------------------------- ukuran teks

    private function textW(string $s, float $pt, bool $bold = false): float
    {
        $w = 0;
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $ch) {
            $o = mb_ord($ch, 'UTF-8');
            $w += ($o >= 32 && $o <= 126) ? self::ARIAL[$o - 32] : 556;
        }

        return $w * $pt / 1000.0 * ($bold ? 1.08 : 1.0) * self::SAFE;
    }

    /** Jumlah baris hasil pembungkusan kata; kata yang terlalu panjang dipotong per karakter. */
    private function countLines(string $text, float $availPt, float $pt = self::FONT_PT, bool $bold = false): int
    {
        $availPt = max(1.0, $availPt);
        $paras = preg_split('/\R/u', $text) ?: [''];
        $sp = $this->textW(' ', $pt, $bold);
        $total = 0;
        foreach ($paras as $para) {
            $lines = 1;
            $cur = 0.0;
            foreach (explode(' ', $para) as $wd) {
                $ww = $this->textW($wd, $pt, $bold);
                $add = ($cur == 0.0) ? $ww : $sp + $ww;
                if ($cur + $add <= $availPt) {
                    $cur += $add;
                    continue;
                }
                if ($cur > 0) {
                    $lines++;
                }
                if ($ww > $availPt) {
                    $extra = (int) ceil($ww / $availPt) - 1;
                    $lines += $extra;
                    $cur = $ww - $extra * $availPt;
                } else {
                    $cur = $ww;
                }
            }
            $total += $lines;
        }

        return $total;
    }

    /** Perkiraan tinggi isi sel (di luar margin sel). $value: string atau array (daftar bernomor). */
    private function cellTextH($value, int $width, bool $bold = false): int
    {
        if (is_array($value)) {
            $items = array_values(array_filter(
                array_map(fn($v) => trim((string) $v), $value),
                fn($v) => $v !== ''
            ));
            $h = 0;
            foreach ($items as $i => $it) {
                $avail = ($width - 2 * self::CELL_M - 360) / 20.0;
                $h += $this->countLines(($i + 1) . ".\t" . $it, $avail, self::FONT_PT, $bold) * self::LINE_H + 40;
            }

            return $h;
        }
        $avail = ($width - 2 * self::CELL_M) / 20.0;

        return $this->countLines((string) ($value ?? ''), $avail, self::FONT_PT, $bold) * self::LINE_H;
    }

    // ------------------------------------------------ tinggi baris & halaman

    private function computeRows(): void
    {
        $namesH = 0;
        foreach ($this->pel as $k => $p) {
            $namesH = max($namesH, $this->cellTextH((string) $p, $this->pw[$k], true));
        }
        $namesH += 2 * self::CELL_M + 20;
        $this->headH = [430, max(430, $namesH), 360]; // baris nomor: teks 9 pt + margin sel butuh >= 350
        $this->hHead = array_sum($this->headH) + 30;

        $cap = self::PAGE_H - self::BOTTOM_BAND;
        $page = 0;
        $y = $this->hHead;
        foreach ($this->steps as $i => $st) {
            $h = max(
                $this->cellTextH($st['kegiatan'] ?? '', $this->wKeg),
                $this->cellTextH($st['persyaratan'] ?? '', $this->wSyr),
                $this->cellTextH($st['output'] ?? '', $this->wOut),
                $this->cellTextH($st['keterangan'] ?? '', $this->wKet),
                $this->cellTextH($st['waktu'] ?? '', $this->wWkt)
            ) + 2 * self::CELL_M + 60;
            if ($this->hasSym($st)) {
                $h = max($h, $this->symHOf($i) + 2 * self::PAD);
            }
            $extra = 0;
            if ($y + $h > $cap && $y > $this->hHead) {
                $page++;
                $y = $this->hHead;
            }
            $brk = ($y === $this->hHead && $page > 0);
            if ($brk) {
                $extra = self::TOP_BAND;
                $h += $extra;
            }
            $this->rows[$i] = ['page' => $page, 'top' => $y, 'h' => $h, 'extra' => $extra, 'brk' => $brk];
            $y += $h + 8;
        }
        $this->pages = $page + 1;
    }

    private function symTop(int $i): int
    {
        $r = $this->rows[$i];

        return $r['top'] + $r['extra'] + intdiv($r['h'] - $r['extra'] - $this->symHOf($i), 2);
    }

    private function symBot(int $i): int
    {
        return $this->symTop($i) + $this->symHOf($i);
    }

    private function pageBottom(int $p): int
    {
        $bottom = $this->hHead;
        foreach ($this->rows as $r) {
            if ($r['page'] === $p) {
                $bottom = $r['top'] + $r['h'] + 40;
            }
        }

        return $bottom;
    }

    // ------------------------------------------------ penempatan bentuk

    /** @return array{0:int,1:int,2:int,3:int} [baris, kolom sel, offset X, offset Y] relatif ke paragraf jangkar */
    private function anchor(int $page, int $x, int $y): array
    {
        $first = -1;
        $last = -1;
        foreach ($this->rows as $i => $r) {
            if ($r['page'] !== $page) {
                continue;
            }
            if ($first < 0) {
                $first = $i;
            }
            $last = $i;
        }
        if ($first < 0) {
            $first = $last = 0;
        }
        $ri = $last;
        if ($y < $this->rows[$first]['top']) {
            $ri = $first;
        } else {
            for ($i = $first; $i <= $last; $i++) {
                $r = $this->rows[$i];
                if ($r['top'] <= $y && $y < $r['top'] + $r['h'] + 8) {
                    $ri = $i;
                    break;
                }
            }
        }
        $c = 0;
        $best = PHP_FLOAT_MAX;
        for ($k = 0; $k < $this->n; $k++) {
            $d = abs($this->colLeft[$k] + $this->pw[$k] / 2 - $x);
            if ($d < $best) {
                $best = $d;
                $c = $k;
            }
        }

        return [
            $ri,
            $c,
            (int) round($x - ($this->colLeft[$c] + self::CELL_M)),
            (int) round($y - ($this->rows[$ri]['top'] + self::CELL_M)),
        ];
    }

    private function add(int $page, int $x, int $y, int $cx, int $cy, string $kind, string $text = ''): void
    {
        [$ri, $c, $hx, $vy] = $this->anchor($page, $x, $y);
        $this->docId++;
        $this->z++;
        $this->shapes[$ri][$c][] = $this->shapeXml($this->docId, $this->z, $hx, $vy, $cx, $cy, $kind, $text);
    }

    private function hline(int $page, int $x1, int $x2, int $y): void
    {
        if ($x1 > $x2) {
            [$x1, $x2] = [$x2, $x1];
        }
        if ($x2 - $x1 < 2) {
            return;
        }
        $this->add($page, $x1, $y, $x2 - $x1, 0, 'line');
    }

    /** Garis vertikal; jika melewati batas halaman dipecah dengan konektor halaman. */
    private function vline(int $x, int $pA, int $yA, int $pB, int $yB, bool $arrow): void
    {
        $kind = $arrow ? 'arrow' : 'line';
        if ($pA === $pB) {
            if ($yB - $yA >= 2) {
                $this->add($pA, $x, $yA, 0, $yB - $yA, $kind);
            }

            return;
        }
        $this->connLetter++;
        $letter = chr(65 + (($this->connLetter - 1) % 26));
        $half = intdiv(self::CONN_W, 2);
        $yEnd = $this->pageBottom($pA);
        if ($yEnd - $yA >= 2) {
            $this->add($pA, $x, $yA, 0, $yEnd - $yA, 'line');
        }
        $this->add($pA, $x - $half, $yEnd, self::CONN_W, self::CONN_H, 'offpage', $letter);
        $yTop = $this->hHead + 20;
        $this->add($pB, $x - $half, $yTop, self::CONN_W, self::CONN_H, 'offpage', $letter);
        $yFrom = $yTop + self::CONN_H;
        if ($yB - $yFrom >= 2) {
            $this->add($pB, $x, $yFrom, 0, $yB - $yFrom, $kind);
        }
    }

    // ------------------------------------------------------------ rute

    /** Daftar indeks langkah tujuan (0-based) untuk langkah $i. */
    private function targets(int $i): array
    {
        $st = $this->steps[$i];
        if ($st['simbol'] === 'selesai') {
            return [];
        }
        $raw = trim((string) ($st['lanjut'] ?? ''));
        if ($raw === '-') {
            return [];
        }
        $idx = [];
        if ($raw !== '') {
            foreach (explode(',', str_replace(';', ',', $raw)) as $part) {
                $part = trim($part);
                if ($part !== '' && ctype_digit($part)) {
                    $idx[] = (int) $part - 1;
                }
            }
        } else {
            $idx = [$i + 1];
        }
        $cnt = count($this->steps);
        $out = [];
        foreach ($idx as $t) {
            // lompati langkah yang tidak punya simbol
            while ($t >= 0 && $t < $cnt && !$this->hasSym($this->steps[$t]) && $t + 1 < $cnt) {
                $t++;
            }
            if ($t > $i && $t < $cnt && $this->hasSym($this->steps[$t]) && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    private function collides(int $col, int $i, int $t): bool
    {
        for ($r = $i + 1; $r < $t; $r++) {
            if (in_array($col, $this->steps[$r]['pelaksana'], true)) {
                return true;
            }
        }

        return false;
    }

    private function routeAll(): void
    {
        foreach ($this->steps as $i => $st) {
            if (!$this->hasSym($st)) {
                continue;
            }
            foreach ($st['pelaksana'] as $col) {
                $this->symbol($i, $col);
            }
        }
        foreach ($this->steps as $i => $st) {
            if (!$this->hasSym($st)) {
                continue;
            }
            foreach ($this->targets($i) as $k => $t) {
                $this->connect($i, $t, $st['simbol'] === 'keputusan' && $k >= 1, $k);
            }
        }
    }

    private function symbol(int $i, int $col): void
    {
        $sim = $this->steps[$i]['simbol'];
        $kind = match ($sim) {
            'mulai', 'selesai' => 'terminator',
            'keputusan' => 'decision',
            default => 'process',
        };
        $this->add(
            $this->rows[$i]['page'],
            $this->cx[$col] - intdiv($this->symW, 2),
            $this->symTop($i),
            $this->symW,
            $this->symHOf($i),
            $kind
        );
    }

    private function nearestCol(array $cols, array $xs): int
    {
        $best = $cols[0];
        $bd = PHP_INT_MAX;
        foreach ($cols as $d) {
            $m = PHP_INT_MAX;
            foreach ($xs as $x) {
                $m = min($m, abs($this->cx[$d] - $x));
            }
            if ($m < $bd) {
                $bd = $m;
                $best = $d;
            }
        }

        return $best;
    }

    private function connect(int $i, int $t, bool $side, int $label): void
    {
        $st = $this->steps[$i];
        $tg = $this->steps[$t];
        $SA = $st['pelaksana'];
        $SB = $tg['pelaksana'];
        $pA = $this->rows[$i]['page'];
        $pB = $this->rows[$t]['page'];
        $yA = $this->symBot($i);
        $yBt = $this->symTop($t);
        $ybus = $yBt - self::BUS_GAP;
        $isDec = $st['simbol'] === 'keputusan';

        // Banyak sumber di baris yang berdekatan: gabung ke bus pengumpul, satu garis utama, lalu pecah ke tujuan.
        if ($t === $i + 1 && !$isDec && !$side && count($SA) > 1) {
            $this->mergeSplit($i, $t);

            return;
        }

        $direct = [];
        $busSrc = [];
        $laneSrc = [];
        foreach ($SA as $s) {
            if ($side || $this->collides($s, $i, $t)) {
                $laneSrc[] = $s;       // keluar dari sisi kanan simbol, turun lewat jalur samping
            } elseif (in_array($s, $SB, true)) {
                $direct[] = $s;        // satu kolom: panah lurus
            } else {
                $busSrc[] = $s;        // bergabung ke bus horizontal
            }
        }
        $busTgt = [];
        foreach ($SB as $c) {
            if (!in_array($c, $direct, true)) {
                $busTgt[] = $c;
            }
        }

        $xs = [];
        foreach ($direct as $s) {
            $this->vline($this->cx[$s], $pA, $yA, $pB, $yBt, true);
        }
        foreach ($busSrc as $s) {
            $this->vline($this->cx[$s], $pA, $yA, $pB, $ybus, false);
            $xs[] = $this->cx[$s];
        }
        foreach ($laneSrc as $s) {
            $sx = $this->cx[$s] + intdiv($this->symW, 2);
            $lane = $this->colLeft[$s] + $this->pw[$s] - 50;
            $ymid = $this->symTop($i) + intdiv($this->symHOf($i), 2);
            $this->hline($pA, $sx, $lane, $ymid);
            $this->vline($lane, $pA, $ymid, $pB, $ybus, false);
            $xs[] = $lane;
            if ($isDec && $label >= 1) {
                $this->add($pA, $this->cx[$s] + intdiv($this->symW, 4) + 40, $ymid - 360, 420, 200, 'label', 'Tidak');
            }
        }
        foreach ($busTgt as $c) {
            $this->vline($this->cx[$c], $pB, $ybus, $pB, $yBt, true);
            $xs[] = $this->cx[$c];
        }
        if ($xs) {
            if (!$busSrc && !$laneSrc && $direct) {
                $xs[] = $this->cx[$this->nearestCol($direct, $xs)];
            } elseif (!$busTgt && $direct) {
                $xs[] = $this->cx[$this->nearestCol($direct, $xs)];
            }
            $this->hline($pB, min($xs), max($xs), $ybus);
        }
        if ($isDec && $label === 0 && !$side) {
            $this->add($pA, $this->cx[$SA[0]] + 40, $yA + 10, 420, 200, 'label', 'Ya');
        }
    }

    /**
     * Pola gabung-lalu-pecah (seperti dokumen asli):
     * simbol sumber -> bus pengumpul -> garis utama -> bus pembagi -> panah ke tiap simbol tujuan.
     */
    private function mergeSplit(int $i, int $t): void
    {
        $SA = $this->steps[$i]['pelaksana'];
        $SB = $this->steps[$t]['pelaksana'];
        $pA = $this->rows[$i]['page'];
        $pB = $this->rows[$t]['page'];
        $yA = $this->symBot($i);
        $yBt = $this->symTop($t);
        $ym = $yA + self::MERGE_GAP;
        $ybus = $yBt - self::BUS_GAP;

        $xsA = array_map(fn($c) => $this->cx[$c], $SA);
        $xsB = array_map(fn($c) => $this->cx[$c], $SB);
        $xt = count($SB) === 1
            ? $xsB[0]
            : intdiv(min(array_merge($xsA, $xsB)) + max(array_merge($xsA, $xsB)), 2);

        foreach ($xsA as $x) {
            $this->vline($x, $pA, $yA, $pA, $ym, false);
        }
        $this->hline($pA, min(array_merge($xsA, [$xt])), max(array_merge($xsA, [$xt])), $ym);

        if (count($SB) === 1) {
            $this->vline($xt, $pA, $ym, $pB, $yBt, true);

            return;
        }
        $this->vline($xt, $pA, $ym, $pB, $ybus, true);
        $this->hline($pB, min(array_merge($xsB, [$xt])), max(array_merge($xsB, [$xt])), $ybus);
        foreach ($xsB as $x) {
            $this->vline($x, $pB, $ybus, $pB, $yBt, true);
        }
    }

    // --------------------------------------------------------- XML bentuk

    private function shapeXml(int $did, int $z, int $x, int $y, int $cx, int $cy, string $kind, string $text = ''): string
    {
        $cxe = $cx * self::EMU;
        $cye = $cy * self::EMU;
        $xe = $x * self::EMU;
        $ye = $y * self::EMU;

        if ($kind === 'line' || $kind === 'arrow') {
            $geom = $kind === 'arrow' ? 'straightConnector1' : 'line';
            $tail = $kind === 'arrow' ? '<a:tailEnd type="triangle" w="med" len="med"/>' : '';
            $sp = sprintf(
                '<wps:cNvCnPr/><wps:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="%d" cy="%d"/></a:xfrm>'
                    . '<a:prstGeom prst="%s"><a:avLst/></a:prstGeom>'
                    . '<a:ln w="9525"%s><a:solidFill><a:srgbClr val="000000"/></a:solidFill>%s</a:ln></wps:spPr>',
                $cxe,
                $cye,
                $geom,
                $kind === 'line' ? ' cap="sq"' : '',
                $tail
            );
            $body = '<wps:bodyPr/>';
        } else {
            $prst = match ($kind) {
                'terminator' => 'roundRect',
                'decision' => 'diamond',
                'offpage' => 'flowChartOffpageConnector',
                default => 'rect', // process, label
            };
            $av = $prst === 'roundRect' ? '<a:avLst><a:gd name="adj" fmla="val 50000"/></a:avLst>' : '<a:avLst/>';
            if ($kind === 'label') {
                $fill = '<a:noFill/>';
                $ln = '<a:ln><a:noFill/></a:ln>';
            } else {
                $fill = '<a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>';
                $ln = '<a:ln w="9525"><a:solidFill><a:srgbClr val="000000"/></a:solidFill></a:ln>';
            }
            $sp = sprintf(
                '<wps:cNvSpPr/><wps:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="%d" cy="%d"/></a:xfrm>'
                    . '<a:prstGeom prst="%s">%s</a:prstGeom>%s%s</wps:spPr>',
                $cxe,
                $cye,
                $prst,
                $av,
                $fill,
                $ln
            );
            if ($text !== '') {
                $size = $kind === 'offpage' ? 14 : 16;
                $body = sprintf(
                    '<wps:txbx><w:txbxContent><w:p><w:pPr><w:spacing w:before="0" w:after="0"/><w:jc w:val="%s"/></w:pPr>'
                        . '<w:r><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="%d"/></w:rPr><w:t>%s</w:t></w:r></w:p>'
                        . '</w:txbxContent></wps:txbx><wps:bodyPr lIns="0" tIns="0" rIns="0" bIns="0" anchor="ctr"/>',
                    $kind === 'offpage' ? 'center' : 'left',
                    $size,
                    htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                );
            } else {
                $body = '<wps:bodyPr/>';
            }
        }

        return sprintf(
            '<w:r><w:drawing><wp:anchor xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
                . 'distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="%d" behindDoc="0" locked="0" '
                . 'layoutInCell="1" allowOverlap="1"><wp:simplePos x="0" y="0"/>'
                . '<wp:positionH relativeFrom="column"><wp:posOffset>%d</wp:posOffset></wp:positionH>'
                . '<wp:positionV relativeFrom="paragraph"><wp:posOffset>%d</wp:posOffset></wp:positionV>'
                . '<wp:extent cx="%d" cy="%d"/><wp:effectExtent l="0" t="0" r="0" b="0"/><wp:wrapNone/>'
                . '<wp:docPr id="%d" name="Alur %d"/><wp:cNvGraphicFramePr/>'
                . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                . '<a:graphicData uri="http://schemas.microsoft.com/office/word/2010/wordprocessingShape">'
                . '<wps:wsp xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape">%s%s</wps:wsp>'
                . '</a:graphicData></a:graphic></wp:anchor></w:drawing></w:r>',
            $z,
            $xe,
            $ye,
            $cxe,
            $cye,
            $did,
            $did,
            $sp,
            $body
        );
    }
}
