<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/image_helper.php';
cek_login();

if (strtoupper(trim($_SESSION['role'] ?? '')) === 'VIEWER') {
    set_flash('error', 'Akses Ditolak: Viewer tidak diizinkan mengunggah atau mengimpor data.');
    redirect('dashboard.php');
    exit;
}

$result_upload = null;
$result_import = null;
$result_plu = null;
$error_upload = '';
$error_import = '';
$error_plu = '';
$active_tab = $_GET['tab'] ?? 'upload';

// =====================================================
// HELPER: DELETE DIR
// =====================================================
function delete_dir($dir) {
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

// =====================================================
// HANDLE: UPLOAD FOTO BULK (ZIP)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_bulk') {
    $active_tab = 'upload';
    
    if (!empty($_FILES['file_zip']['tmp_name'])) {
        $tmp = $_FILES['file_zip']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['file_zip']['name'], PATHINFO_EXTENSION));
        
        if ($ext !== 'zip') {
            $error_upload = 'File harus berupa ZIP.';
        } elseif (!class_exists('ZipArchive')) {
            $error_upload = 'Extension PHP ZipArchive belum aktif.';
        } else {
            $batch_code = 'BATCH_' . date('Ymd_His');
            $temp_dir = STORAGE_PATH . '/temp/' . $batch_code;
            if (!is_dir($temp_dir)) mkdir($temp_dir, 0755, true);
            
            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                $error_upload = 'Gagal membuka file ZIP.';
            } else {
                $zip->extractTo($temp_dir);
                $zip->close();
                
                $stmt = $pdo->prepare("INSERT INTO upload_batch (batch_code, filename_zip, status, user_id, created_at) VALUES (?, ?, 'PROCESSING', ?, NOW())");
                $stmt->execute([$batch_code, $_FILES['file_zip']['name'], $_SESSION['user_id']]);
                $batch_id = $pdo->lastInsertId();
                
                $all_files = [];
                $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp_dir, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($rii as $file) {
                    if ($file->isDir()) continue;
                    $all_files[] = $file->getPathname();
                }
                
                // SMART MATCH: Cache Aset berdasarkan Nama Tanpa Spasi/Simbol
                $cache_aset = [];
                $stmt_cache = $pdo->query("SELECT id, nama_barang, foto_thumb, foto_medium FROM aset");
                while ($r = $stmt_cache->fetch()) {
                    $norm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $r['nama_barang']));
                    $cache_aset[$norm] = ['id' => $r['id'], 'thumb' => $r['foto_thumb'], 'medium' => $r['foto_medium']];
                }
                
                $sukses_insert = 0; $sukses_update = 0; $skip = 0; $failed = [];
                
                $stmt_ins = $pdo->prepare("INSERT INTO aset (nama_barang, status, kondisi, foto_thumb, foto_medium, created_by) VALUES (?, 'AKTIF', 'BAIK', ?, ?, ?)");
                $stmt_upd = $pdo->prepare("UPDATE aset SET foto_thumb = ?, foto_medium = ?, updated_at = NOW() WHERE id = ?");
                
                foreach ($all_files as $file_path) {
                    $filename = pathinfo($file_path, PATHINFO_FILENAME);
                    $ext_file = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                    
                    if (!in_array($ext_file, ALLOWED_EXT)) { $skip++; continue; }
                    
                    $nama_barang = trim($filename);
                    $nama_barang = preg_replace('/[_]+/', ' ', $nama_barang);
                    $nama_barang = trim($nama_barang);
                    
                    if (empty($nama_barang)) { $skip++; $failed[] = ['file' => basename($file_path), 'alasan' => 'Nama file kosong']; continue; }
                    
                    // Normalisasi nama untuk pencarian
                    $norm_key = strtoupper(preg_replace('/[^A-Z0-9]/', '', $nama_barang));
                    
                    $file_data = ['tmp_name' => $file_path, 'name' => basename($file_path), 'size' => filesize($file_path), 'error' => UPLOAD_ERR_OK];
                    $proc = process_image_upload($file_data);
                    
                    if ($proc['success']) {
                        try {
                            if (isset($cache_aset[$norm_key])) {
                                $existing = $cache_aset[$norm_key];
                                if (!empty($existing['thumb'])) delete_image_file($existing['thumb']);
                                if (!empty($existing['medium'])) delete_image_file($existing['medium']);
                                $stmt_upd->execute([$proc['thumb'], $proc['medium'], $existing['id']]);
                                log_activity($pdo, $existing['id'], null, 'UPDATE', null, ['nama_barang' => $nama_barang, 'from' => 'bulk_update_foto']);
                                $sukses_update++;
                            } else {
                                $stmt_ins->execute([$nama_barang, $proc['thumb'], $proc['medium'], $_SESSION['user_id']]);
                                $new_id = $pdo->lastInsertId();
                                $cache_aset[$norm_key] = ['id' => $new_id, 'thumb' => $proc['thumb'], 'medium' => $proc['medium']];
                                log_activity($pdo, $new_id, null, 'CREATE', null, ['nama_barang' => $nama_barang, 'from' => 'bulk_upload']);
                                $sukses_insert++;
                            }
                        } catch (Exception $e) { $skip++; $failed[] = ['file' => basename($file_path), 'alasan' => 'DB error: ' . $e->getMessage()]; }
                    } else { $skip++; $failed[] = ['file' => basename($file_path), 'alasan' => $proc['error']]; }
                }
                
                $stmt = $pdo->prepare("UPDATE upload_batch SET total_files=?, success_files=?, failed_files=?, status='DONE', finished_at=NOW(), log=? WHERE id=?");
                $stmt->execute([count($all_files), ($sukses_insert + $sukses_update), $skip, "Baru: {$sukses_insert}, Update: {$sukses_update}, Skip: {$skip}", $batch_id]);
                delete_dir($temp_dir);
                
                $result_upload = ['batch_code' => $batch_code, 'total' => count($all_files), 'sukses_insert' => $sukses_insert, 'sukses_update' => $sukses_update, 'skip' => $skip, 'failed' => array_slice($failed, 0, 20)];
            }
        }
    }
}

