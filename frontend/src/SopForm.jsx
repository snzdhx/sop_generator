import { useEffect, useRef, useState } from 'react'
import { api } from './api'

const emptyStep = () => ({ kegiatan: '', pelaksana: [], simbol: 'proses', lanjut: '', persyaratan: '', waktu: '', output: '', keterangan: '' })

// Pilihan simbol alur: ditampilkan sebagai gambar bentuk (sama dengan bentuk di dokumen Word)
const SYMBOLS = [
  { value: 'proses', label: 'Proses', hint: 'Kotak' },
  { value: 'mulai', label: 'Mulai', hint: 'Oval' },
  { value: 'selesai', label: 'Selesai', hint: 'Oval' },
  { value: 'keputusan', label: 'Keputusan', hint: 'Belah ketupat' },
]

function SymbolShape({ type }) {
  const common = { fill: '#fff', stroke: '#111827', strokeWidth: 1.6 }
  return (
    <svg viewBox="0 0 64 40" width="64" height="40" aria-hidden="true">
      {type === 'proses' && <rect x="6" y="8" width="52" height="24" {...common} />}
      {(type === 'mulai' || type === 'selesai') && <rect x="6" y="8" width="52" height="24" rx="12" {...common} />}
      {type === 'keputusan' && <polygon points="32,3 60,20 32,37 4,20" {...common} />}
      {type === 'mulai' && <polygon points="28,15 28,25 37,20" fill="#111827" />}
      {type === 'selesai' && <rect x="27" y="15" width="10" height="10" fill="#111827" />}
    </svg>
  )
}

function SymbolPicker({ value, onChange }) {
  return (
    <div className="symbols" role="radiogroup" aria-label="Simbol alur">
      {SYMBOLS.map((s) => (
        <button
          type="button"
          key={s.value}
          role="radio"
          aria-checked={value === s.value}
          className={`symbol${value === s.value ? ' selected' : ''}`}
          onClick={() => onChange(s.value)}
          title={`${s.label} (${s.hint})`}
        >
          <SymbolShape type={s.value} />
          <span>{s.label}</span>
        </button>
      ))}
    </div>
  )
}

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

// Kosongkan string kosong di list & tanggal sebelum dikirim ke backend
const buildPayload = (f) => {
  const clean = (a) => a.map((x) => x.trim()).filter(Boolean)
  return {
    ...f,
    tanggal_pembuatan: f.tanggal_pembuatan || null,
    tanggal_revisi: f.tanggal_revisi || null,
    tanggal_efektif: f.tanggal_efektif || null,
    dasar_hukum: clean(f.dasar_hukum), kualifikasi: clean(f.kualifikasi),
    keterkaitan: clean(f.keterkaitan), peralatan: clean(f.peralatan),
  }
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

// Panel pratinjau: dokumen Word dirender jadi PDF di backend, otomatis diperbarui saat form berubah
function PreviewPanel({ form, onClose }) {
  const [url, setUrl] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [tick, setTick] = useState(0) // naik = paksa muat ulang
  const urlRef = useRef(null)

  useEffect(() => {
    const ctrl = new AbortController()
    setLoading(true)
    const timer = setTimeout(async () => {
      try {
        const blob = await api.preview(buildPayload(form), ctrl.signal)
        const next = URL.createObjectURL(blob)
        if (urlRef.current) URL.revokeObjectURL(urlRef.current)
        urlRef.current = next
        setUrl(next)
        setError('')
        setLoading(false)
      } catch (e) {
        if (e.name === 'AbortError') return
        setError(e.message)
        setLoading(false)
      }
    }, 1200) // tunggu pengguna berhenti mengetik
    return () => { clearTimeout(timer); ctrl.abort() }
  }, [form, tick])

  useEffect(() => () => { if (urlRef.current) URL.revokeObjectURL(urlRef.current) }, [])

  return (
    <aside className="preview">
      <div className="preview-head">
        <strong>Pratinjau Word (PDF)</strong>
        <span>
          {loading && <span className="muted">Memperbarui…</span>}{' '}
          <button type="button" onClick={() => setTick((t) => t + 1)}>⟳ Muat ulang</button>{' '}
          <button type="button" onClick={onClose}>Tutup</button>
        </span>
      </div>
      {error && <pre className="error">{error}</pre>}
      {url && <iframe title="Pratinjau SOP" src={`${url}#view=FitH`} />}
      {!url && !error && <p className="muted">Menyiapkan pratinjau…</p>}
    </aside>
  )
}

// ---------- Alur "Lanjut ke": parsing, pilihan, peringatan ----------
const parseL = (s) => String(s || '').split(/[,;]/).map((x) => x.trim()).filter(Boolean)
const joinL = (a) => a.filter(Boolean).join(',')
const short = (t) => { const x = (t || '').trim(); return x.length > 30 ? x.slice(0, 30) + '…' : x || '(kosong)' }

// Perbarui semua nomor tujuan ("lanjut") di semua langkah. fn(n) -> nomor baru, atau null = hapus.
const remap = (steps, fn) => steps.map((s) => {
  const parts = parseL(s.lanjut)
  if (!parts.length) return s
  const out = parts.map((p) => (p === '-' || isNaN(p) ? p : fn(Number(p)))).filter((x) => x !== null)
  return { ...s, lanjut: joinL(out.map(String)) }
})

function StepSelect({ value, onChange, steps, from, allowBack, blankLabel, allowStop = true }) {
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)}>
      {blankLabel && <option value="">{blankLabel}</option>}
      {allowStop && <option value="-">Berhenti (tanpa panah)</option>}
      {steps.map((st, j) => {
        if (j === from || (!allowBack && j < from)) return null
        return <option key={j} value={String(j + 1)}>{j + 1}. {short(st.kegiatan)}</option>
      })}
    </select>
  )
}

