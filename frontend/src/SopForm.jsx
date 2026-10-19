import { useEffect, useState } from 'react'
import { api } from './api'

const emptyStep = () => ({ kegiatan: '', pelaksana: [], simbol: '', lanjut: '', persyaratan: '', waktu: '', output: '', keterangan: '' })

const empty = {
  instansi: 'BADAN KEPEGAWAIAN DAN PENGEMBANGAN SUMBER DAYA MANUSIA KOTA YOGYAKARTA',
  nama_sop: '',
  nomor_sop: '',
  tanggal_pembuatan: '',
  tanggal_revisi: '',
  tanggal_efektif: '',
  pengesah_jabatan: '',
  pengesah_nama: '',
  pengesah_nip: '',
  dasar_hukum: [''],
  kualifikasi: [''],
  keterkaitan: [''],
  peralatan: [''],
  peringatan: '',
  pencatatan: '',
  pelaksana: ['Pelaksana 1'],
  steps: [emptyStep()],
}

// Editor daftar teks (tambah / hapus baris)
function ListEditor({ label, items, onChange, onRemove }) {
  const set = (i, v) => onChange(items.map((x, k) => (k === i ? v : x)))
  const del = (i) => (onRemove ? onRemove(i) : onChange(items.filter((_, k) => k !== i)))
  return (
    <div className="field">
      <label>{label}</label>
      {items.map((it, i) => (
        <div className="row" key={i}>
          <span className="num">{i + 1}.</span>
          <input value={it} onChange={(e) => set(i, e.target.value)} />
          <button type="button" className="danger" onClick={() => del(i)}>×</button>
        </div>
      ))}
      <button type="button" onClick={() => onChange([...items, ''])}>+ Tambah</button>
    </div>
  )
}

