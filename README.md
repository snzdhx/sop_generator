# Generator SOP

```
sop_generator/
├── backend/    Laravel 13: API + pembuat .docx (http://127.0.0.1:8000)
└── frontend/   React + Vite: form & daftar SOP (http://localhost:5173)
```

## Backend
```bash
cd backend
composer install            # jika vendor/ belum ada
php artisan migrate         # database: lihat backend/.env (saat ini MySQL "sop_generator")
php artisan db:seed --class=SopSeeder   # opsional, contoh SOP CCTV
php artisan serve           # http://127.0.0.1:8000
```
Jika `.env` baru/dibuat ulang: `php artisan key:generate`. Ekstensi PHP: zip, xml, gd.

## Frontend
```bash
cd frontend
npm install
npm run dev                 # http://localhost:5173
```
Alamat API ada di `frontend/.env`:
```
VITE_API_URL=http://127.0.0.1:8000
```
Ubah jika backend memakai alamat lain (mis. `http://sop_generator.test` di Laragon), lalu restart `npm run dev`.
CORS sudah terbuka untuk `/api/*` (bawaan Laravel), jadi tidak perlu setting tambahan.

## API
GET/POST `/api/sops` · GET/PUT/DELETE `/api/sops/{id}` · GET `/api/sops/{id}/download`
