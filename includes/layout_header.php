<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$current_page = basename($_SERVER['PHP_SELF']);
$user_display = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Administrator';

// ✅ CEK ROLE UNTUK MENU
$is_admin = strtoupper(trim($_SESSION['role'] ?? '')) === 'ADMIN';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($page_title) ? $page_title . ' - ' : '' ?>E-Catalog</title>
    
    <!-- Bootstrap CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        :root {
            --sidebar-width: 260px;
            --primary-color: #2563EB;
            --bg-body: #F8FAFC;
        }
        body {
            background: var(--bg-body);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            margin: 0;
            overflow-x: hidden;
        }

        /* ================= SIDEBAR ================= */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: #0F172A;
            color: #94A3B8;
            z-index: 1040;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        .sidebar-header {
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar-logo {
            width: 36px;
            height: 36px;
            background: var(--primary-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        .sidebar-brand {
            color: white;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .sidebar-menu {
            padding: 20px 12px;
            flex: 1;
            overflow-y: auto;
        }
        .menu-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748B;
            padding: 12px 12px 6px;
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            color: #94A3B8;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 500;
            margin-bottom: 4px;
            transition: all 0.2s;
        }
        .sidebar-link:hover, .sidebar-link.active {
            background: rgba(37,99,235,0.1);
            color: white;
        }
        .sidebar-link.active {
            background: var(--primary-color);
        }
        .sidebar-link i {
            font-size: 18px;
        }

        /* ================= MAIN CONTENT ================= */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        /* ================= TOPBAR ================= */
        .topbar {
            background: white;
            height: 70px;
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #E2E8F0;
            position: sticky;
            top: 0;
            z-index: 1030;
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .btn-toggle-sidebar {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            color: #475569;
            cursor: pointer;
            padding: 0;
        }
        .topbar-title {
            font-size: 18px;
            font-weight: 700;
            color: #0F172A;
            margin: 0;
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .user-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 12px;
            border-radius: 8px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
        }
        .user-avatar {
            width: 32px;
            height: 32px;
            background: #DBEAFE;
            color: #2563EB;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 13px;
        }
        .user-info {
            display: flex;
            flex-direction: column;
        }
        .user-name {
            font-size: 13px;
            font-weight: 600;
            color: #0F172A;
            line-height: 1.2;
        }
        .user-role {
            font-size: 11px;
            color: #64748B;
            font-weight: 500;
        }
        .btn-logout {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: #FEE2E2;
            color: #991B1B;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-logout:hover {
            background: #FECACA;
            color: #7F1D1D;
        }

        /* ================= CONTENT WRAPPER ================= */
        .content-wrapper {
            padding: 24px;
            flex: 1;
        }

        /* ================= MOBILE RESPONSIVE ================= */
        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .btn-toggle-sidebar {
                display: block;
            }
            .user-info {
                display: none;
            }
            .user-profile {
                padding: 4px;
                border: none;
                background: transparent;
            }
            .btn-logout span {
                display: none;
            }
            .btn-logout {
                padding: 8px 10px;
            }
        }
        
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1035;
        }
        .sidebar-overlay.show {
            display: block;
        }
    </style>
</head>
<body>

    <!-- SIDEBAR OVERLAY (Mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <i class="bi bi-box-seam-fill"></i>
            </div>
            <div class="sidebar-brand">E-Catalog</div>
        </div>

        <div class="sidebar-menu">
            <!-- MAIN MENU -->
            <a href="dashboard.php" class="sidebar-link <?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <a href="aset.php" class="sidebar-link <?= $current_page == 'aset.php' || $current_page == 'aset_detail.php' ? 'active' : '' ?>">
                <i class="bi bi-grid-3x3-gap"></i> Katalog Aset
            </a>

            <!-- ✅ MENU DIBAWAH INI HANYA MUNCUL UNTUK ADMIN -->
            <?php if ($is_admin): ?>
                
                <!-- MASTER DATA -->
                <div class="menu-label">📊 Master Data</div>
                <a href="kategori.php" class="sidebar-link <?= $current_page == 'kategori.php' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam"></i> Master Barang
                </a>
                <a href="upload_import.php" class="sidebar-link <?= $current_page == 'upload_import.php' ? 'active' : '' ?>">
                    <i class="bi bi-cloud-upload"></i> Upload Data Master
                </a>

                <!-- MENU AKSES -->
                <div class="menu-label">⚙️ Menu Akses</div>
                <a href="users.php" class="sidebar-link <?= $current_page == 'users.php' ? 'active' : '' ?>">
                    <i class="bi bi-people"></i> Users
                </a>

            <?php endif; ?>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        
        <!-- TOPBAR -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="btn-toggle-sidebar" onclick="toggleSidebar()">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="topbar-title">
                    <?= isset($page_title) ? $page_title : 'Dashboard' ?>
                </h1>
            </div>
            
            <div class="topbar-right">
                <!-- PROFIL USER -->
                <div class="user-profile">
                    <div class="user-avatar">
                        <?= strtoupper(substr($user_display, 0, 1)) ?>
                    </div>
                    <div class="user-info">
                        <div class="user-name"><?= htmlspecialchars($user_display) ?></div>
                        <div class="user-role"><?= strtoupper($_SESSION['role'] ?? 'USER') ?></div>
                    </div>
                </div>
                
                <!-- TOMBOL LOGOUT -->
                <a href="logout.php" class="btn-logout" title="Logout">
                    <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
                </a>
            </div>
        </header>

        <!-- WRAPPER UNTUK KONTEN HALAMAN -->
        <div class="content-wrapper">

    <!-- JAVASCRIPT UNTUK MOBILE SIDEBAR -->
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }
    </script>