<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
cek_login();

// =====================================================
// HANDLE POST (Tambah/Edit/Hapus/Import)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ---- TAMBAH / EDIT ----
    if ($action === 'simpan') {
        $id       = (int)($_POST['id'] ?? 0);
        $kode_uu  = trim($_POST['kode_uu'] ?? '');
        $nama     = trim($_POST['store_name'] ?? '');
        $cluster  = trim($_POST['cluster'] ?? '');
        $branch   = trim($_POST['branch'] ?? '');
        $status   = $_POST['status'] ?? 'aktif';
        
        if (empty($kode_uu) || empty($nama)) {
            set_flash('error', 'Kode UU dan Nama Toko wajib diisi!');
        } else {
            try {
                if ($id > 0) {
                    // Edit
                    $stmt = $pdo->prepare("
                        UPDATE toko SET kode_uu=?, store_name=?, cluster=?, branch=?, status=? WHERE id=?
                    ");
                    $stmt->execute([$kode_uu, $nama, $cluster, $branch, $status, $id]);
                    set_flash('success', 'Toko berhasil diupdate!');
                } else {
                    // Tambah
                    $stmt = $pdo->prepare("
                        INSERT INTO toko (kode_uu, store_name, cluster, branch, status)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$kode_uu, $nama, $cluster, $branch, $status]);
                    set_flash('success', 'Toko berhasil ditambahkan!');
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    set_flash('error', 'Kode UU sudah ada!');
                } else {
                    set_flash('error', 'Gagal simpan: ' . $e->getMessage());
                }
            }
        }
        redirect('toko.php');
    }
    
    // ---- HAPUS ----
    if ($action === 'hapus') {
        $id = (int)$_POST['id'];
        try {
            // Cek apakah masih ada aset yang pakai
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM aset WHERE kode_uu = (SELECT kode_uu FROM toko WHERE id=?)");
            $stmt->execute([$id]);
            $jml_aset = $stmt->fetchColumn();
            
            if ($jml_aset > 0) {
                set_flash('error', "Tidak bisa hapus. Masih ada {$jml_aset} aset di toko ini.");
            } else {
                $pdo->prepare("DELETE FROM toko WHERE id = ?")->execute([$id]);
                set_flash('success', 'Toko berhasil dihapus!');
            }
        } catch (Exception $e) {
            set_flash('error', 'Gagal hapus: ' . $e->getMessage());
        }
        redirect('toko.php');
    }
    
    // ---- IMPORT CSV ----
    if ($action === 'import' && !empty($_FILES['file_csv']['tmp_name'])) {
        $tmp = $_FILES['file_csv']['tmp_name'];
        $content = file_get_contents($tmp);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        if (count($lines) < 2) {
            set_flash('error', 'File kosong atau tidak ada data.');
            redirect('toko.php');
        }
        
        // Deteksi delimiter
        $sample = implode('', array_slice($lines, 0, 5));
        $tab = substr_count($sample, "\t");
        $semi = substr_count($sample, ';');
        $comma = substr_count($sample, ',');
        $delim = "\t";
        if ($semi > $tab && $semi > $comma) $delim = ';';
        elseif ($comma > $tab && $comma > $semi) $delim = ',';
        
        // Parse header
        $header = array_map('strtoupper', array_map('trim', explode($delim, $lines[0])));
        $idx = [
            'kode_uu'     => array_search('KODE UU', $header),
            'store_name'  => array_search('STORE_NAME', $header),
            'store_name2' => array_search('NAMA TOKO', $header),
            'cluster'     => array_search('CLUSTER', $header),
            'branch'      => array_search('BRANCH', $header),
        ];
        
        if ($idx['kode_uu'] === false) {
            set_flash('error', 'Kolom "KODE UU" tidak ditemukan di header!');
            redirect('toko.php');
        }
        if ($idx['store_name'] === false) $idx['store_name'] = $idx['store_name2'];
        if ($idx['store_name'] === false) {
            set_flash('error', 'Kolom "STORE_NAME" atau "NAMA TOKO" tidak ditemukan!');
            redirect('toko.php');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO toko (kode_uu, store_name, cluster, branch, status)
            VALUES (?, ?, ?, ?, 'aktif')
            ON DUPLICATE KEY UPDATE
                store_name = VALUES(store_name),
                cluster = VALUES(cluster),
                branch = VALUES(branch)
        ");
        
        $sukses = 0; $skip = 0;
        foreach (array_slice($lines, 1) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $d = explode($delim, $line);
            $kode = trim($d[$idx['kode_uu']] ?? '');
            $nama = trim($d[$idx['store_name']] ?? '');
            
            if (empty($kode) || empty($nama)) { $skip++; continue; }
            
            $cluster = $idx['cluster'] !== false ? trim($d[$idx['cluster']] ?? '') : '';
            $branch  = $idx['branch']  !== false ? trim($d[$idx['branch']]  ?? '') : '';
            
            try {
                $stmt->execute([$kode, $nama, $cluster, $branch]);
                $sukses++;
            } catch (Exception $e) {
                $skip++;
            }
        }
        
        set_flash('success', "Import selesai! Sukses: {$sukses} toko, Skip: {$skip} baris.");
        redirect('toko.php');
    }
}

