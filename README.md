# AIS Absensi - Sistem Presensi & Manajemen Akademik

Sistem informasi presensi dan manajemen akademik berbasis web, dirancang untuk memudahkan pencatatan kehadiran (barcode/QR scanner), pengelolaan data siswa & staf, jadwal kelas, laporan berkala, hingga audit log aktivitas. Seluruh sistem telah dikontainerisasi menggunakan **Docker** dan **Docker Compose** untuk deployment yang cepat dan andal.

---

## 🚀 Fitur Utama

- **Presensi Cepat (Barcode / QR Scanner)**: Pencatatan presensi real-time berbasis scanner web camera maupun scanner fisik.
- **Dashboard Statistik Kehadiran**: Visualisasi statistik kehadiran harian, mingguan, dan bulanan siswa serta staf.
- **Manajemen Data Siswa & Staf**: Pencatatan profil, kelas, unit pendidikan, dan histori kehadiran.
- **Manajemen Akademik**: Pengelolaan tahun ajaran, jadwal mingguan (*weekly schedules*), kelas, dan penugasan.
- **Laporan & Rekapitulasi (PDF Export)**: Pembuatan laporan kehadiran dan rekapitulasi data dengan dukungan cetak PDF via FPDF.
- **Audit Logging**: Jejak audit komprehensif untuk keamanan dan pelacakan seluruh aktivitas admin/staf.
- **Manajemen Multi-User & Hak Akses**: Dukungan peran Super Admin, Admin, dan Staf.

---

## 🛠️ Tech Stack & Arsitektur

- **Backend**: PHP 8.2 (PHP-FPM Alpine)
- **Web Server**: Nginx Alpine (Reverse Proxy & Static file handler)
- **Database**: MariaDB 10.11
- **PDF Engine**: FPDF
- **Frontend**: Responsive UI (Bootstrap / Vanilla JS / HTML5 QR Scanner)
- **Containerization**: Docker & Docker Compose

---

## 📁 Struktur Direktori

```text
ais-absensi/
├── app/                      # Source code aplikasi PHP
│   ├── assets/               # File CSS & JavaScript
│   ├── config/               # Konfigurasi database, auth, & security
│   ├── includes/             # Header, footer, sidebar, & fungsi bantuan
│   ├── kelola/               # Modul pengelolaan super admin
│   ├── public/               # File public, controller, scanner, & view
│   │   ├── academic_years/   # Modul Tahun Ajaran
│   │   ├── activities/       # Modul Aktivitas
│   │   ├── admins/           # Modul Admin
│   │   ├── attendance/       # Modul Presensi
│   │   ├── classes/          # Modul Kelas
│   │   ├── reports/          # Modul Laporan
│   │   ├── scanner.php       # Antarmuka Scanner Presensi
│   │   ├── staff/            # Modul Staf
│   │   └── students/         # Modul Siswa
│   ├── src/                  # Service classes & business logic
│   ├── uploads/              # Direktori upload media (admins, staff, students)
│   └── vendor/               # Library pihak ketiga (FPDF)
├── database/
│   └── init.sql              # Inisialisasi skema & struktur database awal
├── nginx/
│   └── default.conf          # Konfigurasi server Nginx
├── php/
│   ├── Dockerfile            # Dockerfile custom PHP 8.2-FPM Alpine + ekstensi
│   └── php.ini               # Konfigurasi custom PHP
├── .env.example              # Contoh konfigurasi variabel lingkungan
├── docker-compose.yml        # Orchestrasi multi-container Docker
└── README.md
```

---

## ⚡ Panduan Instalasi & Menjalankan (Docker)

### Prasyarat
- [Docker](https://docs.docker.com/get-docker/) (versi 20.10+)
- [Docker Compose](https://docs.docker.com/compose/) (versi 2.0+)
- Git

### Langkah-langkah

1. **Clone repositori**:
   ```bash
   git clone https://github.com/ametriyadhi/ais-absensi.git
   cd ais-absensi
   ```

2. **Salin dan konfigurasi Environment**:
   ```bash
   cp .env.example .env
   ```
   Sesuaikan konfigurasi pada file `.env` jika diperlukan (port, credentials database, dll).

3. **Build dan Jalankan Container**:
   ```bash
   docker compose up -d --build
   ```

4. **Periksa Status Container**:
   ```bash
   docker compose ps
   ```
   Pastikan ketiga container (`ais_absensi_db`, `ais_absensi_app`, `ais_absensi_web`) berstatus `healthy` / `running`.

5. **Akses Aplikasi**:
   Buka browser dan akses:
   ```text
   http://localhost:8089
   ```
   *(atau port sesuai pengaturan `APP_PORT` di file `.env`)*

---

## 🔧 Maintenance & Command Bermanfaat

- **Melihat logs container**:
  ```bash
  docker compose logs -f
  ```
- **Menghentikan container**:
  ```bash
  docker compose down
  ```
- **Masuk ke dalam container app (PHP-FPM)**:
  ```bash
  docker compose exec app sh
  ```
- **Masuk ke dalam database MariaDB**:
  ```bash
  docker compose exec db mariadb -u absensi_user -p library_v4
  ```

---

## 📄 Lisensi

Proyek ini dikembangkan untuk kebutuhan internal sistem presensi AIS.
