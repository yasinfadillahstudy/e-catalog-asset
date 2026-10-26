<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
cek_login();

// =====================================================
// HANDLE POST
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ---- TAMBAH / EDIT ----
    if ($action === 'simpan') {
        $id       = (int)($_POST['id'] ?? 0);
        $kode     = trim($_POST['kode_barang'] ?? '');
        $nama     = trim($_POST['nama_barang'] ?? '');
        $kat_id   = ($_POST['kategori_id'] ?? '') !== '' ? (int)$_POST['kategori_id'] : null;
        $merek    = trim($_POST['merek'] ?? '');
        $satuan   = trim($_POST['satuan'] ?? 'Unit');
        
        if (empty($kode) || empty($nama)) {
            set_flash('error', 'Kode Barang dan Nama Barang wajib diisi!');
        } else {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE barang SET kode_barang=?, nama_barang=?, kategori_id=?, merek=?, satuan=? WHERE id=?
                    ");
                    $stmt->execute([$kode, $nama, $kat_id, $merek ?: null, $satuan ?: 'Unit', $id]);
                    set_flash('success', 'Barang berhasil diupdate!');
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO barang (kode_barang, nama_barang, kategori_id, merek, satuan)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$kode, $nama, $kat_id, $merek ?: null, $satuan ?: 'Unit']);
                    set_flash('success', 'Barang berhasil ditambahkan!');
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    set_flash('error', 'Kode Barang sudah ada!');
                } else {
                    set_flash('error', 'Gagal simpan: ' . $e->getMessage());
                }
            }
        }
        redirect('barang.php');
    }
    
    // ---- HAPUS ----
    if ($action === 'hapus') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM aset WHERE barang_id = ?");
            $stmt->execute([$id]);
            $jml_aset = $stmt->fetchColumn();
            
            if ($jml_aset > 0) {
                set_flash('error', "Tidak bisa hapus. Masih ada {$jml_aset} aset yang pakai barang ini.");
            } else {
                $pdo->prepare("DELETE FROM barang WHERE id = ?")->execute([$id]);
                set_flash('success', 'Barang berhasil dihapus!');
            }
        } catch (Exception $e) {
            set_flash('error', 'Gagal hapus: ' . $e->getMessage());
        }
        redirect('barang.php');
    }
    
    // ---- IMPORT CSV ----
    if ($action === 'import' && !empty($_FILES['file_csv']['tmp_name'])) {
        $tmp = $_FILES['file_csv']['tmp_name'];
        $content = file_get_contents($tmp);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        if (count($lines) < 2) {
            set_flash('error', 'File kosong atau tidak ada data.');
            redirect('barang.php');
        }
        
        // Deteksi delimiter
        $sample = implode('', array_slice($lines, 0, 5));
        $tab   = substr_count($sample, "\t");
        $semi  = substr_count($sample, ';');
        $comma = substr_count($sample, ',');
        $delim = "\t";
        if ($semi > $tab && $semi > $comma) $delim = ';';
        elseif ($comma > $tab && $comma > $semi) $delim = ',';
        
        // Parse header
        $header = array_map('strtoupper', array_map('trim', explode($delim, $lines[0])));
        
        $find_col = function($names) use ($header) {
            foreach ($names as $n) {
                $idx = array_search(strtoupper($n), $header);
                if ($idx !== false) return $idx;
            }
            return false;
        };
        
        $idx = [
            'kode'       => $find_col(['KODE BARANG', 'KODE_BARANG', 'KODEBARANG']),
            'nama'       => $find_col(['DESCP', 'NAMA BARANG', 'NAMA_BARANG', 'NAMA']),
            'kategori'   => $find_col(['KATEGORI', 'KATEGORI_ELECTRICAL', 'KATEGORI ELECTRICAL']),
            'merek'      => $find_col(['MEREK', 'MERK']),
            'satuan'     => $find_col(['SATUAN']),
        ];
        
        if ($idx['kode'] === false || $idx['nama'] === false) {
            set_flash('error', 'Kolom "KODE BARANG" dan "DESCP" (atau NAMA BARANG) wajib ada!');
            redirect('barang.php');
        }
        
        // Cache kategori (nama upper => id)
        $kat_cache = [];
        $stmt = $pdo->query("SELECT id, UPPER(nama_kategori) AS nama FROM kategori");
        while ($r = $stmt->fetch()) {
            $kat_cache[$r['nama']] = $r['id'];
        }
        
        $stmt_kat_insert = $pdo->prepare("INSERT INTO kategori (nama_kategori) VALUES (?)");
        $stmt_ins = $pdo->prepare("
            INSERT INTO barang (kode_barang, nama_barang, kategori_id, merek, satuan)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                nama_barang = VALUES(nama_barang),
                kategori_id = VALUES(kategori_id),
                merek = VALUES(merek),
                satuan = VALUES(satuan)
        ");
        
        $sukses = 0; $skip = 0; $new_kat = 0;
        foreach (array_slice($lines, 1) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $d = explode($delim, $line);
            $kode = trim($d[$idx['kode']] ?? '');
            $nama = trim($d[$idx['nama']] ?? '');
            
            if (empty($kode) || empty($nama)) { $skip++; continue; }
            
            $kat_nama = $idx['kategori'] !== false ? trim($d[$idx['kategori']] ?? '') : '';
            $kat_id = null;
            
            if ($kat_nama) {
                $kat_key = strtoupper($kat_nama);
                if (isset($kat_cache[$kat_key])) {
                    $kat_id = $kat_cache[$kat_key];
                } else {
                    // Auto-create kategori
                    try {
                        $stmt_kat_insert->execute([$kat_nama]);
                        $kat_id = $pdo->lastInsertId();
                        $kat_cache[$kat_key] = $kat_id;
                        $new_kat++;
                    } catch (Exception $e) {
                        $kat_id = null;
                    }
                }
            }
            
            $merek  = $idx['merek']  !== false ? trim($d[$idx['merek']]  ?? '') : '';
            $satuan = $idx['satuan'] !== false ? trim($d[$idx['satuan']] ?? 'Unit') : 'Unit';
            
            try {
                $stmt_ins->execute([$kode, $nama, $kat_id, $merek ?: null, $satuan ?: 'Unit']);
                $sukses++;
            } catch (Exception $e) {
                $skip++;
            }
        }
        
        $msg = "Import selesai! Sukses: {$sukses} barang, Skip: {$skip} baris.";
        if ($new_kat > 0) $msg .= " Kategori baru dibuat: {$new_kat}.";
        set_flash('success', $msg);
        redirect('barang.php');
    }
}

