<?php
// ============================================
// QR CODE HELPER
// Generate QR code via API + cache lokal
// ============================================

/**
 * Dapatkan URL QR code untuk no_tag.
 * - Kalau belum ada, generate via API dan simpan ke cache
 * - Setelah di-cache, bisa dipakai offline
 */
function qr_url($no_tag, $size = 400) {
    if (empty($no_tag)) return '';
    
    $cache_dir = STORAGE_PATH . '/qrcode';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);
    
    $safe = preg_replace('/[^A-Za-z0-9\-_]/', '_', $no_tag);
    $filename = $safe . '.png';
    $cache_file = $cache_dir . '/' . $filename;
    
    // Kalau belum ada di cache, download
    if (!file_exists($cache_file)) {
        $api_url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($no_tag) . '&margin=10';
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'user_agent' => 'EACH-ECatalog/1.0']]);
        $img = @file_get_contents($api_url, false, $ctx);
        if ($img) {
            @file_put_contents($cache_file, $img);
        }
    }
    
    if (file_exists($cache_file)) {
        return APP_URL . '/public/uploads/qrcode/' . $filename;
    }
    
    // Fallback: langsung ke API (butuh internet)
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($no_tag) . '&margin=10';
}