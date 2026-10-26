<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/image_helper.php';
cek_login();

// =====================================================
// STATISTIK UTAMA
// =====================================================

// 1. Total Aset
$total_aset = (int)$pdo->query("SELECT COUNT(*) FROM aset")->fetchColumn();

// 2. Electrical
$total_elec = (int)$pdo->query("
    SELECT COUNT(*) FROM aset 
    WHERE UPPER(TRIM(kategori_electrical)) = 'ELECTRICAL'
")->fetchColumn();

// 3. Non Electrical
$total_non_elec = (int)$pdo->query("
    SELECT COUNT(*) FROM aset 
    WHERE UPPER(TRIM(kategori_electrical)) = 'NON ELECTRICAL'
")->fetchColumn();

// 4. Total Kategori Equipment (DISTINCT)
$total_equip = (int)$pdo->query("
    SELECT COUNT(DISTINCT TRIM(kategori_equipment)) FROM aset 
    WHERE kategori_equipment IS NOT NULL AND TRIM(kategori_equipment) != ''
")->fetchColumn();

$page_title = 'Dashboard';
$page_icon  = 'speedometer2';
require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
    <div>
        <h2 style="font-size: 22px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Dashboard</h2>
        <p style="font-size: 13px; color: #64748B; margin: 0;">Ringkasan aset E-Catalog</p>
    </div>
</div>

<!-- ==================== STAT CARDS ==================== -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">

    <!-- TOTAL ASET -->
    <div class="card border-0 shadow-sm" style="padding: 20px 22px; background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 11px; color: #1E40AF; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 6px;">Total Aset</div>
                <div style="font-size: 32px; font-weight: 800; color: #1E3A8A; line-height: 1;"><?= number_format($total_aset) ?></div>
                <div style="font-size: 12px; color: #3B82F6; margin-top: 6px;">Semua aset terdaftar</div>
            </div>
            <div style="width: 48px; height: 48px; background: white; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #2563EB; font-size: 22px;">
                <i class="bi bi-grid-3x3-gap-fill"></i>
            </div>
        </div>
    </div>

    <!-- ELECTRICAL -->
    <div class="card border-0 shadow-sm" style="padding: 20px 22px; background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 11px; color: #92400E; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 6px;">⚡ Electrical</div>
                <div style="font-size: 32px; font-weight: 800; color: #78350F; line-height: 1;"><?= number_format($total_elec) ?></div>
                <div style="font-size: 12px; color: #B45309; margin-top: 6px;">Aset kategori electrical</div>
            </div>
            <div style="width: 48px; height: 48px; background: white; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #D97706; font-size: 22px;">
                <i class="bi bi-lightning-charge-fill"></i>
            </div>
        </div>
    </div>

    <!-- NON ELECTRICAL -->
    <div class="card border-0 shadow-sm" style="padding: 20px 22px; background: linear-gradient(135deg, #F8FAFC 0%, #E2E8F0 100%);">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 11px; color: #475569; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 6px;">Non-Electrical</div>
                <div style="font-size: 32px; font-weight: 800; color: #334155; line-height: 1;"><?= number_format($total_non_elec) ?></div>
                <div style="font-size: 12px; color: #64748B; margin-top: 6px;">Aset non-electrical</div>
            </div>
            <div style="width: 48px; height: 48px; background: white; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #64748B; font-size: 22px;">
                <i class="bi bi-box"></i>
            </div>
        </div>
    </div>

    <!-- KATEGORI EQUIPMENT -->
    <div class="card border-0 shadow-sm" style="padding: 20px 22px; background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);">
        <div style="display: flex; justify-content: space-between; align-items: start;">
            <div>
                <div style="font-size: 11px; color: #5B21B6; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 6px;">🔧 Kategori Equipment</div>
                <div style="font-size: 32px; font-weight: 800; color: #4C1D95; line-height: 1;"><?= number_format($total_equip) ?></div>
                <div style="font-size: 12px; color: #7C3AED; margin-top: 6px;">Jenis peralatan</div>
            </div>
            <div style="width: 48px; height: 48px; background: white; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #7C3AED; font-size: 22px;">
                <i class="bi bi-tools"></i>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>