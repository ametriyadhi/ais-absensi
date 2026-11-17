<?php
/**
 * PENILAIAN KINERJA BARU — pustaka pengambilan data & rumus (satu sumber untuk semua halaman).
 *
 * Aturan hitung (disepakati 17 Nov 2026):
 *  - Hanya komponen PAS yang terisi yang dihitung.
 *  - Nilai 0 = belum dinilai (tidak dihitung). Nilai > 100 atau bukan angka = tidak wajar (tidak dihitung).
 *  - Baris ganda santri+mapel: yang dipakai baris yang paling akhir diubah.
 *  - Santri keluar/terhapus tidak dihitung.
 *  - Mapel wajib sebuah paralel = mapel yang sudah dinilai untuk >= 50% santri paralel itu.
 *  - Rata-rata santri = rata-rata nilai mapel wajibnya. Santri LENGKAP = punya nilai di semua mapel wajib.
 *  - Hanya santri lengkap yang diberi peringkat dan dipakai untuk rata-rata rombel/paralel/unit
 *    (rata-rata dari rata-rata santri: setiap santri berbobot sama).
 *  - Rata-rata mapel = rata-rata semua nilai sah mapel itu.
 *  - Nilai sama (dibulatkan 2 desimal) = peringkat sama (1, 1, 3).
 *
 * Tidak pernah membuat data pengganti/sintetis: jika AIS tidak bisa dihubungi dan tidak ada cache,
 * halaman menampilkan pesan galat.
 */

if (!defined('KB_CACHE_TTL')) {
    define('KB_CACHE_TTL', 600);          // 10 menit
}
if (!defined('KB_BATAS_NILAI')) {
    define('KB_BATAS_NILAI', 75.0);       // batas "di bawah batas"
}
if (!defined('KB_RASIO_WAJIB')) {
    define('KB_RASIO_WAJIB', 0.5);        // mapel wajib bila dinilai >= 50% santri paralel
}
if (!defined('KB_AMBANG_KELENGKAPAN')) {
    define('KB_AMBANG_KELENGKAPAN', 95.0); // di bawah ini tampil peringatan "PAS baru terisi xx%"
}

// =========================================================================
// 1. PENGAMBILAN DATA DARI AIS
// =========================================================================

if (!function_exists('kbApiCandidateUrls')) {
    /** @param string $endpoint 'pas-scores' | 'tahfidz' */
    function kbApiCandidateUrls(string $endpoint = 'pas-scores'): array
    {
        $explicit = kbEnv('AIS_KINERJA_BARU_API_URL');
        $explicitFb = kbEnv('AIS_KINERJA_BARU_API_FALLBACK_URL');
        $basePrimary  = defined('AIS_PERFORMANCE_API_URL') ? (string)AIS_PERFORMANCE_API_URL : 'https://ais.codika.my.id/V1/performance/academic-averages';
        $baseFallback = defined('AIS_PERFORMANCE_API_FALLBACK_URL') ? (string)AIS_PERFORMANCE_API_FALLBACK_URL : '';

        $derive = static function (string $url) use ($endpoint): string {
            if ($url === '') {
                return '';
            }
            if (preg_match('#/v2/[A-Za-z0-9_\-]+$#', $url)) {
                return preg_replace('#/v2/[A-Za-z0-9_\-]+$#', '/v2/' . $endpoint, $url) ?? '';
            }
            return preg_replace('#/performance/[A-Za-z0-9_\-/]+$#', '/performance/v2/' . $endpoint, $url) ?? '';
        };

        $urls = [
            $derive($explicit !== '' ? $explicit : $basePrimary),
            $derive($explicitFb !== '' ? $explicitFb : $baseFallback),
        ];
        return array_values(array_unique(array_filter($urls)));
    }
}