// =====================================================
// QUERY DATA
// =====================================================
$search = trim($_GET['search'] ?? '');
$filter_kat = (int)($_GET['kategori_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$where = [];
$params = [];
if ($search) {
    $where[] = "(b.kode_barang LIKE ? OR b.nama_barang LIKE ? OR b.merek LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filter_kat > 0) {
    $where[] = "b.kategori_id = ?";
    $params[] = $filter_kat;
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM barang b $where_sql");
$stmt->execute($params);
$total_rows = (int)$stmt->fetchColumn();
$total_pages = ceil($total_rows / $per_page);

$stmt = $pdo->prepare("
    SELECT b.*, k.nama_kategori,
        (SELECT COUNT(*) FROM aset WHERE barang_id = b.id) AS jml_aset
    FROM barang b
    LEFT JOIN kategori k ON b.kategori_id = k.id
    $where_sql
    ORDER BY b.kode_barang ASC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$barang_list = $stmt->fetchAll();

// Dropdown kategori
$kategori_list = $pdo->query("SELECT id, nama_kategori FROM kategori ORDER BY nama_kategori ASC")->fetchAll();

// =====================================================
// RENDER
// =====================================================
$page_title = 'Master Barang';
$page_icon  = 'box';
require_once __DIR__ . '/includes/layout_header.php';
?>

<!-- PAGE HEADER -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Daftar Barang</h2>
        <p style="font-size: 13px; color: #64748B; margin: 0;">
            Total <strong style="color: #2563EB;"><?= number_format($total_rows) ?></strong> barang terdaftar
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button onclick="openImport()" style="padding: 9px 16px; background: #8B5CF6; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
            <i class="bi bi-upload"></i> Import CSV
        </button>
        <button onclick="openTambah()" style="padding: 9px 16px; background: #10B981; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
            <i class="bi bi-plus-circle"></i> Tambah Barang
        </button>
    </div>
</div>

<!-- FILTER BAR -->
<div class="card border-0 shadow-sm mb-3" style="padding: 16px 20px;">
    <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <input type="text" name="search" placeholder="🔍 Cari kode, nama, atau merek..." value="<?= e($search) ?>"
               style="padding: 9px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; min-width: 280px; background: #F8FAFC;">
        
        <select name="kategori_id" onchange="this.form.submit()" style="padding: 9px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; background: #F8FAFC; min-width: 180px;">
            <option value="">Semua Kategori</option>
            <?php foreach ($kategori_list as $k): ?>
                <option value="<?= $k['id'] ?>" <?= $filter_kat === (int)$k['id'] ? 'selected' : '' ?>><?= e($k['nama_kategori']) ?></option>
            <?php endforeach; ?>
        </select>
        
        <button type="submit" style="padding: 9px 18px; background: #2563EB; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
            <i class="bi bi-search"></i> Cari
        </button>
        
        <?php if ($search || $filter_kat): ?>
            <a href="barang.php" style="padding: 9px 16px; background: #F1F5F9; color: #475569; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                <i class="bi bi-x-circle"></i> Reset
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- TABLE -->
<div class="card border-0 shadow-sm">
    <?php if (empty($barang_list)): ?>
        <div style="text-align: center; padding: 80px 20px; color: #94A3B8;">
            <i class="bi bi-box" style="font-size: 56px; display: block; margin-bottom: 12px; color: #CBD5E1;"></i>
            <h4 style="color: #475569; font-size: 15px; margin: 0 0 6px;">Belum Ada Barang</h4>
            <p style="font-size: 13px; margin: 0 0 16px;">Tambahkan barang atau import dari CSV</p>
            <button onclick="openTambah()" style="padding: 10px 20px; background: #2563EB; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;">
                <i class="bi bi-plus-circle"></i> Tambah Barang Pertama
            </button>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size: 13px;">
                <thead style="background: #F8FAFC;">
                    <tr>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; width: 50px; text-align: center;">No</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Kode Barang</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Nama Barang</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Kategori</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Merek</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Satuan</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Aset</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center; width: 140px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = $offset + 1; foreach ($barang_list as $b): ?>
                    <tr>
                        <td style="padding: 13px 14px; color: #94A3B8; font-size: 11.5px; text-align: center;"><?= $no++ ?></td>
                        <td style="padding: 13px 14px;">
                            <span style="font-family: 'Courier New', monospace; font-weight: 700; color: #1E40AF; background: #EFF6FF; padding: 3px 8px; border-radius: 6px; font-size: 12px;">
                                <?= e($b['kode_barang']) ?>
                            </span>
                        </td>
                        <td style="padding: 13px 14px; font-weight: 600; color: #0F172A;"><?= e($b['nama_barang']) ?></td>
                        <td style="padding: 13px 14px;">
                            <?php if ($b['nama_kategori']): ?>
                                <span style="background: #F5F3FF; color: #7C3AED; padding: 3px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 700;">
                                    <?= e($b['nama_kategori']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #CBD5E1;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; color: #64748B; font-size: 12px;"><?= e($b['merek'] ?: '-') ?></td>
                        <td style="padding: 13px 14px; text-align: center; color: #64748B; font-size: 12px;"><?= e($b['satuan'] ?: 'Unit') ?></td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <?php if ($b['jml_aset'] > 0): ?>
                                <span style="background: #FEF3C7; color: #92400E; padding: 3px 10px; border-radius: 12px; font-size: 11.5px; font-weight: 700;">
                                    <?= number_format($b['jml_aset']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #CBD5E1;">-</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; text-align: center; white-space: nowrap;">
                            <button onclick='editData(<?= json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    style="padding: 6px 11px; background: #DBEAFE; color: #1E40AF; border: none; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer;">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin hapus barang <?= e($b['kode_barang']) ?>?');">
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
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
                    ◀ Prev
                </a>
                <?php for ($i = $start; $i <= $end; $i++): $qs['page'] = $i; ?>
                    <a href="?<?= http_build_query($qs) ?>" style="padding: 6px 12px; border: 1px solid <?= $i == $page ? '#2563EB' : '#E2E8F0' ?>; border-radius: 6px; background: <?= $i == $page ? '#2563EB' : 'white' ?>; color: <?= $i == $page ? 'white' : '#475569' ?>; text-decoration: none; font-size: 12.5px; font-weight: 600;">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
                <?php $qs['page'] = min($total_pages, $page + 1); ?>
                <a href="?<?= http_build_query($qs) ?>" style="padding: 6px 12px; border: 1px solid #E2E8F0; border-radius: 6px; background: white; color: #475569; text-decoration: none; font-size: 12.5px; font-weight: 600; <?= $page >= $total_pages ? 'opacity:0.4; pointer-events:none;' : '' ?>">
                    Next ▶
                </a>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ================= MODAL FORM ================= -->
<div id="modalForm" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.65); z-index: 9999; align-items: center; justify-content: center; padding: 20px; overflow-y: auto;">
    <div style="background: white; border-radius: 14px; max-width: 560px; width: 100%; box-shadow: 0 25px 70px rgba(0,0,0,0.3); margin: auto;">
        <div style="padding: 20px 24px; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center;">
            <h3 id="modalTitle" style="margin: 0; font-size: 17px; font-weight: 700; color: #0F172A;">Tambah Barang</h3>
            <button onclick="closeModal()" style="background: #F1F5F9; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; color: #64748B; font-size: 18px;">×</button>
        </div>
        
        <form method="POST">
            <input type="hidden" name="action" value="simpan">
            <input type="hidden" name="id" id="f_id" value="">
            
            <div style="padding: 22px 24px; display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                
                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                        Kode Barang <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="kode_barang" id="f_kode_barang" required
                           style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;"
                           placeholder="Contoh: AC-001">
                </div>
                
                <div>
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Merek</label>
                    <input type="text" name="merek" id="f_merek"
                           style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;"
                           placeholder="Contoh: Daikin">
                </div>
                
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                        Nama Barang <span style="color: #DC2626;">*</span>
                    </label>
                    <input type="text" name="nama_barang" id="f_nama_barang" required
                           style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;"
                           placeholder="Contoh: AC Split 1PK">
                </div>
                
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Kategori</label>
                    <select name="kategori_id" id="f_kategori_id" style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;">
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($kategori_list as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= e($k['nama_kategori']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="grid-column: span 2;">
                    <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Satuan</label>
                    <input type="text" name="satuan" id="f_satuan" value="Unit"
                           style="width: 100%; padding: 10px 13px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13.5px; outline: none;"
                           placeholder="Contoh: Unit / Buah / Set">
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
    <div style="background: white; border-radius: 14px; max-width: 520px; width: 100%;">
        <div style="padding: 20px 24px; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 17px; font-weight: 700; color: #0F172A;">Import Barang dari CSV</h3>
            <button onclick="closeImport()" style="background: #F1F5F9; border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; color: #64748B; font-size: 18px;">×</button>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import">
            
            <div style="padding: 22px 24px;">
                <div style="background: #EFF6FF; border: 1px solid #BFDBFE; padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 12.5px; color: #1E40AF; line-height: 1.7;">
                    <strong><i class="bi bi-info-circle-fill"></i> Format CSV:</strong><br>
                    • Wajib: <strong>KODE BARANG</strong>, <strong>DESCP</strong> (atau NAMA BARANG)<br>
                    • Opsional: KATEGORI, MEREK, SATUAN<br>
                    • Kategori baru akan <strong>otomatis dibuat</strong> kalau belum ada<br>
                    • Kalau KODE BARANG sudah ada → <strong>diupdate</strong>
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
    document.getElementById('modalTitle').textContent = 'Tambah Barang';
    document.getElementById('f_id').value = '';
    document.getElementById('f_kode_barang').value = '';
    document.getElementById('f_nama_barang').value = '';
    document.getElementById('f_kategori_id').value = '';
    document.getElementById('f_merek').value = '';
    document.getElementById('f_satuan').value = 'Unit';
    document.getElementById('modalForm').style.display = 'flex';
    setTimeout(() => document.getElementById('f_kode_barang').focus(), 100);
}

function editData(data) {
    document.getElementById('modalTitle').textContent = 'Edit Barang: ' + data.kode_barang;
    document.getElementById('f_id').value = data.id;
    document.getElementById('f_kode_barang').value = data.kode_barang || '';
    document.getElementById('f_nama_barang').value = data.nama_barang || '';
    document.getElementById('f_kategori_id').value = data.kategori_id || '';
    document.getElementById('f_merek').value = data.merek || '';
    document.getElementById('f_satuan').value = data.satuan || 'Unit';
    document.getElementById('modalForm').style.display = 'flex';
}

function closeModal() { document.getElementById('modalForm').style.display = 'none'; }
function openImport() { document.getElementById('modalImport').style.display = 'flex'; }
function closeImport() { document.getElementById('modalImport').style.display = 'none'; }

document.getElementById('modalForm').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.getElementById('modalImport').addEventListener('click', function(e) {
    if (e.target === this) closeImport();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeModal(); closeImport(); }
});
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>