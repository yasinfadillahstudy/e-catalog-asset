<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/image_helper.php';
cek_login();

// =====================================================
// KEAMANAN: VIEWER DILARANG MELAKUKAN AKSI POST (SIMPAN/HAPUS)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strtoupper(trim($_SESSION['role'] ?? '')) === 'VIEWER') {
    set_flash('error', 'Akses Ditolak: Viewer tidak diizinkan mengubah data.');
    redirect('kategori.php');
    exit;
}

// =====================================================
// HANDLE: SIMPAN BARANG (Tambah/Edit manual + foto)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simpan_barang') {
    $id             = (int)($_POST['id'] ?? 0);
    $nama_barang    = trim($_POST['nama_barang'] ?? '');
    $no_plu         = trim($_POST['no_plu'] ?? '');
    $dimensi        = trim($_POST['dimensi'] ?? '');
    $watt           = trim($_POST['watt'] ?? '');
    $deskripsi      = trim($_POST['deskripsi_umum'] ?? '');
    $guidance       = trim($_POST['guidance'] ?? '');
    $standar_tag    = trim($_POST['standar_penempelan_no_tag'] ?? '');
    $standar_qty    = trim($_POST['standar_qty_di_toko'] ?? '');
    
    $lokasi_detail  = trim($_POST['lokasi_detail'] ?? '');
    $kat_elec       = trim($_POST['kategori_electrical'] ?? '');
    $kat_eq         = trim($_POST['kategori_equipment'] ?? '');
    
    if (empty($nama_barang)) { set_flash('error', 'Nama Barang wajib diisi!'); redirect('kategori.php'); }
    
    try {
        $foto_thumb = null; $foto_medium = null;
        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT foto_thumb, foto_medium FROM aset WHERE id = ?");
            $stmt->execute([$id]); $old = $stmt->fetch();
            if ($old) { $foto_thumb = $old['foto_thumb']; $foto_medium = $old['foto_medium']; }
        }
        
        if (!empty($_FILES['foto']['tmp_name'])) {
            $result = process_image_upload($_FILES['foto']);
            if ($result['success']) {
                if ($foto_thumb) delete_image_file($foto_thumb);
                if ($foto_medium) delete_image_file($foto_medium);
                $foto_thumb = $result['thumb']; $foto_medium = $result['medium'];
            } else {
                set_flash('error', 'Upload foto gagal: ' . $result['error']); redirect('kategori.php');
            }
        }
        
        if ($id > 0) {
            $stmt = $pdo->prepare("
                UPDATE aset SET 
                    nama_barang=?, no_plu=?, dimensi=?, watt=?, deskripsi_umum=?, 
                    guidance=?, standar_penempelan_no_tag=?, standar_qty_di_toko=?,
                    lokasi_detail=?, kategori_electrical=?, kategori_equipment=?, 
                    foto_thumb=?, foto_medium=?, updated_at=NOW() 
                WHERE id=?
            ");
            $stmt->execute([
                $nama_barang, $no_plu ?: null, $dimensi ?: null, $watt ?: null, $deskripsi ?: null,
                $guidance ?: null, $standar_tag ?: null, $standar_qty ?: null,
                $lokasi_detail ?: null, $kat_elec ?: null, $kat_eq ?: null, 
                $foto_thumb, $foto_medium, $id
            ]);
            log_activity($pdo, $id, null, 'UPDATE', null, ['nama_barang' => $nama_barang, 'from' => 'kategori_menu']);
            set_flash('success', 'Barang berhasil diupdate!');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO aset 
                (nama_barang, no_plu, dimensi, watt, deskripsi_umum, guidance, standar_penempelan_no_tag, standar_qty_di_toko,
                 lokasi_detail, kategori_electrical, kategori_equipment, status, kondisi, foto_thumb, foto_medium, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'AKTIF', 'BAIK', ?, ?, ?)
            ");
            $stmt->execute([
                $nama_barang, $no_plu ?: null, $dimensi ?: null, $watt ?: null, $deskripsi ?: null,
                $guidance ?: null, $standar_tag ?: null, $standar_qty ?: null,
                $lokasi_detail ?: null, $kat_elec ?: null, $kat_eq ?: null,
                $foto_thumb, $foto_medium, $_SESSION['user_id']
            ]);
            $new_id = $pdo->lastInsertId();
            log_activity($pdo, $new_id, null, 'CREATE', null, ['nama_barang' => $nama_barang, 'from' => 'kategori_menu']);
            set_flash('success', 'Barang berhasil ditambahkan!');
        }
    } catch (Exception $e) { set_flash('error', 'Gagal simpan: ' . $e->getMessage()); }
    redirect('kategori.php');
}

