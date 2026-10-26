<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/image_helper.php';
cek_login();

$id = (int)($_GET['id'] ?? 0);
$is_edit = $id > 0;
$aset = null;

if ($is_edit) {
    $stmt = $pdo->prepare("SELECT * FROM aset WHERE id = ?");
    $stmt->execute([$id]);
    $aset = $stmt->fetch();
    if (!$aset) {
        set_flash('error', 'Aset tidak ditemukan.');
        redirect('aset.php');
    }
}

// =====================================================
// HANDLE POST
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_barang   = trim($_POST['nama_barang'] ?? '');
    $lokasi_detail = trim($_POST['lokasi_detail'] ?? '');
    $kat_elec      = trim($_POST['kategori_electrical'] ?? '');
    $kat_eq        = trim($_POST['kategori_equipment'] ?? '');
    
    if (empty($nama_barang)) {
        set_flash('error', 'Nama Barang wajib diisi!');
        redirect($_SERVER['REQUEST_URI']);
    }
    
    try {
        $foto_thumb  = $aset['foto_thumb'] ?? null;
        $foto_medium = $aset['foto_medium'] ?? null;
        
        if (!empty($_FILES['foto']['tmp_name'])) {
            $result = process_image_upload($_FILES['foto']);
            if ($result['success']) {
                if ($foto_thumb) delete_image_file($foto_thumb);
                if ($foto_medium) delete_image_file($foto_medium);
                $foto_thumb = $result['thumb'];
                $foto_medium = $result['medium'];
            } else {
                set_flash('error', 'Upload foto gagal: ' . $result['error']);
                redirect($_SERVER['REQUEST_URI']);
            }
        }
        
        if ($is_edit) {
            $stmt = $pdo->prepare("
                UPDATE aset SET 
                    nama_barang=?, lokasi_detail=?, 
                    kategori_electrical=?, kategori_equipment=?,
                    foto_thumb=?, foto_medium=?, updated_at=NOW()
                WHERE id=?
            ");
            $stmt->execute([
                $nama_barang, $lokasi_detail ?: null,
                $kat_elec ?: null, $kat_eq ?: null,
                $foto_thumb, $foto_medium,
                $id
            ]);
            log_activity($pdo, $id, null, 'UPDATE', $aset, [
                'nama_barang' => $nama_barang,
                'lokasi_detail' => $lokasi_detail,
                'kategori_electrical' => $kat_elec,
                'kategori_equipment' => $kat_eq,
            ]);
            set_flash('success', 'Berhasil diupdate!');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO aset 
                (nama_barang, lokasi_detail, kategori_electrical, kategori_equipment,
                 status, kondisi, foto_thumb, foto_medium, created_by)
                VALUES (?, ?, ?, ?, 'AKTIF', 'BAIK', ?, ?, ?)
            ");
            $stmt->execute([
                $nama_barang, $lokasi_detail ?: null,
                $kat_elec ?: null, $kat_eq ?: null,
                $foto_thumb, $foto_medium,
                $_SESSION['user_id']
            ]);
            $new_id = $pdo->lastInsertId();
            log_activity($pdo, $new_id, null, 'CREATE', null, [
                'nama_barang' => $nama_barang,
                'lokasi_detail' => $lokasi_detail,
                'kategori_electrical' => $kat_elec,
                'kategori_equipment' => $kat_eq,
            ]);
            set_flash('success', 'Berhasil ditambahkan!');
        }
        
        redirect('aset.php');
        
    } catch (Exception $e) {
        set_flash('error', 'Gagal simpan: ' . $e->getMessage());
        redirect($_SERVER['REQUEST_URI']);
    }
}

