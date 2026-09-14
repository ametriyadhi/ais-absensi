<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/functions.php';

requireSuperAdmin();
$pageTitle = 'Import Data Siswa (Excel / CSV)';

// Download Template Siswa
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_siswa.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Nama Lengkap', 'NIS', 'NIK', 'Nama Subkelas', 'No HP / WA Ortu', 'Nama File Foto']);
    fputcsv($out, ['Ahmad Fauzan', '242507001', '3201123456780001', '7A', '081234567890', 'fauzan.jpg']);
    fputcsv($out, ['Siti Maryam', '242507002', '3201123456780002', '7A', '081234567891', 'maryam.png']);
    fputcsv($out, ['Budi Prasetyo', '242507003', '3201123456780003', '7B', '081234567892', '']);
    fclose($out);
    exit;
}

// Fungsi Native Membaca XLSX
function parseXlsxFile($filePath) {
    if (!class_exists('ZipArchive')) {
        throw new Exception("Fitur ZipArchive belum aktif di server PHP Anda.");
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        throw new Exception("Gagal membaca file. Pastikan format file adalah .xlsx yang valid.");
    }
    
    $sharedStrings = [];
    if (($ssXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ssXml);
        if ($xml) {
            foreach ($xml->si as $val) {
                $text = '';
                if (isset($val->t)) { $text = (string)$val->t; }
                elseif (isset($val->r)) { foreach ($val->r as $r) { $text .= (string)$r->t; } }
                $sharedStrings[] = $text;
            }
        }
    }
    
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        throw new Exception("Format Excel tidak sesuai. Sheet pertama tidak ditemukan.");
    }
    
    $sheet = simplexml_load_string($sheetXml);
    $rows = [];
    if ($sheet && isset($sheet->sheetData->row)) {
        foreach ($sheet->sheetData->row as $row) {
            $rowData = array_fill(0, 20, '');
            foreach ($row->c as $c) {
                $r = (string)$c['r'];
                $colStr = preg_replace('/[0-9]/', '', $r);
                $colIdx = 0;
                for ($i = 0; $i < strlen($colStr); $i++) { 
                    $colIdx = $colIdx * 26 + (ord(strtoupper($colStr[$i])) - 64); 
                }
                $idx = $colIdx - 1;
                
                $val = (string)$c->v;
                if (isset($c['t']) && (string)$c['t'] === 's') { 
                    $val = $sharedStrings[(int)$val] ?? ''; 
                } elseif (isset($c['t']) && (string)$c['t'] === 'inlineStr' && isset($c->is->t)) {
                    $val = (string)$c->is->t;
                }
                if ($idx >= 0 && $idx < 20) { $rowData[$idx] = $val; }
            }
            $rows[] = $rowData;
        }
    }
    $zip->close(); 
    return $rows;
}

// Fungsi Parser Umum (Mendukung .xlsx dan .csv)
function parseSpreadsheetRows($filePath, $fileName) {
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $rows = [];
        if (($handle = fopen($filePath, 'r')) !== false) {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            while (($data = fgetcsv($handle, 3000, ",")) !== false) {
                if (count($data) === 1) {
                    if (strpos($data[0], ';') !== false) {
                        $data = str_getcsv($data[0], ';');
                    } elseif (strpos($data[0], "\t") !== false) {
                        $data = str_getcsv($data[0], "\t");
                    }
                }
                $rows[] = $data;
            }
            fclose($handle);
        }
        return $rows;
    }
    return parseXlsxFile($filePath);
}

// Sanitasi string impor
function cleanImportString($val) {
    if ($val === null) return '';
    $str = trim((string)$val);
    if (str_starts_with($str, "'")) {
        $str = substr($str, 1);
    }
    if (preg_match('/^(\d+)\.0+$/', $str, $m)) {
        $str = $m[1];
    }
    return trim($str);
}

