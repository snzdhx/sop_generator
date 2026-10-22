// Alamat backend diambil dari frontend/.env -> VITE_API_URL (restart `npm run dev` setelah mengubahnya)
const API_URL = (import.meta.env.VITE_API_URL || '').replace(/\/$/, '')
const BASE = `${API_URL}/api/sops`
const headers = { 'Content-Type': 'application/json', Accept: 'application/json' }

async function request(url, options = {}) {
  let res
  try {
    res = await fetch(url, { headers, ...options })
  } catch {
    throw new Error(`Tidak bisa terhubung ke backend di ${API_URL || 'server ini'}. Pastikan "php artisan serve" berjalan.`)
  }
  if (res.status === 422) {
    const data = await res.json()
    throw new Error(Object.values(data.errors).flat().join('\n'))
  }
  if (!res.ok) throw new Error(`Server error (${res.status})`)
  return res.status === 204 ? null : res.json()
}

// Pratinjau: kirim data form (tanpa menyimpan), terima PDF hasil render dokumen Word
async function preview(data, signal) {
  let res
  try {
    res = await fetch(`${BASE}/preview`, { method: 'POST', headers, body: JSON.stringify(data), signal })
  } catch (e) {
    if (e.name === 'AbortError') throw e
    throw new Error(`Tidak bisa terhubung ke backend di ${API_URL || 'server ini'}. Pastikan "php artisan serve" berjalan.`)
  }
  if (!res.ok) {
    let msg = `Server error (${res.status})`
    try {
      const j = await res.json()
      msg = j.errors ? Object.values(j.errors).flat().join('\n') : j.message || msg
    } catch { /* abaikan */ }
    throw new Error(msg)
  }
  return res.blob()
}

export const api = {
  list: () => request(BASE),
  get: (id) => request(`${BASE}/${id}`),
  create: (data) => request(BASE, { method: 'POST', body: JSON.stringify(data) }),
  update: (id, data) => request(`${BASE}/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  remove: (id) => request(`${BASE}/${id}`, { method: 'DELETE' }),
  downloadUrl: (id) => `${BASE}/${id}/download`,
  preview,
}