// =====================================================
// DATA UNTUK DROPDOWN
// =====================================================
$elec_list = $pdo->query("
    SELECT DISTINCT kategori_electrical FROM kategori 
    WHERE kategori_electrical IS NOT NULL AND kategori_electrical != ''
    ORDER BY kategori_electrical
")->fetchAll(PDO::FETCH_COLUMN);

if (empty($elec_list)) {
    $elec_list = ['ELECTRICAL', 'NON ELECTRICAL'];
}

$eq_list = $pdo->query("
    SELECT DISTINCT kategori_equipment FROM kategori 
    WHERE kategori_equipment IS NOT NULL AND kategori_equipment != ''
    ORDER BY kategori_equipment
")->fetchAll(PDO::FETCH_COLUMN);

$page_title = $is_edit ? 'Edit Aset' : 'Tambah Aset';
$page_icon  = $is_edit ? 'pencil' : 'plus-circle';
require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">
            <?= $is_edit ? 'Edit Aset' : 'Tambah Aset Baru' ?>
        </h2>
        <p style="font-size: 13px; color: #64748B; margin: 0;">
            <?= $is_edit ? 'Edit data aset' : 'Isi data aset lengkap dengan foto' ?>
        </p>
    </div>
    <a href="aset.php" style="padding: 9px 16px; background: #F1F5F9; color: #475569; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
        <i class="bi bi-arrow-left"></i> Kembali
    </a>
</div>

<form method="POST" enctype="multipart/form-data">
    <div class="form-layout" style="display: grid; grid-template-columns: 1fr 380px; gap: 20px; align-items: start;">
        
        <div class="card border-0 shadow-sm">
            <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9;">
                <h3 style="font-size: 14.5px; font-weight: 700; color: #0F172A; margin: 0;">
                    <i class="bi bi-info-circle" style="color: #2563EB;"></i> Data Aset
                </h3>
            </div>
            
            <div style="padding: 24px;">
                
                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                        Nama Barang <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="nama_barang" required
                           value="<?= e($aset['nama_barang'] ?? '') ?>"
                           style="width: 100%; padding: 12px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;"
                           placeholder="Contoh: AC Split 1PK"
                           autofocus>
                </div>
                
                <div style="margin-bottom: 18px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                        Lokasi Detail
                    </label>
                    <input type="text" name="lokasi_detail"
                           value="<?= e($aset['lokasi_detail'] ?? '') ?>"
                           style="width: 100%; padding: 12px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;"
                           placeholder="Contoh: Rak depan kasir">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Kategori Electrical</label>
                        <select name="kategori_electrical"
                                style="width: 100%; padding: 12px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;">
                            <option value="">-- Pilih --</option>
                            <?php foreach ($elec_list as $e): ?>
                                <option value="<?= e($e) ?>" <?= ($aset['kategori_electrical'] ?? '') === $e ? 'selected' : '' ?>><?= e($e) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Kategori Equipment</label>
                        <select name="kategori_equipment"
                                style="width: 100%; padding: 12px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;">
                            <option value="">-- Pilih --</option>
                            <?php foreach ($eq_list as $eq): ?>
                                <option value="<?= e($eq) ?>" <?= ($aset['kategori_equipment'] ?? '') === $eq ? 'selected' : '' ?>><?= e($eq) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card border-0 shadow-sm">
            <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9;">
                <h3 style="font-size: 14.5px; font-weight: 700; color: #0F172A; margin: 0;">
                    <i class="bi bi-camera" style="color: #2563EB;"></i> Foto Aset
                </h3>
            </div>
            
            <div style="padding: 24px;">
                <div id="previewContainer" style="margin-bottom: 16px;">
                    <?php if (!empty($aset['foto_medium'])): ?>
                        <img id="previewImg" src="<?= e(foto_url($aset['foto_medium'])) ?>" 
                             style="width: 100%; border-radius: 10px; border: 1px solid #E2E8F0;">
                    <?php else: ?>
                        <div id="previewPlaceholder" style="width: 100%; aspect-ratio: 1; background: #F8FAFC; border: 2px dashed #E2E8F0; border-radius: 10px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #94A3B8;">
                            <i class="bi bi-camera" style="font-size: 48px; margin-bottom: 8px;"></i>
                            <div style="font-size: 12.5px;">Belum ada foto</div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 8px;">
                    <i class="bi bi-image"></i> Pilih Foto
                </label>
                <input type="file" name="foto" id="fotoInput" accept="image/jpeg,image/jpg,image/png,image/webp" capture="environment"
                       style="width: 100%; padding: 10px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 12.5px;">
                
                <div style="background: #EFF6FF; border: 1px solid #BFDBFE; padding: 12px 14px; border-radius: 8px; margin-top: 14px; font-size: 11.5px; color: #1E40AF; line-height: 1.6;">
                    <i class="bi bi-info-circle-fill"></i> <strong>Format yang didukung:</strong>
                    <ul style="margin: 6px 0 0 18px; padding: 0;">
                        <li><strong>JPG/JPEG</strong> — rekomendasi (dari HP/kamera)</li>
                        <li><strong>PNG</strong> — kalau ada logo</li>
                        <li><strong>WebP</strong> — kalau dari web</li>
                        <li>Max 10 MB, auto-kompres</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
        <a href="aset.php" style="padding: 12px 24px; border: 1px solid #E2E8F0; background: white; border-radius: 8px; font-weight: 600; color: #475569; font-size: 13.5px; text-decoration: none; display: inline-flex; align-items: center;">
            Batal
        </a>
        <button type="submit" style="padding: 12px 28px; background: #2563EB; color: white; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 13.5px; display: inline-flex; align-items: center; gap: 8px;">
            <i class="bi bi-save"></i> <?= $is_edit ? 'Update Aset' : 'Simpan Aset' ?>
        </button>
    </div>
</form>

<script>
document.getElementById('fotoInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    
    const reader = new FileReader();
    reader.onload = function(ev) {
        const container = document.getElementById('previewContainer');
        let img = document.getElementById('previewImg');
        
        if (img) {
            img.src = ev.target.result;
        } else {
            container.innerHTML = '<img id="previewImg" src="' + ev.target.result + '" style="width: 100%; border-radius: 10px; border: 1px solid #E2E8F0;">';
        }
    };
    reader.readAsDataURL(file);
});
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>