if (!function_exists('kbEnv')) {
    /**
     * Baca variabel konfigurasi secara andal.
     * getenv() saja tidak cukup: di Apache Windows (PHP thread-safe) nilai putenv() dari loader .env
     * dihapus kembali setiap kali SATU permintaan selesai, sehingga permintaan lain yang berjalan
     * bersamaan bisa kehilangan nilainya. Urutan: getenv -> $_ENV -> $_SERVER -> baca langsung file .env.
     */
    function kbEnv(string $name): string
    {
        $v = getenv($name);
        if ($v !== false && $v !== '') {
            return trim((string)$v);
        }
        foreach ([$_ENV, $_SERVER] as $src) {
            if (isset($src[$name]) && is_string($src[$name]) && $src[$name] !== '') {
                return trim($src[$name]);
            }
        }
        static $fileVars = null;
        if ($fileVars === null) {
            $fileVars = [];
            $path = dirname(__DIR__) . '/.env';
            $lines = is_readable($path) ? @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
            foreach ($lines ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $val] = explode('=', $line, 2);
                $val = trim($val);
                if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && substr($val, -1) === $val[0]) {
                    $val = substr($val, 1, -1);
                }
                $fileVars[trim($k)] = $val;
            }
        }
        return trim((string)($fileVars[$name] ?? ''));
    }
}

if (!function_exists('kbApiKey')) {
    /**
     * Kunci API khusus bab baru, hanya dari .env (KINERJA_BARU_API_KEY) — tidak ada kunci cadangan di kode
     * karena repo bersifat publik. Kunci ini berbeda dari kunci endpoint lama.
     */
    function kbApiKey(): string
    {
        return kbEnv('KINERJA_BARU_API_KEY');
    }
}

if (!function_exists('kbCacheFile')) {
    /**
     * Lokasi cache. Nama file diturunkan dari kunci rahasia (HMAC) sehingga tidak bisa ditebak
     * walaupun web server tidak menghormati storage/.htaccess.
     */
    function kbCacheFile(string $kind, array $parts): string
    {
        $dir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $secret = kbApiKey() !== '' ? kbApiKey() : (__DIR__ . php_uname());
        $name = substr(hash_hmac('sha256', $kind . '|' . implode('|', $parts), $secret), 0, 40);
        return $dir . '/kb_' . preg_replace('/[^a-z0-9]/', '', $kind) . '_' . $name . '.json';
    }
}

if (!function_exists('kbReadCache')) {
    function kbReadCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $wrap = json_decode((string)@file_get_contents($file), true);
        if (!is_array($wrap) || empty($wrap['payload']['success']) || empty($wrap['fetched_at'])) {
            return null;
        }
        return $wrap;
    }
}

if (!function_exists('kbFetchPasData')) {
    /**
     * @return array{ok:bool,payload:?array,source:string,fetched_at:?int,error:?string}
     *   source: live | cache | offline_cache | none
     */
    function kbFetchPasData(int $unitId, ?int $taId, bool $forceRefresh = false): array
    {
        $query = ['unit_id' => $unitId];
        if ($taId) {
            $query['tahun_ajaran_id'] = $taId;
        }
        return kbFetchJson('pas-scores', 'pas', $query, $forceRefresh, 'nilai', 20);
    }
}

if (!function_exists('kbFetchTahfidzData')) {
    function kbFetchTahfidzData(int $unitId, ?int $taId, int $month, bool $forceRefresh = false): array
    {
        $query = ['unit_id' => $unitId, 'month' => max(0, min(12, $month))];
        if ($taId) {
            $query['tahun_ajaran_id'] = $taId;
        }
        return kbFetchJson('tahfidz', 'tahfidz', $query, $forceRefresh, 'kb_jurnal_dates', 45);
    }
}