// =====================================================
// HANDLE: HAPUS BARANG
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_barang') {
    $id = (int)$_POST['id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM aset WHERE id = ?"); $stmt->execute([$id]); $row = $stmt->fetch();
        if ($row) {
            delete_image_file($row['foto_thumb']); delete_image_file($row['foto_medium']);
            log_activity($pdo, $id, null, 'DELETE', $row, null);
            $pdo->prepare("DELETE FROM aset WHERE id = ?")->execute([$id]);
            set_flash('success', 'Barang berhasil dihapus!');
        }
    } catch (Exception $e) { set_flash('error', 'Gagal hapus: ' . $e->getMessage()); }
    redirect('kategori.php');
}

// =====================================================
// OPTIONS & FILTER
// =====================================================
$elec_options = $pdo->query("SELECT DISTINCT kategori_electrical FROM kategori WHERE kategori_electrical IS NOT NULL AND kategori_electrical != '' ORDER BY kategori_electrical")->fetchAll(PDO::FETCH_COLUMN);
if (empty($elec_options)) { $elec_options = ['ELECTRICAL', 'NON ELECTRICAL']; }
$equip_options = $pdo->query("SELECT DISTINCT kategori_equipment FROM kategori WHERE kategori_equipment IS NOT NULL AND kategori_equipment != '' ORDER BY kategori_equipment")->fetchAll(PDO::FETCH_COLUMN);

$elec_aset = $pdo->query("SELECT DISTINCT kategori_electrical FROM aset WHERE kategori_electrical IS NOT NULL AND kategori_electrical != '' ORDER BY kategori_electrical")->fetchAll(PDO::FETCH_COLUMN);
$equip_aset = $pdo->query("SELECT DISTINCT kategori_equipment FROM aset WHERE kategori_equipment IS NOT NULL AND kategori_equipment != '' ORDER BY kategori_equipment")->fetchAll(PDO::FETCH_COLUMN);

$elec_filter_options = array_unique(array_merge($elec_options, $elec_aset)); sort($elec_filter_options);
$equip_filter_options = array_unique(array_merge($equip_options, $equip_aset)); sort($equip_filter_options);

$search         = trim($_GET['search'] ?? '');
$filter_elec    = trim($_GET['filter_elec'] ?? '');
$filter_equip   = trim($_GET['filter_equip'] ?? '');
$filter_lokasi  = trim($_GET['filter_lokasi'] ?? '');
$sort           = $_GET['sort'] ?? 'id_desc';
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = 25;
$offset         = ($page - 1) * $per_page;

