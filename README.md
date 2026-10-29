# Generator SOP

Aplikasi pembuat dokumen SOP (.docx) lengkap dengan flowchart.

| Bagian | Teknologi | Folder | Port |
|---|---|---|---|
| Backend (API + pembuat .docx) | Laravel 13, SQLite | `backend/` | 8000 |
| Frontend (antarmuka) | React 18 + Vite 8 | `frontend/` | 5173 |

## Menjalankan

Buka dua terminal.

**Terminal 1: backend**

```bash
cd backend
php artisan serve
```

**Terminal 2: frontend**

```bash
cd frontend
npm run dev
```

Lalu buka http://localhost:5173.

## Cara pakai singkat

1. Klik **SOP baru**, isi identitas, informasi pendukung, pelaksana, dan langkah prosedur.
2. Klik **Simpan** (atau `Ctrl + S`).
3. Di halaman daftar, klik ikon unduh untuk mendapatkan file `.docx`.

## Jika ada masalah

| Gejala | Solusi |
|---|---|
| "Tidak bisa terhubung ke backend…" | Pastikan `php artisan serve` sedang berjalan. Cek `VITE_API_URL` di `frontend/.env`, lalu restart `npm run dev`. |
| `no such table: sops` | Jalankan `php artisan migrate --force` di folder `backend`. |
| Port 8000 / 5173 dipakai | Backend: `php artisan serve --port=8001`, lalu sesuaikan `VITE_API_URL`. Frontend: ubah `server.port` di `vite.config.js`. |
| Error saat unduh .docx | Pastikan ekstensi PHP `zip` dan `gd` aktif. |