if (!function_exists('kbFetchJson')) {
    /**
     * Ambil JSON dari endpoint AIS v2 dengan kunci rahasia, cache 10 menit (nama file acak),
     * dan fallback ke data terakhir jika AIS tidak terhubung. Tidak pernah membuat data pengganti.
     */
    function kbFetchJson(string $endpoint, string $kind, array $query, bool $forceRefresh, string $requiredField, int $timeout): array
    {
        $cacheFile = kbCacheFile($kind, array_values($query));
        $cached = kbReadCache($cacheFile);

        if (!$forceRefresh && $cached && (time() - (int)$cached['fetched_at']) < KB_CACHE_TTL) {
            return ['ok' => true, 'payload' => $cached['payload'], 'source' => 'cache', 'fetched_at' => (int)$cached['fetched_at'], 'error' => null];
        }

        $apiKey = kbApiKey();
        $lastError = '';
        if ($apiKey === '') {
            $lastError = 'KINERJA_BARU_API_KEY belum diisi di file .env server.';
        } else {
            foreach (kbApiCandidateUrls($endpoint) as $baseUrl) {
                if (function_exists('isAllowedAisApiUrl') && !isAllowedAisApiUrl($baseUrl)) {
                    continue;
                }
                $isHttps = stripos($baseUrl, 'https://') === 0;
                $ch = curl_init($baseUrl . '?' . http_build_query($query));
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_SSL_VERIFYPEER => $isHttps,
                    CURLOPT_SSL_VERIFYHOST => $isHttps ? 2 : 0,
                    CURLOPT_ENCODING       => '',
                    CURLOPT_HTTPHEADER     => [
                        'X-AIS-KEY: ' . $apiKey,
                        'Accept: application/json',
                        'User-Agent: AbsensiAnak-KinerjaBaru/1.0',
                    ],
                ]);
                $body = curl_exec($ch);
                $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $cerr = curl_error($ch);
                curl_close($ch);

                if ($cerr === '' && $http === 200) {
                    $decoded = json_decode((string)$body, true);
                    if (is_array($decoded) && !empty($decoded['success']) && isset($decoded[$requiredField])) {
                        $now = time();
                        @file_put_contents($cacheFile, json_encode(['fetched_at' => $now, 'payload' => $decoded]), LOCK_EX);
                        return ['ok' => true, 'payload' => $decoded, 'source' => 'live', 'fetched_at' => $now, 'error' => null];
                    }
                    $lastError = 'Balasan AIS tidak sesuai format.';
                } elseif ($http === 404) {
                    $lastError = 'Endpoint v2/' . $endpoint . ' belum dipasang di server AIS (lihat integrations/ais_source/KINERJA_BARU_INSTALL.md).';
                } elseif ($http === 401) {
                    $lastError = 'Kunci API ditolak oleh server AIS (cek KINERJA_BARU_API_KEY).';
                } else {
                    $lastError = $cerr !== '' ? $cerr : ('HTTP ' . $http);
                }
            }
        }

        if ($cached) {
            return [
                'ok' => true, 'payload' => $cached['payload'], 'source' => 'offline_cache',
                'fetched_at' => (int)$cached['fetched_at'], 'error' => $lastError,
            ];
        }
        return ['ok' => false, 'payload' => null, 'source' => 'none', 'fetched_at' => null, 'error' => $lastError ?: 'Server AIS tidak dapat dihubungi.'];
    }
}

// =========================================================================
// 2. RUMUS / ANALISIS
// =========================================================================

if (!function_exists('kbClassifyValue')) {
    /** @return array{0:string,1:?float} [status, nilai] status: sah|kosong|nol|tidak_wajar */
    function kbClassifyValue($raw): array
    {
        if ($raw === null || $raw === '') {
            return ['kosong', null];
        }
        if (is_string($raw)) {
            $raw = str_replace(',', '.', trim($raw));
        }
        if (!is_numeric($raw)) {
            return ['tidak_wajar', null];
        }
        $v = (float)$raw;
        if ($v == 0.0) {
            return ['nol', null];
        }
        if ($v < 0 || $v > 100) {
            return ['tidak_wajar', $v];
        }
        return ['sah', $v];
    }
}

if (!function_exists('kbMean')) {
    function kbMean(array $values): ?float
    {
        $n = count($values);
        return $n > 0 ? array_sum($values) / $n : null;
    }
}

if (!function_exists('kbAssignRanks')) {
    /**
     * Peringkat kompetisi (1,1,3) berdasarkan $key menurun. Item dengan nilai null diberi peringkat null & ditaruh di akhir.
     */
    function kbAssignRanks(array $items, string $key, string $rankKey = 'peringkat'): array
    {
        usort($items, static function ($a, $b) use ($key) {
            $va = $a[$key];
            $vb = $b[$key];
            if ($va === null && $vb === null) {
                return strcmp((string)($a['nama'] ?? ''), (string)($b['nama'] ?? ''));
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }
            $cmp = round($vb, 2) <=> round($va, 2);
            return $cmp !== 0 ? $cmp : strcmp((string)($a['nama'] ?? ''), (string)($b['nama'] ?? ''));
        });
        $prev = null;
        $rank = 0;
        foreach ($items as $i => &$it) {
            if ($it[$key] === null) {
                $it[$rankKey] = null;
                continue;
            }
            $r2 = round($it[$key], 2);
            if ($prev === null || $r2 !== $prev) {
                $rank = $i + 1;
                $prev = $r2;
            }
            $it[$rankKey] = $rank;
        }
        unset($it);
        return $items;
    }
}

