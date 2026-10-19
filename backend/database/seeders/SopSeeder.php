<?php

namespace Database\Seeders;

use App\Models\Sop;
use Illuminate\Database\Seeder;

/** Contoh data: SOP Pengelolaan CCTV BKPSDM (dari dokumen asli). */
class SopSeeder extends Seeder
{
    public function run(): void
    {
        $pelaksana = ['Kepala BKPSDM', 'Kepala UPT Penilaian Kompetensi', 'Kasubbag Umum BKPSDM', 'Administrator', 'Pengguna CCTV'];

        // [kegiatan, [index pelaksana], persyaratan, waktu, output, keterangan]
        // Penandaan pelaksana di bawah adalah perkiraan, silakan sesuaikan lewat form.
        $rows = [
            ['Melakukan login ke sistem CCTV menggunakan akun pengguna yang telah ditetapkan sesuai hak akses.', [4], 'Akun pengguna aktif dan hak akses sesuai kewenangan', '1 menit', 'Pengguna berhasil login ke sistem CCTV', 'Menggunakan username dan password masing-masing.'],
            ['Melakukan pemantauan secara langsung (live view) terhadap area yang berada dalam cakupan pengawasan CCTV sesuai hak akses dan kewenangan pengguna.', [0, 1, 2, 4], 'Sistem CCTV, jaringan, dan perangkat monitor yang berfungsi dengan baik', 'Sesuai kebutuhan', 'Hasil pemantauan pada area yang diawasi', 'Monitoring hanya untuk kepentingan kedinasan'],
            ['Melakukan penelusuran dan pemutaran ulang (playback) rekaman CCTV berdasarkan tanggal, waktu, dan lokasi kamera sesuai kebutuhan serta kewenangan pengguna.', [0, 1, 2, 4], 'Informasi tanggal, waktu, dan lokasi kamera CCTV tersedia', '2-5 menit', 'Rekaman CCTV berhasil ditampilkan sesuai tanggal, waktu, dan lokasi kamera', 'Hanya CCTV keamanan gedung'],
            ['Melakukan pengunduhan rekaman CCTV keamanan sesuai hak akses dan kewenangan pengguna untuk keperluan dokumentasi, pemeriksaan, atau tindak lanjut.', [3, 4], 'Hak akses sesuai kewenangan dan persetujuan', '5 menit', 'Rekaman CCTV keamanan berhasil diunduh', 'Hanya CCTV keamanan gedung'],
            ['Melakukan pengunduhan rekaman CCTV pada Assessment Center sesuai hak akses dan kewenangan pengguna untuk keperluan pelaksanaan, evaluasi, atau tindak lanjut.', [1, 3, 4], 'Hak akses sesuai kewenangan, permintaan, atau persetujuan pengunduhan', '5 menit', 'Rekaman CCTV Assessment Center berhasil diunduh', 'Hanya CCTV Assessment'],
            ['Pihak Eksternal melakukan pengajuan permohonan unduh data.', [4], 'Rekaman CCTV Assessment Center berhasil diunduh', '1 hari kerja', 'Surat permintaan resmi dari Pihak Eksternal', '-'],
            ['Memberikan persetujuan pengunduhan yang dibutuhkan pihak eksternal (Aparat Hukum)', [0], 'Surat permintaan resmi dari Pihak Eksternal', '1 hari kerja', 'Persetujuan pengunduhan rekaman CCTV untuk pihak eksternal (Aparat Hukum) diterbitkan', 'Berdasarkan permintaan resmi dan persetujuan pejabat berwenang'],
            ['Melakukan pengunduhan rekaman CCTV untuk keperluan pemeliharaan sistem, pencadangan (backup), pemulihan data, penanganan teknis atau pihak eksternal lainnya.', [3], 'Kelengkapan pemeliharaan, pencadangan (backup), atau pemulihan data yang telah ditetapkan sesuai hak akses tersedia', '5 menit', 'Rekaman CCTV untuk keperluan teknis berhasil diunduh', 'Backup maintenance, kebutuhan teknis'],
            ['Melakukan pencatatan setiap kegiatan pengunduhan rekaman CCTV ke dalam register atau log pengunduhan sesuai dengan ketentuan yang berlaku.', [3], 'Register atau log pengunduhan yang tersedia', '2 menit', 'Data pengunduhan tercatat pada register atau log pengunduhan', 'Nomor rekaman, tanggal, dan pengguna'],
            ['Melakukan penyimpanan rekaman CCTV secara otomatis pada media penyimpanan resmi yang telah ditetapkan sesuai ketentuan yang berlaku.', [3], 'Media penyimpanan yang tersedia dan kapasitas penyimpanan yang memadai', 'Otomatis', 'Rekaman CCTV tersimpan pada media penyimpanan sistem', 'Tidak boleh disimpan di perangkat pribadi'],
            ['Melakukan penghapusan rekaman CCTV secara otomatis sesuai dengan kapasitas media penyimpanan dan ketentuan masa retensi, dengan tetap mempertahankan rekaman yang diperlukan untuk kepentingan pemeriksaan atau proses hukum.', [3], 'Rekaman CCTV yang telah melewati masa retensi dan tidak sedang digunakan untuk kepentingan pemeriksaan atau proses hukum', 'Otomatis', 'Rekaman CCTV yang telah melewati masa retensi berhasil dihapus sesuai ketentuan yang berlaku', 'Mengikuti retensi sistem'],
            ['Melakukan pemeriksaan dan pemeliharaan berkala kamera, penyimpanan, dan jaringan CCTV.', [2, 3], 'Jadwal pemeliharaan, perangkat pendukung, serta akses ke sistem CCTV', '1 kali setiap bulan', 'Hasil pemeriksaan dan pemeliharaan sistem CCTV berhasil terdokumentasi', 'Pemeriksaan kamera, server, dan storage'],
            ['Kamera CCTV, perangkat penyimpanan, jaringan, dan komponen pendukung lainnya dinyatakan berfungsi dengan baik dan layak digunakan. Dan Hasil Unduhan CCTV diberikan kepada pihak eksternal sesuai permintaan', [3, 4], 'Hasil pemeriksaan dan pemeliharaan sistem CCTV dan Video unduhan sesuai permintaan', '5 menit', 'Sistem CCTV dinyatakan dalam kondisi baik dan layak', 'Hasil akhir dari pemeriksaan dan pemeliharaan berkala'],
        ];

        Sop::create([
            'instansi' => 'BADAN KEPEGAWAIAN DAN PENGEMBANGAN SUMBER DAYA MANUSIA KOTA YOGYAKARTA',
            'nama_sop' => 'SOP Pengelolaan CCTV BKPSDM',
            'pengesah_jabatan' => 'Plt. Kepala BKPSDM Kota Yogyakarta',
            'pengesah_nama' => 'Sarwanto, S.I.P., M.M.',
            'pengesah_nip' => '197207111992031006',
            'dasar_hukum' => [
                'Undang-Undang Nomor 14 Tahun 2008 tentang Keterbukaan Informasi Publik;',
                'Undang-Undang Nomor 27 Tahun 2022 tentang Pelindungan Data Pribadi;',
                'Undang-Undang Nomor 1 Tahun 2024 tentang Perubahan Kedua atas Undang-Undang Nomor 11 Tahun 2008 tentang Informasi dan Transaksi Elektronik;',
                'Peraturan Wali Kota Yogyakarta Nomor 81 Tahun 2019 tentang Kewajiban Penyediaan dan Pemasangan Closed Circuit Television pada Bangunan Gedung dan Reklame;',
                'Keputusan Kepala Dinas Komunikasi Informatika dan Persandian Kota Yogyakarta Nomor 34 Tahun 2024 tentang Penetapan Klasifikasi Daftar Informasi yang Dikecualikan Kota Yogyakarta Tahun 2025.',
            ],
            'kualifikasi' => [
                'Memahami ketentuan peraturan perundang-undangan yang berkaitan dengan pengelolaan CCTV, perlindungan data pribadi, dan Informasi Transaksi Elektronik;',
                'Memahami mekanisme dan tata cara pengelolaan serta pemanfaatan rekaman CCTV sesuai ketentuan yang berlaku;',
                'Mampu mengoperasikan sistem CCTV beserta perangkat pendukungnya;',
                'Mampu mengoperasikan computer dan jaringan yang mendukung pengelolaan sistem CCTV;',
                'Memahami prosedur penyimpanan, pengunduhan, pengamanan, dan penghapusan rekaman CCTV sesuai ketentuan yang berlaku.',
            ],
            'keterkaitan' => [],
            'peralatan' => ['Komputer;', 'Jaringan internet;', 'Sistem/aplikasi pengelolaan CCTV;', 'Perangkat monitor CCTV;', 'Media penyimpanan rekaman CCTV;', 'Alat Tulis Kantor (ATK);', 'Printer.'],
            'peringatan' => 'Apabila SOP tidak dilaksanakan, maka pengelolaan dan pemanfaatan rekaman CCTV tidak dapat dilaksanakan secara tertib, akuntabel, dan sesuai dengan ketentuan, sehingga berpotensi menimbulkan penyalahgunaan akses, kehilangan data, atau kebocoran informasi.',
            'pencatatan' => 'Log akses sistem CCTV, register atau log pengunduhan rekaman CCTV, serta dokumentasi hasil pemeriksaan dan pemeliharaan sistem (manual/elektronik).',
            'pelaksana' => $pelaksana,
            'steps' => array_map(fn ($r) => [
                'kegiatan' => $r[0], 'pelaksana' => $r[1], 'persyaratan' => $r[2],
                'waktu' => $r[3], 'output' => $r[4], 'keterangan' => $r[5],
            ], $rows),
        ]);
    }
}