// =====================================================
// QUERY DATA
// =====================================================
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];
if ($search) {
    $where[] = "(kode_uu LIKE ? OR store_name LIKE ? OR cluster LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM toko $where_sql");
$stmt->execute($params);
$total_rows = (int)$stmt->fetchColumn();
$total_pages = ceil($total_rows / $per_page);

$stmt = $pdo->prepare("
    SELECT t.*, 
        (SELECT COUNT(*) FROM aset WHERE kode_uu = t.kode_uu) AS jml_aset
    FROM toko t
    $where_sql
    ORDER BY t.kode_uu ASC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$toko_list = $stmt->fetchAll();

// =====================================================
// RENDER
// =====================================================
$page_title = 'Master Toko';
$page_icon  = 'shop';
require_once __DIR__ . '/includes/layout_header.php';
?>

<!-- TOOLBAR -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <div style="font-size: 12px; color: #64748B;">
            Total <strong style="color: #2563EB;"><?= number_format($total_rows) ?></strong> toko terdaftar
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <form method="GET" style="display: flex; gap: 8px; margin: 0;">
            <input type="text" name="search" placeholder="Cari kode / nama / cluster..."
                   value="<?= e($search) ?>"
                   style="padding: 9px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; min-width: 240px; background: white;">
            <button type="submit" style="padding: 9px 16px; background: #2563EB; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
                <i class="bi bi-search"></i>
            </button>
            <?php if ($search): ?>
                <a href="toko.php" style="padding: 9px 14px; background: #F1F5F9; color: #475569; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none;">
                    <i class="bi bi-x-circle"></i>
                </a>
            <?php endif; ?>
        </form>
        <button onclick="openImport()" style="padding: 9px 16px; background: #8B5CF6; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
            <i class="bi bi-upload"></i> Import CSV
        </button>
        <button onclick="openTambah()" style="padding: 9px 16px; background: #10B981; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
            <i class="bi bi-plus-circle"></i> Tambah
        </button>
    </div>
</div>

<!-- TABLE -->
<div class="card border-0 shadow-sm">
    <?php if (empty($toko_list)): ?>
        <div style="text-align: center; padding: 80px 20px; color: #94A3B8;">
            <i class="bi bi-inbox" style="font-size: 56px; display: block; margin-bottom: 12px; color: #CBD5E1;"></i>
            <h4 style="color: #475569; font-size: 15px; margin: 0 0 6px;">Tidak Ada Data</h4>
            <p style="font-size: 13px; margin: 0;">Belum ada toko yang terdaftar. Silakan import CSV atau tambah manual.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size: 13px;">
                <thead style="background: #F8FAFC;">
                    <tr>
                        <th style="padding: 13px 20px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; width: 60px;">#</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Kode UU</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Nama Toko</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Cluster</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Branch</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Aset</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Status</th>
                        <th style="padding: 13px 20px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center; width: 140px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = $offset + 1; foreach ($toko_list as $t): ?>
                    <tr>
                        <td style="padding: 13px 20px; color: #94A3B8; font-size: 11.5px;"><?= $no++ ?></td>
                        <td style="padding: 13px 14px;">
                            <span style="font-family: 'Courier New', monospace; font-weight: 700; color: #1E40AF; background: #EFF6FF; padding: 3px 8px; border-radius: 6px; font-size: 12px;">
                                <?= e($t['kode_uu']) ?>
                            </span>
                        </td>
                        <td style="padding: 13px 14px; font-weight: 600; color: #0F172A;"><?= e($t['store_name']) ?></td>
                        <td style="padding: 13px 14px; color: #64748B; font-size: 12px;"><?= e($t['cluster'] ?: '-') ?></td>
                        <td style="padding: 13px 14px; color: #64748B; font-size: 12px;"><?= e($t['branch'] ?: '-') ?></td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <?php if ($t['jml_aset'] > 0): ?>
                                <span style="background: #DBEAFE; color: #1E40AF; padding: 3px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 700;">
                                    <?= number_format($t['jml_aset']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #CBD5E1;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <?php if ($t['status'] === 'aktif'): ?>
                                <span style="background: #D1FAE5; color: #065F46; padding: 3px 10px; border-radius: 12px; font-size: 10.5px; font-weight: 700;">AKTIF</span>
                            <?php else: ?>
                                <span style="background: #F1F5F9; color: #64748B; padding: 3px 10px; border-radius: 12px; font-size: 10.5px; font-weight: 700;">NONAKTIF</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 13px 20px; text-align: center; white-space: nowrap;">
                            <button onclick='editData(<?= json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    style="padding: 6px 11px; background: #DBEAFE; color: #1E40AF; border: none; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer;">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin hapus toko <?= e($t['kode_uu']) ?>?');">
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button type="submit" style="padding: 6px 11px; background: #FEE2E2; color: #991B1B; border: none; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer;">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
        <div style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #F1F5F9; flex-wrap: wrap; gap: 10px;">
            <div style="font-size: 12.5px; color: #64748B;">
                Halaman <strong><?= $page ?></strong> dari <strong><?= $total_pages ?></strong>
            </div>
            <div style="display: flex; gap: 4px;">
                <?php
                $qs = $_GET;
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                
                $qs['page'] = max(1, $page - 1);
                ?>
                <a href="?<?= http_build_query($qs) ?>" style="padding: 6px 12px; border: 1px solid #E2E8F0; border-radius: 6px; background: white; color: #475569; text-decoration: none; font-size: 12.5px; font-weight: 600; <?= $page <= 1 ? 'opacity:0.4; pointer-events:none;' : '' ?>">
                    ◀
                </a>
                <?php for ($i = $start; $i <= $end; $i++): $qs['page'] = $i; ?>
                    <a href="?<?= http_build_query($qs) ?>" style="padding: 6px 12px; border: 1px solid <?= $i == $page ? '#2563EB' : '#E2E8F0' ?>; border-radius: 6px; background: <?= $i == $page ? '#2563EB' : 'white' ?>; color: <?= $i == $page ? 'white' : '#475569' ?>; text-decoration: none; font-size: 12.5px; font-weight: 600;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <?php $qs['page'] = min($total_pages, $page + 1); ?>
                <a href="?<?= http_build_query($qs) ?>" style="padding: 6px 12px; border: 1px solid #E2E8F0; border-radius: 6px; background: white; color: #475569; text-decoration: none; font-size: 12.5px; font-weight: 600; <?= $page >= $total_pages ? 'opacity:0.4; pointer-events:none;' : '' ?>">
                    ▶
                </a>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ================= MODAL FORM ================= -->
<div id="modalForm" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.65); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: white; border-radius: 14px; max-width: 500px; width: 100%; box-shadow: 0 25px 70px rgba(0,0,0,0.3);">
        <div style="padding: 20px 24px; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center;">
            <h3 id="modalTitle" style="margin: 0; font-size: 17px; font-weight: 700; color: #0F172A;">Tambah Toko</h3>
            <button onclick="closeModal()" style="background: #F1F5F9; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; color: #64748B; font-size: 18px;">×</button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="simpan">
            <input type="hidden" name="id" id="f_id" value="">
            
            <div style="padding: 22px 24px;">
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                        Kode UU <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="kode_uu" id="f_kode_uu" required
                           style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;"
                           placeholder="Contoh: 6A03">
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                        Nama Toko <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="store_name" id="f_store_name" required
                           style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;"
                           placeholder="Contoh: MERUYA SELATAN">
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Cluster</label>
                    <input type="text" name="cluster" id="f_cluster"
                           style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;"
                           placeholder="Contoh: MAIN ROAD">
                </div>
                <div style="margin-bottom: 16px;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Branch</label>
                    <input type="text" name="branch" id="f_branch"
                           style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;"
                           placeholder="Contoh: JAKARTA">
                </div>
                <div style="margin-bottom: 0;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Status</label>
                    <select name="status" id="f_status" style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>
            </div>
            
            <div style="padding: 16px 24px; border-top: 1px solid #F1F5F9; display: flex; gap: 10px; justify-content: flex-end; background: #FCFDFE; border-radius: 0 0 14px 14px;">
                <button type="button" onclick="closeModal()" style="padding: 10px 20px; border: 1px solid #E2E8F0; background: white; border-radius: 8px; font-weight: 600; cursor: pointer; color: #475569; font-size: 13px;">
                    Batal
                </button>
                <button type="submit" style="padding: 10px 22px; background: #2563EB; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;">
                    <i class="bi bi-save"></i> Simpan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ================= MODAL IMPORT ================= -->
