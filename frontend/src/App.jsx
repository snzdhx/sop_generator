import { useEffect, useMemo, useState } from 'react'
import { api } from './api'
import SopForm from './SopForm.jsx'

// Ikon garis sederhana (24x24), warna mengikuti teks
const PATHS = {
  search: <><circle cx="11" cy="11" r="6.5" /><path d="m16 16 4.5 4.5" /></>,
  plus: <path d="M12 5v14M5 12h14" />,
  download: <path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19.5h14" />,
  edit: <path d="M4 20h4l10.5-10.5a2.1 2.1 0 0 0-3-3L5 17v3Zm9.5-13.5 3 3" />,
  trash: <path d="M5 7h14M10 7V5h4v2m-8 0 .8 12h8.4L17 7M10 11v5m4-5v5" />,
}

function Icon({ name, size = 18 }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} fill="none" stroke="currentColor"
      strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      {PATHS[name]}
    </svg>
  )
}

const fmtDate = (v) => new Date(v).toLocaleString('id-ID', {
  day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
})

export default function App() {
  const [sops, setSops] = useState([])
  const [editingId, setEditingId] = useState(null) // null = daftar, 'new' = baru, angka = edit
  const [error, setError] = useState('')
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState('tanggal-desc') // nama-asc | nama-desc | tanggal-desc | tanggal-asc

  // Cari di nama & nomor SOP, lalu urutkan menurut nama atau tanggal terakhir diubah
  const shown = useMemo(() => {
    const q = query.trim().toLowerCase()
    const rows = sops.filter((s) =>
      !q || `${s.nama_sop || ''} ${s.nomor_sop || ''}`.toLowerCase().includes(q))
    const [key, dir] = sort.split('-')
    const sign = dir === 'asc' ? 1 : -1
    return [...rows].sort((a, b) =>
      key === 'nama'
        ? sign * (a.nama_sop || '').localeCompare(b.nama_sop || '', 'id', { sensitivity: 'base', numeric: true })
        : sign * (new Date(a.updated_at) - new Date(b.updated_at)))
  }, [sops, query, sort])

  const load = () => api.list().then(setSops).catch((e) => setError(e.message))
  useEffect(() => { load() }, [])

  const remove = async (id) => {
    if (!confirm('Hapus SOP ini?')) return
    await api.remove(id)
    load()
  }

  if (editingId !== null) {
    return (
      <SopForm
        id={editingId === 'new' ? null : editingId}
        onClose={() => { setEditingId(null); load() }}
      />
    )
  }

  return (
    <div className="container">
      <header className="page-head">
        <div>
          <h1>Generator SOP</h1>
          <p className="sub">{sops.length} dokumen</p>
        </div>
        <button className="primary with-icon" onClick={() => setEditingId('new')}>
          <Icon name="plus" size={16} /> SOP baru
        </button>
      </header>

      {error && <p className="error">{error}</p>}

      <div className="filters">
        <label className="search">
          <Icon name="search" size={16} />
          <input
            type="search"
            placeholder="Cari nama atau nomor SOP"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </label>
        <select value={sort} onChange={(e) => setSort(e.target.value)} aria-label="Urutkan">
          <option value="tanggal-desc">Terbaru diubah</option>
          <option value="tanggal-asc">Terlama diubah</option>
          <option value="nama-asc">Nama A–Z</option>
          <option value="nama-desc">Nama Z–A</option>
        </select>
      </div>

      <div className="panel">
        <table className="list">
          <colgroup>
            <col />
            <col className="c-nomor" />
            <col className="c-tgl" />
            <col className="c-aksi" />
          </colgroup>
          <thead>
            <tr><th>Nama SOP</th><th className="c-nomor">Nomor</th><th className="c-tgl">Terakhir diubah</th><th><span className="sr-only">Aksi</span></th></tr>
          </thead>
          <tbody>
            {sops.length === 0 && (
              <tr><td colSpan="4" className="empty">Belum ada SOP. Klik “SOP baru” untuk membuat yang pertama.</td></tr>
            )}
            {sops.length > 0 && shown.length === 0 && (
              <tr><td colSpan="4" className="empty">Tidak ada SOP yang cocok dengan “{query}”.</td></tr>
            )}
            {shown.map((s) => (
              <tr key={s.id}>
                <td className="name">{s.nama_sop}</td>
                <td className="c-nomor">{s.nomor_sop || '–'}</td>
                <td className="c-tgl">{fmtDate(s.updated_at)}</td>
                <td>
                  <div className="actions">
                    <a className="icon-btn" href={api.downloadUrl(s.id)} title="Unduh .docx" aria-label="Unduh .docx">
                      <Icon name="download" />
                    </a>
                    <button className="icon-btn" onClick={() => setEditingId(s.id)} title="Edit" aria-label="Edit">
                      <Icon name="edit" />
                    </button>
                    <button className="icon-btn danger" onClick={() => remove(s.id)} title="Hapus" aria-label="Hapus">
                      <Icon name="trash" />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