function warningsOf(steps, i) {
  const s = steps[i]
  const parts = parseL(s.lanjut)
  const w = []
  const nums = parts.filter((p) => p !== '-').map(Number)
  const isDec = s.simbol === 'keputusan'

  if (isDec && nums.length < 2 && !parts.includes('-')) w.push('Keputusan hanya punya 1 tujuan: isi juga "Jika Tidak".')
  if (isDec && nums.length === 2 && nums[0] === nums[1]) w.push('"Ya" dan "Tidak" menuju langkah yang sama.')
  if (isDec && nums.length === 2 && nums[0] < i + 1) w.push(`"Ya" menuju langkah ${nums[0]} (kembali ke belakang). Biasanya "Ya" maju dan "Tidak" yang kembali.`)
  for (const n of nums) {
    if (!steps[n - 1]) { w.push(`Langkah ${n} tidak ada.`); continue }
    if (n === i + 1) w.push('Tujuan tidak boleh langkah ini sendiri.')
    if (!isDec && n < i + 1) w.push(`Langkah ${n} ada di atas langkah ini; hanya Keputusan yang boleh kembali ke atas.`)
    if (!(steps[n - 1].pelaksana || []).length) w.push(`Langkah ${n} belum punya pelaksana yang dicentang, panah akan melewatinya.`)
  }
  return w
}

// Ringkasan alur satu baris, mis. "7 ─Ya→ 8, ─Tidak→ 4"
function summaryOf(steps, i) {
  const s = steps[i]
  if (s.simbol === 'selesai') return `${i + 1} ■ selesai`
  const parts = parseL(s.lanjut)
  if (parts[0] === '-') return `${i + 1} ■ berhenti`
  const t = parts.length ? parts : (i + 2 <= steps.length ? [String(i + 2)] : [])
  if (!t.length) return `${i + 1} (tidak ada tujuan)`
  if (s.simbol === 'keputusan') {
    const [ya, tidak] = t
    return `${i + 1} ─Ya→ ${ya}` + (tidak ? `, ─Tidak→ ${tidak}` : '')
  }
  return `${i + 1} → ${t.join(', ')}`
}

