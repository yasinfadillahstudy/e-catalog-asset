<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/image_helper.php';
cek_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('error', 'ID tidak valid.'); redirect('aset.php'); }

$stmt = $pdo->prepare("SELECT * FROM aset WHERE id = ?");
$stmt->execute([$id]);
$aset = $stmt->fetch();

if (!$aset) { set_flash('error', 'Aset tidak ditemukan.'); redirect('aset.php'); }

$plu_list = [];
if (!empty($aset['no_plu'])) {
    $plu_list = preg_split('/[\s,]+/', $aset['no_plu']);
    $plu_list = array_filter(array_map('trim', $plu_list)); 
}

// ✅ LOGIKA TOMBOL KEMBALI PINTAR
$back_url = 'aset.php';
$back_label = 'Kembali ke Katalog';
if (isset($_GET['from']) && $_GET['from'] === 'kategori') {
    $back_url = 'kategori.php';
    $back_label = 'Kembali ke Master Barang';
}

$page_title = 'Detail Aset';
$page_icon  = 'file-earmark-text';
require_once __DIR__ . '/includes/layout_header.php';
?>

<style>
    .detail-container { display: grid; grid-template-columns: 400px 1fr; gap: 30px; padding: 30px; align-items: start; }
    .detail-image { width: 100%; aspect-ratio: 1; border-radius: 12px; overflow: hidden; position: relative; background: #F8FAFC; border: 1px solid #E2E8F0; }
    .detail-image img { width: 100%; height: 100%; object-fit: contain; }
    
    .spec-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
    .spec-item { background: #F8FAFC; padding: 12px 16px; border-radius: 8px; border: 1px solid #E2E8F0; }
    .spec-label { font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700; margin-bottom: 4px; display: block; }
    .spec-value { font-size: 14px; color: #0F172A; font-weight: 600; }

    .long-text { margin-bottom: 20px; }
    .long-text-label { font-size: 12px; color: #475569; text-transform: uppercase; font-weight: 700; margin-bottom: 6px; display: block; }
    .long-text-value { font-size: 14px; color: #334155; line-height: 1.6; background: #F8FAFC; padding: 12px; border-radius: 8px; border: 1px solid #E2E8F0; }
    
    .plu-list-horizontal { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
    .plu-item { font-family: monospace; font-size: 13px; background: #E2E8F0; color: #0F172A; padding: 4px 10px; border-radius: 6px; border: 1px solid #CBD5E1; white-space: nowrap; }

    @media (max-width: 900px) {
        .detail-container { grid-template-columns: 1fr; padding: 20px; }
        .detail-image { max-width: 400px; margin: 0 auto; }
    }
    @media (max-width: 500px) { .spec-grid { grid-template-columns: 1fr; } }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-3">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0;">Detail Aset</h2>
    </div>
    <!-- ✅ GUNAKAN VARIABEL DINAMIS -->
    <a href="<?= $back_url ?>" style="padding: 9px 16px; background: #F1F5F9; color: #475569; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
        <i class="bi bi-arrow-left"></i> <?= $back_label ?>
    </a>
</div>

<div class="card border-0 shadow-sm" style="overflow: hidden; border-radius: 12px;">
    <div class="detail-container">
        
        <div class="detail-image">
            <?php if ($aset['foto_medium']): ?>
                <img src="<?= e(foto_url($aset['foto_medium'])) ?>" alt="<?= e($aset['nama_barang']) ?>">
            <?php else: ?>
                <div style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #CBD5E1;">
                    <i class="bi bi-image" style="font-size: 60px; margin-bottom: 12px;"></i>
                    <div style="font-size: 14px;">Belum ada foto</div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="detail-info">
            <h1 style="font-size: 26px; font-weight: 700; color: #0F172A; margin: 0 0 16px; line-height: 1.3;">
                <?= e($aset['nama_barang']) ?>
            </h1>

            <div style="margin-bottom: 24px; display: flex; gap: 10px; flex-wrap: wrap;">
                <?php if (!empty($aset['kategori_electrical'])): ?>
                    <?php if ($aset['kategori_electrical'] === 'ELECTRICAL'): ?>
                        <span style="background: #FEF3C7; color: #92400E; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700;">⚡ ELECTRICAL</span>
                    <?php else: ?>
                        <span style="background: #F1F5F9; color: #64748B; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700;"><?= e($aset['kategori_electrical']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($aset['kategori_equipment'])): ?>
                    <span style="background: #E0E7FF; color: #3730A3; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 700;">🔧 <?= e($aset['kategori_equipment']) ?></span>
                <?php endif; ?>
            </div>

            <div class="spec-grid">
                <div class="spec-item" style="grid-column: 1 / -1; background: white; border: 1px solid #BFDBFE;">
                    <span class="spec-label" style="color: #1E40AF;"><i class="bi bi-upc-scan"></i> No PLU</span>
                    <?php if (empty($plu_list)): ?>
                        <span class="spec-value">-</span>
                    <?php else: ?>
                        <div class="plu-list-horizontal">
                            <?php foreach ($plu_list as $plu): ?>
                                <span class="plu-item"><?= e($plu) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="spec-item">
                    <span class="spec-label">Dimensi</span>
                    <span class="spec-value"><?= e($aset['dimensi'] ?: '-') ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label">Watt</span>
                    <span class="spec-value"><?= e($aset['watt'] ?: '-') ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label">Standar Qty di Toko</span>
                    <span class="spec-value"><?= e($aset['standar_qty_di_toko'] ?: '-') ?></span>
                </div>
                <div class="spec-item">
                    <span class="spec-label">Area Penempatan</span>
                    <span class="spec-value"><?= e($aset['lokasi_detail'] ?: '-') ?></span>
                </div>
            </div>

            <?php if (!empty($aset['deskripsi_umum'])): ?>
            <div class="long-text">
                <span class="long-text-label">Deskripsi Umum</span>
                <div class="long-text-value"><?= nl2br(e($aset['deskripsi_umum'])) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($aset['guidance'])): ?>
            <div class="long-text">
                <span class="long-text-label">Guidance</span>
                <div class="long-text-value"><?= nl2br(e($aset['guidance'])) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($aset['standar_penempelan_no_tag'])): ?>
            <div class="long-text">
                <span class="long-text-label">Standar Penempelan No Tag</span>
                <div class="long-text-value"><?= nl2br(e($aset['standar_penempelan_no_tag'])) ?></div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>