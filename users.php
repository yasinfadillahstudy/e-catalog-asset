<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
cek_login();

// =====================================================
// KEAMANAN: HANYA ADMIN YANG BOLEH AKSES MENU INI
// =====================================================
if (strtoupper(trim($_SESSION['role'] ?? '')) !== 'ADMIN') {
    set_flash('error', 'Akses Ditolak: Anda tidak memiliki hak untuk mengelola User.');
    redirect('dashboard.php');
    exit;
}

// =====================================================
// HANDLE: SIMPAN USER (Tambah/Edit)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simpan') {
    $id       = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $nama     = trim($_POST['nama'] ?? '');
    $role     = trim($_POST['role'] ?? 'VIEWER');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($nama)) {
        set_flash('error', 'Username dan Nama Lengkap wajib diisi!');
        redirect('users.php');
    }
    
    try {
        if ($id > 0) {
            // --- EDIT USER ---
            $cek = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $cek->execute([$username, $id]);
            if ($cek->fetch()) {
                set_flash('error', "Username '$username' sudah dipakai user lain!");
                redirect('users.php');
            }

            if (!empty($password)) {
                // Ganti password juga
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, display_name=?, role=?, password_hash=? WHERE id=?");
                $stmt->execute([$username, $nama, $role, $hash, $id]);
            } else {
                // Password tetap
                $stmt = $pdo->prepare("UPDATE users SET username=?, display_name=?, role=? WHERE id=?");
                $stmt->execute([$username, $nama, $role, $id]);
            }
            set_flash('success', 'Data user berhasil diperbarui!');
            
        } else {
            // --- TAMBAH USER BARU ---
            if (empty($password)) {
                set_flash('error', 'Password wajib diisi untuk user baru!');
                redirect('users.php');
            }
            
            $cek = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $cek->execute([$username]);
            if ($cek->fetch()) {
                set_flash('error', "Username '$username' sudah terdaftar. Gunakan username lain!");
                redirect('users.php');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Menggunakan kolom password_hash dan status
            $stmt = $pdo->prepare("INSERT INTO users (username, display_name, role, password_hash, status) VALUES (?, ?, ?, ?, 'AKTIF')");
            $stmt->execute([$username, $nama, $role, $hash]);
            
            set_flash('success', "User '$nama' berhasil ditambahkan!");
        }
    } catch (Exception $e) {
        // MENANGKAP ERROR DATABASE DAN MENAMPILKANNYA
        set_flash('error', 'Gagal menyimpan ke database: ' . $e->getMessage());
    }
    redirect('users.php');
}

// =====================================================
// HANDLE: HAPUS USER
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus') {
    $id = (int)$_POST['id'];
    
    if ($id === (int)$_SESSION['user_id']) {
        set_flash('error', 'Anda tidak bisa menghapus akun Anda sendiri!');
        redirect('users.php');
    }
    
    try {
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        set_flash('success', 'User berhasil dihapus!');
    } catch (Exception $e) {
        set_flash('error', 'Gagal menghapus: ' . $e->getMessage());
    }
    redirect('users.php');
}

// =====================================================
// QUERY DATA
// =====================================================
$stmt = $pdo->prepare("SELECT * FROM users ORDER BY id ASC");
$stmt->execute();
$users = $stmt->fetchAll();

$page_title = 'Manajemen User';
$page_icon  = 'people';
require_once __DIR__ . '/includes/layout_header.php';
?>

<!-- ✅ TAMPILAN NOTIFIKASI (FLASH MESSAGE) -->
<?php if (isset($_SESSION['flash'])): ?>
    <?php 
        $flash_type = $_SESSION['flash']['type'] ?? 'info';
        $bg_color = $flash_type === 'success' ? '#ECFDF5' : ($flash_type === 'error' ? '#FEF2F2' : '#EFF6FF');
        $text_color = $flash_type === 'success' ? '#065F46' : ($flash_type === 'error' ? '#991B1B' : '#1E40AF');
        $border_color = $flash_type === 'success' ? '#A7F3D0' : ($flash_type === 'error' ? '#FECACA' : '#BFDBFE');
        $icon = $flash_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill';
    ?>
    <div style="padding: 14px 18px; background: <?= $bg_color ?>; color: <?= $text_color ?>; border: 1px solid <?= $border_color ?>; border-radius: 10px; margin-bottom: 20px; font-weight: 500;">
        <i class="bi bi-<?= $icon ?>"></i> <?= $_SESSION['flash']['message'] ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Manajemen User</h2>
        <p style="font-size: 13px; color: #64748B; margin: 0;">Kelola akun akses untuk Administrator dan Viewer (Operasional)</p>
    </div>
    <button type="button" onclick="openModalTambah()" style="padding: 10px 18px; background: #10B981; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
        <i class="bi bi-person-plus"></i> Tambah User Baru
    </button>
</div>