// =====================================================
// HANDLE: IMPORT CSV ASET (SMART MATCH)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_aset') {
    $active_tab = 'import';
    
    if (!empty($_FILES['file_csv']['tmp_name'])) {
        $content = file_get_contents($_FILES['file_csv']['tmp_name']);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        if (count($lines) < 2) {
            $error_import = 'File kosong atau tidak ada data.';
        } else {
            $sample = implode('', array_slice($lines, 0, 5));
            $tab_c = substr_count($sample, "\t");
            $semi = substr_count($sample, ';');
            $comma = substr_count($sample, ',');
            $delim = "\t";
            if ($semi > $tab_c && $semi > $comma) $delim = ';';
            elseif ($comma > $tab_c && $comma > $semi) $delim = ',';
            
            $header = array_map('strtoupper', array_map('trim', explode($delim, $lines[0])));
            
            $find_col = function($names) use ($header) {
                foreach ($names as $n) {
                    $idx = array_search(strtoupper($n), $header);
                    if ($idx !== false) return $idx;
                }
                return false;
            };
            
            $idx = [
                'descp'       => $find_col(['DESCP', 'DESCRIPTION', 'NAMA BARANG', 'NAMA']),
                'elec'        => $find_col(['KATEGORI ELECTRICAL', 'KATEGORI_ELECTRICAL', 'ELECTRICAL']),
                'equip'       => $find_col(['KATEGORI EQUIPMENT', 'KATEGORI_EQUIPMENT', 'EQUIPMENT']),
                'lokasi'      => $find_col(['AREA PENEMPATAN', 'LOKASI DETAIL', 'LOKASI_DETAIL', 'LOKASI', 'AREA']),
                'dimensi'     => $find_col(['DIMENSI']),
                'watt'        => $find_col(['WATT', 'DAYA']),
                'deskripsi'   => $find_col(['DESKRIPSI', 'DESKRIPSI UMUM', 'DESKRIPSI_UMUM']),
                'guidance'    => $find_col(['GUIDANCE', 'PANDUAN']),
                'standar_tag' => $find_col(['STANDAR PENEMPELAN NOMOR TAG', 'STANDAR PENEMPELAN NO TAG', 'STANDAR_TAG']),
                'standar_qty' => $find_col(['STANDAR QTY DI TOKO', 'STANDAR QTY', 'QTY TOKO']),
            ];
            
            if ($idx['descp'] === false || $idx['elec'] === false || $idx['equip'] === false || $idx['lokasi'] === false) {
                $error_import = 'Kolom wajib minimal: DESCP, KATEGORI ELECTRICAL, KATEGORI EQUIPMENT, AREA PENEMPATAN/LOKASI DETAIL';
            } else {
                $cache_kat = [];
                $stmt = $pdo->query("SELECT id, UPPER(nama_kategori) AS n FROM kategori");
                while ($r = $stmt->fetch()) $cache_kat[$r['n']] = $r['id'];
                
                $stmt_kat_ins = $pdo->prepare("INSERT INTO kategori (nama_kategori, kategori_electrical, kategori_equipment) VALUES (?, ?, ?)");
                
                // SMART MATCH untuk Import Aset
                $cache_aset = [];
                $stmt_cache = $pdo->query("SELECT id, nama_barang FROM aset");
                while ($r = $stmt_cache->fetch()) {
                    $norm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $r['nama_barang']));
                    $cache_aset[$norm] = $r['id'];
                }
                
                $stmt_ins = $pdo->prepare("
                    INSERT INTO aset 
                    (nama_barang, lokasi_detail, kategori_id, kategori_electrical, kategori_equipment, 
                     dimensi, watt, deskripsi_umum, guidance, standar_penempelan_no_tag, standar_qty_di_toko,
                     status, kondisi, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'AKTIF', 'BAIK', ?)
                ");

                $stmt_upd = $pdo->prepare("
                    UPDATE aset SET 
                        kategori_electrical = COALESCE(NULLIF(?, ''), kategori_electrical),
                        kategori_equipment = COALESCE(NULLIF(?, ''), kategori_equipment),
                        lokasi_detail = COALESCE(NULLIF(?, ''), lokasi_detail),
                        dimensi = COALESCE(NULLIF(?, ''), dimensi),
                        watt = COALESCE(NULLIF(?, ''), watt),
                        deskripsi_umum = COALESCE(NULLIF(?, ''), deskripsi_umum),
                        guidance = COALESCE(NULLIF(?, ''), guidance),
                        standar_penempelan_no_tag = COALESCE(NULLIF(?, ''), standar_penempelan_no_tag),
                        standar_qty_di_toko = COALESCE(NULLIF(?, ''), standar_qty_di_toko),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $sukses_insert = 0; $sukses_update = 0; $skip = 0; $failed = [];
                $new_kat = 0;
                $user_id = $_SESSION['user_id'];
                
                foreach (array_slice($lines, 1) as $line_num => $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    $d = explode($delim, $line);
                    $descp   = trim($d[$idx['descp']] ?? '');
                    $elec    = trim($d[$idx['elec']] ?? '');
                    $equip   = trim($d[$idx['equip']] ?? '');
                    $lokasi  = trim($d[$idx['lokasi']] ?? '');
                    $row_num = $line_num + 2;
                    
                    $dimensi     = ($idx['dimensi'] !== false)     ? trim($d[$idx['dimensi']] ?? '') : '';
                    $watt        = ($idx['watt'] !== false)        ? trim($d[$idx['watt']] ?? '') : '';
                    $deskripsi   = ($idx['deskripsi'] !== false)   ? trim($d[$idx['deskripsi']] ?? '') : '';
                    $guidance    = ($idx['guidance'] !== false)    ? trim($d[$idx['guidance']] ?? '') : '';
                    $standar_tag = ($idx['standar_tag'] !== false) ? trim($d[$idx['standar_tag']] ?? '') : '';
                    $standar_qty = ($idx['standar_qty'] !== false) ? trim($d[$idx['standar_qty']] ?? '') : '';
                    
                    if (empty($descp)) { $skip++; $failed[] = ['row' => $row_num, 'alasan' => 'DESCP kosong']; continue; }
                    
                    $norm_key = strtoupper(preg_replace('/[^A-Z0-9]/', '', $descp));
                    
                    // Cek apakah barang sudah ada
                    if (isset($cache_aset[$norm_key])) {
                        $existing_id = $cache_aset[$norm_key];
                        try {
                            $stmt_upd->execute([
                                $elec, $equip, $lokasi, $dimensi, $watt, 
                                $deskripsi, $guidance, $standar_tag, $standar_qty,
                                $existing_id
                            ]);
                            log_activity($pdo, $existing_id, null, 'UPDATE', null, ['nama_barang' => $descp, 'from' => 'import_csv']);
                            $sukses_update++;
                        } catch (Exception $e) {
                            $skip++;
                            $failed[] = ['row' => $row_num, 'alasan' => 'DB error update: ' . $e->getMessage()];
                        }
                    } else {
                        // Insert Baru
                        $kat_id = null;
                        $descp_upper = strtoupper($descp);
                        if (isset($cache_kat[$descp_upper])) {
                            $kat_id = $cache_kat[$descp_upper];
                        } else {
                            try { $stmt_kat_ins->execute([$descp, $elec ?: null, $equip ?: null]); $kat_id = $pdo->lastInsertId(); $cache_kat[$descp_upper] = $kat_id; $new_kat++; } catch (Exception $e) {}
                        }
                        
                        try {
                            $stmt_ins->execute([
                                $descp, $lokasi ?: null, $kat_id, $elec ?: null, $equip ?: null, 
                                $dimensi ?: null, $watt ?: null, $deskripsi ?: null, $guidance ?: null, $standar_tag ?: null, $standar_qty ?: null,
                                $user_id
                            ]);
                            $new_id = $pdo->lastInsertId();
                            $cache_aset[$norm_key] = $new_id;
                            log_activity($pdo, $new_id, null, 'CREATE', null, ['nama_barang' => $descp, 'lokasi_detail' => $lokasi, 'from' => 'import_csv']);
                            $sukses_insert++;
                        } catch (Exception $e) { 
                            $skip++; 
                            $failed[] = ['row' => $row_num, 'alasan' => 'DB error insert: ' . $e->getMessage()]; 
                        }
                    }
                }
                
                $result_import = [
                    'total' => $sukses_insert + $sukses_update + $skip,
                    'sukses_insert' => $sukses_insert,
                    'sukses_update' => $sukses_update,
                    'skip' => $skip,
                    'new_kat' => $new_kat,
                    'failed' => array_slice($failed, 0, 20),
                ];
            }
        }
    }
}

