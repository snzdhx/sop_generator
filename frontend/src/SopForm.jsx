import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { api } from './api'
import Icon from './Icon.jsx'
import './form.css'

// id lokal tiap langkah (hanya untuk React key / akordeon, dibuang sebelum dikirim ke backend)
let _uid = 0
const uid = () => `s${++_uid}`

const emptyStep = () => ({ _id: uid(), kegiatan: '', pelaksana: [], simbol: 'proses', lanjut: '', persyaratan: '', waktu: '', output: '', keterangan: '' })

const normStep = (s) => ({
  ...emptyStep(),
  ...s,
  _id: uid(),
  pelaksana: s.pelaksana || [],
  simbol: s.simbol || 'proses', // data lama yang simbolnya kosong dianggap Proses
  kegiatan: s.kegiatan ?? '', lanjut: s.lanjut ?? '', persyaratan: s.persyaratan ?? '',
  waktu: s.waktu ?? '', output: s.output ?? '', keterangan: s.keterangan ?? '',
})

// Pilihan simbol alur: ditampilkan sebagai gambar bentuk (sama dengan bentuk di dokumen Word)
const SYMBOLS = [
  { value: 'proses', label: 'Proses', hint: 'Kotak' },
  { value: 'mulai', label: 'Mulai', hint: 'Oval' },
  { value: 'selesai', label: 'Selesai', hint: 'Oval' },
  { value: 'keputusan', label: 'Keputusan', hint: 'Belah ketupat' },
]

function SymbolShape({ type, w = 64 }) {
  const common = { fill: '#fff', stroke: '#1d1c1a', strokeWidth: 1.6 }
  return (
    <svg viewBox="0 0 64 40" width={w} height={w * 0.625} aria-hidden="true">
      {type === 'proses' && <rect x="6" y="8" width="52" height="24" {...common} />}
      {(type === 'mulai' || type === 'selesai') && <rect x="6" y="8" width="52" height="24" rx="12" {...common} />}
      {type === 'keputusan' && <polygon points="32,3 60,20 32,37 4,20" {...common} />}
      {type === 'mulai' && <polygon points="28,15 28,25 37,20" fill="#1d1c1a" />}
      {type === 'selesai' && <rect x="27" y="15" width="10" height="10" fill="#1d1c1a" />}
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

const DEFAULT_PARAF = ['Sekretaris BKPSDM', 'Ka. Sub Bag. Umum dan Kepegawaian']

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
  paraf_hirarki: DEFAULT_PARAF,
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
    paraf_hirarki: clean(f.paraf_hirarki),
    steps: f.steps.map(({ _id, ...s }) => s),
  }
}

const stepHasContent = (s) =>
  !!(s.kegiatan.trim() || s.persyaratan.trim() || s.waktu.trim() || s.output.trim() || s.keterangan.trim() || s.pelaksana.length)