<!-- TABEL USER -->
<div class="card border-0 shadow-sm mb-4">
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size: 13px;">
            <thead style="background: #F8FAFC;">
                <tr>
                    <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; width: 60px; text-align: center;">No</th>
                    <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Username</th>
                    <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Nama Lengkap</th>
                    <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Role Akses</th>
                    <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Status</th>
                    <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center; width: 140px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="6" style="padding: 40px; text-align: center; color: #94A3B8;">
                            <i class="bi bi-person-x" style="font-size: 32px; display: block; margin-bottom: 10px;"></i>
                            Belum ada data user.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $i => $u): ?>
                    <tr>
                        <td style="padding: 13px 14px; text-align: center; color: #64748B;"><?= $i + 1 ?></td>
                        <td style="padding: 13px 14px; font-weight: 600; color: #0F172A;">
                            <?= e($u['username']) ?>
                            <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                <span style="margin-left: 6px; font-size: 10px; background: #DBEAFE; color: #1E40AF; padding: 2px 6px; border-radius: 4px;">ANDA</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; color: #334155;"><?= e($u['display_name']) ?></td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <?php
                                $role_color = match(strtoupper($u['role'])) {
                                    'ADMIN' => 'background:#FEE2E2; color:#991B1B; border: 1px solid #FECACA;',
                                    default => 'background:#DBEAFE; color:#1E40AF; border: 1px solid #BFDBFE;',
                                };
                            ?>
                            <span style="padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 700; <?= $role_color ?>">
                                <?= strtoupper($u['role']) ?>
                            </span>
                        </td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <?php 
                                $status = strtoupper($u['status'] ?? 'AKTIF');
                                $status_color = $status === 'AKTIF' ? 'background:#D1FAE5; color:#065F46;' : 'background:#FEE2E2; color:#991B1B;';
                            ?>
                            <span style="padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 700; <?= $status_color ?>">
                                <?= $status ?>
                            </span>
                        </td>
                        <td style="padding: 13px 14px; text-align: center; white-space: nowrap;">
                            <button type="button" onclick='openModalEdit(<?= json_encode($u) ?>)' title="Edit" style="padding: 6px 12px; background: #F8FAFC; color: #2563EB; border: 1px solid #E2E8F0; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: 0.2s;" onmouseover="this.style.background='#EFF6FF'" onmouseout="this.style.background='#F8FAFC'">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('PERINGATAN!\n\nApakah Anda yakin ingin menghapus user <?= e($u['username']) ?>?');">
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" title="Hapus" style="padding: 6px 12px; background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: 0.2s;" onmouseover="this.style.background='#FEE2E2'" onmouseout="this.style.background='#FEF2F2'">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL TAMBAH/EDIT USER -->
<div id="modalUser" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: white; border-radius: 12px; max-width: 500px; width: 100%; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
        <div style="padding: 20px 24px; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center;">
            <h3 id="modalTitle" style="font-size: 16px; font-weight: 700; color: #0F172A; margin: 0;">Tambah User Baru</h3>
            <button type="button" onclick="closeModal()" style="background: none; border: none; font-size: 24px; color: #94A3B8; cursor: pointer; line-height: 1;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="simpan">
            <input type="hidden" name="id" id="formId" value="0">
            <div style="padding: 24px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Username <span style="color: #DC2626;">*</span></label>
                    <input type="text" name="username" id="formUsername" required style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none; background: #F8FAFC;" placeholder="Contoh: Fix Asset">
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Nama Lengkap <span style="color: #DC2626;">*</span></label>
                    <input type="text" name="nama" id="formNama" required style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none; background: #F8FAFC;" placeholder="Contoh: FAA STAFF">
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Role Hak Akses <span style="color: #DC2626;">*</span></label>
                    <select name="role" id="formRole" required style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none; background: white;">
                        <option value="ADMIN">ADMIN (Akses Penuh)</option>
                        <option value="VIEWER" selected>VIEWER (Hanya Lihat)</option>
                    </select>
                </div>
                <div style="margin-bottom: 8px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                        Password <span id="reqPass" style="color: #DC2626;">*</span>
                    </label>
                    <input type="password" name="password" id="formPassword" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none; background: #F8FAFC;" placeholder="Masukkan password">
                    <div id="passHint" style="font-size: 11px; color: #64748B; margin-top: 6px; display: none;">Kosongkan jika tidak ingin mengganti password.</div>
                </div>
            </div>
            <div style="padding: 16px 24px; border-top: 1px solid #F1F5F9; display: flex; justify-content: flex-end; gap: 10px; background: #F8FAFC; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;">
                <button type="button" onclick="closeModal()" style="padding: 10px 20px; border: 1px solid #E2E8F0; background: white; border-radius: 8px; font-weight: 600; color: #475569; font-size: 13px; cursor: pointer;">Batal</button>
                <button type="submit" style="padding: 10px 24px; background: #2563EB; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer;"><i class="bi bi-save"></i> Simpan Data</button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('modalUser');

function closeModal() { 
    modal.style.display = 'none'; 
}

modal.addEventListener('click', (e) => { 
    if (e.target === modal) closeModal(); 
});

function openModalTambah() {
    document.getElementById('modalTitle').textContent = 'Tambah User Baru';
    document.getElementById('formId').value = 0;
    document.getElementById('formUsername').value = '';
    document.getElementById('formNama').value = '';
    document.getElementById('formRole').value = 'VIEWER';
    document.getElementById('formPassword').value = '';
    
    document.getElementById('reqPass').style.display = 'inline';
    document.getElementById('passHint').style.display = 'none';
    document.getElementById('formPassword').required = true;
    
    modal.style.display = 'flex';
}

function openModalEdit(data) {
    document.getElementById('modalTitle').textContent = 'Edit Data User';
    document.getElementById('formId').value = data.id;
    document.getElementById('formUsername').value = data.username;
    document.getElementById('formNama').value = data.display_name;
    document.getElementById('formRole').value = data.role;
    document.getElementById('formPassword').value = '';
    
    document.getElementById('reqPass').style.display = 'none';
    document.getElementById('passHint').style.display = 'block';
    document.getElementById('formPassword').required = false;
    
    modal.style.display = 'flex';
}
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>