<div id="modalImport" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.65); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: white; border-radius: 14px; max-width: 500px; width: 100%;">
        <div style="padding: 20px 24px; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: #0F172A;">Import Toko dari CSV</h3>
            <button onclick="closeImport()" style="background: #F1F5F9; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; color: #64748B; font-size: 18px;">×</button>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            
            <div style="padding: 22px 24px;">
                <div style="background: #EFF6FF; border: 1px solid #BFDBFE; padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 12.5px; color: #1E40AF; line-height: 1.7;">
                    <strong><i class="bi bi-info-circle-fill"></i> Format CSV:</strong><br>
                    • Kolom wajib: <strong>KODE UU</strong>, <strong>STORE_NAME</strong> (atau NAMA TOKO)<br>
                    • Opsional: <strong>CLUSTER</strong>, <strong>BRANCH</strong><br>
                    • Delimiter: Tab / Semicolon / Comma (auto-detect)<br>
                    • Kalau KODE UU sudah ada → data akan <strong>diupdate</strong>
                </div>
                
                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 8px;">
                    <i class="bi bi-file-earmark-spreadsheet"></i> File CSV
                </label>
                <input type="file" name="file_csv" accept=".csv,.txt" required
                       style="width: 100%; padding: 10px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px;">
            </div>
            
            <div style="padding: 16px 24px; border-top: 1px solid #F1F5F9; display: flex; gap: 10px; justify-content: flex-end; background: #FCFDFE; border-radius: 0 0 14px 14px;">
                <button type="button" onclick="closeImport()" style="padding: 10px 20px; border: 1px solid #E2E8F0; background: white; border-radius: 8px; font-weight: 600; cursor: pointer; color: #475569; font-size: 13px;">
                    Batal
                </button>
                <button type="submit" style="padding: 10px 22px; background: #8B5CF6; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px;">
                    <i class="bi bi-cloud-upload"></i> Import
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openTambah() {
    document.getElementById('modalTitle').textContent = 'Tambah Toko';
    document.getElementById('f_id').value = '';
    document.getElementById('f_kode_uu').value = '';
    document.getElementById('f_store_name').value = '';
    document.getElementById('f_cluster').value = '';
    document.getElementById('f_branch').value = '';
    document.getElementById('f_status').value = 'aktif';
    document.getElementById('modalForm').style.display = 'flex';
}

function editData(data) {
    document.getElementById('modalTitle').textContent = 'Edit Toko: ' + data.kode_uu;
    document.getElementById('f_id').value = data.id;
    document.getElementById('f_kode_uu').value = data.kode_uu || '';
    document.getElementById('f_store_name').value = data.store_name || '';
    document.getElementById('f_cluster').value = data.cluster || '';
    document.getElementById('f_branch').value = data.branch || '';
    document.getElementById('f_status').value = data.status || 'aktif';
    document.getElementById('modalForm').style.display = 'flex';
}

function closeModal() { document.getElementById('modalForm').style.display = 'none'; }
function openImport() { document.getElementById('modalImport').style.display = 'flex'; }
function closeImport() { document.getElementById('modalImport').style.display = 'none'; }

// Tutup kalau klik di backdrop
document.getElementById('modalForm').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.getElementById('modalImport').addEventListener('click', function(e) {
    if (e.target === this) closeImport();
});

// Tutup dengan ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeModal(); closeImport(); }
});
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>