// Textarea yang tinggi-nya mengikuti isi (tidak perlu scroll di dalam kotak)
function AutoText({ value, inputRef, rows = 1, ...rest }) {
  const ref = useRef(null)
  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${el.scrollHeight + 2}px`
  }, [value])
  return (
    <textarea
      className="autotext"
      rows={rows}
      value={value}
      ref={(el) => { ref.current = el; if (inputRef) inputRef(el) }}
      {...rest}
    />
  )
}

// Kartu bagian form dengan nomor dan judul
function Section({ id, no, title, hint, actions, children }) {
  return (
    <section className="card" id={id}>
      <div className="card-head">
        <span className="card-no">{no}</span>
        <div>
          <h2>{title}</h2>
          {hint && <p className="sub">{hint}</p>}
        </div>
        {actions && <div className="card-actions">{actions}</div>}
      </div>
      {children}
    </section>
  )
}

// Editor daftar teks: kotak tumbuh otomatis, Enter = baris baru, Backspace di baris kosong = hapus baris
function ListEditor({ label, hint, items, onChange, onRemove, usage }) {
  const refs = useRef([])
  const focusIdx = useRef(null)
  useEffect(() => {
    if (focusIdx.current != null) {
      refs.current[focusIdx.current]?.focus()
      focusIdx.current = null
    }
  })
  const set = (i, v) => onChange(items.map((x, k) => (k === i ? v : x)))
  const del = (i) => (onRemove ? onRemove(i) : onChange(items.filter((_, k) => k !== i)))
  const onKey = (e, i) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      if (i === items.length - 1) {
        if (!items[i].trim()) return
        focusIdx.current = i + 1
        onChange([...items, ''])
      } else {
        refs.current[i + 1]?.focus()
      }
    } else if (e.key === 'Backspace' && items[i] === '' && items.length > 1) {
      e.preventDefault()
      focusIdx.current = Math.max(0, i - 1)
      del(i)
    }
  }
  return (
    <div className="field">
      <label>{label}</label>
      <span className="hint keys">{hint ? `${hint} ` : ''}Enter untuk baris baru.</span>
      {items.map((it, i) => (
        <div className="row list-row" key={i}>
          <span className="num">{i + 1}</span>
          <AutoText
            value={it}
            inputRef={(el) => { refs.current[i] = el }}
            onChange={(e) => set(i, e.target.value)}
            onKeyDown={(e) => onKey(e, i)}
          />
          {usage && <span className="use">{usage(i)}</span>}
          <button type="button" className="icon-btn danger" onClick={() => del(i)} title="Hapus" aria-label="Hapus"><Icon name="x" size={16} /></button>
        </div>
      ))}
      <button type="button" className="add-btn" onClick={() => { focusIdx.current = items.length; onChange([...items, '']) }}>
        <Icon name="plus" size={16} /> Tambah
      </button>
    </div>
  )
}

// ---------- Alur "Lanjut ke": parsing, pilihan, peringatan ----------
const parseL = (s) => String(s || '').split(/[,;]/).map((x) => x.trim()).filter(Boolean)
const joinL = (a) => a.filter(Boolean).join(',')

// Perbarui semua nomor tujuan ("lanjut") di semua langkah. fn(n) -> nomor baru, atau null = hapus.
const remap = (steps, fn) => steps.map((s) => {
  const parts = parseL(s.lanjut)
  if (!parts.length) return s
  const out = parts.map((p) => (p === '-' || isNaN(p) ? p : fn(Number(p)))).filter((x) => x !== null)
  return { ...s, lanjut: joinL(out.map(String)) }
})

// Dropdown buatan sendiri: tiap pilihan menampilkan kalimat kegiatan LENGKAP (select bawaan hanya satu baris)
function StepSelect({ value, onChange, steps, from, allowBack, blankLabel, allowStop = true }) {
  const [open, setOpen] = useState(false)
  const ref = useRef(null)
  useEffect(() => {
    if (!open) return
    const close = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false) }
    const esc = (e) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', close)
    document.addEventListener('keydown', esc)
    return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', esc) }
  }, [open])

  const opts = []
  if (blankLabel) opts.push({ v: '', title: blankLabel })
  if (allowStop) opts.push({ v: '-', title: 'Berhenti (tanpa panah)' })
  steps.forEach((st, j) => {
    if (j === from || (!allowBack && j < from)) return
    opts.push({ v: String(j + 1), title: `${j + 1}.`, text: (st.kegiatan || '').trim() || '(kegiatan masih kosong)' })
  })
  const cur = opts.find((o) => o.v === String(value ?? ''))
  const pick = (v) => { setOpen(false); onChange(v) }

  return (
    <div className="picker" ref={ref}>
      <button type="button" className="picker-btn" aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        {cur ? `${cur.title}${cur.text ? ' ' + cur.text : ''}` : `Langkah ${value} (tidak tersedia)`}
      </button>
      {open && (
        <div className="picker-menu" role="listbox">
          {opts.map((o) => (
            <button type="button" role="option" aria-selected={o.v === String(value ?? '')} key={o.v || 'blank'}
              className={`picker-item${o.v === String(value ?? '') ? ' selected' : ''}`} onClick={() => pick(o.v)}>
              <strong>{o.title}</strong>{o.text ? ' ' + o.text : ''}
            </button>
          ))}
        </div>
      )}
      {!open && cur?.text && <div className="picker-full">{cur.text}</div>}
    </div>
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

const scrollToId = (id, block = 'start') => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block })

export default function SopForm({ id, onClose }) {
  const [f, setF] = useState(empty)
  const [loading, setLoading] = useState(!!id)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [errs, setErrs] = useState({})
  // Langkah yang sedang terbuka (akordeon). Form baru: langkah pertama terbuka; edit: semua ringkas.
  const [open, setOpen] = useState(() => new Set(id ? [] : [empty.steps[0]._id]))
  const initial = useRef(JSON.stringify(empty))
  const pendingScroll = useRef(null)

  const dirty = useMemo(() => JSON.stringify(f) !== initial.current, [f])

  useEffect(() => {
    if (!id) return
    api.get(id).then((d) => {
      // Pastikan semua list minimal punya 1 baris & tanggal bentuk yyyy-mm-dd
      const norm = (a) => (a && a.length ? a : [''])
      const loaded = {
        ...empty, ...d,
        tanggal_pembuatan: d.tanggal_pembuatan?.slice(0, 10) ?? '',
        tanggal_revisi: d.tanggal_revisi?.slice(0, 10) ?? '',
        tanggal_efektif: d.tanggal_efektif?.slice(0, 10) ?? '',
        paraf_hirarki: d.paraf_hirarki == null ? DEFAULT_PARAF : norm(d.paraf_hirarki),
        dasar_hukum: norm(d.dasar_hukum), kualifikasi: norm(d.kualifikasi),
        keterkaitan: norm(d.keterkaitan), peralatan: norm(d.peralatan),
        pelaksana: d.pelaksana?.length ? d.pelaksana : ['Pelaksana 1'],
        steps: d.steps?.length ? d.steps.map(normStep) : [emptyStep()],
        peringatan: d.peringatan ?? '', pencatatan: d.pencatatan ?? '',
        nomor_sop: d.nomor_sop ?? '', pengesah_jabatan: d.pengesah_jabatan ?? '',
        pengesah_nama: d.pengesah_nama ?? '', pengesah_nip: d.pengesah_nip ?? '',
      }
      initial.current = JSON.stringify(loaded)
      setF(loaded)
      setLoading(false)
    }).catch((e) => { setError(e.message); setLoading(false) })
  }, [id])

  // Scroll halus setelah render (tambah / duplikat / pindah langkah)
  useEffect(() => {
    if (pendingScroll.current) {
      scrollToId(pendingScroll.current.id, pendingScroll.current.block)
      pendingScroll.current = null
    }
  })

  // Peringatan sebelum menutup tab bila ada perubahan
  useEffect(() => {
    if (!dirty) return
    const warn = (e) => { e.preventDefault(); e.returnValue = '' }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  const up = (k) => (e) => {
    setF({ ...f, [k]: e.target.value })
    if (errs[k]) setErrs({ ...errs, [k]: false })
  }
  const setList = (k) => (v) => setF({ ...f, [k]: v })

  const usageOf = (k) => f.steps.filter((s) => s.pelaksana.includes(k)).length

  // Hapus pelaksana -> sesuaikan penandaan di setiap langkah
  const removePelaksana = (idx) => {
    if (f.pelaksana.length === 1) return
    const n = usageOf(idx)
    if (n && !confirm(`"${f.pelaksana[idx] || `Pelaksana ${idx + 1}`}" dipakai di ${n} langkah. Tetap hapus?`)) return
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

  const toggleOpen = (sid) => {
    const next = new Set(open)
    if (next.has(sid)) next.delete(sid); else next.add(sid)
    setOpen(next)
  }

  const move = (i, dir) => {
    const j = i + dir
    if (j < 0 || j >= f.steps.length) return
    const steps = [...f.steps]
    ;[steps[i], steps[j]] = [steps[j], steps[i]]
    // nomor langkah i dan j bertukar, jadi tujuan yang menunjuk keduanya ikut ditukar
    setF({ ...f, steps: remap(steps, (n) => (n === i + 1 ? j + 1 : n === j + 1 ? i + 1 : n)) })
    pendingScroll.current = { id: `step-${f.steps[i]._id}`, block: 'nearest' }
  }

  const removeStep = (i) => {
    if (stepHasContent(f.steps[i]) && !confirm(`Hapus langkah ${i + 1}?`)) return
    setF({
      ...f,
      steps: remap(f.steps.filter((_, k) => k !== i), (n) => (n === i + 1 ? null : n > i + 1 ? n - 1 : n)),
    })
  }

  const addStep = () => {
    const s = emptyStep()
    setF({ ...f, steps: [...f.steps, s] })
    setOpen(new Set(open).add(s._id))
    pendingScroll.current = { id: `step-${s._id}`, block: 'start' }
  }

  const duplicateStep = (i) => {
    const src = f.steps[i]
    const copy = { ...src, _id: uid(), pelaksana: [...src.pelaksana] }
    const steps = [...f.steps.slice(0, i + 1), copy, ...f.steps.slice(i + 1)]
    // langkah setelah titik sisip bergeser satu nomor
    setF({ ...f, steps: remap(steps, (n) => (n > i + 1 ? n + 1 : n)) })
    setOpen(new Set(open).add(copy._id))
    pendingScroll.current = { id: `step-${copy._id}`, block: 'start' }
  }

  const save = async () => {
    const e = {}
    if (!f.instansi.trim()) e.instansi = true
    if (!f.nama_sop.trim()) e.nama_sop = true
    if (Object.keys(e).length) {
      setErrs(e)
      const first = e.instansi ? 'f-instansi' : 'f-nama'
      scrollToId(first, 'center')
      document.getElementById(first)?.focus({ preventScroll: true })
      return
    }
    setSaving(true); setError('')
    try {
      const payload = buildPayload(f)
      if (id) await api.update(id, payload)
      else await api.create(payload)
      initial.current = JSON.stringify(f)
      onClose()
    } catch (err) {
      setError(err.message)
    } finally {
      setSaving(false)
    }
  }

  // Ctrl/Cmd + S = simpan
  const saveRef = useRef(save)
  saveRef.current = save
  useEffect(() => {
    const onKey = (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault()
        saveRef.current()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const close = () => {
    if (dirty && !confirm('Ada perubahan yang belum disimpan. Tutup tanpa menyimpan?')) return
    onClose()
  }

  if (loading) return <div className="container narrow form-page"><p className="muted">Memuat…</p></div>

  const totalWarn = f.steps.reduce((n, _, i) => n + warningsOf(f.steps, i).length, 0)
  const allOpen = f.steps.length > 0 && f.steps.every((s) => open.has(s._id))

  return (
    <div className="container narrow form-page">
      <header className="form-head">
        <button type="button" className="icon-btn" onClick={close} title="Kembali" aria-label="Kembali">
          <Icon name="back" />
        </button>
        <div>
          <h1>{id ? 'Edit SOP' : 'SOP baru'}</h1>
          {f.nama_sop && <p className="sub">{f.nama_sop}</p>}
        </div>
      </header>

      <nav className="secnav" aria-label="Bagian form">
        <button type="button" onClick={() => scrollToId('sec-identitas')}><span className="n">1</span>Identitas</button>
        <button type="button" onClick={() => scrollToId('sec-info')}><span className="n">2</span>Informasi</button>
        <button type="button" onClick={() => scrollToId('sec-pelaksana')}><span className="n">3</span>Pelaksana</button>
        <button type="button" onClick={() => scrollToId('sec-prosedur')}><span className="n">4</span>Prosedur ({f.steps.length})</button>
      </nav>

      <Section id="sec-identitas" no="1" title="Identitas SOP" hint="Data yang tampil di bagian atas dokumen.">
        <div className={`field${errs.instansi ? ' invalid' : ''}`}>
          <label htmlFor="f-instansi">Nama instansi <span className="req">wajib</span></label>
          <input id="f-instansi" value={f.instansi} onChange={up('instansi')} />
          {errs.instansi && <span className="field-error">Nama instansi wajib diisi.</span>}
        </div>
        <div className="grid g21">
          <div className={`field${errs.nama_sop ? ' invalid' : ''}`}>
            <label htmlFor="f-nama">Nama SOP <span className="req">wajib</span></label>
            <input id="f-nama" value={f.nama_sop} onChange={up('nama_sop')} />
            {errs.nama_sop && <span className="field-error">Nama SOP wajib diisi.</span>}
          </div>
          <div className="field"><label>Nomor SOP</label><input value={f.nomor_sop ?? ''} onChange={up('nomor_sop')} /></div>
        </div>
        <div className="grid g3">
          <div className="field"><label>Tanggal pembuatan</label><input type="date" value={f.tanggal_pembuatan} onChange={up('tanggal_pembuatan')} /></div>
          <div className="field"><label>Tanggal revisi</label><input type="date" value={f.tanggal_revisi} onChange={up('tanggal_revisi')} /></div>
          <div className="field"><label>Tanggal efektif</label><input type="date" value={f.tanggal_efektif} onChange={up('tanggal_efektif')} /></div>
        </div>
        <h3 className="subhead">Disahkan oleh</h3>
        <div className="grid g3">
          <div className="field"><label>Jabatan</label><input value={f.pengesah_jabatan ?? ''} onChange={up('pengesah_jabatan')} /></div>
          <div className="field"><label>Nama pejabat</label><input value={f.pengesah_nama ?? ''} onChange={up('pengesah_nama')} /></div>
          <div className="field"><label>NIP</label><input value={f.pengesah_nip ?? ''} onChange={up('pengesah_nip')} /></div>
        </div>
        <h3 className="subhead">Tabel paraf</h3>
        <ListEditor label="Paraf hirarki" hint="Jabatan pada tabel paraf. Kosongkan semua untuk menyembunyikan tabel." items={f.paraf_hirarki} onChange={setList('paraf_hirarki')} />
      </Section>

      <Section id="sec-info" no="2" title="Informasi pendukung">
        <ListEditor label="Dasar hukum" items={f.dasar_hukum} onChange={setList('dasar_hukum')} />
        <ListEditor label="Kualifikasi pelaksana" items={f.kualifikasi} onChange={setList('kualifikasi')} />
        <ListEditor label="Keterkaitan" items={f.keterkaitan} onChange={setList('keterkaitan')} />
        <ListEditor label="Peralatan / perlengkapan" items={f.peralatan} onChange={setList('peralatan')} />
        <div className="field"><label>Peringatan</label><AutoText rows={3} value={f.peringatan ?? ''} onChange={up('peringatan')} /></div>
        <div className="field"><label>Pencatatan dan pendataan</label><AutoText rows={3} value={f.pencatatan ?? ''} onChange={up('pencatatan')} /></div>
      </Section>

      <Section id="sec-pelaksana" no="3" title="Pelaksana" hint="Tiap pelaksana menjadi satu kolom pada tabel prosedur.">
        <ListEditor
          label="Daftar pelaksana"
          items={f.pelaksana}
          onChange={setList('pelaksana')}
          onRemove={removePelaksana}
          usage={(k) => (usageOf(k) ? `${usageOf(k)} langkah` : 'belum dipakai')}
        />
      </Section>

      <Section
        id="sec-prosedur" no="4" title="Prosedur / kegiatan"
        hint={`${f.steps.length} langkah${totalWarn ? ` · ${totalWarn} peringatan alur` : ''}. Klik langkah untuk membuka / menutup.`}
        actions={
          <button type="button" onClick={() => setOpen(allOpen ? new Set() : new Set(f.steps.map((s) => s._id)))}>
            {allOpen ? 'Ciutkan semua' : 'Buka semua'}
          </button>
        }
      >
        {f.steps.map((s, i) => {
          const isOpen = open.has(s._id)
          const warnCount = warningsOf(f.steps, i).length
          const names = s.pelaksana.map((k) => f.pelaksana[k] || `Pelaksana ${k + 1}`).join(', ') || 'Belum ada pelaksana'
          const sym = SYMBOLS.find((x) => x.value === (s.simbol || 'proses'))
          return (
            <div className={`step${isOpen ? ' open' : ''}`} key={s._id} id={`step-${s._id}`}>
              <div className="step-head">
                <button type="button" className="step-toggle" aria-expanded={isOpen} onClick={() => toggleOpen(s._id)}>
                  <span className="step-no">{i + 1}</span>
                  <span className="step-main">
                    <span className="step-title">{(s.kegiatan || '').trim() || 'Langkah baru'}</span>
                    <span className="step-meta">
                      <SymbolShape type={s.simbol || 'proses'} w={26} />
                      <span>{sym?.label}</span>
                      <span>·</span>
                      <span>{names}</span>
                      <span>·</span>
                      <span>{summaryOf(f.steps, i)}</span>
                    </span>
                  </span>
                  {warnCount > 0 && <span className="badge warn" title="Ada peringatan alur">⚠ {warnCount}</span>}
                  <span className="step-chev"><Icon name="chevron" size={18} /></span>
                </button>
                <span className="step-tools">
                  <button type="button" className="icon-btn" disabled={i === 0} onClick={() => move(i, -1)} title="Naikkan" aria-label="Naikkan"><Icon name="up" size={16} /></button>
                  <button type="button" className="icon-btn" disabled={i === f.steps.length - 1} onClick={() => move(i, 1)} title="Turunkan" aria-label="Turunkan"><Icon name="down" size={16} /></button>
                  <button type="button" className="icon-btn" onClick={() => duplicateStep(i)} title="Duplikat langkah" aria-label="Duplikat langkah"><Icon name="copy" size={16} /></button>
                  <button type="button" className="icon-btn danger" onClick={() => removeStep(i)} title="Hapus langkah" aria-label="Hapus langkah"><Icon name="trash" size={16} /></button>
                </span>
              </div>

              {isOpen && (
                <div className="step-body">
                  <div className="step-block">
                    <h4>Kegiatan</h4>
                    <div className="field">
                      <AutoText rows={2} value={s.kegiatan} onChange={(e) => setStep(i, { kegiatan: e.target.value })} placeholder="Uraikan kegiatan pada langkah ini" />
                    </div>
                  </div>

                  <div className="step-block">
                    <h4>Pelaksana yang terlibat</h4>
                    <div className="checks">
                      {f.pelaksana.map((p, k) => {
                        const on = s.pelaksana.includes(k)
                        return (
                          <label key={k} className={`chip${on ? ' on' : ''}`}>
                            <input type="checkbox" checked={on} onChange={() => toggle(i, k)} />
                            {on && <Icon name="check" size={14} />}
                            {p || `Pelaksana ${k + 1}`}
                          </label>
                        )
                      })}
                    </div>
                  </div>

                  <div className="step-block">
                    <h4>Simbol dan alur</h4>
                    <div className="flow-grid">
                      <div className="field"><label>Simbol</label>
                        <SymbolPicker value={s.simbol || 'proses'} onChange={(v) => setStep(i, { simbol: v })} /></div>
                      <LanjutEditor steps={f.steps} i={i} onChange={(v) => setStep(i, { lanjut: v })} />
                    </div>
                  </div>

                  <div className="step-block">
                    <h4>Mutu baku</h4>
                    <div className="grid g2">
                      <div className="field"><label>Persyaratan dan kelengkapan</label>
                        <AutoText rows={2} value={s.persyaratan} onChange={(e) => setStep(i, { persyaratan: e.target.value })} /></div>
                      <div className="field"><label>Output</label>
                        <AutoText rows={2} value={s.output} onChange={(e) => setStep(i, { output: e.target.value })} /></div>
                      <div className="field"><label>Waktu</label>
                        <input value={s.waktu} onChange={(e) => setStep(i, { waktu: e.target.value })} placeholder="mis. 5 menit" /></div>
                      <div className="field"><label>Keterangan</label>
                        <AutoText rows={2} value={s.keterangan} onChange={(e) => setStep(i, { keterangan: e.target.value })} /></div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          )
        })}
        <button type="button" className="add-btn" onClick={addStep}>
          <Icon name="plus" size={16} /> Tambah langkah
        </button>
      </Section>

      {error && <pre className="error-box error">{error}</pre>}

      <footer className="savebar">
        <div className="savebar-in">
          {dirty && <span className="dirty-note">Ada perubahan yang belum disimpan</span>}
          <button type="button" onClick={close}>Batal</button>
          <button className="primary" disabled={saving} onClick={save} title="Ctrl + S">{saving ? 'Menyimpan…' : 'Simpan'}</button>
        </div>
      </footer>
    </div>
  )
}
