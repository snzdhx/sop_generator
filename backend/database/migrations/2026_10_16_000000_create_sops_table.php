<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sops', function (Blueprint $t) {
            $t->id();
            // Header
            $t->string('instansi')->default('BADAN KEPEGAWAIAN DAN PENGEMBANGAN SUMBER DAYA MANUSIA KOTA YOGYAKARTA');
            $t->string('nama_sop');
            $t->string('nomor_sop')->nullable();
            $t->date('tanggal_pembuatan')->nullable();
            $t->date('tanggal_revisi')->nullable();
            $t->date('tanggal_efektif')->nullable();
            $t->string('pengesah_jabatan')->nullable();
            $t->string('pengesah_nama')->nullable();
            $t->string('pengesah_nip')->nullable();
            // Info (daftar teks -> JSON array of string)
            $t->json('dasar_hukum')->nullable();
            $t->json('kualifikasi')->nullable();
            $t->json('keterkaitan')->nullable();
            $t->json('peralatan')->nullable();
            $t->text('peringatan')->nullable();
            $t->text('pencatatan')->nullable();
            // Flowchart
            $t->json('pelaksana')->nullable(); // ["Kepala BKPSDM", ...]
            $t->json('steps')->nullable();     // [{kegiatan, pelaksana:[0,2], persyaratan, waktu, output, keterangan}]
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sops');
    }
};
