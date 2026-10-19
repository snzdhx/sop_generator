import { useEffect, useState } from 'react'
import { api } from './api'
import SopForm from './SopForm.jsx'

export default function App() {
  const [sops, setSops] = useState([])
  const [editingId, setEditingId] = useState(null) // null = daftar, 'new' = baru, angka = edit
  const [error, setError] = useState('')

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

      <table className="list">
        <thead>
          <tr><th>Nama SOP</th><th>Nomor</th><th>Terakhir diubah</th><th></th></tr>
        </thead>
        <tbody>
          {sops.length === 0 && (
            <tr><td colSpan="4" className="muted">Belum ada SOP.</td></tr>
          )}
          {sops.map((s) => (
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
