<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API "Penilaian Kinerja Baru" (read-only).
 *
 * Berbeda dengan PerformanceApiController (yang mengirim angka rata-rata jadi),
 * endpoint ini mengirim NILAI PAS MENTAH per santri per mapel. Seluruh perhitungan
 * (rata-rata, kelengkapan, peringkat) dilakukan di aplikasi absensi dengan satu
 * set rumus, sehingga angka di semua halaman konsisten.
 *
 * Route: GET V1/performance/v2/pas-scores?unit_id=2&tahun_ajaran_id=10
 */
class KinerjaBaruApiController extends ApiController
{
    /**
     * Kunci khusus endpoint ini: KINERJA_BARU_API_KEY di .env AIS (minimal 32 karakter).
     * Sengaja TIDAK ada kunci cadangan di kode (repo bersifat publik) dan tidak menerima ?api_key= di URL.
     */
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

    public function getPasScores(Request $request)
    {
        if ($authError = $this->authorizeApiKey($request)) {
            return $authError;
        }

        try {
            $units = DB::table('unit')->select('id', 'nama')->orderBy('id')->get();

            $academicYears = DB::table('tahun_ajaran as ta')
                ->join('ams_nilai_raport as anr', 'anr.tahun_ajaran_id', '=', 'ta.id')
                ->select('ta.id', 'ta.nama', 'ta.semester', DB::raw('COUNT(anr.id) as total_nilai'))
                ->groupBy('ta.id', 'ta.nama', 'ta.semester')
                ->orderBy('ta.id', 'desc')
                ->get();

            $unitId = (int)$request->input('unit_id', 2);
            $selectedUnit = $units->firstWhere('id', $unitId) ?? $units->first();
            $unitId = $selectedUnit ? (int)$selectedUnit->id : 2;

            if ($academicYears->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'meta'    => [
                        'selected_unit'         => $selectedUnit,
                        'selected_tahun_ajaran' => null,
                        'units'                 => $units,
                        'academic_years'        => [],
                        'server_time'           => now()->toIso8601String(),
                    ],
                    'paralel' => [], 'rombel' => [], 'mapel' => [], 'siswa' => [], 'nilai' => [],
                ]);
            }

            $taId = (int)$request->input('tahun_ajaran_id', 0);
            $selectedTa = $academicYears->firstWhere('id', $taId) ?? $academicYears->first();
            $taId = (int)$selectedTa->id;

            // Nilai raport mentah. Santri/rombel/kelas yang sudah dihapus tidak diikutkan.
            // component_nilai di-parse di PHP agar baris tanpa komponen PAS tetap terbaca (sebagai "kosong").
            $rows = DB::select("
                SELECT
                    anr.id               AS anr_id,
                    anr.level_sub_siswa_id AS enrol_id,
                    anr.mata_pelajaran_id AS mapel_id,
                    anr.component_nilai,
                    anr.updated_at,
                    alss.siswa_id,
                    alss.tgl_keluar,
                    s.nama               AS siswa_nama,
                    COALESCE(s.nis, '')  AS siswa_nis,
                    uls.id               AS rombel_id,
                    uls.nama             AS rombel_nama,
                    COALESCE(uls.code, '') AS rombel_code,
                    ul.id                AS paralel_id,
                    ul.nama              AS paralel_nama,
                    mp.nama              AS mapel_nama
                FROM ams_nilai_raport anr
                JOIN ams_level_sub_siswa alss ON alss.id = anr.level_sub_siswa_id
                JOIN siswa s                  ON s.id = alss.siswa_id
                JOIN unit_level_sub uls       ON uls.id = alss.unit_level_sub_id
                JOIN unit_level ul            ON ul.id = uls.unit_level_id
                JOIN mata_pelajaran mp        ON mp.id = anr.mata_pelajaran_id
                WHERE ul.unit_id = ?
                  AND anr.tahun_ajaran_id = ?
                  AND alss.deleted_at IS NULL
                  AND s.deleted_at IS NULL
                  AND uls.deleted_at IS NULL
                  AND ul.deleted_at IS NULL
                ORDER BY ul.id, uls.id, s.nama, anr.id
            ", [$unitId, $taId]);

            $paralel = [];
            $rombel  = [];
            $mapel   = [];
            $siswa   = [];
            $nilai   = [];

            foreach ($rows as $r) {
                $pId = (int)$r->paralel_id;
                $rId = (int)$r->rombel_id;
                $mId = (int)$r->mapel_id;
                $eId = (int)$r->enrol_id;

                $paralel[$pId] = ['id' => $pId, 'nama' => (string)$r->paralel_nama];
                $rombel[$rId]  = ['id' => $rId, 'paralel_id' => $pId, 'nama' => (string)$r->rombel_nama, 'code' => (string)$r->rombel_code];
                $mapel[$mId]   = ['id' => $mId, 'nama' => (string)$r->mapel_nama];
                $siswa[$eId]   = [
                    'enrol_id'   => $eId,
                    'siswa_id'   => (int)$r->siswa_id,
                    'nama'       => (string)$r->siswa_nama,
                    'nis'        => (string)$r->siswa_nis,
                    'rombel_id'  => $rId,
                    'tgl_keluar' => $r->tgl_keluar,
                ];

                // Ambil nilai komponen PAS apa adanya (null jika komponen/nilai tidak ada).
                $pas = null;
                $components = json_decode((string)$r->component_nilai, true);
                if (is_array($components)) {
                    foreach ($components as $c) {
                        if (is_array($c) && ($c['id'] ?? null) === 'pas') {
                            $pas = array_key_exists('value', $c) ? $c['value'] : null;
                            break;
                        }
                    }
                }
                if (is_string($pas)) {
                    $pas = trim($pas);
                }

                // Format ringkas: [anr_id, enrol_id, mapel_id, nilai_pas_mentah, updated_at]
                $nilai[] = [(int)$r->anr_id, $eId, $mId, $pas, $r->updated_at];
            }

            return response()->json([
                'success' => true,
                'meta'    => [
                    'selected_unit'         => $selectedUnit,
                    'selected_tahun_ajaran' => $selectedTa,
                    'units'                 => $units,
                    'academic_years'        => $academicYears,
                    'server_time'           => now()->toIso8601String(),
                    'format'                => 'nilai: [anr_id, enrol_id, mapel_id, pas, updated_at]',
                ],
                'paralel' => array_values($paralel),
                'rombel'  => array_values($rombel),
                'mapel'   => array_values($mapel),
                'siswa'   => array_values($siswa),
                'nilai'   => $nilai,
            ]);
        } catch (\Throwable $e) {
            Log::error('KinerjaBaruApiController@getPasScores: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan internal saat mengambil nilai PAS.',
            ], 500);
        }
    }
}
