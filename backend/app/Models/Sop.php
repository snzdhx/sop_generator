<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sop extends Model
{
    protected $guarded = [];

    protected $casts = [
        'tanggal_pembuatan' => 'date:Y-m-d',
        'tanggal_revisi'    => 'date:Y-m-d',
        'tanggal_efektif'   => 'date:Y-m-d',
        'dasar_hukum'       => 'array',
        'kualifikasi'       => 'array',
        'keterkaitan'       => 'array',
        'peralatan'         => 'array',
        'pelaksana'         => 'array',
        'steps'             => 'array',
    ];
}