export default function SopForm({ id, onClose }) {
  const [f, setF] = useState(empty)
  const [loading, setLoading] = useState(!!id)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!id) return
    api.get(id).then((d) => {
      // Pastikan semua list minimal punya 1 baris & tanggal bentuk yyyy-mm-dd
      const norm = (a) => (a && a.length ? a : [''])
      setF({
        ...empty, ...d,
        tanggal_pembuatan: d.tanggal_pembuatan?.slice(0, 10) ?? '',
        tanggal_revisi: d.tanggal_revisi?.slice(0, 10) ?? '',
        tanggal_efektif: d.tanggal_efektif?.slice(0, 10) ?? '',
        dasar_hukum: norm(d.dasar_hukum), kualifikasi: norm(d.kualifikasi),
        keterkaitan: norm(d.keterkaitan), peralatan: norm(d.peralatan),
        pelaksana: d.pelaksana?.length ? d.pelaksana : ['Pelaksana 1'],
        steps: d.steps?.length ? d.steps : [emptyStep()],
      })
      setLoading(false)
    })
  }, [id])

  const up = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const setList = (k) => (v) => setF({ ...f, [k]: v })

  // Hapus pelaksana -> sesuaikan penandaan di setiap langkah
  const removePelaksana = (idx) => {
    if (f.pelaksana.length === 1) return
    setF({
      ...f,
      pelaksana: f.pelaksana.filter((_, k) => k !== idx),
      steps: f.steps.map((s) => ({
        ...s,
        pelaksana: s.pelaksana.filter((p) => p !== idx).map((p) => (p > idx ? p - 1 : p)),
      })),
    })
  }

  const setStep = (i, patch) =>
    setF({ ...f, steps: f.steps.map((s, k) => (k === i ? { ...s, ...patch } : s)) })

  const toggle = (i, p) => {
    const cur = f.steps[i].pelaksana
    setStep(i, { pelaksana: cur.includes(p) ? cur.filter((x) => x !== p) : [...cur, p].sort() })
  }

  const move = (i, dir) => {
    const j = i + dir
    if (j < 0 || j >= f.steps.length) return
    const steps = [...f.steps]
    ;[steps[i], steps[j]] = [steps[j], steps[i]]
    setF({ ...f, steps })
  }

  const save = async (download) => {
    setSaving(true); setError('')
    try {
      // Kosongkan string kosong di list & tanggal
      const clean = (a) => a.map((x) => x.trim()).filter(Boolean)
      const payload = {
        ...f,
        tanggal_pembuatan: f.tanggal_pembuatan || null,
        tanggal_revisi: f.tanggal_revisi || null,
        tanggal_efektif: f.tanggal_efektif || null,
        dasar_hukum: clean(f.dasar_hukum), kualifikasi: clean(f.kualifikasi),
        keterkaitan: clean(f.keterkaitan), peralatan: clean(f.peralatan),
      }
      const saved = id ? await api.update(id, payload) : await api.create(payload)
      if (download) window.location.href = api.downloadUrl(saved.id)
      else onClose()
    } catch (e) {
      setError(e.message)
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <div className="container">Memuat…</div>

  return (
    <div className="container">
      <header className="bar">
        <h1>{id ? 'Edit SOP' : 'SOP Baru'}</h1>
        <button onClick={onClose}>← Kembali</button>
      </header>

      <section>
        <h2>1. Identitas SOP</h2>
        <div className="field"><label>Nama Instansi</label><input value={f.instansi} onChange={up('instansi')} /></div>
        <div className="field"><label>Nama SOP *</label><input value={f.nama_sop} onChange={up('nama_sop')} /></div>
        <div className="grid">
          <div className="field"><label>Nomor SOP</label><input value={f.nomor_sop ?? ''} onChange={up('nomor_sop')} /></div>
          <div className="field"><label>Tanggal Pembuatan</label><input type="date" value={f.tanggal_pembuatan} onChange={up('tanggal_pembuatan')} /></div>
          <div className="field"><label>Tanggal Revisi</label><input type="date" value={f.tanggal_revisi} onChange={up('tanggal_revisi')} /></div>
          <div className="field"><label>Tanggal Efektif</label><input type="date" value={f.tanggal_efektif} onChange={up('tanggal_efektif')} /></div>
        </div>
        <div className="grid">
          <div className="field"><label>Disahkan oleh (jabatan)</label><input value={f.pengesah_jabatan ?? ''} onChange={up('pengesah_jabatan')} /></div>
          <div className="field"><label>Nama pejabat</label><input value={f.pengesah_nama ?? ''} onChange={up('pengesah_nama')} /></div>
          <div className="field"><label>NIP</label><input value={f.pengesah_nip ?? ''} onChange={up('pengesah_nip')} /></div>
        </div>
      </section>

      <section>
        <h2>2. Informasi Pendukung</h2>
        <ListEditor label="Dasar Hukum" items={f.dasar_hukum} onChange={setList('dasar_hukum')} />
        <ListEditor label="Kualifikasi Pelaksana" items={f.kualifikasi} onChange={setList('kualifikasi')} />
        <ListEditor label="Keterkaitan" items={f.keterkaitan} onChange={setList('keterkaitan')} />
        <ListEditor label="Peralatan / Perlengkapan" items={f.peralatan} onChange={setList('peralatan')} />
        <div className="field"><label>Peringatan</label><textarea rows="3" value={f.peringatan ?? ''} onChange={up('peringatan')} /></div>
        <div className="field"><label>Pencatatan dan Pendataan</label><textarea rows="3" value={f.pencatatan ?? ''} onChange={up('pencatatan')} /></div>
      </section>

      <section>
        <h2>3. Pelaksana</h2>
        <p className="muted">Menjadi kolom pada tabel prosedur.</p>
        <ListEditor
          label="Daftar pelaksana"
          items={f.pelaksana}
          onChange={setList('pelaksana')}
          onRemove={removePelaksana}
        />
      </section>

      <section>
        <h2>4. Prosedur / Kegiatan</h2>
        {f.steps.map((s, i) => (
          <div className="step" key={i}>
            <div className="step-head">
              <strong>Langkah {i + 1}</strong>
              <span>
                <button type="button" onClick={() => move(i, -1)}>↑</button>
                <button type="button" onClick={() => move(i, 1)}>↓</button>
                <button type="button" className="danger"
                  onClick={() => setF({ ...f, steps: f.steps.filter((_, k) => k !== i) })}>Hapus</button>
              </span>
            </div>
            <div className="field"><label>Kegiatan</label>
              <textarea rows="2" value={s.kegiatan} onChange={(e) => setStep(i, { kegiatan: e.target.value })} /></div>
            <div className="field"><label>Pelaksana yang terlibat</label>
              <div className="checks">
                {f.pelaksana.map((p, k) => (
                  <label key={k} className="check">
                    <input type="checkbox" checked={s.pelaksana.includes(k)} onChange={() => toggle(i, k)} /> {p || `(Pelaksana ${k + 1})`}
                  </label>
                ))}
              </div>
            </div>
            <div className="grid">
              <div className="field"><label>Simbol alur</label>
                <select value={s.simbol || ''} onChange={(e) => setStep(i, { simbol: e.target.value })}>
                  <option value="">Otomatis (langkah pertama = Mulai, terakhir = Selesai)</option>
                  <option value="proses">Proses (kotak)</option>
                  <option value="mulai">Mulai (oval)</option>
                  <option value="selesai">Selesai (oval)</option>
                  <option value="keputusan">Keputusan (belah ketupat)</option>
                </select></div>
              <div className="field"><label>Lanjut ke langkah nomor</label>
                <input placeholder="Kosong = langkah berikutnya" value={s.lanjut || ''}
                  onChange={(e) => setStep(i, { lanjut: e.target.value })} />
                <small>Pada Keputusan, isi dua nomor: Ya, Tidak (mis. 8,9). Isi tanda minus (-) jika alur berhenti. Hanya bisa ke langkah di bawahnya.</small></div>
            </div>
            <div className="grid">
              <div className="field"><label>Persyaratan dan kelengkapan</label>
                <textarea rows="2" value={s.persyaratan} onChange={(e) => setStep(i, { persyaratan: e.target.value })} /></div>
              <div className="field"><label>Waktu</label>
                <input value={s.waktu} onChange={(e) => setStep(i, { waktu: e.target.value })} /></div>
              <div className="field"><label>Output</label>
                <textarea rows="2" value={s.output} onChange={(e) => setStep(i, { output: e.target.value })} /></div>
              <div className="field"><label>Keterangan</label>
                <textarea rows="2" value={s.keterangan} onChange={(e) => setStep(i, { keterangan: e.target.value })} /></div>
            </div>
          </div>
        ))}
        <button type="button" onClick={() => setF({ ...f, steps: [...f.steps, emptyStep()] })}>+ Tambah langkah</button>
      </section>

      {error && <pre className="error">{error}</pre>}

      <footer className="bar sticky">
        <button disabled={saving} onClick={() => save(false)}>Simpan</button>
        <button className="primary" disabled={saving} onClick={() => save(true)}>Simpan &amp; Unduh .docx</button>
      </footer>
    </div>
  )
}