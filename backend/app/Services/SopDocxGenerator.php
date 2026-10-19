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
 * Halaman 1 (header + informasi): A4 portrait. Halaman prosedur: A4 landscape.
 */
class SopDocxGenerator
{
    private const TOTAL_PORTRAIT  = 10300; // lebar konten halaman informasi (A4 portrait, twips)
    private const TOTAL_LANDSCAPE = 15600; // lebar konten tabel prosedur (A4 landscape, twips)
    private const FONT  = ['name' => 'Arial', 'size' => 10];
    private const GRAY  = 'D9D9D9';

    public function generate(Sop $sop): string
    {
        // Wajib: supaya karakter seperti & < > di data tidak merusak XML docx
        Settings::setOutputEscapingEnabled(true);

        $word = new PhpWord();
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(10);
        $word->setDefaultParagraphStyle(['spaceAfter' => 0, 'spaceBefore' => 0]);

        // Section 1: portrait
        $portrait = $word->addSection([
            'pageSizeW' => 11906,
            'pageSizeH' => 16838,
            'marginTop' => 800,
            'marginBottom' => 800,
            'marginLeft' => 800,
            'marginRight' => 800,
        ]);
        $this->header($portrait, $sop);
        $portrait->addText('', ['size' => 4]);
        $this->info($portrait, $sop);

        // Section 2: landscape (otomatis mulai halaman baru)
        $landscape = $word->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => 16838,
            'pageSizeH' => 11906,
            'marginTop' => 800,
            'marginBottom' => 800,
            'marginLeft' => 600,
            'marginRight' => 600,
        ]);
        $markers = $this->flowchart($landscape, $sop);

        $path = sys_get_temp_dir() . '/' . Str::uuid() . '.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        if ($markers) {
            $this->injectShapes($path, $markers);
        }

        return $path;
    }

    /**
     * PHPWord tidak bisa menggambar bentuk/panah. Sel pelaksana diberi penanda teks (@@SHP_baris_kolom@@),
     * lalu setelah file disimpan penanda itu diganti dengan XML bentuk DrawingML hasil FlowchartLayout.
     */
    private function injectShapes(string $path, array $markers): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return;
        }
        $xml = $zip->getFromName('word/document.xml');
        if (is_string($xml)) {
            $out = preg_replace_callback(
                '#<w:r>(?:(?!</w:r>).)*?(@@SHP_\d+_\d+@@)(?:(?!</w:r>).)*?</w:r>#s',
                fn($m) => $markers[$m[1]] ?? '',
                $xml
            );
            if (is_string($out)) {
                $zip->addFromString('word/document.xml', $out);
            }
        }
        $zip->close();
    }

    // ---------- Tabel 1: header ----------
    private function header($section, Sop $sop): void
    {
        $table = $this->table($section, self::TOTAL_PORTRAIT);
        $colL = 4300;
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
                $c->addTextBreak(1);
                $this->p($c, $sop->instansi, ['bold' => true], Jc::CENTER);
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
        [$wNo, $wKeg, $wSyr, $wWkt, $wOut, $wKet, $wPel] = [$L->wNo, $L->wKeg, $L->wSyr, $L->wWkt, $L->wOut, $L->wKet, $L->wPel];
        $wMutu = $wSyr + $wWkt + $wOut + $wKet;

        $table = $this->table($section, self::TOTAL_LANDSCAPE);
        $head = $this->cell(['bgColor' => self::GRAY, 'valign' => 'center']);
        $bold = ['bold' => true];

        // Baris header 1
        $table->addRow($L->headH[0], ['tblHeader' => true, 'cantSplit' => true]);
        $this->p($table->addCell($wNo, $this->cell(['bgColor' => self::GRAY, 'valign' => 'center', 'vMerge' => 'restart'])), 'No', $bold, Jc::CENTER);
        $this->p($table->addCell($wKeg, $this->cell(['bgColor' => self::GRAY, 'valign' => 'center', 'vMerge' => 'restart'])), 'Kegiatan', $bold, Jc::CENTER);
        $this->p($table->addCell($wPel * $n, $this->cell(['bgColor' => self::GRAY, 'valign' => 'center', 'gridSpan' => $n])), 'Pelaksana', $bold, Jc::CENTER);
        $this->p($table->addCell($wMutu, $this->cell(['bgColor' => self::GRAY, 'valign' => 'center', 'gridSpan' => 4])), 'Mutu Baku', $bold, Jc::CENTER);

        // Baris header 2
        $table->addRow($L->headH[1], ['tblHeader' => true, 'cantSplit' => true]);
        $table->addCell($wNo, $this->cell(['vMerge' => 'continue', 'bgColor' => self::GRAY]));
        $table->addCell($wKeg, $this->cell(['vMerge' => 'continue', 'bgColor' => self::GRAY]));
        foreach ($pelaksana as $name) {
            $this->p($table->addCell($wPel, $head), $name, $bold, Jc::CENTER);
        }
        foreach ([[$wSyr, 'Persyaratan dan kelengkapan'], [$wWkt, 'Waktu'], [$wOut, 'Output'], [$wKet, 'Keterangan']] as [$w, $t]) {
            $this->p($table->addCell($w, $head), $t, $bold, Jc::CENTER);
        }

        // Baris nomor kolom (1 2 3 ...)
        $table->addRow($L->headH[2], ['tblHeader' => true, 'cantSplit' => true]);
        $widths = array_merge([$wNo, $wKeg], array_fill(0, $n, $wPel), [$wSyr, $wWkt, $wOut, $wKet]);
        foreach ($widths as $i => $w) {
            $this->p($table->addCell($w, $this->cell(['bgColor' => self::GRAY, 'valign' => 'center'])), (string) ($i + 1), ['size' => 9], Jc::CENTER);
        }

        // Baris langkah
        $markers = [];
        foreach ($steps as $i => $step) {
            $r = $L->rows[$i];
            // Tinggi baris = perkiraan (minimal); baris yang memulai halaman baru dipaksa pindah halaman
            $table->addRow($r['h'], ['cantSplit' => true]);

            $this->p(
                $table->addCell($wNo, $this->cell(['valign' => 'center'])),
                ($i + 1) . '.',
                [],
                Jc::CENTER,
                $r['brk'] ? ['pageBreakBefore' => true] : []
            );
            $this->fill($table->addCell($wKeg, $this->cell(['valign' => 'center'])), $step['kegiatan'] ?? '', Jc::BOTH);

            // Sel pelaksana: rata atas, berisi satu paragraf penanda sebagai jangkar bentuk
            for ($k = 0; $k < $n; $k++) {
                $c = $table->addCell($wPel, $this->cell(['valign' => 'top']));
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
