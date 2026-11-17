<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API "Penilaian Kinerja Baru" — Tahfidz & Jurnal Guru Qur'an (read-only).
 *
 * Memakai ulang seluruh logika getTahfidzAchievement() dari PerformanceApiController (tidak diubah),
 * dengan dua perbedaan:
 *  1. Dilindungi kunci khusus KINERJA_BARU_API_KEY (tanpa kunci cadangan di kode, tanpa ?api_key=).
 *  2. Menambahkan DAFTAR TANGGAL jurnal per guru dan per unit, agar % jurnal di aplikasi absensi bisa
 *     dihitung terhadap "hari guru hadir DAN ada kegiatan halaqah", bukan sekadar jumlah hari.
 *
 * Route: GET V1/performance/v2/tahfidz?unit_id=2&tahun_ajaran_id=10&month=0
 */
class KinerjaBaruTahfidzController extends PerformanceApiController
{
    protected function authorizeApiKey(Request $request)
    {
        $expectedKey = (string)env('KINERJA_BARU_API_KEY', '');
        if (strlen($expectedKey) < 32) {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint belum dikonfigurasi (KINERJA_BARU_API_KEY).',
            ], 503);
        }
        $clientKey = (string)($request->header('X-AIS-KEY') ?? '');
        if ($clientKey === '' || !hash_equals($expectedKey, $clientKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: X-AIS-KEY tidak valid.',
            ], 401);
        }
        return null;
    }

    public function getTahfidzV2(Request $request)
    {
        $base = $this->getTahfidzAchievement($request);
        if (!$base instanceof JsonResponse) {
            return $base;
        }
        $data = $base->getData(true);
        if (empty($data['success'])) {
            return $base;
        }

        try {
            $unitId = (int)($data['meta']['selected_unit']['id'] ?? 0);
            $taId   = (int)($data['meta']['selected_tahun_ajaran']['id'] ?? 0);
            $month  = (int)($data['meta']['selected_month'] ?? 0);
            $monthSql = ($month >= 1 && $month <= 12) ? ' AND MONTH(qj.date) = ' . $month : '';

            $rows = DB::select("
                SELECT DISTINCT qh.pengajar_id AS guru_id, DATE(qj.date) AS tgl
                FROM quran_jurnal qj
                JOIN quran_halaqah qh ON qh.id = qj.halaqah_id
                JOIN unit_level ul    ON ul.id = qh.unit_level_id
                WHERE ul.unit_id = ?
                  AND qh.tahun_ajaran_id = ?
                  AND qj.deleted_at IS NULL
                  {$monthSql}
                ORDER BY tgl
            ", [$unitId, $taId]);

            $byGuru = [];
            $unitDates = [];
            foreach ($rows as $r) {
                $d = (string)$r->tgl;
                $unitDates[$d] = true;
                if ($r->guru_id !== null) {
                    $byGuru[(string)(int)$r->guru_id][] = $d;
                }
            }

            $data['kb_jurnal_dates'] = [
                'unit' => array_keys($unitDates),   // hari ada kegiatan halaqah (jurnal) di unit
                'guru' => $byGuru,                  // guru_id => [tanggal jurnal diisi]
            ];
            $data['meta']['kb_version'] = 1;
        } catch (\Throwable $e) {
            Log::error('KinerjaBaruTahfidzController@getTahfidzV2: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat mengambil tanggal jurnal.',
            ], 500);
        }

        return response()->json($data);
    }
}
