<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
cek_login();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 1) {
    // Jika kosong, ambil 15 barang terbaru sebagai rekomendasi awal
    $stmt = $pdo->prepare("
        SELECT 
            MIN(id) AS id, 
            nama_barang,
            MAX(kategori_electrical) AS kategori_electrical,
            MAX(kategori_equipment) AS kategori_equipment,
            MAX(lokasi_detail) AS lokasi_detail
        FROM aset 
        GROUP BY nama_barang 
        ORDER BY MAX(id) DESC
        LIMIT 15
    ");
    $stmt->execute();
} else {
    // Jika ada isi, cari berdasarkan nama
    $stmt = $pdo->prepare("
        SELECT 
            MIN(id) AS id, 
            nama_barang,
            MAX(kategori_electrical) AS kategori_electrical,
            MAX(kategori_equipment) AS kategori_equipment,
            MAX(lokasi_detail) AS lokasi_detail
        FROM aset 
        WHERE nama_barang LIKE ? 
        GROUP BY nama_barang 
        ORDER BY nama_barang ASC
        LIMIT 15
    ");
    $stmt->execute(["%$q%"]);
}

$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($results);