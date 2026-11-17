# Pemasangan endpoint "Penilaian Kinerja Baru" di AIS

Bab **Penilaian Kinerja Baru** di aplikasi absensi memakai dua endpoint baru di AIS (Laravel, laptop sekolah).
Endpoint lama (`academic-averages`, `academic-students`, `tahfidz-achievement`) **tidak diubah**.

> Status 17 Nov 2026: kedua endpoint sudah terpasang dan berjalan di laptop sekolah.

| Endpoint | Controller | Isi |
|---|---|---|
| `GET V1/performance/v2/pas-scores` | `KinerjaBaruApiController@getPasScores` | Nilai PAS mentah per santri per mapel |
| `GET V1/performance/v2/tahfidz` | `KinerjaBaruTahfidzController@getTahfidzV2` | Data tahfidz (logika sama dengan `tahfidz-achievement`) + daftar tanggal jurnal per guru & per unit |

## Keamanan

- Kedua endpoint **hanya** menerima header `X-AIS-KEY` berisi `KINERJA_BARU_API_KEY` (minimal 32 karakter).
- Tidak ada kunci cadangan di kode (repo ini publik) dan kunci **tidak** diterima lewat URL (`?api_key=`).
- Jika `KINERJA_BARU_API_KEY` belum diisi, endpoint membalas 503 dan tidak mengirim data.
- Kunci ini **berbeda** dari kunci endpoint lama (yang sudah terlanjur tertulis di repo publik).

## 1. Salin controller

```
annahl/API/app/Http/Controllers/Api/V1/KinerjaBaruApiController.php
annahl/API/app/Http/Controllers/Api/V1/KinerjaBaruTahfidzController.php
```

`KinerjaBaruTahfidzController` mewarisi `PerformanceApiController`, jadi file itu harus sudah ada.

## 2. Tambah route

Di `annahl/API/routes/api.php`, di grup `V1` publik yang sama dengan `performance/academic-averages`:

```php
Route::get('/performance/v2/pas-scores', 'KinerjaBaruApiController@getPasScores');
Route::get('/performance/v2/tahfidz', 'KinerjaBaruTahfidzController@getTahfidzV2');
```

## 3. Kunci rahasia di AIS

Tambahkan ke `.env` AIS (di laptop sekolah: `~/projects/ais_docker/lms.env`, di-mount ke container `ais-php`):

```
KINERJA_BARU_API_KEY=<64 karakter hex acak, mis. hasil: openssl rand -hex 32>
```

Tambahkan dengan `>>` (menambah ke file yang sama) agar container langsung membacanya tanpa restart.

## 4. Kunci yang sama di server absensi

Tambahkan ke `.env` aplikasi absensi (file ini tidak ikut git):

```
KINERJA_BARU_API_KEY=<kunci yang sama dengan langkah 3>
```

Opsional, jika alamat AIS berbeda dari bawaan (`https://ais.codika.my.id/V1/performance/...`):

```
AIS_KINERJA_BARU_API_URL=https://ais.codika.my.id/V1/performance/v2/pas-scores
AIS_KINERJA_BARU_API_FALLBACK_URL=http://100.126.179.54:8069/V1/performance/v2/pas-scores
```

## 5. Uji

```bash
curl -s -o /dev/null -w "%{http_code}\n" -H "X-AIS-KEY: <kunci>" "http://127.0.0.1:8069/V1/performance/v2/pas-scores?unit_id=2"   # 200
curl -s -o /dev/null -w "%{http_code}\n" "http://127.0.0.1:8069/V1/performance/v2/pas-scores?unit_id=2"                            # 401
```

## Isi data

- `pas-scores`: nilai PAS **mentah** (tanpa rata-rata). Santri/rombel/kelas yang terhapus tidak ikut.
  Format baris nilai: `[anr_id, enrol_id, mapel_id, nilai_pas, updated_at]`.
- `tahfidz`: sama dengan `tahfidz-achievement`, ditambah `kb_jurnal_dates.unit` (tanggal ada kegiatan halaqah di unit)
  dan `kb_jurnal_dates.guru` (`guru_id => [tanggal jurnal diisi]`).

Seluruh perhitungan dilakukan di `includes/kinerja_baru.php` dan `includes/kinerja_baru_tahfidz.php` aplikasi absensi.
