<?php

namespace App\Http\Controllers;

use App\Models\Sop;
use App\Services\SopDocxGenerator;
use App\Services\SopPdfConverter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SopController extends Controller
{
    private function rules(): array
    {
        return [
            'instansi'          => 'required|string|max:255',
            'nama_sop'          => 'required|string|max:255',
            'nomor_sop'         => 'nullable|string|max:100',
            'tanggal_pembuatan' => 'nullable|date',
            'tanggal_revisi'    => 'nullable|date',
            'tanggal_efektif'   => 'nullable|date',
            'pengesah_jabatan'  => 'nullable|string|max:255',
            'pengesah_nama'     => 'nullable|string|max:255',
            'pengesah_nip'      => 'nullable|string|max:50',
            'dasar_hukum'       => 'nullable|array',
            'dasar_hukum.*'     => 'nullable|string',
            'kualifikasi'       => 'nullable|array',
            'kualifikasi.*'     => 'nullable|string',
            'keterkaitan'       => 'nullable|array',
            'keterkaitan.*'     => 'nullable|string',
            'peralatan'         => 'nullable|array',
            'peralatan.*'       => 'nullable|string',
            'peringatan'        => 'nullable|string',
            'pencatatan'        => 'nullable|string',
            'pelaksana'         => 'nullable|array',
            'pelaksana.*'       => 'nullable|string|max:100',
            'steps'             => 'nullable|array',
            'steps.*.kegiatan'     => 'nullable|string',
            'steps.*.pelaksana'    => 'nullable|array',
            'steps.*.pelaksana.*'  => 'integer',
            'steps.*.persyaratan'  => 'nullable|string',
            'steps.*.waktu'        => 'nullable|string|max:100',
            'steps.*.output'       => 'nullable|string',
            'steps.*.keterangan'   => 'nullable|string',
            'steps.*.simbol'       => 'nullable|string|in:proses,mulai,selesai,keputusan',
            'steps.*.lanjut'       => 'nullable|string|max:50',
        ];
    }

    public function index()
    {
        return Sop::orderByDesc('updated_at')
            ->get(['id', 'nama_sop', 'nomor_sop', 'updated_at']);
    }

    public function store(Request $request)
    {
        return response()->json(Sop::create($request->validate($this->rules())), 201);
    }

    public function show(Sop $sop)
    {
        return $sop;
    }

    public function update(Request $request, Sop $sop)
    {
        $sop->update($request->validate($this->rules()));
        return $sop;
    }

    public function destroy(Sop $sop)
    {
        $sop->delete();
        return response()->noContent();
    }

    public function download(Sop $sop, SopDocxGenerator $generator)
    {
        $path = $generator->generate($sop);
        $name = 'SOP-' . Str::slug($sop->nama_sop) . '.docx';

        return response()->download($path, $name)->deleteFileAfterSend(true);
    }

    /**
     * Pratinjau: bangun .docx dari data form (belum disimpan), ubah ke PDF, kirim sebagai PDF.
     * Tidak ada yang ditulis ke database.
     */
    public function preview(Request $request, SopDocxGenerator $generator, SopPdfConverter $converter)
    {
        // Saat pratinjau, field wajib boleh kosong (form belum selesai diisi)
        $data = $request->validate(array_merge($this->rules(), [
            'instansi' => 'nullable|string|max:255',
            'nama_sop' => 'nullable|string|max:255',
        ]));

        $data['instansi'] = $data['instansi'] ?? '';
        $data['nama_sop'] = $data['nama_sop'] ?? '';
        $data['pelaksana'] = array_map(fn ($v) => (string) $v, $data['pelaksana'] ?? []);
        foreach (['dasar_hukum', 'kualifikasi', 'keterkaitan', 'peralatan'] as $k) {
            $data[$k] = array_values(array_filter(array_map(fn ($v) => (string) $v, $data[$k] ?? []), fn ($v) => trim($v) !== ''));
        }

        $docx = $generator->generate(new Sop($data));
        try {
            $pdf = $converter->convert($docx);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } finally {
            @unlink($docx);
        }

        return response()->file($pdf, ['Content-Type' => 'application/pdf'])->deleteFileAfterSend(true);
    }
}
