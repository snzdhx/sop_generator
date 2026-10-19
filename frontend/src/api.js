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

export const api = {
  list: () => request(BASE),
  get: (id) => request(`${BASE}/${id}`),
  create: (data) => request(BASE, { method: 'POST', body: JSON.stringify(data) }),
  update: (id, data) => request(`${BASE}/${id}`, { method: 'PUT', body: JSON.stringify(data) }),
  remove: (id) => request(`${BASE}/${id}`, { method: 'DELETE' }),
  downloadUrl: (id) => `${BASE}/${id}/download`,
}
