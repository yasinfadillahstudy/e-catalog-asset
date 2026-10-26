<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/image_helper.php';
cek_login();

$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file_zip']['tmp_name'])) {
    $tmp = $_FILES['file_zip']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['file_zip']['name'], PATHINFO_EXTENSION));
    
    if ($ext !== 'zip') {
        $error = 'File harus berupa ZIP.';
    } elseif (!class_exists('ZipArchive')) {
        $error = 'Extension PHP ZipArchive belum aktif. Aktifkan "extension=zip" di php.ini.';
    } else {
        $batch_code = 'BATCH_' . date('Ymd_His');
        $temp_dir = STORAGE_PATH . '/temp/' . $batch_code;
        
        if (!is_dir($temp_dir)) mkdir($temp_dir, 0755, true);
        
        // Extract ZIP
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            $error = 'Gagal membuka file ZIP.';
        } else {
            $zip->extractTo($temp_dir);
            $zip->close();
            
            // Insert batch log
            $stmt = $pdo->prepare("
                INSERT INTO upload_batch (batch_code, filename_zip, status, user_id, created_at)
                VALUES (?, ?, 'PROCESSING', ?, NOW())
            ");
            $stmt->execute([$batch_code, $_FILES['file_zip']['name'], $_SESSION['user_id']]);
            $batch_id = $pdo->lastInsertId();
            
            // Scan semua file di folder (termasuk subfolder)
            $all_files = [];
            $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp_dir));
            foreach ($rii as $file) {
                if ($file->isDir()) continue;
                $all_files[] = $file->getPathname();
            }
            
            $sukses = 0;
            $skip = 0;
            $failed = [];
            
            // Prepare update query
            $stmt_check = $pdo->prepare("SELECT id, foto_thumb, foto_medium FROM aset WHERE no_tag = ?");
            $stmt_update = $pdo->prepare("
                UPDATE aset SET foto_thumb = ?, foto_medium = ?, updated_at = NOW()
                WHERE no_tag = ?
            ");
            
            foreach ($all_files as $file_path) {
                $filename = pathinfo($file_path, PATHINFO_FILENAME);
                $ext_file = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                
                // Skip file non-gambar
                if (!in_array($ext_file, ALLOWED_EXT)) { $skip++; continue; }
                
                // Ambil no_tag dari nama file (tanpa extension)
                $no_tag = $filename;
                
                // Cek apakah no_tag ada di database
                $stmt_check->execute([$no_tag]);
                $existing = $stmt_check->fetch();
                
                if (!$existing) {
                    $skip++;
                    $failed[] = ['file' => basename($file_path), 'alasan' => 'No tag tidak ditemukan di database'];
                    continue;
                }
                
                // Proses image
                $file_data = [
                    'tmp_name' => $file_path,
                    'name' => basename($file_path),
                    'size' => filesize($file_path),
                    'error' => UPLOAD_ERR_OK,
                ];
                
                $proc = process_image_upload($file_data, $no_tag);
                
                if ($proc['success']) {
                    // Hapus foto lama
                    if ($existing['foto_thumb']) delete_image_file($existing['foto_thumb']);
                    if ($existing['foto_medium']) delete_image_file($existing['foto_medium']);
                    
                    // Update database
                    $stmt_update->execute([$proc['thumb'], $proc['medium'], $no_tag]);
                    
                    // Log
                    log_activity($pdo, $existing['id'], $no_tag, 'UPLOAD_FOTO', null, ['batch' => $batch_code]);
                    
                    $sukses++;
                } else {
                    $skip++;
                    $failed[] = ['file' => basename($file_path), 'alasan' => $proc['error']];
                }
            }
            
            // Update batch status
            $stmt = $pdo->prepare("
                UPDATE upload_batch 
                SET total_files = ?, success_files = ?, failed_files = ?, 
                    status = 'DONE', finished_at = NOW(),
                    log = ?
                WHERE id = ?
            ");
            $log_text = "Sukses: {$sukses}, Skip: {$skip}\n" . json_encode(array_slice($failed, 0, 50), JSON_PRETTY_PRINT);
            $stmt->execute([count($all_files), $sukses, $skip, $log_text, $batch_id]);
            
            // Hapus folder temp
            delete_dir($temp_dir);
            
            $result = [
                'batch_code' => $batch_code,
                'total' => count($all_files),
                'sukses' => $sukses,
                'skip' => $skip,
                'failed' => array_slice($failed, 0, 20),
            ];
        }
    }
}