// =====================================================
// HANDLE: IMPORT NO PLU (SMART MATCH)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_plu') {
    $active_tab = 'plu';
    
    if (!empty($_FILES['file_csv_plu']['tmp_name'])) {
        $content = file_get_contents($_FILES['file_csv_plu']['tmp_name']);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        if (count($lines) < 2) {
            $error_plu = 'File kosong atau tidak ada data.';
        } else {
            $sample = implode('', array_slice($lines, 0, 5));
            $tab_c = substr_count($sample, "\t");
            $semi = substr_count($sample, ';');
            $comma = substr_count($sample, ',');
            $delim = "\t";
            if ($semi > $tab_c && $semi > $comma) $delim = ';';
            elseif ($comma > $tab_c && $comma > $semi) $delim = ',';
            
            $header = array_map('strtoupper', array_map('trim', explode($delim, $lines[0])));
            
            $find_col = function($names) use ($header) {
                foreach ($names as $n) {
                    $idx = array_search(strtoupper($n), $header);
                    if ($idx !== false) return $idx;
                }
                return false;
            };
            
            $idx = [
                'descp' => $find_col(['DESCP', 'DESCRIPTION', 'NAMA BARANG', 'NAMA']),
                'plu'   => $find_col(['NO PLU', 'NO_PLU', 'PLU', 'KODE']),
            ];
            
            if ($idx['descp'] === false || $idx['plu'] === false) {
                $error_plu = 'Kolom wajib: DESCP, NO PLU';
            } else {
                $grouped_data = [];
                foreach (array_slice($lines, 1) as $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    $d = explode($delim, $line);
                    $descp = trim($d[$idx['descp']] ?? '');
                    $plu   = trim($d[$idx['plu']] ?? '');
                    
                    if (empty($descp) || empty($plu)) continue;
                    
                    // Grouping pakai Smart Match juga agar CSV yang double spasi tetap masuk ke 1 grup
                    $norm_key = strtoupper(preg_replace('/[^A-Z0-9]/', '', $descp));
                    
                    if (!isset($grouped_data[$norm_key])) {
                        $grouped_data[$norm_key] = [
                            'nama_asli' => $descp,
                            'plus' => []
                        ];
                    }
                    if (!in_array($plu, $grouped_data[$norm_key]['plus'])) {
                        $grouped_data[$norm_key]['plus'][] = $plu;
                    }
                }
                
                if (empty($grouped_data)) {
                    $error_plu = 'Tidak ada data valid yang bisa diproses.';
                } else {
                    // SMART MATCH map untuk database
                    $aset_map = [];
                    $stmt_all = $pdo->query("SELECT id, nama_barang, no_plu FROM aset");
                    while ($row = $stmt_all->fetch()) {
                        $norm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $row['nama_barang']));
                        $aset_map[$norm] = $row;
                    }

                    $sukses_update = 0;
                    $sukses_insert = 0;
                    $failed = [];
                    
                    foreach ($grouped_data as $norm_key => $data) {
                        $descp = $data['nama_asli'];
                        $new_plus = $data['plus'];
                        
                        try {
                            if (isset($aset_map[$norm_key])) {
                                // Data Ditemukan -> Update
                                $existing = $aset_map[$norm_key];
                                
                                $old_plus = [];
                                if (!empty($existing['no_plu'])) {
                                    $old_plus = preg_split('/[\s,]+/', $existing['no_plu']);
                                    $old_plus = array_filter($old_plus);
                                }
                                
                                $merged = array_merge($old_plus, $new_plus);
                                $merged = array_unique($merged);
                                $merged = array_values($merged); 
                                
                                $final_plu_string = implode("\n", $merged); 
                                
                                $stmt_upd = $pdo->prepare("UPDATE aset SET no_plu = ?, updated_at = NOW() WHERE id = ?");
                                $stmt_upd->execute([$final_plu_string, $existing['id']]);
                                
                                log_activity($pdo, $existing['id'], null, 'UPDATE', null, ['nama_barang' => $descp, 'from' => 'import_plu']);
                                $sukses_update++;
                            } else {
                                // Data Tidak Ditemukan -> Insert Baru
                                $final_plu_string = implode("\n", $new_plus);
                                $stmt_ins = $pdo->prepare("INSERT INTO aset (nama_barang, no_plu, status, kondisi, created_by) VALUES (?, ?, 'AKTIF', 'BAIK', ?)");
                                $stmt_ins->execute([$descp, $final_plu_string, $_SESSION['user_id']]);
                                $new_id = $pdo->lastInsertId();
                                
                                log_activity($pdo, $new_id, null, 'CREATE', null, ['nama_barang' => $descp, 'from' => 'import_plu']);
                                $sukses_insert++;
                            }
                        } catch (Exception $e) {
                            $failed[] = ['descp' => $descp, 'alasan' => 'DB error: ' . $e->getMessage()];
                        }
                    }
                    
                    $result_plu = [
                        'total_baris' => count($grouped_data),
                        'sukses_update' => $sukses_update,
                        'sukses_insert' => $sukses_insert,
                        'failed' => $failed
                    ];
                }
            }
        }
    }
}