function LanjutEditor({ steps, i, onChange }) {
  const s = steps[i]
  const parts = parseL(s.lanjut)
  const nextNo = i + 2 <= steps.length ? String(i + 2) : ''
  const warns = warningsOf(steps, i)

  let body
  if (s.simbol === 'selesai') {
    body = <p className="muted">Simbol Selesai tidak punya panah keluar.</p>
  } else if (s.simbol === 'keputusan') {
    const ya = parts[0] || nextNo
    const tidak = parts[1] || ''
    body = (
      <>
        <div className="lanjut-row">
          <label>Jika Ya →</label>
          <StepSelect value={ya} steps={steps} from={i} allowBack onChange={(v) => onChange(joinL([v, tidak]))} allowStop={false} />
        </div>
        <div className="lanjut-row">
          <label>Jika Tidak →</label>
          <StepSelect value={tidak} steps={steps} from={i} allowBack blankLabel="(belum dipilih)" allowStop={false}
            onChange={(v) => onChange(joinL([ya, v]))} />
        </div>
      </>
    )
  } else {
    const first = parts[0] || ''
    const second = parts[1] || ''
    body = (
      <>
        <div className="lanjut-row">
          <label>Lanjut ke →</label>
          <StepSelect value={first} steps={steps} from={i} blankLabel={`(langkah berikutnya${nextNo ? ' = ' + nextNo : ''})`}
            onChange={(v) => onChange(v === '-' ? '-' : joinL([v || (second ? nextNo : ''), second]))} />
        </div>
        {first !== '-' && (
          <div className="lanjut-row">
            <label>Cabang ke-2 →</label>
            <StepSelect value={second} steps={steps} from={i} blankLabel="(tidak ada cabang kedua)" allowStop={false}
              onChange={(v) => onChange(joinL([first || nextNo, v]))} />
          </div>
        )}
      </>
    )
  }

  return (
    <div className="field">
      <label>Alur setelah langkah ini</label>
      {body}
      <div className="flow">Alur: {summaryOf(steps, i)}</div>
      {warns.map((w, k) => <div className="warn" key={k}>⚠ {w}</div>)}
    </div>
  )
}

export default function SopForm({ id, onClose }) {
  const [f, setF] = useState(empty)
  const [loading, setLoading] = useState(!!id)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [showPreview, setShowPreview] = useState(false)

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
        // Data lama yang simbolnya kosong (dulu "otomatis") dianggap Proses
        steps: d.steps?.length ? d.steps.map((s) => ({ ...s, simbol: s.simbol || 'proses' })) : [emptyStep()],
      })
      setLoading(false)
    }).catch((e) => { setError(e.message); setLoading(false) })
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
    setStep(i, { pelaksana: cur.includes(p) ? cur.filter((x) => x !== p) : [...cur, p].sort((a, b) => a - b) })
  }

  const move = (i, dir) => {
    const j = i + dir
    if (j < 0 || j >= f.steps.length) return
    const steps = [...f.steps]
    ;[steps[i], steps[j]] = [steps[j], steps[i]]
    // nomor langkah i dan j bertukar, jadi tujuan yang menunjuk keduanya ikut ditukar
    setF({ ...f, steps: remap(steps, (n) => (n === i + 1 ? j + 1 : n === j + 1 ? i + 1 : n)) })
  }

  const removeStep = (i) =>
    setF({
      ...f,
      steps: remap(f.steps.filter((_, k) => k !== i), (n) => (n === i + 1 ? null : n > i + 1 ? n - 1 : n)),
    })

  const save = async (download) => {
    setSaving(true); setError('')
    try {
      const payload = buildPayload(f)
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
    <div className={`container${showPreview ? ' wide' : ''}`}>
      <header className="bar">
        <h1>{id ? 'Edit SOP' : 'SOP Baru'}</h1>
        <span>
          <button type="button" onClick={() => setShowPreview((v) => !v)}>
            {showPreview ? 'Sembunyikan pratinjau' : '👁 Pratinjau Word'}
          </button>{' '}
          <button onClick={onClose}>← Kembali</button>
        </span>
      </header>

      <div className={showPreview ? 'split' : ''}>
        <div className="form-col">
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
                    <button type="button" className="danger" onClick={() => removeStep(i)}>Hapus</button>
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
                    <SymbolPicker value={s.simbol || 'proses'} onChange={(v) => setStep(i, { simbol: v })} /></div>
                  <LanjutEditor steps={f.steps} i={i} onChange={(v) => setStep(i, { lanjut: v })} />
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
        </div>

        {showPreview && <PreviewPanel form={f} onClose={() => setShowPreview(false)} />}
      </div>

      <footer className="bar sticky">
        <button disabled={saving} onClick={() => save(false)}>Simpan</button>
        <button className="primary" disabled={saving} onClick={() => save(true)}>Simpan &amp; Unduh .docx</button>
      </footer>
    </div>
  )
}
