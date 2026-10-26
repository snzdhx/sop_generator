<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sops', function (Blueprint $t) {
            // Daftar jabatan pada tabel Paraf Hirarki (JSON array of string); null = pakai bawaan
            $t->json('paraf_hirarki')->nullable()->after('pengesah_nip');
        });
    }

    public function down(): void
    {
        Schema::table('sops', function (Blueprint $t) {
            $t->dropColumn('paraf_hirarki');
        });
    }
};
