# Panduan Menjalankan Generator SOP

Sistem terdiri dari dua bagian yang dijalankan bersamaan:

| Bagian | Teknologi | Folder | Port |
|---|---|---|---|
| Backend (API + pembuat .docx) | Laravel 13, SQLite, PhpWord | `backend/` | 8000 |
| Frontend (antarmuka) | React 18 + Vite 8 | `frontend/` | 5173 |

## 1. Prasyarat

- **PHP 8.3+** dengan ekstensi: `gd`, `zip`, `dom`, `xml`, `mbstring`, `openssl`, `fileinfo`, `pdo_sqlite`, `sqlite3`
  (`gd` dan `zip` wajib untuk PhpWord. Jika memakai XAMPP, aktifkan di `php.ini` dengan menghapus tanda `;` di depan `extension=gd`, `extension=zip`, `extension=pdo_sqlite`, `extension=sqlite3`).
- **Composer 2.x**
- **Node.js 20.19+ atau 22.12+** (dibutuhkan Vite 8) dan npm
- Microsoft Word / LibreOffice untuk membuka hasil `.docx`

Cek versi:

```bash
php -v
php -m        # pastikan gd, zip, pdo_sqlite ada
composer -V
node -v
```

## 2. Menjalankan Backend (terminal 1)

```bash
cd backend

# 1. Pasang dependensi
composer install

# 2. Siapkan file environment
cp .env.example .env          # Windows CMD: copy .env.example .env
php artisan key:generate

# 3. Buat file database SQLite (kosong)
touch database/database.sqlite   # Windows CMD: type nul > database\database.sqlite

# 4. Buat tabel
php artisan migrate --force

# 5. (Opsional) Isi contoh data: SOP Pengelolaan CCTV BKPSDM
php artisan db:seed --class=SopSeeder

# 6. Jalankan server
php artisan serve
```

Pintasan: `composer run setup` menjalankan langkah 1-4 sekaligus (tetap buat dulu `database/database.sqlite` jika belum ada).
`composer run dev` sama dengan `php artisan serve`.

Cek backend hidup: buka http://127.0.0.1:8000 → harus tampil
`{"app":"SOP Generator API","status":"ok"}`.
Cek API: http://127.0.0.1:8000/api/sops → daftar SOP (JSON).

> Catatan: `php artisan db:seed` tanpa `--class` hanya membuat user test, **bukan** data SOP.

### Logo (opsional)

Logo pada header dokumen dibaca dari `backend/resources/sop/logo.png`. Jika file tidak ada, dokumen tetap dibuat tanpa logo.

## 3. Menjalankan Frontend (terminal 2)

```bash
cd frontend

# 1. Pasang dependensi
npm install

# 2. Siapkan alamat backend
cp .env.example .env          # Windows CMD: copy .env.example .env
#    isi: VITE_API_URL=http://127.0.0.1:8000

# 3. Jalankan
npm run dev
```

Buka http://localhost:5173.

> Jika `VITE_API_URL` diubah, hentikan lalu jalankan ulang `npm run dev`.

## 4. Cara Pakai Singkat

1. Klik **SOP baru**, isi identitas, informasi pendukung, daftar pelaksana, dan langkah-langkah prosedur (pilih simbol Proses/Mulai/Selesai/Keputusan dan tujuan "Lanjut ke").
2. Klik **Simpan** (atau `Ctrl + S`).
3. Di halaman daftar, klik ikon unduh untuk mendapatkan file `.docx` lengkap dengan flowchart.

## 5. Build Produksi Frontend (opsional)

```bash
cd frontend
npm run build      # hasil di frontend/dist
```

Untuk produksi, set `VITE_API_URL` ke alamat backend yang sebenarnya sebelum build, dan di backend gunakan `APP_ENV=production`, `APP_DEBUG=false`.

## 6. Menjalankan Test Backend

```bash
cd backend
composer test
```

## 7. Pemecahan Masalah

| Gejala | Penyebab / solusi |
|---|---|
| "Tidak bisa terhubung ke backend…" di frontend | `php artisan serve` belum jalan, atau `VITE_API_URL` salah. Perbaiki `.env` lalu restart `npm run dev`. |
| `could not find driver` | Ekstensi `pdo_sqlite` belum aktif di `php.ini`. |
| `Class "ZipArchive" not found` / error saat unduh .docx | Aktifkan ekstensi `zip` dan `gd`. |
| `No application encryption key has been specified` | Jalankan `php artisan key:generate`. |
| `Database file ... does not exist` | Buat `backend/database/database.sqlite` (langkah 3 backend). |
| `no such table: sops` | Jalankan `php artisan migrate --force`. |
| Daftar SOP kosong | Normal jika belum ada data; jalankan seeder (langkah 5 backend) atau buat SOP baru. |
| Error versi Node saat `npm install` / `npm run dev` | Naikkan Node ke 20.19+ atau 22.12+. |
| Port 8000 / 5173 dipakai | Backend: `php artisan serve --port=8001` lalu sesuaikan `VITE_API_URL`. Frontend: ubah `server.port` di `vite.config.js`. |
| Posisi panah/simbol di Word sedikit bergeser | Hasil dirancang untuk Word; LibreOffice bisa sedikit berbeda. Buka dengan Microsoft Word untuk hasil terbaik. |
