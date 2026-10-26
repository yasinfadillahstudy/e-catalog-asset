<?php
// ============================================
// IMAGE HELPER
// ============================================

function process_image_upload($file, $prefix = '') {
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File tidak valid atau gagal upload.'];
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        return ['success' => false, 'error' => 'File terlalu besar (max ' . (MAX_UPLOAD_SIZE / 1024 / 1024) . ' MB).'];
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT)) {
        return ['success' => false, 'error' => 'Format file harus: ' . implode(', ', ALLOWED_EXT)];
    }

    $image_info = @getimagesize($file['tmp_name']);
    if ($image_info === false) {
        return ['success' => false, 'error' => 'File bukan gambar valid.'];
    }

    // Load gambar
    $src = null;
    switch ($image_info['mime']) {
        case 'image/jpeg':
        case 'image/jpg':
            $src = @imagecreatefromjpeg($file['tmp_name']);
            break;
        case 'image/png':
            $src = @imagecreatefrompng($file['tmp_name']);
            break;
        case 'image/webp':
            $src = @imagecreatefromwebp($file['tmp_name']);
            break;
        default:
            return ['success' => false, 'error' => 'Format gambar tidak didukung.'];
    }

    if (!$src) {
        return ['success' => false, 'error' => 'Gagal memproses gambar.'];
    }

    $orig_w = imagesx($src);
    $orig_h = imagesy($src);

    // Folder by tahun/bulan
    $sub_dir = date('Y/m');
    $base_dir = STORAGE_PATH . '/aset';
    $thumb_dir = $base_dir . '/thumbnails/' . $sub_dir;
    $med_dir   = $base_dir . '/medium/' . $sub_dir;

    if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);
    if (!is_dir($med_dir)) mkdir($med_dir, 0755, true);

    // Nama file unik berdasarkan timestamp + random
    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.webp';

    $thumb_path = $thumb_dir . '/' . $filename;
    $med_path   = $med_dir . '/' . $filename;

    // === THUMBNAIL 300x300 ===
    $thumb_size = 300;
    $t_scale = min($thumb_size / $orig_w, $thumb_size / $orig_h);
    $t_w = (int)($orig_w * $t_scale);
    $t_h = (int)($orig_h * $t_scale);

    $thumb = imagecreatetruecolor($thumb_size, $thumb_size);
    $bg = imagecolorallocate($thumb, 241, 245, 249);
    imagefill($thumb, 0, 0, $bg);
    $t_x = (int)(($thumb_size - $t_w) / 2);
    $t_y = (int)(($thumb_size - $t_h) / 2);
    imagecopyresampled($thumb, $src, $t_x, $t_y, 0, 0, $t_w, $t_h, $orig_w, $orig_h);

    // === MEDIUM max 1200 width ===
    $med_max = 1200;
    if ($orig_w > $med_max) {
        $m_scale = $med_max / $orig_w;
        $m_w = $med_max;
        $m_h = (int)($orig_h * $m_scale);
    } else {
        $m_w = $orig_w;
        $m_h = $orig_h;
    }
    $medium = imagecreatetruecolor($m_w, $m_h);
    imagecopyresampled($medium, $src, 0, 0, 0, 0, $m_w, $m_h, $orig_w, $orig_h);

    // === SAVE ===
    $thumb_ok = imagewebp($thumb, $thumb_path, 80);
    $med_ok   = imagewebp($medium, $med_path, 85);

    imagedestroy($src);
    imagedestroy($thumb);
    imagedestroy($medium);

    if (!$thumb_ok || !$med_ok) {
        return ['success' => false, 'error' => 'Gagal menyimpan gambar.'];
    }

    return [
        'success' => true,
        'thumb'   => 'aset/thumbnails/' . $sub_dir . '/' . $filename,
        'medium'  => 'aset/medium/' . $sub_dir . '/' . $filename,
    ];
}

function delete_image_file($relative_path) {
    if (empty($relative_path)) return false;
    $full_path = STORAGE_PATH . '/' . $relative_path;
    if (file_exists($full_path)) {
        return @unlink($full_path);
    }
    return false;
}

function foto_url($relative_path, $fallback = null) {
    if (empty($relative_path)) return $fallback;
    return APP_URL . '/public/uploads/' . $relative_path;
}