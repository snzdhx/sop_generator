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
    private const PAD = 260;         // jarak simbol ke tepi atas/bawah baris (ruang bus gabung/pisah)
    private const PAD_BUS = 650;     // jarak simbol ke tepi baris untuk baris berpola bus (gap antar simbol ~1300 = proporsi gambar Word asli)
    private const TOP_BAND = 300;   // ruang tambahan di baris pertama tiap halaman
    private const BOTTOM_BAND = 420; // ruang di bawah baris terakhir tiap halaman
    private const PAGE_H = 10640;    // tinggi konten halaman landscape 20160 x 12240 (12240 - 2 x 800)
    private const BUS_GAP = 200;    // jarak bus pembagi ke atas simbol tujuan (cukup panjang agar badan panah terlihat, bukan hanya kepala)
    private const MERGE_GAP = 260;  // jarak bus pengumpul ke bawah simbol sumber
    private const JOIN_GAP = 260;   // jarak minimum bus gabung (pola batang) ke bawah simbol sumber
    private const JOIN_FRAC = 0.27; // jarak bus gabung = 27% jarak antar simbol (seperti dokumen Word asli)
    private const FORK_FRAC = 0.33; // jarak bus pembagi ke atas simbol tujuan = 33% jarak antar simbol
    /** Dokumen asli: pada pola gabung-pisah hanya simbol terluar (kiri & kanan) yang disambung ke bus gabung. */
    private const JOIN_OUTER_ONLY = true;
    /** Lompatan garis (jembatan) di titik garis mendatar memotong garis tegak koneksi lain, seperti Word asli. */
    private const HOP_HW = 55;      // setengah lebar lompatan
    private const HOP_H = 90;       // tinggi lompatan (ke atas)
    private const HOP_MARGIN = 60;  // jarak minimum lompatan dari ujung/tekukan garis
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
    /** @var array[] garis yang dicatat dulu (page, pts, arrow, grp) lalu digambar setelah lompatan dihitung */
    private array $paths = [];
    private string $grp = '';

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
        foreach ($steps as $st) {
            $st = (array) $st;
            // Simbol ditentukan pengguna secara eksplisit; tidak ada deteksi otomatis.
            // Nilai kosong/tidak dikenal dianggap "proses" (kotak).
            $sim = (string) ($st['simbol'] ?? '');
            if (!in_array($sim, ['proses', 'mulai', 'selesai', 'keputusan'], true)) {
                $sim = 'proses';
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
        // Header "Persyaratan dan kelengkapan" ikut dihitung (sebelumnya terpotong karena baris terlalu pendek)
        $namesH = max($namesH, $this->cellTextH('Persyaratan dan kelengkapan', $this->wSyr, true));
        $namesH += 2 * self::CELL_M + 20;
        $this->headH = [430, max(430, $namesH), 360]; // baris nomor: teks 9 pt + margin sel butuh >= 350
        $this->hHead = array_sum($this->headH) + 30;

        // Baris yang terlibat pola bus (gabung -> batang -> pecah) butuh ruang vertikal lega
        // agar bus gabung, batang, dan bus pecah tidak saling menempel (seperti dokumen Word asli).
        $needBus = [];
        foreach ($this->steps as $i => $st) {
            if (!$this->hasSym($st) || $st['simbol'] === 'keputusan') {
                continue;
            }
            foreach ($this->targets($i) as $t) {
                if (count($st['pelaksana']) > 1 || count($this->steps[$t]['pelaksana']) > 1) {
                    $needBus[$i] = true;
                    $needBus[$t] = true;
                }
            }
        }

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
                if (!empty($needBus[$i])) {
                    $h = max($h, $this->symHOf($i) + 2 * self::PAD_BUS);
                }
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

    /**
     * Garis menekuk dengan kepala panah di titik terakhir. Koordinat absolut halaman (twips).
     * Garis dipotong per baris tabel dan tiap potongan dijangkarkan ke barisnya sendiri, supaya posisinya
     * tidak bergeser terhadap simbol walaupun Word menghitung tinggi baris sedikit berbeda (selisih menumpuk
     * jika satu objek dijangkarkan di baris yang jauh dari tujuannya). Sambungan antar potongan jatuh di garis tabel.
     */
    private function poly(int $page, array $pts, bool $arrow = true): void
    {
        $this->paths[] = ['page' => $page, 'pts' => $pts, 'arrow' => $arrow, 'grp' => $this->grp];
    }

    /** Hitung lompatan lalu gambar semua garis yang tercatat (urutan gambar = urutan pencatatan). */
    private function flushPaths(): void
    {
        $vert = [];
        foreach ($this->paths as $n => $pa) {
            $c = count($pa['pts']);
            for ($k = 0; $k < $c - 1; $k++) {
                [$a, $b] = [$pa['pts'][$k], $pa['pts'][$k + 1]];
                if ($a[0] === $b[0] && $a[1] !== $b[1]) {
                    $vert[$pa['page']][] = [$n, $a[0], min($a[1], $b[1]), max($a[1], $b[1])];
                }
            }
        }
        $hw = self::HOP_HW;
        $m = self::HOP_MARGIN;
        foreach ($this->paths as $n => $pa) {
            $pts = $pa['pts'];
            $out = [$pts[0]];
            for ($k = 0; $k < count($pts) - 1; $k++) {
                [$a, $b] = [$pts[$k], $pts[$k + 1]];
                if ($a[1] === $b[1] && abs($b[0] - $a[0]) >= 2 * ($hw + $m)) {
                    $y = $a[1];
                    $lo = min($a[0], $b[0]) + $hw + $m;
                    $hi = max($a[0], $b[0]) - $hw - $m;
                    $xs = [];
                    foreach ($vert[$pa['page']] ?? [] as [$j, $x, $y1, $y2]) {
                        if ($this->paths[$j]['grp'] !== $pa['grp'] && $x > $lo && $x < $hi && $y > $y1 + 30 && $y < $y2 - 30) {
                            $xs[] = $x;
                        }
                    }
                    $dir = $b[0] > $a[0] ? 1 : -1;
                    sort($xs);
                    if ($dir < 0) {
                        $xs = array_reverse($xs);
                    }
                    $last = null;
                    foreach ($xs as $x) {
                        if ($last !== null && abs($x - $last) < 2 * $hw + $m) {
                            continue;
                        }
                        $last = $x;
                        array_push($out, [$x - $dir * $hw, $y], [$x - $dir * $hw, $y - self::HOP_H], [$x + $dir * $hw, $y - self::HOP_H], [$x + $dir * $hw, $y]);
                    }
                }
                $out[] = $b;
            }
            $this->emitPoly($pa['page'], $out, $pa['arrow']);
        }
        $this->paths = [];
    }

    private function emitPoly(int $page, array $pts, bool $arrow): void
    {
        $bands = $this->bands($page);
        $nSeg = count($pts) - 1;
        foreach ($bands as [$ri, $lo, $hi]) {
            $pieces = [];
            $cur = [];
            $curArrow = false;
            for ($k = 0; $k < $nSeg; $k++) {
                $seg = $this->clipSeg($pts[$k], $pts[$k + 1], $lo, $hi);
                if ($seg === null) {
                    if ($cur) {
                        $pieces[] = [$cur, $curArrow];
                        $cur = [];
                        $curArrow = false;
                    }
                    continue;
                }
                if ($cur && $cur[count($cur) - 1] !== $seg[0]) {
                    $pieces[] = [$cur, $curArrow];
                    $cur = [];
                    $curArrow = false;
                }
                if (!$cur) {
                    $cur[] = $seg[0];
                }
                $cur[] = $seg[1];
                if ($k === $nSeg - 1 && $seg[1] === $pts[$k + 1]) {
                    $curArrow = true;
                }
            }
            if ($cur) {
                $pieces[] = [$cur, $curArrow];
            }
            foreach ($pieces as [$pp, $isLast]) {
                $this->emitPiece($page, $ri, $pp, $arrow && $isLast);
            }
        }
    }

    /** Pita baris halaman: [indeks baris, batas atas, batas bawah) ; baris pertama/terakhir diperluas tak hingga. */
    private function bands(int $page): array
    {
        $list = [];
        foreach ($this->rows as $i => $r) {
            if ($r['page'] === $page) {
                $list[] = [$i, $r['top'], $r['top'] + $r['h'] + 8];
            }
        }
        if (!$list) {
            return [[0, PHP_INT_MIN, PHP_INT_MAX]];
        }
        $list[0][1] = PHP_INT_MIN;
        $list[count($list) - 1][2] = PHP_INT_MAX;

        return $list;
    }

    /** Potong ruas lurus (sumbu X atau Y) ke pita [lo, hi). Mengembalikan [awal, akhir] atau null. */
    private function clipSeg(array $a, array $b, int $lo, int $hi): ?array
    {
        if ($a[1] === $b[1]) { // datar
            return ($a[1] >= $lo && ($a[1] < $hi || $hi === PHP_INT_MAX)) ? [$a, $b] : null;
        }
        $mn = max(min($a[1], $b[1]), $lo);
        $mx = min(max($a[1], $b[1]), $hi);
        if ($mx - $mn < 1) {
            return null;
        }
        $down = $b[1] > $a[1];

        return $down ? [[$a[0], $mn], [$a[0], $mx]] : [[$a[0], $mx], [$a[0], $mn]];
    }

    private function emitPiece(int $page, int $ri, array $pts, bool $arrow): void
    {
        $x0 = min(array_column($pts, 0));
        $y0 = min(array_column($pts, 1));
        $w = max(array_column($pts, 0)) - $x0;
        $h = max(array_column($pts, 1)) - $y0;
        $c = 0;
        $best = PHP_FLOAT_MAX;
        for ($k = 0; $k < $this->n; $k++) {
            $d = abs($this->colLeft[$k] + $this->pw[$k] / 2 - $x0);
            if ($d < $best) {
                $best = $d;
                $c = $k;
            }
        }
        $hx = (int) round($x0 - ($this->colLeft[$c] + self::CELL_M));
        $vy = (int) round($y0 - ($this->rows[$ri]['top'] + self::CELL_M));
        $this->docId++;
        $this->z++;

        if (count($pts) === 2 && $pts[0][0] === $pts[1][0] && $pts[1][1] > $pts[0][1]) {
            $kind = $arrow ? 'arrow' : 'line'; // vertikal ke bawah
            $this->shapes[$ri][$c][] = $this->shapeXml($this->docId, $this->z, $hx, $vy, 0, $h, $kind);

            return;
        }
        if (count($pts) === 2 && !$arrow) {
            $this->shapes[$ri][$c][] = $this->shapeXml($this->docId, $this->z, $hx, $vy, $w, $h, 'line');

            return;
        }
        $rel = array_map(fn($p) => [$p[0] - $x0, $p[1] - $y0], $pts);
        $this->shapes[$ri][$c][] = $this->shapeXml($this->docId, $this->z, $hx, $vy, $w, $h, $arrow ? 'poly' : 'polyline', '', $rel);
    }

    private function hline(int $page, int $x1, int $x2, int $y): void
    {
        if ($x1 > $x2) {
            [$x1, $x2] = [$x2, $x1];
        }
        if ($x2 - $x1 < 2) {
            return;
        }
        $this->poly($page, [[$x1, $y], [$x2, $y]], false);
    }

    /** Garis vertikal; jika melewati batas halaman dipecah dengan konektor halaman. */
    private function vline(int $x, int $pA, int $yA, int $pB, int $yB, bool $arrow): void
    {
        $kind = $arrow ? 'arrow' : 'line';
        if ($pA === $pB) {
            if ($yB - $yA >= 2) {
                $this->poly($pA, [[$x, $yA], [$x, $yB]], $arrow);
            }

            return;
        }
        $half = intdiv(self::CONN_W, 2);
        $yTop = $this->hHead + 20;
        // Setiap batas halaman (termasuk halaman perantara yang dilewati) diberi sepasang konektor
        for ($p = $pA; $p < $pB; $p++) {
            $from = $p === $pA ? $yA : $yTop + self::CONN_H;
            $yEnd = $this->pageBottom($p);
            if ($yEnd - $from >= 2) {
                $this->poly($p, [[$x, $from], [$x, $yEnd]], false);
            }
            $this->add($p, $x - $half, $yEnd, self::CONN_W, self::CONN_H, 'offpage');
            $this->add($p + 1, $x - $half, $yTop, self::CONN_W, self::CONN_H, 'offpage');
        }
        $yFrom = $yTop + self::CONN_H;
        if ($yB - $yFrom >= 2) {
            $this->poly($pB, [[$x, $yFrom], [$x, $yB]], $arrow);
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
            // Keputusan boleh kembali ke langkah sebelumnya (mis. Tidak -> ulangi langkah 4)
            $okDir = $t > $i || ($st['simbol'] === 'keputusan' && $t >= 0 && $t < $i);
            if ($okDir && $t < $cnt && $this->hasSym($this->steps[$t]) && !in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        // Simbol Proses: urutan pengisian tidak berpengaruh (tujuan terdekat = jalur utama, sisanya cabang).
        // Keputusan tetap mengikuti urutan input karena urutannya menentukan Ya/Tidak.
        if ($st['simbol'] !== 'keputusan') {
            sort($out);
        } else {
            // Tujuan yang maju (ke bawah) = Ya, tujuan yang kembali ke langkah sebelumnya = Tidak (ulang),
            // apa pun urutan pengisiannya. Sesama maju tetap mengikuti urutan input.
            $fw = array_values(array_filter($out, fn($x) => $x > $i));
            $bw = array_values(array_filter($out, fn($x) => $x < $i));
            $out = array_merge($fw, $bw);
        }

        return $out;
    }

    /**
     * Cabang keputusan bisa lurus: keluar dari sisi simbol sumber, mendatar ke kolom tujuan,
     * lalu turun lurus di kolom tujuan. Syarat: kolom tujuan tidak punya simbol di baris antara
     * sumber dan tujuan, dan tidak ada simbol lain di baris sumber yang terlewati garis mendatar.
     */
    private function straightOk(int $i, int $t, int $s, int $c): bool
    {
        if ($s === $c || $this->collides($c, $i, $t)) {
            return false;
        }
        $lo = min($s, $c);
        $hi = max($s, $c);
        foreach ($this->steps[$i]['pelaksana'] as $d) {
            if ($d !== $s && $d >= $lo && $d <= $hi) {
                return false;
            }
        }

        return true;
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

    /**
     * Rencana panah satu koneksi (langkah $i -> $t, satu halaman): daftar pasangan [kolom sumber, kolom tujuan].
     * Kolom yang sama -> [c, c] (masuk dari atas); sumber lain dihubungkan ke tujuan yang tidak sekolom.
     */
    private function planArrows(int $i, int $t, bool $branch): array
    {
        $SA = $this->steps[$i]['pelaksana'];
        $SB = $this->steps[$t]['pelaksana'];
        if ($branch) {
            $SA = [$SA[0]];
        }
        $nearest = function (array $cands, int $ref) {
            $best = $cands[0];
            foreach ($cands as $x) {
                if (abs($this->cx[$x] - $this->cx[$ref]) < abs($this->cx[$best] - $this->cx[$ref])) {
                    $best = $x;
                }
            }

            return $best;
        };
        $pairs = [];
        foreach ($SB as $c) {
            if (in_array($c, $SA, true)) {
                $pairs[] = [$c, $c];
            }
        }
        $needC = array_values(array_diff($SB, $SA));
        foreach (array_values(array_diff($SA, $SB)) as $s) {
            foreach ($needC ?: [$nearest($SB, $s)] as $c) {
                $pairs[] = [$s, $c];
            }
        }
        foreach ($needC as $c) {
            $has = false;
            foreach ($pairs as $p) {
                $has = $has || $p[1] === $c;
            }
            if (!$has) {
                $pairs[] = [$nearest($SA, $c), $c];
            }
        }

        return $pairs;
    }

    /**
     * Menggambar satu panah dari kolom $s (langkah $i) ke kolom $c (langkah $t) dengan titik masuk $entry:
     * 'top' (atas), 'left' (sisi kiri), 'right' (sisi kanan). Keluar selalu dari bawah simbol
     * (cabang kedua keputusan: dari sisi). Seluruh panah satu objek garis menekuk.
     */
    private function drawArrow(int $i, int $t, int $s, int $c, string $entry, bool $branch): void
    {
        $SB = $this->steps[$t]['pelaksana'];
        $pg = $this->rows[$i]['page'];
        $half = intdiv($this->symW, 2);
        $yA = $this->symBot($i);
        $yBt = $this->symTop($t);
        $ymidT = $yBt + intdiv($this->symHOf($t), 2);
        $yF = $yBt - self::BUS_GAP;
        if ($yF <= $yA + 20) {
            $yF = intdiv($yA + $yBt, 2);
        }
        $x0 = $this->cx[$s];
        $xc = $this->cx[$c];
        $lane = $this->colLeft[$s] + $this->pw[$s] - 50;
        $viaLane = false;

        // Cabang keputusan: lurus ke kolom tujuan jika kolomnya kosong (menghindari simbol bila tidak)
        $toDec = !$branch && $this->steps[$t]['simbol'] === 'keputusan' && count($this->steps[$i]['pelaksana']) === 1;
        if (($branch || $toDec) && $this->straightOk($i, $t, $s, $c)) {
            $ymidS = $this->symTop($i) + intdiv($this->symHOf($i), 2);
            $dir = $this->cx[$c] > $this->cx[$s] ? 1 : -1;
            $this->poly($pg, [
                [$x0 + $dir * $half, $ymidS],
                [$xc, $ymidS],
                [$xc, $yBt],
            ]);

            return;
        }

        if ($branch) {
            $ymidS = $this->symTop($i) + intdiv($this->symHOf($i), 2);
            $pts = [[$x0 + $half, $ymidS], [$lane, $ymidS]];
            $xv = $lane;
            $viaLane = true;
        } elseif ($this->collides($s, $i, $t)) {
            $yl = $yA + self::MERGE_GAP;
            $pts = [[$x0, $yA], [$x0, $yl], [$lane, $yl]];
            $xv = $lane;
            $viaLane = true;
        } else {
            $pts = [[$x0, $yA]];
            $xv = $x0;
        }

        // simbol tujuan lain di antara sumber dan tujuan (baris tujuan) menghalangi jalur datar
        $blocked = false;
        foreach ($SB as $d) {
            if ($d !== $c && $d > min($s, $c) && $d < max($s, $c)) {
                $blocked = true;
            }
        }
        if (!$viaLane && in_array($s, $SB, true) && $s !== $c) {
            $blocked = true; // garis turun dari sumber menabrak simbol tujuan di kolomnya
        }

        if ($entry === 'top') {
            if ($xv === $xc) {
                $pts[] = [$xc, $yBt];
            } else {
                array_push($pts, [$xv, $yF], [$xc, $yF], [$xc, $yBt]);
            }
        } elseif ($entry === 'left') {
            $edge = $xc - $half;
            if (!$blocked && $xv < $edge) {
                array_push($pts, [$xv, $ymidT], [$edge, $ymidT]);
            } else {
                $ln = $edge - 50;
                array_push($pts, [$xv, $yF], [$ln, $yF], [$ln, $ymidT], [$edge, $ymidT]);
            }
        } else {
            $edge = $xc + $half;
            if (!$blocked && $xv > $edge) {
                array_push($pts, [$xv, $ymidT], [$edge, $ymidT]);
            } else {
                $ln = $edge + 50;
                array_push($pts, [$xv, $yF], [$ln, $yF], [$ln, $ymidT], [$edge, $ymidT]);
            }
        }

        if (count($pts) === 2 && $pts[0][0] === $pts[1][0]) {
            $this->vline($pts[0][0], $pg, $pts[0][1], $pg, $pts[1][1], true);

            return;
        }
        $this->poly($pg, $pts);
    }

    /**
     * Cabang keputusan yang kembali ke langkah sebelumnya (seperti Word asli): keluar dari sisi kanan simbol,
     * naik lewat jalur di tepi kolom paling kanan (tembus konektor halaman bila beda halaman),
     * lalu masuk ke sisi kanan simbol tujuan.
     */
    private function drawBack(int $i, int $t, int $k): void
    {
        $s = $this->steps[$i]['pelaksana'][0];
        $c = max($this->steps[$t]['pelaksana']);
        $half = intdiv($this->symW, 2);
        $pA = $this->rows[$i]['page'];
        $pB = $this->rows[$t]['page'];
        $ymidS = $this->symTop($i) + intdiv($this->symHOf($i), 2);
        $ymidT = $this->symTop($t) + intdiv($this->symHOf($t), 2);
        $lane = $this->colLeft[$this->n - 1] + $this->pw[$this->n - 1] - 50;
        $edge = $this->cx[$c] + $half;
        $x0 = $this->cx[$s] + $half;

        if ($pA === $pB) {
            $this->poly($pA, [[$x0, $ymidS], [$lane, $ymidS], [$lane, $ymidT], [$edge, $ymidT]]);
        } else {
            $this->poly($pA, [[$x0, $ymidS], [$lane, $ymidS]], false);
            $half2 = intdiv(self::CONN_W, 2);
            $yTop = $this->hHead + 20;
            for ($p = $pA; $p > $pB; $p--) {
                $from = $p === $pA ? $ymidS : $this->pageBottom($p);
                $to = $yTop + self::CONN_H;
                if ($from - $to >= 2) {
                    $this->poly($p, [[$lane, $from], [$lane, $to]], false);
                }
                $this->add($p, $lane - $half2, $yTop, self::CONN_W, self::CONN_H, 'offpage');
                $this->add($p - 1, $lane - $half2, $this->pageBottom($p - 1), self::CONN_W, self::CONN_H, 'offpage');
            }
            $this->poly($pB, [[$lane, $this->pageBottom($pB)], [$lane, $ymidT], [$edge, $ymidT]]);
        }
        if ($this->steps[$i]['simbol'] === 'keputusan' && $k >= 1) {
            $this->add($pA, $this->cx[$s] + intdiv($this->symW, 4) + 40, $ymidS - 360, 420, 200, 'label', 'Tidak');
        }
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

        // 1) Kumpulkan semua panah. Lintas halaman tetap memakai rute lama (masuk dari atas -> atas dianggap terpakai).
        $arrows = [];
        $old = [];
        $labels = [];
        $back = [];
        $taken = [];
        foreach ($this->steps as $i => $st) {
            if (!$this->hasSym($st)) {
                continue;
            }
            $tg = $this->targets($i);
            foreach ($tg as $k => $t) {
                if ($t < $i) {
                    $back[] = [$i, $t, $k];
                    continue;
                }
                // Output kedua dst. dari simbol mana pun (keputusan maupun proses dengan 2+ tujuan) keluar dari sisi simbol.
                $branch = $k >= 1 && ($st['simbol'] === 'keputusan' || count($tg) >= 2);
                // Pola bus (gabung -> batang -> pecah) dipertahankan untuk banyak simbol sumber/tujuan dan lintas halaman;
                // aturan masuk samping/atas per panah hanya untuk satu sumber -> satu tujuan serta cabang keputusan.
                $useBus = !$branch && (count($st['pelaksana']) > 1 || count($this->steps[$t]['pelaksana']) > 1);
                if ($useBus || $this->rows[$i]['page'] !== $this->rows[$t]['page']) {
                    $old[] = [$i, $t, $branch, $k, count($tg)];
                    foreach ($this->steps[$t]['pelaksana'] as $c) {
                        $taken[$t][$c]['top'] = true;
                    }
                    continue;
                }
                $labels[] = [$i, $t, $branch, $k, count($tg)];
                foreach ($this->planArrows($i, $t, $branch) as [$s, $c]) {
                    $arrows[] = ['i' => $i, 't' => $t, 's' => $s, 'c' => $c, 'branch' => $branch, 'entry' => 'top'];
                }
            }
        }

        // 2) Tentukan titik masuk per simbol tujuan: beda kolom -> samping, sekolom -> atas;
        //    jika satu simbol menerima >1 panah, tiap panah harus masuk dari titik yang berbeda.
        $groups = [];
        foreach ($arrows as $n => $a) {
            $groups[$a['t']][$a['c']][] = $n;
        }
        foreach ($groups as $t => $byCol) {
            foreach ($byCol as $c => $idxs) {
                usort($idxs, function ($p, $q) use ($arrows, $c) {
                    $fp = $arrows[$p]['s'] === $c ? 0 : 1;
                    $fq = $arrows[$q]['s'] === $c ? 0 : 1;
                    if ($fp !== $fq) {
                        return $fp <=> $fq;
                    }

                    return abs($this->cx[$arrows[$p]['s']] - $this->cx[$c]) <=> abs($this->cx[$arrows[$q]['s']] - $this->cx[$c]);
                });
                foreach ($idxs as $n) {
                    $s = $arrows[$n]['s'];
                    if ($s === $c) {
                        $cands = ['top'];
                    } elseif ($arrows[$n]['branch'] && $this->straightOk($arrows[$n]['i'], $t, $s, $c)) {
                        $cands = ['top']; // cabang lurus selalu masuk dari atas
                    } elseif ($this->steps[$t]['simbol'] === 'keputusan') {
                        $cands = ['top']; // masuk ke belah ketupat selalu dari atas; sisi kiri/kanan khusus jalur keluar (Tidak)
                    } else {
                        $pref = $this->cx[$c] > $this->cx[$s] ? 'left' : 'right';
                        $cands = [$pref, 'top', $pref === 'left' ? 'right' : 'left'];
                    }
                    $pick = $cands[0];
                    foreach ($cands as $e) {
                        if (empty($taken[$t][$c][$e])) {
                            $pick = $e;
                            break;
                        }
                    }
                    $taken[$t][$c][$pick] = true;
                    $arrows[$n]['entry'] = $pick;
                }
            }
        }

        // 3) Gambar
        foreach ($arrows as $a) {
            $this->grp = $a['i'] . '>' . $a['t'];
            $this->drawArrow($a['i'], $a['t'], $a['s'], $a['c'], $a['entry'], $a['branch']);
        }
        foreach ($labels as [$i, $t, $branch, $k, $ntg]) {
            if ($this->steps[$i]['simbol'] !== 'keputusan' || $ntg < 2) {
                continue;
            }
            $s0 = $this->steps[$i]['pelaksana'][0];
            if ($branch) {
                $ymidS = $this->symTop($i) + intdiv($this->symHOf($i), 2);
                $c0 = $this->steps[$t]['pelaksana'][0];
                $left = $this->straightOk($i, $t, $s0, $c0) && $this->cx[$c0] < $this->cx[$s0];
                $lx = $left
                    ? $this->cx[$s0] - intdiv($this->symW, 4) - 460
                    : $this->cx[$s0] + intdiv($this->symW, 4) + 40;
                $this->add($this->rows[$i]['page'], $lx, $ymidS - 360, 420, 200, 'label', 'Tidak');
            } elseif ($k === 0) {
                $this->add($this->rows[$i]['page'], $this->cx[$s0] + 40, $this->symBot($i) + 10, 420, 200, 'label', 'Ya');
            }
        }
        foreach ($old as [$i, $t, $branch, $k, $ntg]) {
            $this->grp = $i . '>' . $t;
            if (!$branch && $this->trunk($i, $t, $ntg)) {
                continue;
            }
            $this->connect($i, $t, $branch, $k, $ntg);
        }
        foreach ($back as [$i, $t, $k]) {
            $this->grp = $i . '>' . $t;
            $this->drawBack($i, $t, $k);
        }
        $this->grp = '';
        $this->flushPaths();
    }

    /**
     * Pola gabung-pisah dengan satu batang (seperti Word asli).
     *
     * Pola dokumen asli (tujuan > 1 simbol, sumber bukan keputusan):
     *  - batang berada di TITIK TENGAH simbol-simbol terluar (bukan di bawah salah satu kotak),
     *  - sumber tunggal keluar dari sisi simbolnya, menekuk horizontal ke batang, lalu turun,
     *  - sumber banyak: hanya simbol paling kiri & kanan yang turun ke bus gabung,
     *  - batang berujung panah di bus pembagi, bus pembagi memanah ke tiap kotak tujuan.
     * Kasus lain (tujuan tunggal / sumber keputusan) memakai pola lama.
     * Mengembalikan false jika batang bertabrakan dengan simbol lain (dipakai rute jalur samping).
     */
    private function trunk(int $i, int $t, int $ntg): bool
    {
        $st = $this->steps[$i];
        $SA = $st['pelaksana'];
        $SB = $this->steps[$t]['pelaksana'];
        $pA = $this->rows[$i]['page'];
        $pB = $this->rows[$t]['page'];
        $yA = $this->symBot($i);
        $yBt = $this->symTop($t);
        $isDec = $st['simbol'] === 'keputusan';
        $nA = count($SA);
        $nB = count($SB);

        // Jarak bus proporsional terhadap jarak antar simbol (sama dengan proporsi di Word asli)
        $gapJ = self::JOIN_GAP;
        $gapF = self::BUS_GAP;
        if ($pA === $pB && $yBt > $yA) {
            $span = $yBt - $yA;
            $gapJ = max(self::JOIN_GAP, min(420, (int) round($span * self::JOIN_FRAC)));
            $gapF = max(self::BUS_GAP, min(500, (int) round($span * self::FORK_FRAC)));
        }
        $yJoin = $yA + $gapJ;
        $yFork = $yBt - $gapF;

        // ---------- Pola Word asli: banyak tujuan ----------
        if (!$isDec && $nB > 1) {
            $xsA = array_map(fn($c) => $this->cx[$c], $SA);
            $xsB = array_map(fn($c) => $this->cx[$c], $SB);
            // Panah ke bus pembagi selalu di tengah kotak-kotak tujuan (bukan tengah gabungan sumber + tujuan)
            $tx = intdiv(min($xsB) + max($xsB), 2);
            if ($nB % 2 === 1) {
                // Cabang ganjil (mis. 3): panah masuk bus tepat di tengah, segaris dengan kotak tengah
                $mid = $xsB[intdiv($nB, 2)];
                if (abs($mid - $tx) <= intdiv($this->symW, 2)) {
                    $tx = $mid;
                }
            }
            if ($this->collidesX($tx, $i, $t)) {
                return false;
            }

            if ($nA > 1) {
                $join = $nA > 1 ? (self::JOIN_OUTER_ONLY ? [min($xsA), max($xsA)] : $xsA) : [];
                foreach (array_unique($join) as $x) {
                    $this->vline($x, $pA, $yA, $pA, $yJoin, false);
                }
                $this->hline($pA, min(array_merge($xsA, [$tx])), max(array_merge($xsA, [$tx])), $yJoin);
                $y0 = $yJoin;
            } else {
                $sx = $xsA[0];
                $half = intdiv($this->symW, 2);
                if (abs($sx - $tx) <= $half - 50) {
                    $y0 = $yA;                      // batang masih di bawah simbol sumber
                } else {
                    // Output tunggal selalu keluar dari bawah simbol, lalu menekuk ke batang
                    $this->vline($sx, $pA, $yA, $pA, $yJoin, false);
                    $this->hline($pA, $sx, $tx, $yJoin);
                    $y0 = $yJoin;
                }
            }

            $this->vline($tx, $pA, $y0, $pB, $yFork, true); // panah di tengah menuju bus pembagi
            $this->hline($pB, min(array_merge($xsB, [$tx])), max(array_merge($xsB, [$tx])), $yFork);
            foreach ($xsB as $x) {
                $this->vline($x, $pB, $yFork, $pB, $yBt, true);
            }

            return true;
        }

        // ---------- Pola lama: tujuan tunggal / sumber keputusan ----------
        if ($nA === 1) {
            $tc = $SA[0];
        } elseif ($nB === 1) {
            $tc = $SB[0];
        } else {
            $all = array_merge($SA, $SB);
            $sum = 0;
            foreach ($all as $c) {
                $sum += $this->cx[$c];
            }
            $mean = $sum / count($all);
            $common = array_values(array_intersect($SA, $SB)) ?: $SA;
            $tc = $common[0];
            $best = PHP_FLOAT_MAX;
            foreach ($common as $c) {
                $d = abs($this->cx[$c] - $mean);
                if ($d < $best) {
                    $best = $d;
                    $tc = $c;
                }
            }
        }
        if ($this->collides($tc, $i, $t)) {
            return false;
        }

        $tx = $this->cx[$tc];
        $join = $nA > 1 || $tx !== $this->cx[$SA[0]];
        $fork = $nB > 1 || $tx !== $this->cx[$SB[0]];

        // Satu sumber -> satu tujuan beda kolom, satu halaman: satu garis menekuk (badan + tekukan + panah menyatu)
        if ($fork && !$join && $nA === 1 && $nB === 1 && $pA === $pB && $yFork > $yA && $yBt > $yFork) {
            $this->poly($pA, [
                [$this->cx[$SA[0]], $yA],
                [$this->cx[$SA[0]], $yFork],
                [$this->cx[$SB[0]], $yFork],
                [$this->cx[$SB[0]], $yBt],
            ]);
            if ($isDec && $ntg > 1) {
                $this->add($pA, $this->cx[$SA[0]] + 40, $yA + 10, 420, 200, 'label', 'Ya');
            }

            return true;
        }

        $y0 = $yA;
        if ($join) {
            $xsA = [$tx];
            foreach ($SA as $s) {
                $this->vline($this->cx[$s], $pA, $yA, $pA, $yJoin, false);
                $xsA[] = $this->cx[$s];
            }
            $this->hline($pA, min($xsA), max($xsA), $yJoin);
            $y0 = $yJoin;
        }
        if ($fork) {
            $this->vline($tx, $pA, $y0, $pB, $yFork, $nB > 1); // panah ke bus hanya jika bercabang ke banyak kotak; tujuan tunggal: satu panah di kotak
            $xsB = [$tx];
            foreach ($SB as $c) {
                $xsB[] = $this->cx[$c];
            }
            $this->hline($pB, min($xsB), max($xsB), $yFork);
            foreach ($SB as $c) {
                $this->vline($this->cx[$c], $pB, $yFork, $pB, $yBt, true);
            }
        } else {
            $this->vline($tx, $pA, $y0, $pB, $yBt, true);
        }
        if ($isDec && $ntg > 1) {
            $this->add($pA, $this->cx[$SA[0]] + 40, $yA + 10, 420, 200, 'label', 'Ya');
        }

        return true;
    }

    /** Apakah garis vertikal di posisi X melewati simbol pada baris di antara $i dan $t. */
    private function collidesX(int $x, int $i, int $t): bool
    {
        for ($r = $i + 1; $r < $t; $r++) {
            foreach ($this->steps[$r]['pelaksana'] as $c) {
                if (abs($this->cx[$c] - $x) < intdiv($this->symW, 2) + 60) {
                    return true;
                }
            }
        }

        return false;
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

    private function connect(int $i, int $t, bool $side, int $label, int $ntg = 1): void
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

        // Cabang keputusan lintas halaman: lurus di kolom tujuan bila kosong (tembus konektor halaman)
        if ($side && count($SB) === 1 && $this->straightOk($i, $t, $SA[0], $SB[0])) {
            $s = $SA[0];
            $c = $SB[0];
            $half = intdiv($this->symW, 2);
            $dir = $this->cx[$c] > $this->cx[$s] ? 1 : -1;
            $ymid = $this->symTop($i) + intdiv($this->symHOf($i), 2);
            $this->hline($pA, $this->cx[$s] + $dir * $half, $this->cx[$c], $ymid);
            $this->vline($this->cx[$c], $pA, $ymid, $pB, $yBt, true);
            if ($isDec && $label >= 1) {
                $lx = $dir > 0
                    ? $this->cx[$s] + intdiv($this->symW, 4) + 40
                    : $this->cx[$s] - intdiv($this->symW, 4) - 460;
                $this->add($pA, $lx, $ymid - 360, 420, 200, 'label', 'Tidak');
            }

            return;
        }

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
            $lane = $this->colLeft[$s] + $this->pw[$s] - 50;
            if ($side) {
                // Output kedua dst. (cabang keputusan): boleh keluar dari sisi simbol
                $sx = $this->cx[$s] + intdiv($this->symW, 2);
                $ymid = $this->symTop($i) + intdiv($this->symHOf($i), 2);
                $this->hline($pA, $sx, $lane, $ymid);
                $this->vline($lane, $pA, $ymid, $pB, $ybus, false);
            } else {
                // Output tunggal: selalu mulai dari bawah simbol, lalu bergeser ke jalur samping
                $ymid = $yA + self::MERGE_GAP;
                $this->vline($this->cx[$s], $pA, $yA, $pA, $ymid, false);
                $this->hline($pA, $this->cx[$s], $lane, $ymid);
                $this->vline($lane, $pA, $ymid, $pB, $ybus, false);
            }
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
        if ($isDec && $label === 0 && !$side && $ntg > 1) {
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

    private function shapeXml(int $did, int $z, int $x, int $y, int $cx, int $cy, string $kind, string $text = '', array $pts = []): string
    {
        $cxe = $cx * self::EMU;
        $cye = $cy * self::EMU;
        $xe = $x * self::EMU;
        $ye = $y * self::EMU;

        if ($kind === 'poly' || $kind === 'polyline') {
            // Satu garis bebas (custGeom) yang menekuk; kepala panah di ujung akhir.
            $path = '';
            foreach ($pts as $k => [$px, $py]) {
                $pt = sprintf('<a:pt x="%d" y="%d"/>', $px * self::EMU, $py * self::EMU);
                $path .= $k === 0 ? "<a:moveTo>{$pt}</a:moveTo>" : "<a:lnTo>{$pt}</a:lnTo>";
            }
            $sp = sprintf(
                '<wps:cNvSpPr/><wps:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="%d" cy="%d"/></a:xfrm>'
                    . '<a:custGeom><a:avLst/><a:gdLst/><a:ahLst/><a:cxnLst/><a:rect l="0" t="0" r="r" b="b"/>'
                    . '<a:pathLst><a:path w="%d" h="%d" fill="none">%s</a:path></a:pathLst></a:custGeom>'
                    . '<a:noFill/><a:ln w="19050"><a:solidFill><a:srgbClr val="000000"/></a:solidFill>'
                    . '<a:miter lim="800000"/>%s</a:ln></wps:spPr>',
                $cxe,
                $cye,
                max(1, $cxe),
                max(1, $cye),
                $path,
                $kind === 'poly' ? '<a:tailEnd type="triangle" w="med" len="med"/>' : ''
            );
            $body = '<wps:bodyPr/>';
        } elseif ($kind === 'line' || $kind === 'arrow') {
            $geom = $kind === 'arrow' ? 'straightConnector1' : 'line';
            $tail = $kind === 'arrow' ? '<a:tailEnd type="triangle" w="med" len="med"/>' : '';
            $sp = sprintf(
                '<wps:cNvCnPr/><wps:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="%d" cy="%d"/></a:xfrm>'
                    . '<a:prstGeom prst="%s"><a:avLst/></a:prstGeom>'
                    . '<a:ln w="19050"%s><a:solidFill><a:srgbClr val="000000"/></a:solidFill>%s</a:ln></wps:spPr>',
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
                $ln = '<a:ln w="19050"><a:solidFill><a:srgbClr val="000000"/></a:solidFill></a:ln>';
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
