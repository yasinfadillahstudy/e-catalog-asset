<?php
// ============================================
// HELPER FUNCTIONS
// ============================================

// Sanitasi output
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

// Format rupiah
function rupiah($n, $prefix = 'Rp ') {
    return $prefix . number_format((float)$n, 0, ',', '.');
}

// Format tanggal Indonesia
function tanggal_indo($date, $with_time = false) {
    if (empty($date) || $date === '0000-00-00') return '-';
    $bulan = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun',
              '07'=>'Jul','08'=>'Agu','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];
    $ts = strtotime($date);
    $str = date('d', $ts) . ' ' . $bulan[date('m', $ts)] . ' ' . date('Y', $ts);
    if ($with_time) $str .= ' ' . date('H:i', $ts);
    return $str;
}

// Label periode
function label_periode($p) {
    if (empty($p)) return '-';
    $bulan = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
              '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $pecah = explode('-', $p);
    if (count($pecah) !== 2) return $p;
    return ($bulan[$pecah[1]] ?? $pecah[1]) . ' ' . $pecah[0];
}

// Alert flash message
function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Redirect helper
function redirect($url) {
    header("Location: $url");
    exit;
}

// Cek login
function cek_login() {
    if (empty($_SESSION['user_id'])) {
        redirect(APP_URL . '/index.php');
    }
}

// Generate CSRF token
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf($token) {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Log activity
function log_activity($pdo, $aset_id, $no_tag, $action, $data_lama = null, $data_baru = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO aset_log (aset_id, no_tag, action, data_lama, data_baru, user_id, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $aset_id,
            $no_tag,
            $action,
            $data_lama ? json_encode($data_lama, JSON_UNESCAPED_UNICODE) : null,
            $data_baru ? json_encode($data_baru, JSON_UNESCAPED_UNICODE) : null,
            $_SESSION['user_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        // Silent fail
    }
}