$stmt_special = $pdo->query("SELECT id, nama_barang, foto_thumb FROM aset WHERE nama_barang LIKE '%/%' OR nama_barang LIKE '%\"%' ORDER BY nama_barang ASC");
$special_items = $stmt_special->fetchAll();

$page_title = 'Upload Data Master';
$page_icon  = 'cloud-upload';
require_once __DIR__ . '/includes/layout_header.php';
?>

<style>
.tab-btn.active { color: #2563EB !important; border-bottom-color: #2563EB !important; }
.tab-content { display: none; }
.tab-content.active { display: block; }
.tab-header-wrapper { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; border-bottom: 1px solid #E2E8F0; }
.form-container { max-width: 640px; margin: 0 auto; }
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 14px; margin-bottom: 18px; }
@media (max-width: 600px) { .tab-btn { padding: 10px 14px !important; font-size: 12.5px !important; } }
</style>

<div class="tab-header-wrapper">
    <a href="?tab=upload" class="tab-btn" id="tabBtnUpload" style="padding: 12px 20px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-bottom: 3px solid transparent; color: #64748B; display: inline-flex; align-items: center; gap: 8px;">
        <i class="bi bi-cloud-upload"></i> Upload Foto Bulk
    </a>
    <a href="?tab=import" class="tab-btn" id="tabBtnImport" style="padding: 12px 20px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-bottom: 3px solid transparent; color: #64748B; display: inline-flex; align-items: center; gap: 8px;">
        <i class="bi bi-file-earmark-spreadsheet"></i> Import Data Aset
    </a>
    <a href="?tab=plu" class="tab-btn" id="tabBtnPlu" style="padding: 12px 20px; font-size: 13.5px; font-weight: 600; text-decoration: none; border-bottom: 3px solid transparent; color: #64748B; display: inline-flex; align-items: center; gap: 8px;">
        <i class="bi bi-upc-scan"></i> Import No PLU
    </a>
</div>

<!-- ==================== TAB: UPLOAD FOTO ==================== -->
<div id="tabUpload" class="tab-content">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Upload Foto Bulk</h2>
            <p style="font-size: 13px; color: #64748B; margin: 0;">Nama file jadi nama barang — barang baru akan dibuat, barang lama akan diupdate fotonya</p>
        </div>
    </div>
    
    <?php if ($error_upload): ?>
        <div style="padding: 14px 18px; background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; border-radius: 10px; margin-bottom: 20px;">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error_upload) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($result_upload): ?>
        <div class="card border-0 shadow-sm mb-3" style="background: #ECFDF5; border: 1px solid #A7F3D0 !important;">
            <div style="padding: 24px;">
                <h3 style="font-size: 16px; font-weight: 700; color: #065F46; margin: 0 0 16px;">
                    <i class="bi bi-check-circle-fill"></i> Upload Selesai
                </h3>
                <div class="stats-grid">
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">Total File</div>
                        <div style="font-size: 28px; font-weight: 700; color: #0F172A;"><?= number_format($result_upload['total']) ?></div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">✅ Baru</div>
                        <div style="font-size: 28px; font-weight: 700; color: #059669;"><?= number_format($result_upload['sukses_insert']) ?></div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">🔄 Update Foto</div>
                        <div style="font-size: 28px; font-weight: 700; color: #2563EB;"><?= number_format($result_upload['sukses_update']) ?></div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">⚠️ Skip</div>
                        <div style="font-size: 28px; font-weight: 700; color: #D97706;"><?= number_format($result_upload['skip']) ?></div>
                    </div>
                </div>
                <?php if (!empty($result_upload['failed'])): ?>
                    <div style="margin-top: 16px; padding: 14px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px;">
                        <div style="font-size: 12.5px; font-weight: 700; color: #92400E; margin-bottom: 8px;">Detail file yang di-skip:</div>
                        <table style="width: 100%; font-size: 11.5px; color: #78350F;">
                            <?php foreach ($result_upload['failed'] as $f): ?>
                                <tr>
                                    <td style="padding: 4px 0; font-family: monospace;"><?= e($f['file']) ?></td>
                                    <td style="padding: 4px 0; text-align: right;"><?= e($f['alasan']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
                <div style="margin-top: 18px;">
                    <a href="aset.php" style="padding: 10px 20px; background: #2563EB; color: white; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="bi bi-grid"></i> Lihat Katalog
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="form-container" style="margin-bottom: 30px;">
        <div class="card border-0 shadow-sm">
            <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9;">
                <h3 style="font-size: 14.5px; font-weight: 700; color: #0F172A; margin: 0;">
                    <i class="bi bi-cloud-upload" style="color: #2563EB;"></i> Upload File ZIP
                </h3>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_bulk">
                <div style="padding: 24px;">
                    <div style="background: #EFF6FF; border: 1px solid #BFDBFE; padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 12.5px; color: #1E40AF; line-height: 1.7;">
                        <strong><i class="bi bi-info-circle-fill"></i> Cara Pakai:</strong>
                        <ol style="margin: 6px 0 0 18px; padding: 0;">
                            <li>Kumpulkan semua foto dalam 1 folder</li>
                            <li>Rename foto = <strong>nama barang</strong> (harus persis sama dengan nama di sistem)</li>
                            <li>Kompres jadi <strong>ZIP</strong></li>
                            <li>Upload di sini</li>
                        </ol>
                        <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #93C5FD;">
                            ✅ Jika barang belum ada → <strong>Buat Aset Baru</strong><br>
                            🔄 Jika barang sudah ada → <strong>Update/Ganti Foto</strong><br>
                            ✅ Format: <strong>JPG/PNG/WebP</strong> (max 10 MB)
                        </div>
                    </div>
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 8px;">
                        <i class="bi bi-file-earmark-zip"></i> File ZIP
                    </label>
                    <input type="file" name="file_zip" accept=".zip" required style="width: 100%; padding: 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px;">
                    <button type="submit" style="width: 100%; margin-top: 18px; padding: 12px; background: #2563EB; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer;">
                        <i class="bi bi-cloud-upload-fill"></i> Upload & Proses
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (count($special_items) > 0): ?>
    <div style="max-width: 800px; margin: 0 auto;">
        <div class="card border-0 shadow-sm" style="border-left: 4px solid #F59E0B !important;">
            <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9; background: #FFFBEB;">
                <h3 style="font-size: 14.5px; font-weight: 700; color: #92400E; margin: 0;">
                    <i class="bi bi-exclamation-triangle-fill"></i> Perlu Input Foto Manual
                </h3>
                <p style="font-size: 12.5px; color: #B45309; margin: 4px 0 0;">Nama barang di bawah ini mengandung garis miring ( / ) atau tanda kutip ( " ) yang tidak didukung oleh nama file ZIP. Silakan edit barang untuk mengunggah fotonya.</p>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size: 13px;">
                    <thead style="background: #F8FAFC;">
                        <tr>
                            <th style="padding: 12px 24px;">Nama Barang</th>
                            <th style="padding: 12px 24px; text-align: center; width: 180px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($special_items as $sp): ?>
                        <tr>
                            <td style="padding: 12px 24px; font-weight: 600; color: #0F172A;">
                                <?= e($sp['nama_barang']) ?>
                                <?php if ($sp['foto_thumb']): ?>
                                    <span style="margin-left: 8px; font-size: 10px; background: #D1FAE5; color: #065F46; padding: 2px 8px; border-radius: 4px;">Sudah ada foto</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 24px; text-align: center;">
                                <a href="kategori.php?edit_id=<?= $sp['id'] ?>" style="padding: 6px 14px; background: #DBEAFE; color: #1E40AF; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                    <i class="bi bi-pencil-square"></i> Edit Manual
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ==================== TAB: IMPORT ASET ==================== -->
<div id="tabImport" class="tab-content">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Import Data Aset</h2>
            <p style="font-size: 13px; color: #64748B; margin: 0;">Import massal data aset dari CSV</p>
        </div>
    </div>
    
    <?php if ($error_import): ?>
        <div style="padding: 14px 18px; background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; border-radius: 10px; margin-bottom: 20px;">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error_import) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($result_import): ?>
        <div class="card border-0 shadow-sm mb-3" style="background: #ECFDF5; border: 1px solid #A7F3D0 !important;">
            <div style="padding: 24px;">
                <h3 style="font-size: 16px; font-weight: 700; color: #065F46; margin: 0 0 16px;">
                    <i class="bi bi-check-circle-fill"></i> Import Selesai
                </h3>
                <div class="stats-grid">
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">Total Baris</div>
                        <div style="font-size: 28px; font-weight: 700; color: #0F172A;"><?= number_format($result_import['total']) ?></div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">✅ Barang Baru</div>
                        <div style="font-size: 28px; font-weight: 700; color: #059669;"><?= number_format($result_import['sukses_insert']) ?></div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">🔄 Barang Update</div>
                        <div style="font-size: 28px; font-weight: 700; color: #2563EB;"><?= number_format($result_import['sukses_update']) ?></div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">⚠️ Skip</div>
                        <div style="font-size: 28px; font-weight: 700; color: #D97706;"><?= number_format($result_import['skip']) ?></div>
                    </div>
                </div>
                <div style="font-size: 12.5px; color: #065F46;">
                    📌 Kategori baru dibuat: <strong><?= $result_import['new_kat'] ?></strong>
                </div>
                <?php if (!empty($result_import['failed'])): ?>
                    <div style="margin-top: 16px; padding: 14px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px;">
                        <div style="font-size: 12.5px; font-weight: 700; color: #92400E; margin-bottom: 8px;">Detail baris yang di-skip:</div>
                        <table style="width: 100%; font-size: 11.5px; color: #78350F;">
                            <?php foreach ($result_import['failed'] as $f): ?>
                                <tr>
                                    <td style="padding: 4px 0;">Baris <?= $f['row'] ?></td>
                                    <td style="padding: 4px 0; text-align: right;"><?= e($f['alasan']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
                <div style="margin-top: 18px;">
                    <a href="aset.php" style="padding: 10px 20px; background: #2563EB; color: white; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="bi bi-grid"></i> Lihat Katalog
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="form-container">
        <div class="card border-0 shadow-sm">
            <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9;">
                <h3 style="font-size: 14.5px; font-weight: 700; color: #0F172A; margin: 0;">
                    <i class="bi bi-file-earmark-spreadsheet" style="color: #2563EB;"></i> Upload File CSV
                </h3>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_aset">
                <div style="padding: 24px;">
                    <div style="background: #EFF6FF; border: 1px solid #BFDBFE; padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 12.5px; color: #1E40AF; line-height: 1.8;">
                        <strong><i class="bi bi-info-circle-fill"></i> Format CSV (10 Kolom):</strong>
                        <div style="margin-top: 8px;">
                            • <code>DESCP</code> — Nama barang<br>
                            • <code>KATEGORI ELECTRICAL</code> — ELECTRICAL / NON ELECTRICAL<br>
                            • <code>KATEGORI EQUIPMENT</code> — HVAC / KITCHEN / dll<br>
                            • <code>AREA PENEMPATAN</code> — Contoh: Rak depan kasir<br>
                            • <code>DIMENSI</code> — Contoh: 50x30 cm<br>
                            • <code>WATT</code> — Contoh: 400 W<br>
                            • <code>DESKRIPSI</code> — Deskripsi umum barang<br>
                            • <code>GUIDANCE</code> — Panduan peletakan<br>
                            • <code>STANDAR PENEMPELAN NOMOR TAG</code> — Contoh: Tempel di belakang<br>
                            • <code>STANDAR QTY DI TOKO</code> — Contoh: 5 Unit
                        </div>
                        <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed #93C5FD;">
                            ✅ Kategori auto-create kalau belum ada<br>
                            ✅ Delimiter auto-detect (Tab / Semicolon / Comma)<br>
                            🔄 <strong>Jika nama barang sudah ada, data akan diperbarui (Update)</strong>
                        </div>
                    </div>
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 8px;">
                        <i class="bi bi-file-earmark-spreadsheet"></i> File CSV
                    </label>
                    <input type="file" name="file_csv" accept=".csv,.txt" required style="width: 100%; padding: 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px;">
                    <button type="submit" style="width: 100%; margin-top: 18px; padding: 12px; background: #2563EB; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer;">
                        <i class="bi bi-upload"></i> Import Sekarang
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== TAB: IMPORT NO PLU ==================== -->
<div id="tabPlu" class="tab-content">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Import No PLU</h2>
            <p style="font-size: 13px; color: #64748B; margin: 0;">Tambahkan banyak No PLU ke dalam satu nama barang sekaligus</p>
        </div>
    </div>
    
    <?php if ($error_plu): ?>
        <div style="padding: 14px 18px; background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; border-radius: 10px; margin-bottom: 20px;">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error_plu) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($result_plu): ?>
        <div class="card border-0 shadow-sm mb-3" style="background: #ECFDF5; border: 1px solid #A7F3D0 !important;">
            <div style="padding: 24px;">
                <h3 style="font-size: 16px; font-weight: 700; color: #065F46; margin: 0 0 16px;">
                    <i class="bi bi-check-circle-fill"></i> Import PLU Selesai
                </h3>
                <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">Total Barang Diproses</div>
                        <div style="font-size: 28px; font-weight: 700; color: #0F172A;"><?= number_format($result_plu['total_baris']) ?></div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">🔄 Barang Diupdate</div>
                        <div style="font-size: 28px; font-weight: 700; color: #2563EB;"><?= number_format($result_plu['sukses_update']) ?></div>
                    </div>
                    <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                        <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">✅ Barang Baru</div>
                        <div style="font-size: 28px; font-weight: 700; color: #059669;"><?= number_format($result_plu['sukses_insert']) ?></div>
                    </div>
                </div>
                <?php if (!empty($result_plu['failed'])): ?>
                    <div style="margin-top: 16px; padding: 14px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px;">
                        <div style="font-size: 12.5px; font-weight: 700; color: #92400E; margin-bottom: 8px;">Detail barang yang gagal:</div>
                        <table style="width: 100%; font-size: 11.5px; color: #78350F;">
                            <?php foreach ($result_plu['failed'] as $f): ?>
                                <tr>
                                    <td style="padding: 4px 0;"><?= e($f['descp']) ?></td>
                                    <td style="padding: 4px 0; text-align: right;"><?= e($f['alasan']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endif; ?>
                <div style="margin-top: 18px;">
                    <a href="aset.php" style="padding: 10px 20px; background: #2563EB; color: white; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                        <i class="bi bi-grid"></i> Lihat Katalog
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="form-container">
        <div class="card border-0 shadow-sm">
            <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9;">
                <h3 style="font-size: 14.5px; font-weight: 700; color: #0F172A; margin: 0;">
                    <i class="bi bi-upc-scan" style="color: #2563EB;"></i> Upload File CSV PLU
                </h3>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_plu">
                <div style="padding: 24px;">
                    <div style="background: #EFF6FF; border: 1px solid #BFDBFE; padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 12.5px; color: #1E40AF; line-height: 1.8;">
                        <strong><i class="bi bi-info-circle-fill"></i> Format CSV (2 Kolom Wajib):</strong>
                        <div style="margin-top: 8px;">
                            • <code>DESCP</code> — Nama barang<br>
                            • <code>NO PLU</code> — Nomor PLU
                        </div>
                        <div style="margin-top: 10px; padding-top: 8px; border-top: 1px dashed #93C5FD;">
                            ✅ Jika nama barang sama, PLU akan otomatis digabung (tidak menimpa PLU lama).<br>
                            ✅ Jika nama barang belum ada, akan dibuat barang baru.<br>
                            ✅ Delimiter auto-detect (Tab / Semicolon / Comma).
                        </div>
                    </div>
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 8px;">
                        <i class="bi bi-file-earmark-spreadsheet"></i> File CSV PLU
                    </label>
                    <input type="file" name="file_csv_plu" accept=".csv,.txt" required style="width: 100%; padding: 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px;">
                    <button type="submit" style="width: 100%; margin-top: 18px; padding: 12px; background: #2563EB; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer;">
                        <i class="bi bi-upload"></i> Import No PLU
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const activeTab = <?= json_encode($active_tab) ?>;
const btnUpload = document.getElementById('tabBtnUpload');
const btnImport = document.getElementById('tabBtnImport');
const btnPlu = document.getElementById('tabBtnPlu');
const contentUpload = document.getElementById('tabUpload');
const contentImport = document.getElementById('tabImport');
const contentPlu = document.getElementById('tabPlu');

function showTab(tab) {
    contentUpload.classList.remove('active');
    contentImport.classList.remove('active');
    contentPlu.classList.remove('active');
    
    btnUpload.classList.remove('active');
    btnImport.classList.remove('active');
    btnPlu.classList.remove('active');
    
    if (tab === 'import') {
        contentImport.classList.add('active');
        btnImport.classList.add('active');
    } else if (tab === 'plu') {
        contentPlu.classList.add('active');
        btnPlu.classList.add('active');
    } else {
        contentUpload.classList.add('active');
        btnUpload.classList.add('active');
    }
}

showTab(activeTab);
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>