// Auto-detect kolom berdasarkan nama header baris 1
function detectHeaderIndices(array $headerRow) {
    $map = [
        'name'  => null,
        'nis'   => null,
        'nik'   => null,
        'class' => null,
        'phone' => null,
        'photo' => null
    ];
    
    foreach ($headerRow as $idx => $rawCol) {
        $col = strtolower(trim((string)$rawCol));
        if ($col === '') continue;
        
        // Lewati kolom nomor urut biasa
        if ($col === 'no' || $col === 'no.' || $col === 'nomor') {
            continue;
        }
        
        // Nama
        if ($map['name'] === null && (str_contains($col, 'nama') || str_contains($col, 'siswa') || str_contains($col, 'name'))) {
            if (!str_contains($col, 'foto') && !str_contains($col, 'photo') && !str_contains($col, 'subkelas') && !str_contains($col, 'kelas')) {
                $map['name'] = $idx;
                continue;
            }
        }
        // NIS
        if ($map['nis'] === null && (str_contains($col, 'nis') || str_contains($col, 'no induk') || str_contains($col, 'nomor induk siswa'))) {
            $map['nis'] = $idx;
            continue;
        }
        // NIK
        if ($map['nik'] === null && (str_contains($col, 'nik') || str_contains($col, 'ktp') || str_contains($col, 'kependudukan'))) {
            $map['nik'] = $idx;
            continue;
        }
        // Subkelas / Kelas
        if ($map['class'] === null && (str_contains($col, 'subkelas') || str_contains($col, 'kelas') || str_contains($col, 'rombel') || str_contains($col, 'class'))) {
            $map['class'] = $idx;
            continue;
        }
        // No HP / WA
        if ($map['phone'] === null && (str_contains($col, 'hp') || str_contains($col, 'wa') || str_contains($col, 'telepon') || str_contains($col, 'phone') || str_contains($col, 'kontak') || str_contains($col, 'ortu'))) {
            $map['phone'] = $idx;
            continue;
        }
        // Foto
        if ($map['photo'] === null && (str_contains($col, 'foto') || str_contains($col, 'photo') || str_contains($col, 'gambar') || str_contains($col, 'image'))) {
            $map['photo'] = $idx;
            continue;
        }
    }
    
    // Fallback jika tidak terdeteksi via nama header:
    if ($map['name'] === null)  $map['name']  = 0;
    if ($map['nis'] === null)   $map['nis']   = 1;
    if ($map['nik'] === null)   $map['nik']   = 2;
    if ($map['class'] === null) $map['class'] = 3;
    
    if ($map['phone'] === null && $map['photo'] === null) {
        if (count($headerRow) >= 6) {
            $map['phone'] = 4;
            $map['photo'] = 5;
        } else {
            $map['photo'] = 4;
        }
    }
    
    return $map;
}

// Pencocokan Subkelas Cerdas (Smart Class Matcher)
function resolveClassGroupId($subkelasInput, array $classGroupsList) {
    $raw = strtolower(trim((string)$subkelasInput));
    if ($raw === '') return null;

    // Normalisasi spasi dan tanda hubung
    $normalized = preg_replace('/\s+/', ' ', $raw);
    $cleanAlphaNum = preg_replace('/[^a-z0-9]/', '', $raw);

    // 1. Exact match pada name subkelas (contoh: '7A')
    foreach ($classGroupsList as $cg) {
        if (strtolower(trim($cg['name'])) === $normalized) {
            return (int)$cg['id'];
        }
    }

    // 2. Exact match tanpa karakter non-alphanumeric (contoh: '7-A' -> '7a')
    foreach ($classGroupsList as $cg) {
        if (preg_replace('/[^a-z0-9]/', '', strtolower($cg['name'])) === $cleanAlphaNum) {
            return (int)$cg['id'];
        }
    }

    // 3. Format kombinasi "Grade - Subkelas" (contoh: "Kelas 7 - 7A" atau "Kelas 7 7A")
    foreach ($classGroupsList as $cg) {
        $combo1 = strtolower(trim($cg['grade'] . ' - ' . $cg['name']));
        $combo2 = strtolower(trim($cg['grade'] . ' ' . $cg['name']));
        if ($combo1 === $normalized || $combo2 === $normalized) {
            return (int)$cg['id'];
        }
    }

    // 4. Jika input berupa angka ID subkelas langsung
    if (is_numeric($raw)) {
        $targetId = (int)$raw;
        foreach ($classGroupsList as $cg) {
            if ((int)$cg['id'] === $targetId) {
                return $targetId;
            }
        }
    }

    return null;
}

// Ambil Tahun Ajaran Aktif
$stmtAy = $pdo->query("SELECT id, name FROM academic_years WHERE status='active' LIMIT 1");
$activeAy = $stmtAy->fetch(PDO::FETCH_ASSOC);
$activeAyId = $activeAy['id'] ?? null;
$activeAyName = $activeAy['name'] ?? null;

