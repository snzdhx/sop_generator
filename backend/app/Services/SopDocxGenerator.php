<?php

namespace App\Services;

use App\Models\Sop;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Membuat file .docx SOP dari data model Sop.
 * Semua halaman (header + informasi, prosedur): landscape 20160 x 12240 twips (sama dengan dokumen asli).
 */
class SopDocxGenerator
{
    private const TOTAL_PORTRAIT  = 18715; // lebar konten halaman informasi (sama dengan halaman prosedur, twips)
    private const TOTAL_LANDSCAPE = 18715; // sama dengan tabel prosedur dokumen asli // lebar konten tabel prosedur (A4 landscape, twips)
    private const FONT  = ['name' => 'Arial', 'size' => 10];
    private const GRAY  = 'D9D9D9';
    /** Jabatan pada tabel Paraf Hirarki di header (sesuai dokumen asli). */
    private const PARAF_JABATAN = ['Sekretaris BKPSDM', 'Ka. Sub Bag. Umum dan Kepegawaian'];

    public function generate(Sop $sop): string
    {
        // Wajib: supaya karakter seperti & < > di data tidak merusak XML docx
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord();
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(10);
        $word->setDefaultParagraphStyle(['spaceAfter' => 0, 'spaceBefore' => 0]);

        // Section 1: header + informasi. Ukuran kertas & margin sama dengan halaman prosedur.
        $portrait = $word->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => 20160,
            'pageSizeH' => 12240,
            'marginTop' => 800,
            'marginBottom' => 800,
            'marginLeft' => 284,
            'marginRight' => 740,
        ]);
        $this->header($portrait, $sop);
        $portrait->addText('', ['size' => 4]);
        $this->info($portrait, $sop);

        // Section 2: landscape (otomatis mulai halaman baru)
        $landscape = $word->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => 20160,
            'pageSizeH' => 12240,
            'marginTop' => 800,
            'marginBottom' => 800,
            'marginLeft' => 284,
            'marginRight' => 740,
        ]);
        $markers = $this->flowchart($landscape, $sop);

        $path = sys_get_temp_dir() . '/' . Str::uuid() . '.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        $this->postProcess($path, $markers);

        return $path;
    }

    /**
     * PHPWord tidak bisa menggambar bentuk/panah. Sel pelaksana diberi penanda teks (@@SHP_baris_kolom@@),
     * lalu setelah file disimpan penanda itu diganti dengan XML bentuk DrawingML hasil FlowchartLayout.
     */
    private function postProcess(string $path, array $markers): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return;
        }
        $xml = $zip->getFromName('word/document.xml');
        if (is_string($xml)) {
            // PHPWord menulis firstLine="0" bersama hanging="..." (saling bertentangan) sehingga
            // hanging indent daftar bernomor tidak berlaku; buang firstLine agar baris lanjutan rata di bawah teks.
            $out = preg_replace('#\s+w:firstLine="0"(\s+w:hanging="\d+")#', '$1', $xml) ?? $xml;
            if ($markers) {
                $out = preg_replace_callback(
                    '#<w:r>(?:(?!</w:r>).)*?(@@SHP_\d+_\d+@@)(?:(?!</w:r>).)*?</w:r>#s',
                    fn($m) => $markers[$m[1]] ?? '',
                    $out
                ) ?? $out;
            }
            $zip->addFromString('word/document.xml', $out);
        }
        $zip->close();
    }

    // ---------- Tabel 1: header ----------
    private function header($section, Sop $sop): void
    {
        $table = $this->table($section, self::TOTAL_PORTRAIT);
        $colL = 8000;
        $colLabel = 2200;
        $colVal = self::TOTAL_PORTRAIT - $colL - $colLabel;

        $fmt = fn($d) => $d ? $d->locale('id')->translatedFormat('d F Y') : '';

        $rows = [
            ['Nomor SOP', $sop->nomor_sop ?? ''],
            ['Tanggal Pembuatan', $fmt($sop->tanggal_pembuatan)],
            ['Tanggal Revisi', $fmt($sop->tanggal_revisi)],
            ['Tanggal Efektif', $fmt($sop->tanggal_efektif)],
            ['Disahkan oleh', '__PENGESAH__'],
            ['Nama SOP:', $sop->nama_sop],
        ];

        foreach ($rows as $i => [$label, $value]) {
            $table->addRow($label === 'Disahkan oleh' ? 1700 : 400, ['cantSplit' => true]);

            if ($i === 0) {
                $c = $table->addCell($colL, $this->cell(['vMerge' => 'restart', 'valign' => 'center']));
                $logo = resource_path('sop/logo.png');
                if (is_file($logo)) {
                    $c->addImage($logo, ['width' => 56, 'height' => 92, 'alignment' => Jc::CENTER]);
                }
                $this->parafHirarki($c, $sop->instansi, $colL - 200);
            } else {
                $table->addCell($colL, $this->cell(['vMerge' => 'continue']));
            }

            $this->p($table->addCell($colLabel, $this->cell()), $label);

            $vc = $table->addCell($colVal, $this->cell());
            if ($value === '__PENGESAH__') {
                $this->p($vc, $sop->pengesah_jabatan ?? '', [], Jc::CENTER);
                $vc->addTextBreak(3);
                $this->p($vc, $sop->pengesah_nama ?? '', ['underline' => 'single'], Jc::CENTER);
                if ($sop->pengesah_nip) {
                    $this->p($vc, 'NIP. ' . $sop->pengesah_nip, [], Jc::CENTER);
                }
            } else {
                $this->p($vc, $value, $label === 'Nama SOP:' ? ['bold' => true] : []);
            }
        }
    }

    /**
     * Blok di sel kiri header: nama instansi (kiri) dan tabel "Paraf Hirarki" (kanan), seperti dokumen Word asli.
     * Satu tabel bersarang; sel nama instansi tanpa garis dan digabung vertikal di sepanjang tabel paraf.
     */
    private function parafHirarki($cell, string $instansi, int $width): void
    {
        $wJab = 1800;
        $wParaf = 650;
        $wTgl = 800;
        $wParafTotal = $wJab + $wParaf + $wTgl;
        $wText = $width - $wParafTotal;
        $f = ['name' => 'Arial', 'size' => 8];

        $t = $cell->addTable([
            'cellMargin' => 40,
            'layout'     => TableStyle::LAYOUT_FIXED,
            'unit'       => TblWidth::TWIP,
            'width'      => $width,
        ]);

        $text = fn(bool $first) => $t->addCell($wText, $first ? ['vMerge' => 'restart', 'valign' => 'top'] : ['vMerge' => 'continue']);

        $t->addRow(250, ['cantSplit' => true]);
        $c = $text(true);
        $c->addText($instansi, ['name' => 'Arial', 'size' => 10], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
        $this->p($t->addCell($wParafTotal, $this->cell(['gridSpan' => 3, 'valign' => 'center'])), 'Paraf Hirarki', $f + ['bold' => true], Jc::CENTER);

        $t->addRow(250, ['cantSplit' => true]);
        $text(false);
        foreach ([[$wJab, 'Jabatan'], [$wParaf, 'Paraf'], [$wTgl, 'Tanggal']] as [$w, $label]) {
            $this->p($t->addCell($w, $this->cell(['valign' => 'center'])), $label, $f, Jc::CENTER);
        }

        foreach (self::PARAF_JABATAN as $jabatan) {
            $t->addRow(400, ['cantSplit' => true]);
            $text(false);
            $this->p($t->addCell($wJab, $this->cell(['valign' => 'center'])), $jabatan, $f);
            $this->p($t->addCell($wParaf, $this->cell()), '', $f);
            $this->p($t->addCell($wTgl, $this->cell()), '', $f);
        }
    }

    // ---------- Tabel 2: dasar hukum, kualifikasi, dst ----------
    private function info($section, Sop $sop): void
    {
        $table = $this->table($section, self::TOTAL_PORTRAIT);
        $half = intdiv(self::TOTAL_PORTRAIT, 2);

        $pairs = [
            [['Dasar Hukum:', $sop->dasar_hukum], ['Kualifikasi Pelaksana:', $sop->kualifikasi]],
            [['Keterkaitan:', $sop->keterkaitan], ['Peralatan / Perlengkapan:', $sop->peralatan]],
            [['Peringatan:', $sop->peringatan], ['Pencatatan dan Pendataan:', $sop->pencatatan]],
        ];

        foreach ($pairs as $pair) {
            $table->addRow(null, ['cantSplit' => true]);
            foreach ($pair as [$label]) {
                $this->p($table->addCell($half, $this->cell()), $label, ['bold' => true]);
            }

            $table->addRow(1000, ['cantSplit' => true]);
            foreach ($pair as [, $value]) {
                $this->fill($table->addCell($half, $this->cell()), $value);
            }
        }
    }

    // ---------- Tabel 3: flowchart / prosedur ----------
    /**
     * @return array<string,string> peta penanda => XML bentuk, untuk diinjeksikan setelah file disimpan
     */
    private function flowchart($section, Sop $sop): array
    {
        $pelaksana = array_values($sop->pelaksana ?? []);
        if (!$pelaksana) {
            $pelaksana = ['Pelaksana'];
        }
        $steps = array_values($sop->steps ?? []);

        // Tinggi baris, pembagian halaman, dan bentuk/panah dihitung di FlowchartLayout
        $L = new FlowchartLayout($pelaksana, $steps, self::TOTAL_LANDSCAPE);
        $n = $L->n;
        [$wNo, $wKeg, $wSyr, $wWkt, $wOut, $wKet] = [$L->wNo, $L->wKeg, $L->wSyr, $L->wWkt, $L->wOut, $L->wKet];
        $pw = $L->pw;
        $wMutu = $wSyr + $wWkt + $wOut + $wKet;

        $head = $this->cell(['bgColor' => self::GRAY, 'valign' => 'center']);
        $bold = ['bold' => true];

        // Satu tabel per halaman (header ditulis ulang di tiap tabel). Pemisah halaman memakai paragraf
        // "page break before" di LUAR tabel, karena LibreOffice mengabaikan page break di dalam sel tabel
        // sehingga pembagian halaman hasil FlowchartLayout tidak diikuti.
        $header = function ($table) use ($L, $n, $pw, $wNo, $wKeg, $wSyr, $wWkt, $wOut, $wKet, $wMutu, $pelaksana, $head, $bold) {
        // Baris header 1
        $table->addRow($L->headH[0], ['tblHeader' => true, 'cantSplit' => true, 'exactHeight' => true]);
        $this->p($table->addCell($wNo, $this->cell(['bgColor' => self::GRAY, 'valign' => 'center', 'vMerge' => 'restart'])), 'No', $bold, Jc::CENTER);
        $this->p($table->addCell($wKeg, $this->cell(['bgColor' => self::GRAY, 'valign' => 'center', 'vMerge' => 'restart'])), 'Kegiatan', $bold, Jc::CENTER);
        $this->p($table->addCell(array_sum($pw), $this->cell(['bgColor' => self::GRAY, 'valign' => 'center', 'gridSpan' => $n])), 'Pelaksana', $bold, Jc::CENTER);
        $this->p($table->addCell($wMutu, $this->cell(['bgColor' => self::GRAY, 'valign' => 'center', 'gridSpan' => 4])), 'Mutu Baku', $bold, Jc::CENTER);

        // Baris header 2
        $table->addRow($L->headH[1], ['tblHeader' => true, 'cantSplit' => true, 'exactHeight' => true]);
        $table->addCell($wNo, $this->cell(['vMerge' => 'continue', 'bgColor' => self::GRAY]));
        $table->addCell($wKeg, $this->cell(['vMerge' => 'continue', 'bgColor' => self::GRAY]));
        foreach ($pelaksana as $k => $name) {
            $this->p($table->addCell($pw[$k], $head), $name, $bold, Jc::CENTER);
        }
        foreach ([[$wSyr, 'Persyaratan dan kelengkapan'], [$wWkt, 'Waktu'], [$wOut, 'Output'], [$wKet, 'Keterangan']] as [$w, $t]) {
            $this->p($table->addCell($w, $head), $t, $bold, Jc::CENTER);
        }

        // Baris nomor kolom (1 2 3 ...)
        $table->addRow($L->headH[2], ['tblHeader' => true, 'cantSplit' => true, 'exactHeight' => true]);
        $widths = array_merge([$wNo, $wKeg], $pw, [$wSyr, $wWkt, $wOut, $wKet]);
        foreach ($widths as $i => $w) {
            $this->p($table->addCell($w, $this->cell(['bgColor' => self::GRAY, 'valign' => 'center'])), (string) ($i + 1), ['size' => 9], Jc::CENTER);
        }
        };

        $table = $this->table($section, self::TOTAL_LANDSCAPE);
        $header($table);

        // Baris langkah
        $markers = [];
        foreach ($steps as $i => $step) {
            $r = $L->rows[$i];
            if ($r['brk']) {
                $section->addText('', ['name' => 'Arial', 'size' => 1], ['pageBreakBefore' => true, 'spaceBefore' => 0, 'spaceAfter' => 0]);
                $table = $this->table($section, self::TOTAL_LANDSCAPE);
                $header($table);
            }
            // Tinggi baris dikunci EXACT sesuai hitungan FlowchartLayout, supaya posisi simbol/panah
            // (offset absolut) tidak bergeser kalau Word/LibreOffice membungkus teks sedikit berbeda.
            $table->addRow($r['h'], ['cantSplit' => true, 'exactHeight' => true]);

            $this->p(
                $table->addCell($wNo, $this->cell(['valign' => 'center'])),
                ($i + 1) . '.',
                [],
                Jc::CENTER
            );
            $this->fill($table->addCell($wKeg, $this->cell(['valign' => 'center'])), $step['kegiatan'] ?? '', Jc::BOTH);

            // Sel pelaksana: rata atas, berisi satu paragraf penanda sebagai jangkar bentuk
            for ($k = 0; $k < $n; $k++) {
                $c = $table->addCell($pw[$k], $this->cell(['valign' => 'top']));
                if (!empty($L->shapes[$i][$k])) {
                    $key = '@@SHP_' . $i . '_' . $k . '@@';
                    $c->addText($key, ['name' => 'Arial', 'size' => 2], ['spaceAfter' => 0, 'spaceBefore' => 0]);
                    $markers[$key] = implode('', $L->shapes[$i][$k]);
                } else {
                    $this->p($c, '');
                }
            }

            $this->fill($table->addCell($wSyr, $this->cell(['valign' => 'center'])), $step['persyaratan'] ?? '');
            $this->p($table->addCell($wWkt, $this->cell(['valign' => 'center'])), $step['waktu'] ?? '', [], Jc::CENTER);
            $this->fill($table->addCell($wOut, $this->cell(['valign' => 'center'])), $step['output'] ?? '');
            $this->fill($table->addCell($wKet, $this->cell(['valign' => 'center'])), $step['keterangan'] ?? '');
        }

        return $markers;
    }

    // ---------- Helper ----------
    private function table($section, int $width)
    {
        return $section->addTable([
            'borderSize'  => 6,
            'borderColor' => '000000',
            'cellMargin'  => 70,
            'layout'      => TableStyle::LAYOUT_FIXED,
            'unit'        => TblWidth::TWIP,
            'width'       => $width,
        ]);
    }

    private function cell(array $extra = []): array
    {
        return array_merge(['borderSize' => 6, 'borderColor' => '000000'], $extra);
    }

    /** Satu paragraf teks. */
    private function p($cell, string $text, array $font = [], string $align = Jc::LEFT, array $para = []): void
    {
        $cell->addText($text, array_merge(self::FONT, $font), array_merge(['alignment' => $align, 'spaceAfter' => 0], $para));
    }

    /**
     * Isi sel: array => daftar bernomor "1. ...", string => paragraf (baris baru dipisah).
     */
    private function fill($cell, $value, string $align = Jc::LEFT): void
    {
        if (is_array($value)) {
            $items = array_values(array_filter(array_map('trim', $value), fn($v) => $v !== ''));
            foreach ($items as $i => $item) {
                $cell->addText(
                    ($i + 1) . '.' . "\t" . $item,
                    self::FONT,
                    ['alignment' => Jc::BOTH, 'indentation' => ['left' => 360, 'hanging' => 360], 'spaceAfter' => 40]
                );
            }
            if (!$items) {
                $this->p($cell, '');
            }
            return;
        }

        $lines = preg_split('/\R/', (string) $value) ?: [''];
        foreach ($lines as $line) {
            $this->p($cell, $line, [], $align);
        }
    }
}