$where = []; $params = [];
if ($search)        { $where[] = "nama_barang LIKE ?";         $params[] = "%$search%"; }
if ($filter_elec)   { $where[] = "kategori_electrical = ?";    $params[] = $filter_elec; }
if ($filter_equip)  { $where[] = "kategori_equipment = ?";     $params[] = $filter_equip; }
if ($filter_lokasi) { $where[] = "lokasi_detail LIKE ?";       $params[] = "%$filter_lokasi%"; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$order_map = [ 'id_desc' => 'id DESC', 'id_asc' => 'id ASC', 'nama_asc' => 'nama_barang ASC', 'nama_desc' => 'nama_barang DESC' ];
$order_sql = $order_map[$sort] ?? 'id DESC';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM aset $where_sql"); $stmt->execute($params); $total_rows = (int)$stmt->fetchColumn();
$total_pages = ceil($total_rows / $per_page);
$stmt = $pdo->prepare("SELECT * FROM aset $where_sql ORDER BY $order_sql LIMIT $per_page OFFSET $offset"); $stmt->execute($params); $barang_list = $stmt->fetchAll();
$has_filter = $search || $filter_elec || $filter_equip || $filter_lokasi;

$page_title = 'Master Barang';
$page_icon  = 'box-seam';
require_once __DIR__ . '/includes/layout_header.php';
?>

<style>
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
    .filter-grid { display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr 1fr auto; gap: 10px; align-items: end; }
    .filter-buttons { display: flex; gap: 6px; }
    .modal-grid { display: grid; grid-template-columns: 1fr 280px; gap: 20px; align-items: start; }
    
    @media (max-width: 1100px) { .filter-grid { grid-template-columns: 1fr 1fr 1fr; } }
    @media (max-width: 768px) {
        .filter-grid { grid-template-columns: 1fr 1fr; }
        .filter-buttons { grid-column: 1 / -1; }
        .filter-buttons > * { flex: 1; justify-content: center; }
        .modal-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 500px) { .filter-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-header">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Master Barang</h2>
        <p style="font-size: 13px; color: #64748B; margin: 0;">
            Total <strong style="color: #2563EB;"><?= number_format($total_rows) ?></strong> barang terdaftar
            <?php if ($has_filter): ?><span style="color: #D97706;">(terfilter)</span><?php endif; ?>
        </p>
    </div>
    
    <?php if (strtoupper(trim($_SESSION['role'] ?? '')) !== 'VIEWER'): ?>
    <button type="button" onclick="openModalTambah()" style="padding: 10px 18px; background: #10B981; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
        <i class="bi bi-plus-circle"></i> Tambah Barang
    </button>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm mb-3" style="padding: 18px 20px;">
    <form method="GET" id="filterForm">
        <div class="filter-grid">
            <div style="position: relative;">
                <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;"><i class="bi bi-search"></i> Cari Barang</label>
                <input type="text" name="search" id="searchInputKategori" placeholder="Ketik nama barang lalu Enter..." value="<?= e($search) ?>" style="width: 100%; padding: 9px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; background: #F8FAFC;" autocomplete="off">
                <div id="searchDropdownKategori"></div>
            </div>
            
            <div>
                <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">⚡ Electrical</label>
                <select name="filter_elec" style="width: 100%; padding: 9px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; background: white;">
                    <option value="">Semua</option>
                    <?php foreach ($elec_filter_options as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filter_elec === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">🔧 Equipment</label>
                <select name="filter_equip" style="width: 100%; padding: 9px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; background: white;">
                    <option value="">Semua</option>
                    <?php foreach ($equip_filter_options as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filter_equip === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">📍 Area Penempatan</label>
                <input type="text" name="filter_lokasi" placeholder="Ketik area penempatan..." value="<?= e($filter_lokasi) ?>" style="width: 100%; padding: 9px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; background: #F8FAFC;">
            </div>
            
            <div>
                <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">↕️ Urutkan</label>
                <select name="sort" style="width: 100%; padding: 9px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; background: white;">
                    <option value="id_desc"   <?= $sort === 'id_desc'   ? 'selected' : '' ?>>Terbaru</option>
                    <option value="id_asc"    <?= $sort === 'id_asc'    ? 'selected' : '' ?>>Terlama</option>
                    <option value="nama_asc"  <?= $sort === 'nama_asc'  ? 'selected' : '' ?>>Nama A → Z</option>
                    <option value="nama_desc" <?= $sort === 'nama_desc' ? 'selected' : '' ?>>Nama Z → A</option>
                </select>
            </div>
            
            <div class="filter-buttons">
                <button type="submit" style="padding: 9px 16px; background: #2563EB; color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                    <i class="bi bi-funnel-fill"></i> Filter
                </button>
                <?php if ($has_filter || $sort !== 'id_desc'): ?>
                    <a href="kategori.php" style="padding: 9px 14px; background: #FEE2E2; color: #991B1B; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                        <i class="bi bi-x-circle"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <?php if (empty($barang_list)): ?>
        <div style="text-align: center; padding: 80px 20px; color: #94A3B8;">
            <i class="bi bi-inbox" style="font-size: 56px; display: block; margin-bottom: 12px; color: #CBD5E1;"></i>
            <h4 style="color: #475569; font-size: 15px; margin: 0 0 6px;"><?= $has_filter ? 'Tidak Ada Hasil' : 'Belum Ada Barang' ?></h4>
            <p style="font-size: 13px; margin: 0 0 16px;"><?= $has_filter ? 'Coba ubah atau reset filter' : 'Klik tombol "Tambah Barang" untuk mulai' ?></p>
        </div>
    <?php else: ?>
        <div class="table-responsive" style="-webkit-overflow-scrolling: touch;">
            <table class="table table-hover mb-0" style="font-size: 13px; min-width: 800px;">
                <thead style="background: #F8FAFC;">
                    <tr>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; width: 80px; text-align: center;">Foto</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Nama Barang</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Kategori Electrical</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Kategori Equipment</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Area Penempatan</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center; width: 180px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($barang_list as $b): ?>
                    <tr>
                        <td style="padding: 8px 14px; text-align: center;">
                            <?php if ($b['foto_thumb']): ?>
                                <img src="<?= e(foto_url($b['foto_thumb'])) ?>" alt="" style="width: 56px; height: 56px; object-fit: cover; border-radius: 8px; border: 1px solid #E2E8F0;">
                            <?php else: ?>
                                <div style="width: 56px; height: 56px; background: #F1F5F9; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #CBD5E1;"><i class="bi bi-image"></i></div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; font-weight: 600; color: #0F172A; font-size: 13.5px;"><?= e($b['nama_barang']) ?></td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <?php if (!empty($b['kategori_electrical'])): ?>
                                <?php if ($b['kategori_electrical'] === 'ELECTRICAL'): ?>
                                    <span style="padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #FEF3C7; color: #92400E;">⚡ ELECTRICAL</span>
                                <?php else: ?>
                                    <span style="padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #F1F5F9; color: #64748B;"><?= e($b['kategori_electrical']) ?></span>
                                <?php endif; ?>
                            <?php else: ?><span style="color: #CBD5E1;">-</span><?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <?php if (!empty($b['kategori_equipment'])): ?>
                                <span style="padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #E0E7FF; color: #3730A3;"><?= e($b['kategori_equipment']) ?></span>
                            <?php else: ?><span style="color: #CBD5E1;">-</span><?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; color: #64748B; font-size: 12.5px;"><?= e($b['lokasi_detail'] ?: '-') ?></td>
                        <td style="padding: 13px 14px; text-align: center; white-space: nowrap;">
                            
                            <!-- ✅ PERBAIKAN: Hapus target="_blank" & Tambahkan &from=kategori -->
                            <a href="aset_detail.php?id=<?= $b['id'] ?>&from=kategori" style="padding: 6px 11px; background: #F5F3FF; color: #7C3AED; border-radius: 6px; font-size: 11.5px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="bi bi-eye"></i>
                            </a>
                            
                            <?php if (strtoupper(trim($_SESSION['role'] ?? '')) !== 'VIEWER'): ?>
                                <button type="button" onclick='openModalEdit(<?= json_encode($b) ?>)' style="padding: 6px 11px; background: #DBEAFE; color: #1E40AF; border: none; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin hapus barang ini?');">
                                    <input type="hidden" name="action" value="hapus_barang">
                                    <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                    <button type="submit" style="padding: 6px 11px; background: #FEE2E2; color: #991B1B; border: none; border-radius: 6px; font-size: 11.5px; font-weight: 600; cursor: pointer;">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($total_pages > 1): ?>
<div style="margin-top: 20px; padding: 16px 20px; background: white; border-radius: 10px; border: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
    <div style="font-size: 12.5px; color: #64748B;">Halaman <strong><?= $page ?></strong> dari <strong><?= $total_pages ?></strong></div>
    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
        <?php
        $qs = $_GET; $start = max(1, $page - 2); $end = min($total_pages, $page + 2); $qs['page'] = max(1, $page - 1);
        ?>
        <a href="?<?= http_build_query($qs) ?>" style="padding: 6px 12px; border: 1px solid #E2E8F0; border-radius: 6px; background: white; color: #475569; text-decoration: none; font-size: 12.5px; font-weight: 600; <?= $page <= 1 ? 'opacity:0.4; pointer-events:none;' : '' ?>">◀ Prev</a>
        <?php for ($i = $start; $i <= $end; $i++): $qs['page'] = $i; ?>
            <a href="?<?= http_build_query($qs) ?>" style="padding: 6px 12px; border: 1px solid <?= $i == $page ? '#2563EB' : '#E2E8F0' ?>; border-radius: 6px; background: <?= $i == $page ? '#2563EB' : 'white' ?>; color: <?= $i == $page ? 'white' : '#475569' ?>; text-decoration: none; font-size: 12.5px; font-weight: 600;"><?= $i ?></a>
        <?php endfor; ?>
        <?php $qs['page'] = min($total_pages, $page + 1); ?>
        <a href="?<?= http_build_query($qs) ?>" style="padding: 6px 12px; border: 1px solid #E2E8F0; border-radius: 6px; background: white; color: #475569; text-decoration: none; font-size: 12.5px; font-weight: 600; <?= $page >= $total_pages ? 'opacity:0.4; pointer-events:none;' : '' ?>">Next ▶</a>
    </div>
</div>
<?php endif; ?>

<?php if (strtoupper(trim($_SESSION['role'] ?? '')) !== 'VIEWER'): ?>
<div id="modalBarang" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: white; border-radius: 12px; max-width: 800px; width: 100%; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: white; z-index: 10;">
            <h3 id="modalTitle" style="font-size: 15px; font-weight: 700; color: #0F172A; margin: 0;">Tambah Barang</h3>
            <button type="button" onclick="closeModal()" style="background: none; border: none; font-size: 20px; color: #94A3B8; cursor: pointer;">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="simpan_barang">
            <input type="hidden" name="id" id="formId" value="0">
            <div style="padding: 24px;">
                <div class="modal-grid">
                    <!-- KOLOM KIRI -->
                    <div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Nama Barang <span style="color: #DC2626;">*</span></label>
                            <input type="text" name="nama_barang" id="formNama" required style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;" placeholder="Contoh: AC Split 1PK">
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">No PLU</label>
                                <input type="text" name="no_plu" id="formPlu" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;" placeholder="Contoh: 12345678">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Dimensi</label>
                                <input type="text" name="dimensi" id="formDimensi" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;" placeholder="Contoh: 50x30x20 cm">
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Watt</label>
                                <input type="text" name="watt" id="formWatt" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;" placeholder="Contoh: 400 W">
                            </div>
                            <div>
                                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Standar Qty di Toko</label>
                                <input type="text" name="standar_qty_di_toko" id="formQty" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;" placeholder="Contoh: 5 Unit">
                            </div>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Area Penempatan</label>
                            <input type="text" name="lokasi_detail" id="formLokasi" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;" placeholder="Contoh: Ruang Server Lantai 2">
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Kategori Electrical</label>
                                <select name="kategori_electrical" id="formElec" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;">
                                    <option value="">-- Pilih --</option>
                                    <?php foreach ($elec_options as $opt): ?>
                                        <option value="<?= e($opt) ?>"><?= e($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div style="position: relative;">
                                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Kategori Equipment</label>
                                <input type="text" name="kategori_equipment" id="formEquip" autocomplete="off" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none;" placeholder="Ketik atau tekan Enter...">
                                <div id="equipDropdown" style="display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; background:white; border:1px solid #E2E8F0; border-radius:8px; box-shadow:0 5px 15px rgba(0,0,0,0.1); z-index:1000; max-height:200px; overflow-y:auto; padding:4px;"></div>
                            </div>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Deskripsi Umum</label>
                            <textarea name="deskripsi_umum" id="formDeskripsi" rows="2" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none; resize: vertical;" placeholder="Deskripsi singkat barang..."></textarea>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Guidance</label>
                            <textarea name="guidance" id="formGuidance" rows="2" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none; resize: vertical;" placeholder="Panduan penggunaan/peletakan..."></textarea>
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 6px;">Standar Penempelan No Tag</label>
                            <textarea name="standar_penempelan_no_tag" id="formStandarTag" rows="2" style="width: 100%; padding: 11px 14px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 14px; outline: none; resize: vertical;" placeholder="Contoh: Tempel di bagian belakang mesin..."></textarea>
                        </div>

                    </div>
                    
                    <!-- KOLOM KANAN -->
                    <div>
                        <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 8px;"><i class="bi bi-camera"></i> Foto Barang</label>
                        <div id="previewWrap" style="margin-bottom: 10px;">
                            <div id="previewPlaceholder" style="width: 100%; aspect-ratio: 1; background: #F8FAFC; border: 2px dashed #E2E8F0; border-radius: 10px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #94A3B8;">
                                <i class="bi bi-camera" style="font-size: 40px; margin-bottom: 6px;"></i>
                                <div style="font-size: 12px;">Belum ada foto</div>
                            </div>
                            <img id="previewImg" style="width: 100%; border-radius: 10px; border: 1px solid #E2E8F0; display: none;">
                        </div>
                        <input type="file" name="foto" id="formFoto" accept="image/jpeg,image/jpg,image/png,image/webp" capture="environment" style="width: 100%; padding: 10px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 12.5px;">
                        <div style="background: #EFF6FF; border: 1px solid #BFDBFE; padding: 10px 12px; border-radius: 8px; margin-top: 10px; font-size: 11px; color: #1E40AF; line-height: 1.6;">
                            <i class="bi bi-info-circle-fill"></i> Format: <strong>JPG/PNG/WebP</strong> · Max 10 MB
                        </div>
                    </div>
                </div>
            </div>
            <div style="padding: 16px 24px; border-top: 1px solid #F1F5F9; display: flex; justify-content: flex-end; gap: 10px; background: white; position: sticky; bottom: 0;">
                <button type="button" onclick="closeModal()" style="padding: 10px 20px; border: 1px solid #E2E8F0; background: white; border-radius: 8px; font-weight: 600; color: #475569; font-size: 13px; cursor: pointer;">Batal</button>
                <button type="submit" style="padding: 10px 24px; background: #2563EB; color: white; border: none; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer;"><i class="bi bi-save"></i> Simpan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
<?php if (strtoupper(trim($_SESSION['role'] ?? '')) !== 'VIEWER'): ?>
const modal = document.getElementById('modalBarang');
function closeModal() { modal.style.display = 'none'; }
modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });

function resetForm() {
    document.getElementById('formId').value = 0;
    document.getElementById('formNama').value = '';
    document.getElementById('formPlu').value = '';
    document.getElementById('formDimensi').value = '';
    document.getElementById('formWatt').value = '';
    document.getElementById('formQty').value = '';
    document.getElementById('formLokasi').value = '';
    document.getElementById('formElec').value = '';
    document.getElementById('formEquip').value = '';
    document.getElementById('formDeskripsi').value = '';
    document.getElementById('formGuidance').value = '';
    document.getElementById('formStandarTag').value = '';
    document.getElementById('formFoto').value = '';
    document.getElementById('previewImg').style.display = 'none';
    document.getElementById('previewImg').src = '';
    document.getElementById('previewPlaceholder').style.display = 'flex';
    document.getElementById('equipDropdown').style.display = 'none';
}

function openModalTambah() {
    resetForm();
    document.getElementById('modalTitle').textContent = 'Tambah Barang';
    modal.style.display = 'flex';
}

function openModalEdit(data) {
    resetForm();
    document.getElementById('modalTitle').textContent = 'Edit Barang';
    document.getElementById('formId').value = data.id;
    document.getElementById('formNama').value = data.nama_barang || '';
    document.getElementById('formPlu').value = data.no_plu || '';
    document.getElementById('formDimensi').value = data.dimensi || '';
    document.getElementById('formWatt').value = data.watt || '';
    document.getElementById('formQty').value = data.standar_qty_di_toko || '';
    document.getElementById('formLokasi').value = data.lokasi_detail || '';
    document.getElementById('formElec').value = data.kategori_electrical || '';
    document.getElementById('formEquip').value = data.kategori_equipment || '';
    document.getElementById('formDeskripsi').value = data.deskripsi_umum || '';
    document.getElementById('formGuidance').value = data.guidance || '';
    document.getElementById('formStandarTag').value = data.standar_penempelan_no_tag || '';
    
    if (data.foto_medium) {
        const img = document.getElementById('previewImg');
        img.src = '<?= APP_URL ?>/public/uploads/' + data.foto_medium;
        img.style.display = 'block';
        document.getElementById('previewPlaceholder').style.display = 'none';
    }
    modal.style.display = 'flex';
}

document.getElementById('formFoto').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(ev) {
        const img = document.getElementById('previewImg');
        img.src = ev.target.result;
        img.style.display = 'block';
        document.getElementById('previewPlaceholder').style.display = 'none';
    };
    reader.readAsDataURL(file);
});

// AUTOCOMPLETE KATEGORI EQUIPMENT
const equipOptions = <?= json_encode($equip_filter_options) ?>;
const equipInput = document.getElementById('formEquip');
const equipDropdown = document.getElementById('equipDropdown');
let equipFocus = -1;

function renderEquipDropdown(val, showAll = false) {
    equipDropdown.innerHTML = '';
    equipFocus = -1;
    if ((!val || val.length < 2) && !showAll) { equipDropdown.style.display = 'none'; return; }

    let matches = equipOptions;
    if (val && val.length >= 1) {
        matches = equipOptions.filter(opt => opt.toLowerCase().includes(val.toLowerCase()));
    }

    if (matches.length === 0) { equipDropdown.style.display = 'none'; return; }

    matches.forEach((match) => {
        const div = document.createElement('div');
        div.textContent = match;
        div.style.padding = '8px 12px';
        div.style.cursor = 'pointer';
        div.style.fontSize = '13.5px';
        div.style.borderBottom = '1px solid #F1F5F9';
        div.addEventListener('mouseover', function() { this.style.background = '#F8FAFC'; });
        div.addEventListener('mouseout', function() { this.style.background = 'transparent'; });
        div.addEventListener('click', function() { equipInput.value = this.innerText; equipDropdown.style.display = 'none'; });
        equipDropdown.appendChild(div);
    });
    equipDropdown.style.display = 'block';
}

equipInput.addEventListener('input', function() { renderEquipDropdown(this.value); });
equipInput.addEventListener('keydown', function(e) {
    const items = equipDropdown.querySelectorAll('div');
    if (e.key === 'ArrowDown') {
        e.preventDefault(); if (equipDropdown.style.display === 'none') renderEquipDropdown(this.value, true);
        equipFocus++; addEquipActive(items);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault(); if (equipDropdown.style.display === 'none') renderEquipDropdown(this.value, true);
        equipFocus--; addEquipActive(items);
    } else if (e.key === 'Enter') {
        if (equipFocus > -1 && items[equipFocus]) {
            e.preventDefault(); equipInput.value = items[equipFocus].innerText; equipDropdown.style.display = 'none';
        } else {
            e.preventDefault(); renderEquipDropdown(this.value, true); 
        }
    } else if (e.key === 'Escape') {
        equipDropdown.style.display = 'none'; equipFocus = -1;
    }
});

function addEquipActive(items) {
    if (!items) return false;
    removeEquipActive(items);
    if (equipFocus >= items.length) equipFocus = 0;
    if (equipFocus < 0) equipFocus = (items.length - 1);
    items[equipFocus].style.background = '#EFF6FF';
    items[equipFocus].style.color = '#2563EB';
    items[equipFocus].style.fontWeight = '600';
}
function removeEquipActive(items) {
    for (let i = 0; i < items.length; i++) {
        items[i].style.background = 'transparent';
        items[i].style.color = 'inherit';
        items[i].style.fontWeight = 'normal';
    }
}
document.addEventListener('click', function(e) {
    if (e.target !== equipInput) equipDropdown.style.display = 'none';
});
<?php endif; ?>

// AUTOCOMPLETE SEARCH PENCARIAN (Tabel)
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInputKategori');
    const dropdown    = document.getElementById('searchDropdownKategori');
    if (!searchInput || !dropdown) return;
    let debounceTimer, selectedIndex = -1;

    dropdown.style.cssText = `position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: white; border: 1px solid #E2E8F0; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); z-index: 9999; display: none; max-height: 320px; overflow-y: auto; padding: 6px;`;
    function escapeHtml(t) { return t ? t.replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])) : ''; }

    function renderResults(data) {
        selectedIndex = -1;
        if (!data || data.length === 0) {
            dropdown.innerHTML = `<div style="padding: 24px 16px; text-align: center; color: #94A3B8; font-size: 13px;"><i class="bi bi-search" style="font-size: 24px; display: block; margin-bottom: 6px;"></i>Tidak ada barang yang cocok</div>`;
            dropdown.style.display = 'block';
            return;
        }
        dropdown.innerHTML = data.map((item, i) => `
            <div class="search-item" data-index="${i}" data-id="${item.id}" style="padding: 10px 12px; cursor: pointer; border-radius: 8px; display: flex; align-items: center; gap: 12px; transition: background 0.1s;">
                <div style="width: 36px; height: 36px; background: #EFF6FF; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #2563EB; flex-shrink: 0;"><i class="bi bi-box-seam" style="font-size: 15px;"></i></div>
                <div style="flex: 1; min-width: 0;">
                    <div style="font-size: 13px; font-weight: 600; color: #0F172A; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(item.nama_barang)}</div>
                    <div style="font-size: 11px; color: #64748B; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${item.kategori_electrical ? escapeHtml(item.kategori_electrical) : '-'} · ${item.kategori_equipment ? escapeHtml(item.kategori_equipment) : '-'}</div>
                </div>
            </div>
        `).join('');
        dropdown.style.display = 'block';

        dropdown.querySelectorAll('.search-item').forEach(el => {
            el.addEventListener('click', () => { window.location.href = 'aset_detail.php?id=' + el.dataset.id + '&from=kategori'; });
            el.addEventListener('mouseenter', () => {
                dropdown.querySelectorAll('.search-item').forEach(x => x.style.background = 'transparent');
                el.style.background = '#F8FAFC';
            });
        });
    }

    function fetchSearch(q) {
        fetch('api_search.php?q=' + encodeURIComponent(q))
            .then(r => r.json()).then(data => renderResults(data)).catch(() => dropdown.style.display = 'none');
    }

    searchInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        const q = this.value.trim();
        if (q.length < 2) { dropdown.style.display = 'none'; return; }
        debounceTimer = setTimeout(() => fetchSearch(q), 300);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const items = dropdown.querySelectorAll('.search-item');
            if (selectedIndex >= 0 && items[selectedIndex]) {
                e.preventDefault(); window.location.href = 'aset_detail.php?id=' + items[selectedIndex].dataset.id + '&from=kategori'; return;
            }
            e.preventDefault(); clearTimeout(debounceTimer); fetchSearch(this.value.trim());
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none'; selectedIndex = -1;
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const items = dropdown.querySelectorAll('.search-item');
            if (!items.length) return;
            selectedIndex = e.key === 'ArrowDown' ? Math.min(selectedIndex + 1, items.length - 1) : Math.max(selectedIndex - 1, 0);
            items.forEach((el, i) => { el.style.background = i === selectedIndex ? '#EFF6FF' : 'transparent'; });
            if (items[selectedIndex]) items[selectedIndex].scrollIntoView({ block: 'nearest' });
        }
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) { dropdown.style.display = 'none'; selectedIndex = -1; }
    });
});
</script>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>