<?php
/**
 * PENILAIAN KINERJA BARU — Tahfidz & Kinerja Guru Qur'an (sub-bab 4–5).
 *
 * Aturan (disepakati 17 Nov 2026):
 *  - Capaian santri memakai data AIS + hasil AI yang SUDAH DISETUJUI admin + koreksi manual.
 *    Hasil AI yang belum ditinjau tidak dihitung (berbeda dari bab lama yang memakai AI otomatis >= 80%).
 *  - % Jurnal guru = hari jurnal diisi ÷ hari guru HADIR (tepat waktu/terlambat) YANG ADA kegiatan halaqah
 *    di unitnya. Hari hadir tanpa kegiatan halaqah tidak lagi menurunkan persentase.
 *  - Guru AIS dicocokkan ke staf absensi: pemetaan manual -> NIK -> nama.
 *  - Batas kuadran (jurnal tinggi/rendah, ketuntasan) bisa diatur di halaman Pengaturan.
 *
 * Tabel milik bab baru: kinerja_baru_tahfidz_targets, kinerja_baru_guru_map, kinerja_baru_settings.
 * Tabel BERSAMA dengan bab lama (data tentang catatan & koreksi santri, bukan rumus):
 *  kinerja_tahfidz_ai_cache (hasil baca AI + status tinjauan), kinerja_tahfidz_student_overrides (koreksi manual).
 */

require_once __DIR__ . '/kinerja_baru.php';

if (!defined('KB_AI_HANYA_TERVERIFIKASI')) {
    define('KB_AI_HANYA_TERVERIFIKASI', 1.01); // ambang > 1 => hanya status "verified" yang dipakai
}

if (!function_exists('kbSettings')) {
    function kbSettings(PDO $pdo): array
    {
        $s = ['jurnal_tinggi' => 85.0, 'jurnal_rendah' => 60.0, 'tuntas_batas' => 75.0];
        try {
            foreach ($pdo->query('SELECT setting_key, setting_value FROM kinerja_baru_settings') as $r) {
                if (array_key_exists($r['setting_key'], $s) && is_numeric($r['setting_value'])) {
                    $s[$r['setting_key']] = (float)$r['setting_value'];
                }
            }
        } catch (Throwable $e) {
            // tabel belum dimigrasi: pakai bawaan
        }
        return $s;
    }
}