// Ambil Seluruh Data Subkelas Lengkap dengan Grade & Unit
$classGroupsList = $pdo->query("
    SELECT cg.id, cg.name, cg.grade_id, g.grade, u.unit
    FROM class_groups cg
    INNER JOIN grades g ON g.id = cg.grade_id
    INNER JOIN units u ON u.id = g.unit_id
    ORDER BY u.id, g.sort_order, cg.name
")->fetchAll(PDO::FETCH_ASSOC);

// Proses Upload Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file_excel'])) {
    requireCsrf();

    if (!$activeAyId) {
        flash('error', 'Gagal: Belum ada Tahun Ajaran yang berstatus aktif. Aktifkan Tahun Ajaran terlebih dahulu pada menu Master Data > Tahun Ajaran.');
        redirect('import.php');
    }

    if (empty($_FILES['file_excel']['name']) || $_FILES['file_excel']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Gagal: File spreadsheet tidak valid atau gagal diunggah.');
        redirect('import.php');
    }

    $importMode = $_POST['import_mode'] ?? 'update'; // 'update' (Upsert) atau 'skip'
    $matchBy = $_POST['match_by'] ?? 'auto';         // 'auto', 'nis', 'nik'

    // Ambil daftar file foto jika diupload bersamaan (mendukung foto .jpg/.png/.webp atau file .zip)
    $uploadedPhotos = [];
    $tempZipDir = null;

    if (!empty($_FILES['photos']['name'][0])) {
        foreach ($_FILES['photos']['name'] as $i => $origName) {
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                $trimmedName = trim($origName);
                $ext = strtolower(pathinfo($trimmedName, PATHINFO_EXTENSION));
                $tmpPath = $_FILES['photos']['tmp_name'][$i];

                if ($ext === 'zip') {
                    if (class_exists('ZipArchive')) {
                        $zip = new ZipArchive();
                        if ($zip->open($tmpPath) === true) {
                            $tempZipDir = sys_get_temp_dir() . '/zip_photos_' . uniqid();
                            @mkdir($tempZipDir, 0755, true);
                            for ($z = 0; $z < $zip->numFiles; $z++) {
                                $entryName = $zip->getNameIndex($z);
                                if (str_starts_with($entryName, '__MACOSX') || str_ends_with($entryName, '/')) {
                                    continue;
                                }
                                $entryBase = basename($entryName);
                                $entryExt = strtolower(pathinfo($entryBase, PATHINFO_EXTENSION));
                                if (in_array($entryExt, ['jpg', 'jpeg', 'png', 'webp'])) {
                                    $targetPath = $tempZipDir . '/' . uniqid() . '_' . $entryBase;
                                    copy("zip://" . $tmpPath . "#" . $entryName, $targetPath);
                                    $uploadedPhotos[strtolower($entryBase)] = [
                                        'path'      => $targetPath,
                                        'orig'      => $entryBase,
                                        'is_upload' => false
                                    ];
                                }
                            }
                            $zip->close();
                        }
                    }
                } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $uploadedPhotos[strtolower($trimmedName)] = [
                        'path'      => $tmpPath,
                        'orig'      => $trimmedName,
                        'is_upload' => true
                    ];
                }
            }
        }
    }

    try {
        $origFileName = $_FILES['file_excel']['name'];
        $tmpFilePath  = $_FILES['file_excel']['tmp_name'];
        $rows = parseSpreadsheetRows($tmpFilePath, $origFileName);

        if (empty($rows) || count($rows) <= 1) {
            flash('error', 'File Excel/CSV kosong atau hanya berisi baris header.');
            redirect('import.php');
        }

        // Header Detection
        $headerRow = array_shift($rows);
        $colMap = detectHeaderIndices($headerRow);

        $totalRows = count($rows);
        $insertedCount = 0;
        $updatedCount  = 0;
        $skippedCount  = 0;
        $failedCount   = 0;
        $photosMatchedCount = 0;
        $errorDetails  = [];

        // Indeks foto yang sudah ada di disk UPLOAD_STUDENT (case-insensitive)
        $existingDiskPhotos = [];
        if (is_dir(UPLOAD_STUDENT)) {
            $scan = scandir(UPLOAD_STUDENT);
            foreach ($scan as $f) {
                if ($f === '.' || $f === '..' || str_ends_with(strtolower($f), '.zip')) continue;
                $existingDiskPhotos[strtolower($f)] = $f;
            }
        }

        $pdo->beginTransaction();

        // Prepared statements untuk pencarian dan operasi siswa
        $stmtFindByNis = $pdo->prepare("SELECT * FROM students WHERE nis = ? LIMIT 1");
        $stmtFindByNik = $pdo->prepare("SELECT * FROM students WHERE nik = ? LIMIT 1");
        $stmtCheckNisCollision = $pdo->prepare("SELECT id FROM students WHERE nis = ? AND id != ? LIMIT 1");
        $stmtCheckNikCollision = $pdo->prepare("SELECT id FROM students WHERE nik = ? AND id != ? LIMIT 1");

        $stmtInsertStudent = $pdo->prepare("
            INSERT INTO students (name, nis, nik, parent_phone, photo, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmtUpdateStudent = $pdo->prepare("
            UPDATE students
            SET name = ?, nis = ?, nik = ?, parent_phone = ?, photo = COALESCE(?, photo), deleted_at = NULL, updated_at = NOW()
            WHERE id = ?
        ");

        // Prepared statements untuk enrollment Tahun Ajaran aktif
        $stmtCheckEnroll = $pdo->prepare("
            SELECT id, class_group_id
            FROM student_enrollments
            WHERE student_id = ? AND academic_year_id = ?
            LIMIT 1
        ");
        $stmtInsertEnroll = $pdo->prepare("
            INSERT INTO student_enrollments (student_id, academic_year_id, class_group_id, status, created_at, updated_at)
            VALUES (?, ?, ?, 'active', NOW(), NOW())
        ");
        $stmtUpdateEnroll = $pdo->prepare("
            UPDATE student_enrollments
            SET class_group_id = ?, status = 'active', updated_at = NOW()
            WHERE id = ?
        ");

        foreach ($rows as $lineNum => $data) {
            $rowNum = $lineNum + 2;

            $nama         = cleanImportString($data[$colMap['name']] ?? '');
            $nis          = cleanImportString($data[$colMap['nis']] ?? '');
            $nik          = cleanImportString($data[$colMap['nik']] ?? '');
            $subkelasName = cleanImportString($data[$colMap['class']] ?? '');
            $parentPhone  = isset($colMap['phone']) ? cleanImportString($data[$colMap['phone']] ?? '') : '';
            $namaFileFoto = isset($colMap['photo']) ? cleanImportString($data[$colMap['photo']] ?? '') : '';

            // Jika seluruh baris kosong, lewati tanpa error
            if ($nama === '' && $nis === '' && $nik === '' && $subkelasName === '') {
                continue;
            }

            // Validasi nama siswa wajib ada
            if ($nama === '') {
                $failedCount++;
                if (count($errorDetails) < 10) {
                    $errorDetails[] = "Baris $rowNum: Nama siswa kosong.";
                }
                continue;
            }

            // Validasi subkelas
            $matchedClassId = null;
            if ($subkelasName !== '') {
                $matchedClassId = resolveClassGroupId($subkelasName, $classGroupsList);
                if (!$matchedClassId) {
                    $failedCount++;
                    if (count($errorDetails) < 10) {
                        $errorDetails[] = "Baris $rowNum: Subkelas '$subkelasName' untuk siswa '$nama' tidak ditemukan dalam sistem.";
                    }
                    continue;
                }
            } else {
                $failedCount++;
                if (count($errorDetails) < 10) {
                    $errorDetails[] = "Baris $rowNum: Subkelas untuk siswa '$nama' wajib diisi.";
                }
                continue;
            }

            // Cek apakah ada file foto yang cocok (dari upload form, zip, atau file lokal di uploads/students)
            $photoNameSave = null;
            if (!empty($namaFileFoto)) {
                $trimmedFoto = trim($namaFileFoto);
                $lowerFotoName = strtolower($trimmedFoto);
                $photoExt = strtolower(pathinfo($trimmedFoto, PATHINFO_EXTENSION));

                // Kandidat nama pencocokan (lengkap maupun tanpa ekstensi)
                $candidates = [$lowerFotoName];
                if (empty($photoExt)) {
                    foreach (['jpg', 'jpeg', 'png', 'webp'] as $testExt) {
                        $candidates[] = $lowerFotoName . '.' . $testExt;
                    }
                }

                $sourcePath = null;
                $isTempUploaded = false;
                $matchedExt = $photoExt;

                // 1. Cek dari file yang baru diunggah (upload form atau ekstrak zip)
                foreach ($candidates as $cand) {
                    if (isset($uploadedPhotos[$cand])) {
                        $sourcePath = $uploadedPhotos[$cand]['path'];
                        $isTempUploaded = $uploadedPhotos[$cand]['is_upload'];
                        $matchedExt = strtolower(pathinfo($uploadedPhotos[$cand]['orig'], PATHINFO_EXTENSION));
                        break;
                    }
                }

                // 2. Jika belum ketemu, cek apakah file sudah ada di folder server UPLOAD_STUDENT
                if (!$sourcePath && is_dir(UPLOAD_STUDENT)) {
                    foreach ($candidates as $cand) {
                        if (file_exists(UPLOAD_STUDENT . $cand)) {
                            $sourcePath = UPLOAD_STUDENT . $cand;
                            $matchedExt = strtolower(pathinfo($cand, PATHINFO_EXTENSION));
                            break;
                        }
                        if (isset($existingDiskPhotos[$cand])) {
                            $sourcePath = UPLOAD_STUDENT . $existingDiskPhotos[$cand];
                            $matchedExt = strtolower(pathinfo($existingDiskPhotos[$cand], PATHINFO_EXTENSION));
                            break;
                        }
                    }
                }

                // Jika ditemukan sumber file foto yang valid
                if ($sourcePath && file_exists($sourcePath) && in_array($matchedExt, ['jpg', 'jpeg', 'png', 'webp'])) {
                    if (!is_dir(UPLOAD_STUDENT)) {
                        mkdir(UPLOAD_STUDENT, 0755, true);
                    }
                    $photoNameSave = uniqid('student_', true) . '.' . $matchedExt;
                    if ($isTempUploaded && is_uploaded_file($sourcePath)) {
                        move_uploaded_file($sourcePath, UPLOAD_STUDENT . $photoNameSave);
                    } else {
                        copy($sourcePath, UPLOAD_STUDENT . $photoNameSave);
                    }
                    $photosMatchedCount++;
                }
            }

            // Identifikasi Data Siswa di Database
            $existingStudent = null;

            if ($matchBy === 'nis' && $nis !== '') {
                $stmtFindByNis->execute([$nis]);
                $existingStudent = $stmtFindByNis->fetch(PDO::FETCH_ASSOC);
            } elseif ($matchBy === 'nik' && $nik !== '') {
                $stmtFindByNik->execute([$nik]);
                $existingStudent = $stmtFindByNik->fetch(PDO::FETCH_ASSOC);
            } else {
                // Default: 'auto' (Cocokkan NIS dahulu, jika belum ketemu cocokkan NIK)
                if ($nis !== '') {
                    $stmtFindByNis->execute([$nis]);
                    $existingStudent = $stmtFindByNis->fetch(PDO::FETCH_ASSOC);
                }
                if (!$existingStudent && $nik !== '') {
                    $stmtFindByNik->execute([$nik]);
                    $existingStudent = $stmtFindByNik->fetch(PDO::FETCH_ASSOC);
                }
            }

            // SKENARIO 1: SISWA SUDAH ADA DALAM DATABASE
            if ($existingStudent) {
                if ($importMode === 'skip') {
                    $skippedCount++;
                    continue;
                }

                $studentId = (int)$existingStudent['id'];

                // Validasi bentrok NIS dengan siswa lain
                $targetNis = $nis !== '' ? $nis : $existingStudent['nis'];
                if (!empty($targetNis)) {
                    $stmtCheckNisCollision->execute([$targetNis, $studentId]);
                    if ($stmtCheckNisCollision->fetchColumn()) {
                        $failedCount++;
                        if (count($errorDetails) < 10) {
                            $errorDetails[] = "Baris $rowNum: Gagal update siswa '$nama'. NIS '$targetNis' sudah digunakan oleh siswa lain.";
                        }
                        continue;
                    }
                }

                // Validasi bentrok NIK dengan siswa lain
                $targetNik = $nik !== '' ? $nik : $existingStudent['nik'];
                if (!empty($targetNik)) {
                    $stmtCheckNikCollision->execute([$targetNik, $studentId]);
                    if ($stmtCheckNikCollision->fetchColumn()) {
                        $failedCount++;
                        if (count($errorDetails) < 10) {
                            $errorDetails[] = "Baris $rowNum: Gagal update siswa '$nama'. NIK '$targetNik' sudah digunakan oleh siswa lain.";
                        }
                        continue;
                    }
                }

                $targetPhone = $parentPhone !== '' ? $parentPhone : $existingStudent['parent_phone'];

                // Eksekusi Update Profil Siswa
                $stmtUpdateStudent->execute([
                    $nama,
                    $targetNis !== '' ? $targetNis : null,
                    $targetNik !== '' ? $targetNik : null,
                    $targetPhone !== '' ? $targetPhone : null,
                    $photoNameSave, // jika null, COALESCE di SQL tetap mempertahankan foto lama
                    $studentId
                ]);

                // Hapus file foto lama di disk jika digantikan dengan foto baru
                if ($photoNameSave && !empty($existingStudent['photo']) && $existingStudent['photo'] !== $photoNameSave) {
                    $oldPhotoPath = UPLOAD_STUDENT . $existingStudent['photo'];
                    if (file_exists($oldPhotoPath)) {
                        @unlink($oldPhotoPath);
                    }
                }

                // Update / Pasang Enrollment di Tahun Ajaran Aktif
                $stmtCheckEnroll->execute([$studentId, $activeAyId]);
                $existingEnrollment = $stmtCheckEnroll->fetch(PDO::FETCH_ASSOC);

                if ($existingEnrollment) {
                    $stmtUpdateEnroll->execute([$matchedClassId, $existingEnrollment['id']]);
                } else {
                    $stmtInsertEnroll->execute([$studentId, $activeAyId, $matchedClassId]);
                }

                $updatedCount++;

            } else {
                // SKENARIO 2: SISWA BARU (INSERT)
                // Cek bentrok NIS sebelum insert
                if ($nis !== '') {
                    $stmtFindByNis->execute([$nis]);
                    if ($stmtFindByNis->fetchColumn()) {
                        $failedCount++;
                        if (count($errorDetails) < 10) {
                            $errorDetails[] = "Baris $rowNum: NIS '$nis' sudah terdaftar di database.";
                        }
                        continue;
                    }
                }

                // Cek bentrok NIK sebelum insert
                if ($nik !== '') {
                    $stmtFindByNik->execute([$nik]);
                    if ($stmtFindByNik->fetchColumn()) {
                        $failedCount++;
                        if (count($errorDetails) < 10) {
                            $errorDetails[] = "Baris $rowNum: NIK '$nik' sudah terdaftar di database.";
                        }
                        continue;
                    }
                }

                // Eksekusi Insert Siswa Baru
                $stmtInsertStudent->execute([
                    $nama,
                    $nis !== '' ? $nis : null,
                    $nik !== '' ? $nik : null,
                    $parentPhone !== '' ? $parentPhone : null,
                    $photoNameSave
                ]);
                $newStudentId = (int)$pdo->lastInsertId();

                // Pasang Enrollment di Tahun Ajaran Aktif
                $stmtInsertEnroll->execute([$newStudentId, $activeAyId, $matchedClassId]);

                $insertedCount++;
            }
        }

        $pdo->commit();

        // Hapus direktori temporary jika mengekstrak file zip
        if (!empty($tempZipDir) && is_dir($tempZipDir)) {
            $tempFiles = scandir($tempZipDir);
            foreach ($tempFiles as $tf) {
                if ($tf !== '.' && $tf !== '..') {
                    @unlink($tempZipDir . '/' . $tf);
                }
            }
            @rmdir($tempZipDir);
        }

        // Audit Logging
        logAudit($pdo, 'import_students', 'students', null, [
            'total_rows'     => $totalRows,
            'inserted'       => $insertedCount,
            'updated'        => $updatedCount,
            'skipped'        => $skippedCount,
            'failed'         => $failedCount,
            'photos_matched' => $photosMatchedCount,
            'mode'           => $importMode,
            'match_by'       => $matchBy,
            'year_id'        => $activeAyId
        ]);

        $feedbackMsg = "Proses Import Selesai! Data Baru: {$insertedCount}, Diperbarui: {$updatedCount}, Dilewati: {$skippedCount}, Gagal: {$failedCount}, Foto Berhasil Terpasang: {$photosMatchedCount}.";
        if (!empty($errorDetails)) {
            $feedbackMsg .= "<br><br><strong>Catatan Kendala:</strong><ul style='margin: 4px 0 0 16px;'>";
            foreach ($errorDetails as $err) {
                $feedbackMsg .= "<li>" . e($err) . "</li>";
            }
            if ($failedCount > count($errorDetails)) {
                $sisa = $failedCount - count($errorDetails);
                $feedbackMsg .= "<li>...dan $sisa baris lainnya.</li>";
            }
            $feedbackMsg .= "</ul>";
        }

        $flashType = ($insertedCount > 0 || $updatedCount > 0) ? 'success' : 'warning';
        flash($flashType, $feedbackMsg);
        redirect('index.php');

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!empty($tempZipDir) && is_dir($tempZipDir)) {
            $tempFiles = scandir($tempZipDir);
            foreach ($tempFiles as $tf) {
                if ($tf !== '.' && $tf !== '..') {
                    @unlink($tempZipDir . '/' . $tf);
                }
            }
            @rmdir($tempZipDir);
        }
        flash('error', 'Terjadi kesalahan sistem saat memproses impor: ' . $e->getMessage());
        redirect('import.php');
    }
}

require __DIR__ . '/../../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <div>
            <h3 style="display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-file-import" style="color: #10b981;"></i>
                Import Data Siswa (Excel / CSV)
            </h3>
            <small style="color: #64748b;">
                Tahun Ajaran Aktif: <strong><?= e($activeAyName ?? 'Belum ada TA aktif') ?></strong>
            </small>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="?download_template=1" class="btn btn-success" style="background-color: #10b981; color: white;">
                <i class="fa-solid fa-download"></i> Download Template (CSV / Excel)
            </a>
            <a href="index.php" class="btn" style="background-color: #64748b; color: white;">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Data Siswa
            </a>
        </div>
    </div>

    <!-- PANDUAN PENGISIAN DAN FORMAT -->
    <div style="margin-bottom: 24px; background: #f8fafc; border: 1px solid #cbd5e1; padding: 18px; border-radius: 10px;">
        <h4 style="margin: 0 0 10px 0; color: #1e293b; display: flex; align-items: center; gap: 8px; font-size: 15px;">
            <i class="fa-solid fa-circle-info" style="color: #0284c7;"></i>
            Format Kolom Spreadsheet (Excel .xlsx / CSV .csv)
        </h4>
        <p style="margin: 0 0 12px 0; font-size: 13px; color: #475569;">
            Baris pertama merupakan nama kolom (Header). Sistem otomatis mendeteksi kolom berdasarkan nama judul berikut:
        </p>

        <div style="overflow-x: auto;">
            <table style="width:100%; border-collapse: collapse; background: white; font-size: 13px;" border="1" cellpadding="8">
                <thead>
                    <tr style="background-color: #e2e8f0; color: #1e293b;">
                        <th>Kolom A (Nama Lengkap) *</th>
                        <th>Kolom B (NIS)</th>
                        <th>Kolom C (NIK)</th>
                        <th>Kolom D (Nama Subkelas) *</th>
                        <th>Kolom E (No. HP / WA Ortu)</th>
                        <th>Kolom F (Nama File Foto)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Ahmad Fauzan</td>
                        <td><code>242507001</code></td>
                        <td><code>3201123456780001</code></td>
                        <td><span class="badge badge-primary" style="background:#0284c7; color:white;">7A</span></td>
                        <td>081234567890</td>
                        <td><code>fauzan.jpg</code></td>
                    </tr>
                    <tr>
                        <td>Siti Maryam</td>
                        <td><code>242507002</code></td>
                        <td><code>3201123456780002</code></td>
                        <td><span class="badge badge-primary" style="background:#0284c7; color:white;">7B</span></td>
                        <td>081234567891</td>
                        <td><em>(kosongkan jika tanpa foto)</em></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 14px; font-size: 12.5px; color: #334155; line-height: 1.6;">
            <strong>Daftar Subkelas Terdaftar di Sistem:</strong>
            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px;">
                <?php foreach ($classGroupsList as $cg): ?>
                    <span style="background: #e2e8f0; color: #1e293b; padding: 3px 8px; border-radius: 5px; font-size: 12px;">
                        <strong><?= e($cg['name']) ?></strong> <small>(<?= e($cg['unit']) ?> - <?= e($cg['grade']) ?>)</small>
                    </span>
                <?php endforeach; ?>
            </div>
            <small style="color: #64748b; display: block; margin-top: 8px;">
                * Pada kolom <strong>Nama Subkelas</strong>, Anda cukup mengisi nama subkelasnya (contoh: <code>7A</code> atau <code>8B</code>). Kolom foto dan nomor HP bersifat opsional.
            </small>
        </div>
    </div>

    <!-- FORM IMPORT SISWA -->
    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>

        <!-- PENGATURAN MODE UPDATE / UPSERT -->
        <div style="background: #f0fdf4; border: 1px solid #86efac; padding: 16px; border-radius: 10px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 10px 0; color: #166534; font-size: 14.5px; display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-arrows-rotate"></i>
                Pengaturan Update &amp; Deteksi Data Siswa Eksisting (Upsert)
            </h4>

            <div style="margin-bottom: 14px;">
                <label style="font-weight: 600; font-size: 13.5px; color: #1e293b; display: block; margin-bottom: 6px;">
                    1. Perilaku Jika Data Siswa Sudah Ada di Database:
                </label>
                <div style="display: flex; flex-direction: column; gap: 8px; margin-left: 4px;">
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="import_mode" value="update" checked style="margin-top: 3px;">
                        <div>
                            <strong>Perbarui Data Siswa (Update / Upsert)</strong> &mdash; <em>Rekomendasi</em><br>
                            <span style="color: #475569;">Jika siswa sudah terdaftar (berdasarkan NIS / NIK), perbarui profil nama, No WA ortu, foto (jika ada file baru), serta perbarui penempatan subkelasnya di Tahun Ajaran aktif (<?= e($activeAyName ?? '-') ?>). Jika belum ada, buat siswa baru.</span>
                        </div>
                    </label>
                    <label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="import_mode" value="skip" style="margin-top: 3px;">
                        <div>
                            <strong>Lewati Siswa yang Sudah Ada (Skip Duplicate)</strong><br>
                            <span style="color: #475569;">Hanya menambahkan siswa baru. Baris data yang NIS atau NIK-nya telah terdaftar di database akan dilewati.</span>
                        </div>
                    </label>
                </div>
            </div>

            <div>
                <label style="font-weight: 600; font-size: 13.5px; color: #1e293b; display: block; margin-bottom: 6px;">
                    2. Kunci Pencocokan Duplikasi:
                </label>
                <div style="display: flex; gap: 16px; flex-wrap: wrap; margin-left: 4px;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="match_by" value="auto" checked>
                        <span>Otomatis (Berdasarkan NIS atau NIK)</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="match_by" value="nis">
                        <span>Hanya Berdasarkan NIS</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="match_by" value="nik">
                        <span>Hanya Berdasarkan NIK</span>
                    </label>
                </div>
            </div>
        </div>

        <!-- UPLOAD FILE EXCEL & FOTO -->
        <div class="form-group" style="margin-bottom: 18px;">
            <label style="font-weight: 600; display: block; margin-bottom: 6px; color: #1e293b;">
                3. Upload File Spreadsheet (.xlsx atau .csv) *
            </label>
            <input type="file" name="file_excel" accept=".xlsx, .csv" required style="padding: 12px; border: 2px dashed #94a3b8; width: 100%; border-radius: 8px; background: #ffffff;">
            <small style="color: #64748b; margin-top: 4px; display: block;">Mendukung Microsoft Excel (.xlsx) atau file teks CSV (.csv).</small>
        </div>

        <div class="form-group" style="margin-bottom: 24px;">
            <label style="font-weight: 600; display: block; margin-bottom: 6px; color: #1e293b;">
                4. Upload File Foto Sekaligus (Opsional: Pilih Banyak Foto .JPG/.PNG/.WEBP atau 1 File .ZIP Foto)
            </label>
            <input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp,.zip,application/zip" style="padding: 12px; border: 2px dashed #94a3b8; width: 100%; border-radius: 8px; background: #ffffff;">
            <small style="color: #64748b; margin-top: 4px; display: block;">
                Pilih atau seret banyak file foto sekaligus ATAU unggah 1 file <code>.zip</code> foto. Sistem otomatis mencocokkan nama file dengan <strong>Kolom F (Nama File Foto)</strong>. Sistem juga otomatis mendeteksi file foto yang sudah tersimpan di folder server <code>app/uploads/students/</code>.
            </small>
        </div>

        <!-- TOMBOL AKSI -->
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 600; font-size: 14px;">
                <i class="fa-solid fa-cloud-arrow-up"></i> Mulai Proses Import Siswa
            </button>
            <a href="index.php" class="btn" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 10px 18px;">
                Batal
            </a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>