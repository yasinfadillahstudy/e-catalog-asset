<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/qr_helper.php';
cek_login();

$no_tag = trim($_GET['no_tag'] ?? '');
if (empty($no_tag)) {
    die('No Tag tidak diberikan.');
}

$stmt = $pdo->prepare("
    SELECT a.*, t.store_name, k.nama_kategori
    FROM aset a
    LEFT JOIN toko t ON a.kode_uu = t.kode_uu
    LEFT JOIN kategori k ON a.kategori_id = k.id
    WHERE a.no_tag = ?
");
$stmt->execute([$no_tag]);
$aset = $stmt->fetch();

if (!$aset) {
    die('Aset tidak ditemukan.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Print Label - <?= e($no_tag) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: #E2E8F0;
            padding: 30px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }
        
        .toolbar {
            background: white;
            padding: 14px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
        }
        .btn-primary { background: #2563EB; color: white; }
        .btn-primary:hover { background: #1D4ED8; }
        .btn-light { background: #F1F5F9; color: #475569; }
        .btn-light:hover { background: #E2E8F0; }
        
        /* ==================== LABEL STYLE ==================== */
        .label {
            width: 400px;
            background: white;
            border: 2px solid #0F172A;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 16px;
            align-items: center;
        }
        .label .qr-box {
            text-align: center;
        }
        .label .qr-box img {
            width: 140px;
            height: 140px;
            border-radius: 8px;
            background: white;
        }
        .label .info h1 {
            font-size: 13px;
            font-weight: 800;
            color: #0F172A;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            line-height: 1.2;
        }
        .label .info .no-tag {
            font-family: 'Courier New', monospace;
            font-size: 20px;
            font-weight: 700;
            color: #1E40AF;
            margin: 6px 0 10px;
            letter-spacing: 0.5px;
        }
        .label .info .row {
            font-size: 11px;
            color: #64748B;
            margin-bottom: 3px;
            line-height: 1.4;
        }
        .label .info .row strong {
            color: #0F172A;
            font-weight: 600;
        }
        .label .info .nama-barang {
            font-size: 12px;
            font-weight: 700;
            color: #0F172A;
            margin-bottom: 6px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.3;
        }
        
        /* ==================== PRINT ==================== */
        @media print {
            body {
                background: white;
                padding: 0;
                gap: 0;
            }
            .toolbar { display: none !important; }
            .label {
                box-shadow: none;
                border: 2px solid #000;
                page-break-inside: avoid;
            }
            @page {
                size: 90mm 60mm;
                margin: 5mm;
            }
        }
    </style>
</head>
<body>

<!-- TOOLBAR -->
<div class="toolbar">
    <button onclick="window.print()" class="btn btn-primary">
        <i>🖨️</i> Print / Save as PDF
    </button>
    <button onclick="window.close()" class="btn btn-light">Tutup</button>
    <span style="font-size: 12px; color: #64748B; margin-left: 10px;">
        💡 Tips: ubah layout ke <strong>Landscape</strong> saat print, atau pilih printer label thermal 90×60mm
    </span>
</div>

<!-- LABEL -->
<div class="label" id="label">
    <div class="qr-box">
        <img src="<?= e(qr_url($aset['no_tag'], 300)) ?>" alt="QR">
    </div>
    <div class="info">
        <h1>⚡ EACH · FIXED ASSET</h1>
        <div class="no-tag"><?= e($aset['no_tag']) ?></div>
        <div class="nama-barang"><?= e($aset['nama_barang']) ?></div>
        <div class="row"><strong>Toko:</strong> <?= e($aset['kode_uu']) ?> <?= e($aset['store_name'] ?? '') ?></div>
        <div class="row"><strong>Kategori:</strong> <?= e($aset['nama_kategori'] ?? '-') ?></div>
        <?php if ($aset['lokasi_detail']): ?>
            <div class="row"><strong>Lokasi:</strong> <?= e(substr($aset['lokasi_detail'], 0, 40)) ?></div>
        <?php endif; ?>
        <div class="row"><strong>Status:</strong> <?= e($aset['status']) ?> · <?= e($aset['kondisi']) ?></div>
    </div>
</div>

</body>
</html>