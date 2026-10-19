<?php

namespace App\Services;

use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Mengubah .docx menjadi PDF memakai LibreOffice (headless) untuk pratinjau.
 * Lokasi soffice bisa diatur lewat SOFFICE_PATH di backend/.env.
 */
class SopPdfConverter
{
    /** @return string path file PDF sementara (hapus setelah dipakai) */
    public function convert(string $docxPath): string
    {
        $soffice = $this->findSoffice();
        if (!$soffice) {
            throw new \RuntimeException(
                'LibreOffice tidak ditemukan. Install LibreOffice (libreoffice.org) lalu, jika perlu, isi SOFFICE_PATH di backend/.env '
                    . '(contoh Windows: SOFFICE_PATH="C:\\Program Files\\LibreOffice\\program\\soffice.exe").'
            );
        }

        $docxPath = realpath($docxPath) ?: $docxPath;
        $work = $this->normalize((realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/sop-preview-' . Str::uuid());
        mkdir($work . '/profile', 0777, true);

        try {
            // Percobaan 1: profil terpisah (tidak bentrok dengan LibreOffice yang sedang terbuka).
            // Percobaan 2: profil bawaan LibreOffice, jika percobaan 1 tidak menghasilkan PDF.
            $log = [];
            foreach ([true, false] as $isolated) {
                $cmd = [$soffice, '--headless', '--norestore', '--nologo', '--nodefault'];
                if ($isolated) {
                    $cmd[] = '-env:UserInstallation=' . $this->fileUri($work . '/profile');
                }
                array_push($cmd, '--convert-to', 'pdf:writer_pdf_Export', '--outdir', $work, $docxPath);

                $process = new Process($cmd);
                $process->setTimeout(120);
                $process->run();

                $pdf = $work . '/' . pathinfo($docxPath, PATHINFO_FILENAME) . '.pdf';
                if (is_file($pdf)) {
                    $out = sys_get_temp_dir() . '/' . Str::uuid() . '.pdf';
                    copy($pdf, $out);

                    return $out;
                }

                $log[] = sprintf(
                    '[%s] exit=%s stdout=%s stderr=%s',
                    $isolated ? 'profil terpisah' : 'profil bawaan',
                    var_export($process->getExitCode(), true),
                    trim($process->getOutput()) ?: '-',
                    trim($process->getErrorOutput()) ?: '-'
                );
            }

            throw new \RuntimeException(
                "Konversi ke PDF gagal.\nsoffice: {$soffice}\nfile: {$docxPath}\n" . implode("\n", $log)
            );
        } finally {
            $this->rrmdir($work);
        }
    }

    private function findSoffice(): ?string
    {
        $env = env('SOFFICE_PATH');
        if ($env && is_file($env)) {
            return $env;
        }

        $candidates = [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/soffice',
            '/usr/local/bin/soffice',
            '/opt/libreoffice/program/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }

        // Cari di PATH
        $which = new Process(PHP_OS_FAMILY === 'Windows' ? ['where', 'soffice'] : ['which', 'soffice']);
        $which->run();
        if ($which->isSuccessful()) {
            $line = trim(strtok($which->getOutput(), "\r\n") ?: '');
            if ($line !== '' && is_file($line)) {
                return $line;
            }
        }

        return null;
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function fileUri(string $path): string
    {
        $path = $this->normalize($path);

        return 'file://' . (str_starts_with($path, '/') ? '' : '/') . $path;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