if (!function_exists('kbPredikat')) {
    function kbPredikat(?float $v): string
    {
        if ($v === null) {
            return '-';
        }
        return $v >= 90 ? 'A' : ($v >= 80 ? 'B' : ($v >= 70 ? 'C' : 'D'));
    }
}

if (!function_exists('kbScoreStats')) {
    function kbScoreStats(array $scores): array
    {
        $vals = array_values($scores);
        $below = 0;
        foreach ($vals as $v) {
            if ($v < KB_BATAS_NILAI) {
                $below++;
            }
        }
        return [
            'rata_rata'     => kbMean($vals),
            'jumlah_nilai'  => count($vals),
            'tertinggi'     => $vals ? max($vals) : null,
            'terendah'      => $vals ? min($vals) : null,
            'di_bawah_batas'=> $below,
        ];
    }
}

if (!function_exists('kbAnalyze')) {
    /**
     * Mengolah payload mentah v2/pas-scores menjadi struktur siap tampil.
     */
    function kbAnalyze(array $payload): array
    {
        $paralelInfo = [];
        foreach ($payload['paralel'] ?? [] as $p) {
            $paralelInfo[(int)$p['id']] = (string)$p['nama'];
        }
        $rombelInfo = [];
        foreach ($payload['rombel'] ?? [] as $r) {
            $rNama = trim((string)($r['nama'] ?? ''));
            $rCode = trim((string)($r['code'] ?? ''));
            if ($rNama === '') {
                $rNama = $rCode !== '' ? $rCode : (($paralelInfo[(int)$r['paralel_id']] ?? 'Rombel') . ' (rombel tunggal)');
            }
            $rombelInfo[(int)$r['id']] = ['paralel_id' => (int)$r['paralel_id'], 'nama' => $rNama, 'code' => $rCode];
        }
        $mapelInfo = [];
        foreach ($payload['mapel'] ?? [] as $m) {
            $mapelInfo[(int)$m['id']] = (string)$m['nama'];
        }
        $enrol = [];
        foreach ($payload['siswa'] ?? [] as $s) {
            $enrol[(int)$s['enrol_id']] = $s;
        }

        $issues = [];
        $addIssue = static function (string $type, int $enrolId, ?int $mapelId, string $detail) use (&$issues, $enrol, $rombelInfo, $mapelInfo) {
            $s = $enrol[$enrolId] ?? [];
            $rId = (int)($s['rombel_id'] ?? 0);
            $issues[] = [
                'jenis'      => $type,
                'paralel_id' => $rombelInfo[$rId]['paralel_id'] ?? 0,
                'rombel'     => $rombelInfo[$rId]['nama'] ?? '-',
                'santri'     => (string)($s['nama'] ?? '-'),
                'nis'        => (string)($s['nis'] ?? ''),
                'mapel'      => $mapelId ? ($mapelInfo[$mapelId] ?? ('Mapel #' . $mapelId)) : '-',
                'detail'     => $detail,
            ];
        };

        // --- a. Pilih satu baris per santri+mapel (yang paling akhir diubah) ---
        $chosen = [];   // "enrol|mapel" => row
        $dupes  = [];   // "enrol|mapel" => [rows]
        foreach ($payload['nilai'] ?? [] as $row) {
            [$anrId, $eId, $mId, $raw, $upd] = array_pad((array)$row, 5, null);
            $k = (int)$eId . '|' . (int)$mId;
            $row = ['anr_id' => (int)$anrId, 'enrol_id' => (int)$eId, 'mapel_id' => (int)$mId, 'raw' => $raw, 'updated_at' => (string)$upd];
            if (!isset($chosen[$k])) {
                $chosen[$k] = $row;
                continue;
            }
            $dupes[$k][] = $chosen[$k];
            $dupes[$k][] = $row;
            $cur = $chosen[$k];
            if ($row['updated_at'] > $cur['updated_at'] || ($row['updated_at'] === $cur['updated_at'] && $row['anr_id'] > $cur['anr_id'])) {
                $chosen[$k] = $row;
            }
        }
        foreach ($dupes as $k => $rows) {
            $uniq = [];
            foreach ($rows as $r) {
                $uniq[$r['anr_id']] = $r['raw'] === null || $r['raw'] === '' ? 'kosong' : (string)$r['raw'];
            }
            $used = $chosen[$k];
            $addIssue('ganda', $used['enrol_id'], $used['mapel_id'],
                'Tercatat ' . count($uniq) . ' kali (nilai: ' . implode(' / ', $uniq) . '). Dipakai yang terakhir diubah: '
                . ($used['raw'] === null || $used['raw'] === '' ? 'kosong' : $used['raw']) . '.');
        }

        // --- b. Klasifikasi nilai & santri keluar ---
        $keluar = [];
        foreach ($enrol as $eId => $s) {
            if (!empty($s['tgl_keluar'])) {
                $keluar[$eId] = true;
                $addIssue('keluar', (int)$eId, null, 'Tercatat keluar ' . $s['tgl_keluar'] . '; tidak dihitung.');
            }
        }

        $scores = [];     // enrol => [mapel => float] (hanya sah)
        $status = [];     // enrol => [mapel => status]
        $count = ['sah' => 0, 'kosong' => 0, 'nol' => 0, 'tidak_wajar' => 0, 'ganda' => count($dupes), 'keluar' => count($keluar)];
        foreach ($chosen as $row) {
            $eId = $row['enrol_id'];
            $mId = $row['mapel_id'];
            if (isset($keluar[$eId]) || !isset($enrol[$eId])) {
                continue;
            }
            [$st, $v] = kbClassifyValue($row['raw']);
            $status[$eId][$mId] = $st;
            $count[$st]++;
            if ($st === 'sah') {
                $scores[$eId][$mId] = $v;
            } elseif ($st === 'nol') {
                $addIssue('nol', $eId, $mId, 'Nilai PAS 0 — dianggap belum dinilai.');
            } elseif ($st === 'tidak_wajar') {
                $addIssue('tidak_wajar', $eId, $mId, 'Nilai PAS "' . $row['raw'] . '" di luar 1–100 — tidak dihitung.');
            }
            // "kosong" tidak dimasukkan ke daftar masalah satu per satu (bisa ratusan saat semester berjalan);
            // jumlahnya tampil di ringkasan & di daftar santri belum lengkap.
        }

        // --- c. Kelompokkan santri aktif per paralel ---
        $santriByParalel = [];
        foreach ($enrol as $eId => $s) {
            if (isset($keluar[$eId])) {
                continue;
            }
            $rId = (int)$s['rombel_id'];
            $pId = $rombelInfo[$rId]['paralel_id'] ?? 0;
            if (!isset($status[$eId])) {
                continue;
            }
            $santriByParalel[$pId][] = (int)$eId;
        }

        $paralels = [];
        $unitSantriAvgs = [];
        $unitMapelScores = [];   // mapel => [paralel_id => [scores]]
        $unitExpected = 0;
        $unitFilled = 0;
        $unitSantri = 0;
        $unitLengkap = 0;

        foreach ($santriByParalel as $pId => $eIds) {
            $nSantri = count($eIds);

            // Mapel wajib ditentukan PER ROMBEL (kurikulum bisa beda: putra/putri, IPA/IPS):
            // mapel wajib rombel = mapel yang sudah dinilai untuk >= 50% santri rombel itu.
            $mapelSeen = [];                // mapel yang muncul di paralel ini
            $rombelSantri = [];             // rombel => [enrol]
            $rombelMapelSeen = [];          // rombel => [mapel => true]
            $rombelMapelValid = [];         // rombel => [mapel => jumlah nilai sah]
            foreach ($eIds as $eId) {
                $rId = (int)$enrol[$eId]['rombel_id'];
                $rombelSantri[$rId][] = $eId;
                foreach ($status[$eId] as $mId => $st) {
                    $mapelSeen[$mId] = true;
                    $rombelMapelSeen[$rId][$mId] = true;
                    if ($st === 'sah') {
                        $rombelMapelValid[$rId][$mId] = ($rombelMapelValid[$rId][$mId] ?? 0) + 1;
                    }
                }
            }
            $wajibByRombel = [];
            $wajibAny = [];
            foreach ($rombelSantri as $rId => $rEids) {
                $wajibByRombel[$rId] = [];
                foreach ($rombelMapelValid[$rId] ?? [] as $mId => $nValid) {
                    if ($nValid / count($rEids) >= KB_RASIO_WAJIB) {
                        $wajibByRombel[$rId][$mId] = true;
                        $wajibAny[$mId] = true;
                    }
                }
            }
            // Rombel yang sama sekali tidak punya baris nilai untuk mapel yang wajib di rombel lain
            // (bisa beda kurikulum, bisa juga nilai belum dimasukkan) -> catat agar terlihat.
            foreach ($rombelSantri as $rId => $rEids) {
                foreach ($wajibAny as $mId => $_) {
                    if (!isset($rombelMapelSeen[$rId][$mId])) {
                        $issues[] = [
                            'jenis'      => 'mapel_tidak_ada',
                            'paralel_id' => $pId,
                            'rombel'     => $rombelInfo[$rId]['nama'] ?? '-',
                            'santri'     => 'Seluruh rombel (' . count($rEids) . ' santri)',
                            'nis'        => '',
                            'mapel'      => $mapelInfo[$mId] ?? ('Mapel #' . $mId),
                            'detail'     => 'Rombel lain di paralel ini punya nilai mapel ini, rombel ini tidak ada sama sekali. Abaikan jika memang beda kurikulum.',
                        ];
                    }
                }
            }
            $nWajibList = array_map('count', $wajibByRombel);
            $nWajib = $nWajibList ? max($nWajibList) : 0;
            $nWajibMin = $nWajibList ? min($nWajibList) : 0;

            // santri
            $santriRows = [];
            $filled = 0;
            $expected = 0;
            foreach ($eIds as $eId) {
                $s = $enrol[$eId];
                $wajib = $wajibByRombel[(int)$s['rombel_id']] ?? [];
                $expected += count($wajib);
                $wajibScores = [];
                $missing = [];
                foreach ($wajib as $mId => $_) {
                    if (isset($scores[$eId][$mId])) {
                        $wajibScores[$mId] = $scores[$eId][$mId];
                    } else {
                        $st = $status[$eId][$mId] ?? 'tidak_ada';
                        $missing[] = ['mapel' => $mapelInfo[$mId] ?? ('Mapel #' . $mId), 'alasan' => $st];
                    }
                }
                $filled += count($wajibScores);
                $lengkap = count($wajib) > 0 && count($missing) === 0;
                $avg = kbMean($wajibScores);
                $rId = (int)$s['rombel_id'];
                $santriRows[] = [
                    'enrol_id'    => $eId,
                    'siswa_id'    => (int)$s['siswa_id'],
                    'nama'        => (string)$s['nama'],
                    'nis'         => (string)$s['nis'],
                    'rombel_id'   => $rId,
                    'rombel'      => $rombelInfo[$rId]['nama'] ?? '-',
                    'rata_rata'   => $lengkap ? $avg : null,       // dipakai untuk peringkat
                    'rata_sementara' => $avg,                       // info untuk santri belum lengkap
                    'jumlah_mapel'=> count($wajibScores),
                    'total'       => array_sum($wajibScores),
                    'lengkap'     => $lengkap,
                    'kurang'      => $missing,
                    'nilai'       => $scores[$eId] ?? [],
                ];
            }
            $santriRows = kbAssignRanks($santriRows, 'rata_rata');

            // rombel
            $rombelAgg = [];
            foreach ($santriRows as $sr) {
                $rId = $sr['rombel_id'];
                if (!isset($rombelAgg[$rId])) {
                    $rombelAgg[$rId] = ['id' => $rId, 'nama' => $sr['rombel'], 'avgs' => [], 'santri' => 0];
                }
                $rombelAgg[$rId]['santri']++;
                if ($sr['lengkap']) {
                    $rombelAgg[$rId]['avgs'][] = $sr['rata_rata'];
                }
            }
            $rombels = [];
            foreach ($rombelAgg as $ra) {
                $rombels[] = [
                    'id' => $ra['id'], 'nama' => $ra['nama'], 'rata_rata' => kbMean($ra['avgs']),
                    'santri' => $ra['santri'], 'lengkap' => count($ra['avgs']),
                ];
            }
            $rombels = kbAssignRanks($rombels, 'rata_rata');

            // mapel (semua nilai sah, termasuk dari santri belum lengkap)
            $mapelRows = [];
            foreach (array_keys($mapelSeen) as $mId) {
                $vals = [];
                $studentScores = [];
                foreach ($santriRows as $sr) {
                    if (isset($scores[$sr['enrol_id']][$mId])) {
                        $v = $scores[$sr['enrol_id']][$mId];
                        $vals[] = $v;
                        $studentScores[] = ['nama' => $sr['nama'], 'nis' => $sr['nis'], 'rombel' => $sr['rombel'], 'nilai' => $v];
                        $unitMapelScores[$mId][$pId][] = $v;
                    }
                }
                $stats = kbScoreStats($vals);
                $studentScores = kbAssignRanks($studentScores, 'nilai');
                $mapelRows[] = array_merge([
                    'id' => $mId, 'nama' => $mapelInfo[$mId] ?? ('Mapel #' . $mId),
                    'wajib' => isset($wajibAny[$mId]), 'santri_nilai' => $studentScores,
                ], $stats);
            }
            $mapelRows = kbAssignRanks($mapelRows, 'rata_rata');

            $lengkapAvgs = [];
            foreach ($santriRows as $sr) {
                if ($sr['lengkap']) {
                    $lengkapAvgs[] = $sr['rata_rata'];
                    $unitSantriAvgs[] = $sr['rata_rata'];
                }
            }
            $unitExpected += $expected;
            $unitFilled += $filled;
            $unitSantri += $nSantri;
            $unitLengkap += count($lengkapAvgs);

            $paralels[$pId] = [
                'id'          => $pId,
                'nama'        => $paralelInfo[$pId] ?? ('Paralel #' . $pId),
                'rata_rata'   => kbMean($lengkapAvgs),
                'santri'      => $nSantri,
                'lengkap'     => count($lengkapAvgs),
                'mapel_wajib' => $nWajib,
                'mapel_wajib_min' => $nWajibMin,
                'kelengkapan' => $expected > 0 ? ($filled / $expected) * 100 : 0.0,
                'rombel'      => $rombels,
                'mapel'       => $mapelRows,
                'santri_list' => $santriRows,
            ];
        }

        // urutan bawaan paralel: sesuai urutan dari AIS (kelas 1 -> 6)
        $paralelOrder = array_keys($paralelInfo);
        uksort($paralels, static fn($a, $b) => array_search($a, $paralelOrder, true) <=> array_search($b, $paralelOrder, true));
        $paralelRanked = kbAssignRanks(array_values($paralels), 'rata_rata');
        $rankById = [];
        foreach ($paralelRanked as $pr) {
            $rankById[$pr['id']] = $pr['peringkat'];
        }
        foreach ($paralels as $pId => &$pRef) {
            $pRef['peringkat'] = $rankById[$pId] ?? null;
        }
        unset($pRef);

        // mapel se-unit
        $unitMapel = [];
        foreach ($unitMapelScores as $mId => $byParalel) {
            $all = [];
            $perParalel = [];
            foreach ($byParalel as $pId => $vals) {
                $all = array_merge($all, $vals);
                $perParalel[] = ['paralel_id' => $pId, 'nama' => $paralels[$pId]['nama'] ?? '-', 'rata_rata' => kbMean($vals), 'jumlah_nilai' => count($vals)];
            }
            $unitMapel[] = array_merge([
                'id' => $mId, 'nama' => $mapelInfo[$mId] ?? ('Mapel #' . $mId),
                'per_paralel' => kbAssignRanks($perParalel, 'rata_rata'),
            ], kbScoreStats($all));
        }
        $unitMapel = kbAssignRanks($unitMapel, 'rata_rata');

        $issueOrder = ['ganda' => 1, 'tidak_wajar' => 2, 'nol' => 3, 'mapel_tidak_ada' => 4, 'keluar' => 5];
        usort($issues, static fn($a, $b) => [$issueOrder[$a['jenis']] ?? 9, $a['paralel_id'], $a['rombel'], $a['santri']]
            <=> [$issueOrder[$b['jenis']] ?? 9, $b['paralel_id'], $b['rombel'], $b['santri']]);

        return [
            'unit' => [
                'rata_rata'   => kbMean($unitSantriAvgs),
                'santri'      => $unitSantri,
                'lengkap'     => $unitLengkap,
                'kelengkapan' => $unitExpected > 0 ? ($unitFilled / $unitExpected) * 100 : 0.0,
            ],
            'paralel'        => $paralels,          // urut kelas, tiap item punya 'peringkat'
            'paralel_ranked' => $paralelRanked,     // urut peringkat
            'mapel'          => $unitMapel,         // urut rata-rata se-unit
            'masalah'        => $issues,
            'hitung'         => $count,
        ];
    }
}