// Helper: hapus folder recursive
function delete_dir($dir) {
    if (!is_dir($dir)) return;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isDir()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($dir);
}

// Ambil riwayat batch
$batches = $pdo->query("
    SELECT b.*, u.display_name 
    FROM upload_batch b 
    LEFT JOIN users u ON b.user_id = u.id 
    ORDER BY b.created_at DESC 
    LIMIT 10
")->fetchAll();

$page_title = 'Upload Bulk Foto';
$page_icon  = 'cloud-upload';
require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Upload Bulk Foto</h2>
        <p style="font-size: 13px; color: #64748B; margin: 0;">
            Upload ZIP berisi banyak foto sekaligus — nama file otomatis jadi <strong>no tag</strong>
        </p>
    </div>
</div>

<?php if ($error): ?>
    <div style="padding: 14px 18px; background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; border-radius: 10px; margin-bottom: 20px;">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if ($result): ?>
    <!-- HASIL -->
    <div class="card border-0 shadow-sm mb-3" style="background: #ECFDF5; border: 1px solid #A7F3D0 !important;">
        <div style="padding: 24px;">
            <h3 style="font-size: 16px; font-weight: 700; color: #065F46; margin: 0 0 16px;">
                <i class="bi bi-check-circle-fill"></i> Upload Selesai
            </h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 18px;">
                <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                    <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700; margin-bottom: 6px;">Total File</div>
                    <div style="font-size: 28px; font-weight: 700; color: #0F172A;"><?= number_format($result['total']) ?></div>
                </div>
                <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                    <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700; margin-bottom: 6px;">✅ Sukses</div>
                    <div style="font-size: 28px; font-weight: 700; color: #059669;"><?= number_format($result['sukses']) ?></div>
                </div>
                <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                    <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700; margin-bottom: 6px;">⚠️ Skip</div>
                    <div style="font-size: 28px; font-weight: 700; color: #D97706;"><?= number_format($result['skip']) ?></div>
                </div>
            </div>
            
            <div style="font-size: 12px; color: #065F46;">
                <strong>Batch Code:</strong> <span style="font-family: monospace;"><?= e($result['batch_code']) ?></span>
            </div>
            
            <?php if (!empty($result['failed'])): ?>
                <div style="margin-top: 16px; padding: 14px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px;">
                    <div style="font-size: 12.5px; font-weight: 700; color: #92400E; margin-bottom: 8px;">
                        File yang di-skip (max 20 pertama):
                    </div>
                    <table style="width: 100%; font-size: 11.5px; color: #78350F;">
                        <?php foreach ($result['failed'] as $f): ?>
                            <tr>
                                <td style="padding: 4px 0; font-family: monospace;"><?= e($f['file']) ?></td>
                                <td style="padding: 4px 0; text-align: right;"><?= e($f['alasan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 18px;">
                <a href="upload_bulk.php" style="padding: 10px 20px; background: #2563EB; color: white; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="bi bi-arrow-repeat"></i> Upload Lagi
                </a>
                <a href="aset.php" style="padding: 10px 20px; background: #F1F5F9; color: #475569; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; margin-left: 8px;">
                    <i class="bi bi-grid"></i> Lihat Katalog
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start;">
    
    <!-- FORM UPLOAD -->
    <div class="card border-0 shadow-sm">
        <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9;">
            <h3 style="font-size: 14.5px; font-weight: 700; color: #0F172A; margin: 0;">
                <i class="bi bi-cloud-upload" style="color: #2563EB;"></i> Upload File ZIP
            </h3>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <div style="padding: 24px;">
                <div style="background: #EFF6FF; border: 1px solid #BFDBFE; padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 12.5px; color: #1E40AF; line-height: 1.7;">
                    <strong><i class="bi bi-info-circle-fill"></i> Cara Pakai:</strong>
                    <ol style="margin: 6px 0 0 18px; padding: 0;">
                        <li>Kumpulkan semua foto dalam 1 folder</li>
                        <li>Rename setiap foto = <strong>no tag aset</strong> (contoh: <code>TAG-001.jpg</code>)</li>
                        <li>Kompres folder jadi <strong>ZIP</strong></li>
                        <li>Upload di sini</li>
                    </ol>
                    <div style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #93C5FD;">
                        ✅ Format: JPG, PNG, WebP<br>
                        ✅ Nama file = no tag (tanpa ekstensi)<br>
                        ✅ Foto lama akan otomatis ter-replace
                    </div>
                </div>
                
                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 8px;">
                    <i class="bi bi-file-earmark-zip"></i> File ZIP
                </label>
                <input type="file" name="file_zip" accept=".zip" required
                       style="width: 100%; padding: 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px;">
                
                <button type="submit" style="width: 100%; margin-top: 18px; padding: 12px; background: #2563EB; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer;">
                    <i class="bi bi-cloud-upload-fill"></i> Upload & Proses
                </button>
                
                <div style="margin-top: 12px; padding: 10px 14px; background: #FEF3C7; border: 1px solid #FDE68A; border-radius: 8px; font-size: 11.5px; color: #92400E;">
                    ⚠️ <strong>Note:</strong> Proses bulk upload butuh waktu — jangan tutup browser sampai selesai.
                </div>
            </div>
        </form>
    </div>
    
    <!-- RIWAYAT -->
    <div class="card border-0 shadow-sm">
        <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9;">
            <h3 style="font-size: 14.5px; font-weight: 700; color: #0F172A; margin: 0;">
                <i class="bi bi-clock-history" style="color: #2563EB;"></i> Riwayat Upload (10 terakhir)
            </h3>
        </div>
        
        <?php if (empty($batches)): ?>
            <div style="text-align: center; padding: 40px 20px; color: #94A3B8;">
                <i class="bi bi-inbox" style="font-size: 36px; display: block; margin-bottom: 8px;"></i>
                <div style="font-size: 13px;">Belum ada riwayat upload</div>
            </div>
        <?php else: ?>
            <div style="padding: 16px 24px;">
                <?php foreach ($batches as $b): 
                    $status_badge = match($b['status']) {
                        'DONE'       => 'background:#D1FAE5; color:#065F46;',
                        'PROCESSING' => 'background:#FEF3C7; color:#92400E;',
                        'FAILED'     => 'background:#FEE2E2; color:#991B1B;',
                        default      => 'background:#F1F5F9; color:#64748B;',
                    };
                ?>
                <div style="padding: 12px 0; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                    <div style="min-width: 0; flex: 1;">
                        <div style="font-family: monospace; font-size: 11.5px; color: #1E40AF; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?= e($b['filename_zip'] ?? $b['batch_code']) ?>
                        </div>
                        <div style="font-size: 11px; color: #94A3B8; margin-top: 3px;">
                            <?= tanggal_indo($b['created_at'], true) ?> · <?= e($b['display_name'] ?? 'System') ?>
                        </div>
                    </div>
                    <div style="text-align: right; flex-shrink: 0;">
                        <span style="padding: 3px 10px; border-radius: 12px; font-size: 10.5px; font-weight: 700; <?= $status_badge ?>">
                            <?= e($b['status']) ?>
                        </span>
                        <div style="font-size: 10.5px; color: #64748B; margin-top: 4px;">
                            ✅ <?= $b['success_files'] ?> / ⚠️ <?= $b['failed_files'] ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>