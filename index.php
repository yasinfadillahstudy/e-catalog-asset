<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';

// Kalau sudah login → redirect ke dashboard
if (!empty($_SESSION['user_id'])) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';

    if (!verify_csrf($csrf)) {
        $error = 'Sesi tidak valid. Silakan coba lagi.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Login sukses
            $_SESSION['user_id']      = $user['id'];
            $_SESSION['username']     = $user['username'];
            $_SESSION['display_name'] = $user['display_name'];
            $_SESSION['role']         = $user['role'];

            // Update last_login
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);

            redirect('dashboard.php');
        } else {
            $error = 'Username atau password salah.';
        }
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            margin: 0;
        }
        .login-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 25px 70px rgba(0,0,0,0.4);
            width: 100%;
            max-width: 420px;
            padding: 40px 36px;
            position: relative;
            overflow: hidden;
        }
        .login-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #2563EB, #60A5FA);
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #2563EB, #1D4ED8);
            border-radius: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
            box-shadow: 0 8px 20px rgba(37,99,235,0.3);
        }
        .logo-icon i { font-size: 34px; color: white; }
        .logo h1 {
            font-size: 20px;
            font-weight: 700;
            color: #0F172A;
            margin: 0 0 4px;
            letter-spacing: 0.3px;
        }
        .logo p {
            font-size: 12.5px;
            color: #64748B;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        .form-group { margin-bottom: 18px; }
        .form-label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .form-input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            font-size: 14px;
            outline: none;
            transition: 0.15s;
            background: #F8FAFC;
            color: #1E293B;
            font-family: inherit;
        }
        .form-input:focus {
            border-color: #2563EB;
            background: white;
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .input-icon-wrap { position: relative; }
        .input-icon-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94A3B8;
            font-size: 16px;
        }
        .input-icon-wrap .form-input { padding-left: 42px; }
        .btn-login {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #2563EB, #1D4ED8);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.15s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.4px;
            margin-top: 6px;
        }
        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(37,99,235,0.35);
        }
        .btn-login:active { transform: translateY(0); }
        .alert-error {
            background: #FEF2F2;
            color: #991B1B;
            border: 1px solid #FECACA;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 11.5px;
            color: #94A3B8;
            letter-spacing: 0.3px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="logo">
        <div class="logo-icon">
            <i class="bi bi-box-seam"></i>
        </div>
        <h1><?= APP_NAME ?></h1>
        <p>Divisi Fixed Asset</p>
    </div>

    <?php if ($error): ?>
        <div class="alert-error">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

        <div class="form-group">
            <label class="form-label">Username</label>
            <div class="input-icon-wrap">
                <i class="bi bi-person"></i>
                <input type="text" name="username" class="form-input" placeholder="Masukkan username" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Password</label>
            <div class="input-icon-wrap">
                <i class="bi bi-lock"></i>
                <input type="password" name="password" class="form-input" placeholder="Masukkan password" required>
            </div>
        </div>

        <button type="submit" class="btn-login">
            <i class="bi bi-box-arrow-in-right"></i> Masuk
        </button>
    </form>

    <div class="footer-text">
        © 2026 <?= APP_NAME ?> v<?= APP_VERSION ?>
    </div>
</div>

</body>
</html>