// =========================================================================
// 3. KONTEKS HALAMAN (filter, hak akses unit, pengambilan & analisis)
// =========================================================================

if (!function_exists('kbLoadPage')) {
    function kbLoadPage(PDO $pdo, array $query): array
    {
        $unitId = isset($query['unit_id']) ? max(1, (int)$query['unit_id']) : 2;
        $taId   = isset($query['ta']) && (int)$query['ta'] > 0 ? (int)$query['ta'] : null;
        $force  = ($query['refresh'] ?? '') === '1';

        $res = kbFetchPasData($unitId, $taId, $force);

        // Pembatasan unit berdasarkan hak akses (kepala sekolah/admin unit)
        $accessCtx = function_exists('getTahfidzAccessContext') ? getTahfidzAccessContext($pdo) : ['is_super_admin' => true];
        $units = $res['payload']['meta']['units'] ?? [];
        $allowedUnits = function_exists('filterTahfidzAisUnitsByAccess') ? filterTahfidzAisUnitsByAccess($units, $accessCtx) : $units;
        $allowedIds = array_map(static fn($u) => (int)$u['id'], $allowedUnits);
        if ($res['ok'] && !empty($units) && !in_array($unitId, $allowedIds, true)) {
            if (empty($allowedIds)) {
                http_response_code(403);
                die('403 - Akses Ditolak: unit Anda tidak terdaftar pada data AIS.');
            }
            $unitId = $allowedIds[0];
            $taId = null;
            $res = kbFetchPasData($unitId, null, $force);
        }

        $analysis = null;
        if ($res['ok']) {
            $analysis = kbAnalyze($res['payload']);
        }
        $meta = $res['payload']['meta'] ?? [];
        $selTa = $meta['selected_tahun_ajaran'] ?? null;

        return [
            'unit_id'    => $unitId,
            'ta_id'      => $selTa ? (int)$selTa['id'] : $taId,
            'units'      => $allowedUnits,
            'years'      => $meta['academic_years'] ?? [],
            'unit'       => $meta['selected_unit'] ?? null,
            'ta'         => $selTa,
            'ok'         => $res['ok'],
            'source'     => $res['source'],
            'fetched_at' => $res['fetched_at'],
            'error'      => $res['error'],
            'a'          => $analysis,
        ];
    }
}

if (!function_exists('kbFmt')) {
    function kbFmt(?float $v, int $dec = 2): string
    {
        return $v === null ? '–' : number_format($v, $dec, ',', '.');
    }
}

if (!function_exists('kbAge')) {
    function kbAge(int $ts): string
    {
        $d = max(0, time() - $ts);
        if ($d < 60) {
            return 'baru saja';
        }
        if ($d < 3600) {
            return floor($d / 60) . ' menit lalu';
        }
        if ($d < 86400) {
            return floor($d / 3600) . ' jam lalu';
        }
        return floor($d / 86400) . ' hari lalu';
    }
}