if (!function_exists('kbTahfidzTargets')) {
    /** Target kurikulum milik bab baru (bentuk sama dengan getTahfidzTargetConfigs). */
    function kbTahfidzTargets(PDO $pdo, int $unitId, int $semester): array
    {
        $out = [];
        try {
            $st = $pdo->prepare('SELECT * FROM kinerja_baru_tahfidz_targets WHERE unit_id = ? AND semester = ? ORDER BY paralel_id');
            $st->execute([$unitId, $semester === 2 ? 2 : 1]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int)$row['paralel_id']] = [
                    'id'                 => (int)$row['id'],
                    'unit_id'            => (int)$row['unit_id'],
                    'paralel_id'         => (int)$row['paralel_id'],
                    'paralel_nama'       => (string)$row['paralel_nama'],
                    'semester'           => (int)$row['semester'],
                    'target_type'        => (string)$row['target_type'],
                    'target_value'       => (float)$row['target_value'],
                    'target_label'       => (string)$row['target_label'],
                    'require_munaqasyah' => (int)$row['require_munaqasyah'] === 1,
                    'updated_at'         => $row['updated_at'],
                ];
            }
        } catch (Throwable $e) {
            error_log('[kbTahfidzTargets] ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('kbGuruMap')) {
    function kbGuruMap(PDO $pdo): array
    {
        $map = [];
        try {
            foreach ($pdo->query('SELECT ais_guru_id, staff_id FROM kinerja_baru_guru_map') as $r) {
                $map[(int)$r['ais_guru_id']] = (int)$r['staff_id'];
            }
        } catch (Throwable $e) {
        }
        return $map;
    }
}

if (!function_exists('kbPeriodRange')) {
    /** Rentang tanggal absensi untuk TA (dan bulan, jika dipilih), dibatasi sampai hari ini. */
    function kbPeriodRange(?array $ta, int $month): array
    {
        $start = (string)($ta['start_date'] ?? '');
        $end   = (string)($ta['end_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $start)) {
            $start = date('Y-01-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $end)) {
            $end = date('Y-m-d');
        }
        $start = substr($start, 0, 10);
        $end = substr($end, 0, 10);
        if ($month >= 1 && $month <= 12) {
            $year = (int)substr($start, 0, 4);
            for ($y = (int)substr($start, 0, 4); $y <= (int)substr($end, 0, 4); $y++) {
                $probe = sprintf('%04d-%02d-15', $y, $month);
                if ($probe >= $start && $probe <= $end) {
                    $year = $y;
                    break;
                }
            }
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = date('Y-m-t', strtotime($start));
        }
        $today = date('Y-m-d');
        if ($end > $today) {
            $end = $today;
        }
        return [$start, $end];
    }
}

if (!function_exists('kbGuruKinerja')) {
    /**
     * Baris indikator kerja Guru Qur'an dengan rumus baru.
     *
     * @param array $api        payload v2/tahfidz (guru_journals, kb_jurnal_dates, meta)
     * @param array $teacherStats teacher_stats dari calculateTahfidzHierarchy (kualitas santri binaan)
     */
    function kbGuruKinerja(PDO $pdo, array $api, array $teacherStats, array $settings, int $month): array
    {
        [$attStart, $attEnd] = kbPeriodRange($api['meta']['selected_tahun_ajaran'] ?? null, $month);
        $guruJournals = is_array($api['guru_journals'] ?? null) ? $api['guru_journals'] : [];
        $unitDates = [];
        foreach ($api['kb_jurnal_dates']['unit'] ?? [] as $d) {
            if ($d >= $attStart && $d <= $attEnd) {
                $unitDates[$d] = true;
            }
        }
        $guruDates = $api['kb_jurnal_dates']['guru'] ?? [];

        // --- pencocokan guru AIS -> staf absensi ---
        $manual = kbGuruMap($pdo);
        $staffRows = $pdo->query('SELECT id, name, nik FROM staff WHERE deleted_at IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        $staffById = [];
        $byNik = [];
        $byName = [];
        foreach ($staffRows as $sr) {
            $staffById[(int)$sr['id']] = $sr;
            $n = preg_replace('/[^a-zA-Z0-9]/', '', (string)($sr['nik'] ?? '')) ?? '';
            if ($n !== '') {
                $byNik[$n] = $sr;
            }
            $nm = function_exists('normalizeTeacherPersonName') ? normalizeTeacherPersonName((string)$sr['name']) : strtolower((string)$sr['name']);
            if ($nm !== '') {
                $byName[$nm] = $sr;
            }
        }

        $matches = [];
        foreach ($guruJournals as $gj) {
            $gId = (int)($gj['guru_id'] ?? 0);
            $nik = preg_replace('/[^a-zA-Z0-9]/', '', (string)($gj['guru_nik'] ?? '')) ?? '';
            $nm  = function_exists('normalizeTeacherPersonName') ? normalizeTeacherPersonName((string)($gj['guru_nama'] ?? '')) : '';
            if (isset($manual[$gId], $staffById[$manual[$gId]])) {
                $matches[$gId] = [$staffById[$manual[$gId]], 'manual'];
            } elseif ($nik !== '' && isset($byNik[$nik])) {
                $matches[$gId] = [$byNik[$nik], 'nik'];
            } elseif ($nm !== '' && isset($byName[$nm])) {
                $matches[$gId] = [$byName[$nm], 'nama'];
            } else {
                $matches[$gId] = [null, 'belum'];
            }
        }

        // --- tanggal kehadiran per staf ---
        $attDates = [];
        $attOther = [];
        $staffIds = array_values(array_unique(array_filter(array_map(static fn($m) => $m[0] ? (int)$m[0]['id'] : 0, $matches))));
        if ($staffIds) {
            $ph = implode(',', array_fill(0, count($staffIds), '?'));
            $st = $pdo->prepare("
                SELECT staff_id, attendance_date, status
                FROM staff_attendances
                WHERE staff_id IN ({$ph}) AND attendance_date BETWEEN ? AND ?
            ");
            $st->execute(array_merge($staffIds, [$attStart, $attEnd]));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sid = (int)$r['staff_id'];
                if (in_array($r['status'], ['tepat_waktu', 'terlambat'], true)) {
                    $attDates[$sid][$r['attendance_date']] = true;
                } else {
                    $attOther[$sid][$r['status']][$r['attendance_date']] = true;
                }
            }
        }

        $statsByNik = [];
        foreach ($teacherStats as $ts) {
            if (!empty($ts['clean_nik'])) {
                $statsByNik[$ts['clean_nik']] = $ts;
            }
        }

        $hi = $settings['jurnal_tinggi'];
        $lo = $settings['jurnal_rendah'];
        $tb = $settings['tuntas_batas'];

        $rows = [];
        foreach ($guruJournals as $gj) {
            $gId = (int)($gj['guru_id'] ?? 0);
            [$staff, $method] = $matches[$gId];
            $sid = $staff ? (int)$staff['id'] : 0;
            $A = $attDates[$sid] ?? [];
            $J = [];
            foreach ($guruDates[(string)$gId] ?? [] as $d) {
                if ($d >= $attStart && $d <= $attEnd) {
                    $J[$d] = true;
                }
            }
            $basis = array_intersect_key($A, $unitDates);              // hadir & ada kegiatan halaqah
            $jurnalOnBasis = count(array_intersect_key($J, $basis));
            $jurnalTanpaHadir = count(array_diff_key($J, $A));
            $pct = count($basis) > 0 ? min(100.0, $jurnalOnBasis / count($basis) * 100) : null;

            $nik = preg_replace('/[^a-zA-Z0-9]/', '', (string)($gj['guru_nik'] ?? '')) ?? '';
            $q = $teacherStats[$gId] ?? ($nik !== '' ? ($statsByNik[$nik] ?? null) : null);
            $bTotal = $q ? (int)$q['total_students'] : (int)($gj['total_santri'] ?? 0);
            $bTuntas = $q ? (int)$q['achieved_students'] : 0;
            $bPct = $q ? (float)$q['percentage'] : 0.0;

            if ($bTotal === 0) {
                [$qk, $ql, $qb, $qd] = ['no_students', 'Belum ada santri', 'slate', 'Belum ada santri halaqah aktif pada periode ini.'];
            } elseif (!$staff) {
                [$qk, $ql, $qb, $qd] = ['unmatched', 'Belum dipetakan', 'slate', 'Guru AIS belum cocok dengan data staf absensi. Pasangkan di tab Pengaturan.'];
            } elseif (!$A) {
                [$qk, $ql, $qb, $qd] = ['no_attendance', 'Belum ada data hadir', 'slate', 'Tidak ada catatan hadir di sistem absensi pada periode ini.'];
            } elseif ($pct === null) {
                [$qk, $ql, $qb, $qd] = ['no_basis', 'Tidak ada hari halaqah', 'slate', 'Hari hadir guru tidak ada yang bertepatan dengan hari kegiatan halaqah unit.'];
            } elseif ($pct >= $hi && $bPct >= $tb) {
                [$qk, $ql, $qb, $qd] = ['prima', 'Kinerja prima', 'emerald', 'Jurnal tertib dan mayoritas santri binaan tuntas target.'];
            } elseif ($pct >= $hi) {
                [$qk, $ql, $qb, $qd] = ['perlu_metode', 'Tertib · perkuat metode', 'blue', 'Jurnal tertib, tetapi ketuntasan santri binaan masih di bawah batas.'];
            } elseif ($pct >= $lo && $bPct >= $tb) {
                [$qk, $ql, $qb, $qd] = ['baik_tingkatkan_jurnal', 'Hasil baik · tingkatkan jurnal', 'teal', 'Ketuntasan santri baik, pengisian jurnal perlu ditingkatkan.'];
            } elseif ($pct >= $lo) {
                [$qk, $ql, $qb, $qd] = ['pantau_berkala', 'Cukup · pantau berkala', 'amber', 'Jurnal dan ketuntasan santri masih di zona menengah.'];
            } elseif ($bPct >= $tb) {
                [$qk, $ql, $qb, $qd] = ['penertiban_jurnal', 'Perlu penertiban jurnal', 'amber', 'Ketuntasan santri baik, tetapi jurnal jarang diisi.'];
            } else {
                [$qk, $ql, $qb, $qd] = ['prioritas_supervisi', 'Prioritas supervisi', 'rose', 'Jurnal jarang diisi dan ketuntasan santri binaan rendah.'];
            }

            $rows[] = [
                'guru_id'           => $gId,
                'guru_nama'         => (string)($gj['guru_nama'] ?? '-'),
                'guru_nik'          => (string)($gj['guru_nik'] ?? ''),
                'level_list'        => (string)($gj['level_list'] ?? '-'),
                'jml_halaqah'       => (int)($gj['jml_halaqah'] ?? 0),
                'staff_id'          => $sid,
                'staff_nama'        => $staff ? (string)$staff['name'] : '',
                'cocok_lewat'       => $method,
                'hari_hadir'        => count($A),
                'hari_izin_sakit'   => count($attOther[$sid]['izin'] ?? []) + count($attOther[$sid]['sakit'] ?? []),
                'hari_alpha'        => count($attOther[$sid]['alpha'] ?? []),
                'hari_basis'        => count($basis),
                'jurnal_pada_basis' => $jurnalOnBasis,
                'jurnal_total'      => count($J),
                'jurnal_tanpa_hadir'=> $jurnalTanpaHadir,
                'jurnal_pct'        => $pct,
                'binaan_total'      => $bTotal,
                'binaan_tuntas'     => $bTuntas,
                'binaan_pct'        => $bPct,
                'kuadran'           => $qk,
                'kuadran_label'     => $ql,
                'kuadran_warna'     => $qb,
                'kuadran_ket'       => $qd,
            ];
        }
        usort($rows, static fn($a, $b) => [$a['jurnal_pct'] === null, -($a['jurnal_pct'] ?? 0), $a['guru_nama']]
            <=> [$b['jurnal_pct'] === null, -($b['jurnal_pct'] ?? 0), $b['guru_nama']]);

        $withPct = array_values(array_filter($rows, static fn($r) => $r['jurnal_pct'] !== null));
        $count = static fn(string $k) => count(array_filter($rows, static fn($r) => $r['kuadran'] === $k));
        return [
            'periode' => ['mulai' => $attStart, 'selesai' => $attEnd, 'hari_halaqah_unit' => count($unitDates)],
            'ringkas' => [
                'guru'              => count($rows),
                'guru_terukur'      => count($withPct),
                'rata_jurnal'       => $withPct ? array_sum(array_column($withPct, 'jurnal_pct')) / count($withPct) : null,
                'prima'             => $count('prima'),
                'prioritas'         => $count('prioritas_supervisi'),
                'belum_dipetakan'   => $count('unmatched'),
            ],
            'rows' => $rows,
        ];
    }
}

if (!function_exists('kbLoadTahfidzPage')) {
    /**
     * Konteks halaman tahfidz: filter, hak akses unit, data AIS, target, dan capaian
     * (data AIS + AI terverifikasi + koreksi manual).
     */
    function kbLoadTahfidzPage(PDO $pdo, array $query): array
    {
        $unitId = isset($query['unit_id']) ? max(1, (int)$query['unit_id']) : 2;
        $taId   = isset($query['ta']) && (int)$query['ta'] > 0 ? (int)$query['ta'] : null;
        $month  = isset($query['month']) ? max(0, min(12, (int)$query['month'])) : 0;
        $force  = ($query['refresh'] ?? '') === '1';
        $sort   = in_array($query['sort'] ?? '', ['grade_asc', 'highest', 'lowest'], true) ? (string)$query['sort'] : 'grade_asc';

        $res = kbFetchTahfidzData($unitId, $taId, $month, $force);

        $accessCtx = function_exists('getTahfidzAccessContext') ? getTahfidzAccessContext($pdo) : ['is_super_admin' => true];
        $units = $res['payload']['meta']['units'] ?? [];
        $allowedUnits = function_exists('filterTahfidzAisUnitsByAccess') ? filterTahfidzAisUnitsByAccess($units, $accessCtx) : $units;
        $allowedIds = array_map(static fn($u) => (int)$u['id'], $allowedUnits);
        if ($res['ok'] && $units && !in_array($unitId, $allowedIds, true)) {
            if (!$allowedIds) {
                http_response_code(403);
                die('403 - Akses Ditolak: unit Anda tidak terdaftar pada data AIS.');
            }
            $unitId = $allowedIds[0];
            $taId = null;
            $res = kbFetchTahfidzData($unitId, null, $month, $force);
        }

        $ctx = [
            'unit_id' => $unitId, 'ta_id' => $taId, 'month' => $month, 'sort' => $sort,
            'units' => $allowedUnits, 'allowed_unit_ids' => $allowedIds,
            'years' => [], 'unit' => null, 'ta' => null, 'months' => [],
            'ok' => $res['ok'], 'source' => $res['source'], 'fetched_at' => $res['fetched_at'], 'error' => $res['error'],
            'api' => null, 'api_mentah' => null, 'targets' => [], 'hierarki' => null, 'hierarki_mentah' => null,
            'ai' => null, 'a' => null,
        ];
        if (!$res['ok']) {
            return $ctx;
        }

        $api = $res['payload'];
        $meta = $api['meta'] ?? [];
        $ta = $meta['selected_tahun_ajaran'] ?? null;
        $ctx['ta'] = $ta;
        $ctx['ta_id'] = $ta ? (int)$ta['id'] : $taId;
        $ctx['unit'] = $meta['selected_unit'] ?? null;
        $ctx['years'] = $meta['academic_years'] ?? [];
        $ctx['months'] = $meta['available_months'] ?? [];
        $semester = (int)($ta['semester'] ?? 1);

        $ctx['targets'] = kbTahfidzTargets($pdo, $unitId, $semester);
        $ctx['api_mentah'] = $api;

        // Capaian murni AIS (pembanding) dan capaian final (AIS + AI terverifikasi + koreksi manual)
        $ctx['hierarki_mentah'] = calculateTahfidzHierarchy($api, $ctx['targets'], $sort);
        $apiFinal = $api;
        $ctx['ai'] = applyTahfidzAiCacheToStudents($pdo, $apiFinal, $unitId, (int)$ctx['ta_id'], KB_AI_HANYA_TERVERIFIKASI);
        $ctx['api'] = $apiFinal;
        $ctx['hierarki'] = calculateTahfidzHierarchy($apiFinal, $ctx['targets'], $sort);
        return $ctx;
    }
}

if (!function_exists('kbAssertUnitPost')) {
    /** Tolak POST untuk unit di luar hak akses pengguna (anti-IDOR). */
    function kbAssertUnitPost(PDO $pdo, int $unitId): void
    {
        $accessCtx = function_exists('getTahfidzAccessContext') ? getTahfidzAccessContext($pdo) : ['is_super_admin' => true];
        if (!empty($accessCtx['is_super_admin']) || empty($accessCtx['allowed_unit_names'])) {
            return;
        }
        $probe = kbFetchTahfidzData($unitId, null, 0, false);
        $allowed = filterTahfidzAisUnitsByAccess($probe['payload']['meta']['units'] ?? [], $accessCtx);
        if (!in_array($unitId, array_map(static fn($u) => (int)$u['id'], $allowed), true)) {
            http_response_code(403);
            die('403 - Akses Ditolak: Anda tidak memiliki hak akses untuk unit ini.');
        }
    }
}
