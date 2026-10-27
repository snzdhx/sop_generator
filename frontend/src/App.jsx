import { useEffect, useMemo, useState } from 'react'
import { api } from './api'
import SopForm from './SopForm.jsx'

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
      <header className="bar">
        <h1>Generator SOP</h1>
        <button className="primary" onClick={() => setEditingId('new')}>+ SOP Baru</button>
      </header>

      {error && <p className="error">{error}</p>}

      <div className="filters">
        <input
          type="search"
          placeholder="Cari nama atau nomor SOP..."
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
        <select value={sort} onChange={(e) => setSort(e.target.value)} aria-label="Urutkan">
          <option value="tanggal-desc">Tanggal: terbaru</option>
          <option value="tanggal-asc">Tanggal: terlama</option>
          <option value="nama-asc">Nama: A → Z</option>
          <option value="nama-desc">Nama: Z → A</option>
        </select>
      </div>

      <table className="list">
        <thead>
          <tr><th>Nama SOP</th><th>Nomor</th><th>Terakhir diubah</th><th></th></tr>
        </thead>
        <tbody>
          {sops.length === 0 && (
            <tr><td colSpan="4" className="muted">Belum ada SOP.</td></tr>
          )}
          {sops.length > 0 && shown.length === 0 && (
            <tr><td colSpan="4" className="muted">Tidak ada SOP yang cocok dengan "{query}".</td></tr>
          )}
          {shown.map((s) => (
            <tr key={s.id}>
              <td>{s.nama_sop}</td>
              <td>{s.nomor_sop || '-'}</td>
              <td>{new Date(s.updated_at).toLocaleString('id-ID')}</td>
              <td className="actions">
                <a className="btn primary" href={api.downloadUrl(s.id)}>Unduh .docx</a>
                <button onClick={() => setEditingId(s.id)}>Edit</button>
                <button className="danger" onClick={() => remove(s.id)}>Hapus</button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
