<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
require_once __DIR__ . '/config/image_helper.php';
cek_login();

$search         = trim($_GET['search'] ?? '');
$filter_elec    = trim($_GET['filter_elec'] ?? '');
$filter_equip   = trim($_GET['filter_equip'] ?? '');
$filter_lokasi  = trim($_GET['filter_lokasi'] ?? '');
$sort           = $_GET['sort'] ?? 'id_desc';
$view           = $_GET['view'] ?? 'grid';
$page           = max(1, (int)($_GET['page'] ?? 1));
$per_page       = $view === 'grid' ? 24 : 25;
$offset         = ($page - 1) * $per_page;

$where = []; $params = [];
if ($search)        { $where[] = "a.nama_barang LIKE ?";      $params[] = "%$search%"; }
if ($filter_elec)   { $where[] = "a.kategori_electrical = ?"; $params[] = $filter_elec; }
if ($filter_equip)  { $where[] = "a.kategori_equipment = ?";  $params[] = $filter_equip; }
if ($filter_lokasi) { $where[] = "a.lokasi_detail LIKE ?";    $params[] = "%$filter_lokasi%"; }

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$order_map = ['id_desc'=>'a.id DESC','id_asc'=>'a.id ASC','nama_asc'=>'a.nama_barang ASC','nama_desc'=>'a.nama_barang DESC'];
$order_sql = $order_map[$sort] ?? 'a.id DESC';

$stmt = $pdo->prepare("SELECT COUNT(*) FROM aset a $where_sql"); $stmt->execute($params); $total_rows = (int)$stmt->fetchColumn();
$total_pages = ceil($total_rows / $per_page);
$stmt = $pdo->prepare("SELECT a.* FROM aset a $where_sql ORDER BY $order_sql LIMIT $per_page OFFSET $offset"); $stmt->execute($params); $aset_list = $stmt->fetchAll();
$has_filter = $search || $filter_elec || $filter_equip || $filter_lokasi;

$elec_options = $pdo->query("SELECT DISTINCT kategori_electrical FROM aset WHERE kategori_electrical IS NOT NULL AND kategori_electrical != '' ORDER BY kategori_electrical")->fetchAll(PDO::FETCH_COLUMN);
$equip_options = $pdo->query("SELECT DISTINCT kategori_equipment FROM aset WHERE kategori_equipment IS NOT NULL AND kategori_equipment != '' ORDER BY kategori_equipment")->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Katalog Aset';
$page_icon  = 'grid-3x3-gap';
require_once __DIR__ . '/includes/layout_header.php';
?>

<style>
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
    .view-toggle { display: inline-flex; border: 1px solid #E2E8F0; border-radius: 8px; overflow: hidden; background: white; }
    .filter-grid { display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr 1fr auto; gap: 10px; align-items: end; }
    .filter-buttons { display: flex; gap: 6px; }
    
    @media (max-width: 1100px) { .filter-grid { grid-template-columns: 1fr 1fr 1fr; } }
    @media (max-width: 768px) {
        .filter-grid { grid-template-columns: 1fr 1fr; }
        .filter-buttons { grid-column: 1 / -1; }
        .filter-buttons > * { flex: 1; justify-content: center; }
    }
    @media (max-width: 500px) {
        .filter-grid { grid-template-columns: 1fr; }
        .view-toggle { width: 100%; justify-content: center; }
    }
</style>

<div class="page-header">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Katalog Aset</h2>
        <p style="font-size: 13px; color: #64748B; margin: 0;">
            Total <strong style="color: #2563EB;"><?= number_format($total_rows) ?></strong> aset terdaftar
            <?php if ($has_filter): ?><span style="color: #D97706;">(terfilter)</span><?php endif; ?>
        </p>
    </div>
    <div class="view-toggle">
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'grid'])) ?>" 
           style="padding: 9px 14px; text-decoration: none; font-size: 13px; font-weight: 600; <?= $view === 'grid' ? 'background: #2563EB; color: white;' : 'color: #64748B;' ?>">
            <i class="bi bi-grid-3x3-gap-fill"></i> Grid
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'list'])) ?>" 
           style="padding: 9px 14px; text-decoration: none; font-size: 13px; font-weight: 600; <?= $view === 'list' ? 'background: #2563EB; color: white;' : 'color: #64748B;' ?>">
            <i class="bi bi-list-ul"></i> List
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3" style="padding: 18px 20px;">
    <form method="GET" id="filterFormAset">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        
        <div class="filter-grid">
            <div style="position: relative;">
                <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;"><i class="bi bi-search"></i> Cari Nama Barang</label>
                <input type="text" name="search" id="searchInputAset" placeholder="Ketik nama barang lalu Enter..." value="<?= e($search) ?>" style="width: 100%; padding: 9px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; background: #F8FAFC;" autocomplete="off">
                <div id="searchDropdownAset"></div>
            </div>
            
            <div>
                <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">⚡ Electrical</label>
                <select name="filter_elec" style="width: 100%; padding: 9px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; background: white;">
                    <option value="">Semua</option>
                    <?php foreach ($elec_options as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filter_elec === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label style="display: block; font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 5px;">🔧 Equipment</label>
                <select name="filter_equip" style="width: 100%; padding: 9px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; outline: none; background: white;">
                    <option value="">Semua</option>
                    <?php foreach ($equip_options as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $filter_equip === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- PERUBAHAN LABEL DI SINI -->
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
                    <a href="aset.php?view=<?= e($view) ?>" style="padding: 9px 14px; background: #FEE2E2; color: #991B1B; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;">
                        <i class="bi bi-x-circle"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<?php if (empty($aset_list)): ?>
    <div class="card border-0 shadow-sm">
        <div style="text-align: center; padding: 80px 20px; color: #94A3B8;">
            <i class="bi bi-inbox" style="font-size: 56px; display: block; margin-bottom: 12px; color: #CBD5E1;"></i>
            <h4 style="color: #475569; font-size: 15px; margin: 0 0 6px;"><?= $has_filter ? 'Tidak Ada Hasil' : 'Belum Ada Aset' ?></h4>
            <p style="font-size: 13px; margin: 0;"><?= $has_filter ? 'Coba ubah atau reset filter' : 'Upload aset via menu Upload & Import' ?></p>
        </div>
    </div>

<?php elseif ($view === 'grid'): ?>
    <div class="aset-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px;">
        <?php foreach ($aset_list as $a): ?>
        <a href="aset_detail.php?id=<?= $a['id'] ?>" style="text-decoration: none; color: inherit;">
            <div class="card border-0 shadow-sm h-100" style="overflow: hidden; transition: 0.15s; cursor: pointer;" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 20px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='';this.style.boxShadow='';">
                <div style="position: relative; padding-top: 100%; background: #F1F5F9; overflow: hidden;">
                    <?php if ($a['foto_thumb']): ?>
                        <img src="<?= e(foto_url($a['foto_thumb'])) ?>" alt="<?= e($a['nama_barang']) ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;">
                    <?php else: ?>
                        <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #CBD5E1;">
                            <i class="bi bi-image" style="font-size: 40px;"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="padding: 14px 16px;">
                    <div style="font-weight: 600; color: #0F172A; font-size: 13.5px; line-height: 1.4; text-align: center; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 10px;">
                        <?= e($a['nama_barang']) ?>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;">
                        <?php if (!empty($a['kategori_electrical'])): ?>
                            <?php if ($a['kategori_electrical'] === 'ELECTRICAL'): ?>
                                <span style="font-size: 9.5px; font-weight: 700; padding: 3px 8px; border-radius: 10px; background: #FEF3C7; color: #92400E;">⚡ ELECTRICAL</span>
                            <?php else: ?>
                                <span style="font-size: 9.5px; font-weight: 700; padding: 3px 8px; border-radius: 10px; background: #F1F5F9; color: #64748B;"><?= e($a['kategori_electrical']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($a['kategori_equipment'])): ?>
                            <span style="font-size: 9.5px; font-weight: 700; padding: 3px 8px; border-radius: 10px; background: #E0E7FF; color: #3730A3;"><?= e($a['kategori_equipment']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

<?php else: ?>
    <div class="card border-0 shadow-sm">
        <div class="table-responsive" style="-webkit-overflow-scrolling: touch;">
            <table class="table table-hover mb-0" style="font-size: 13px; min-width: 800px;">
                <thead style="background: #F8FAFC;">
                    <tr>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; width: 80px; text-align: center;">Foto</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Nama Barang</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Kategori Electrical</th>
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center;">Kategori Equipment</th>
                        
                        <!-- PERUBAHAN HEADER TABEL -->
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700;">Area Penempatan</th>
                        
                        <th style="padding: 13px 14px; font-size: 11px; text-transform: uppercase; color: #475569; font-weight: 700; text-align: center; width: 120px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($aset_list as $a): ?>
                    <tr>
                        <td style="padding: 8px 14px; text-align: center;">
                            <?php if ($a['foto_thumb']): ?>
                                <img src="<?= e(foto_url($a['foto_thumb'])) ?>" alt="" style="width: 56px; height: 56px; object-fit: cover; border-radius: 8px; border: 1px solid #E2E8F0;">
                            <?php else: ?>
                                <div style="width: 56px; height: 56px; background: #F1F5F9; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; color: #CBD5E1;">
                                    <i class="bi bi-image"></i>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; font-weight: 600; color: #0F172A; font-size: 13.5px;"><?= e($a['nama_barang']) ?></td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <?php if (!empty($a['kategori_electrical'])): ?>
                                <?php if ($a['kategori_electrical'] === 'ELECTRICAL'): ?>
                                    <span style="padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #FEF3C7; color: #92400E;">⚡ ELECTRICAL</span>
                                <?php else: ?>
                                    <span style="padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #F1F5F9; color: #64748B;"><?= e($a['kategori_electrical']) ?></span>
                                <?php endif; ?>
                            <?php else: ?><span style="color: #CBD5E1;">-</span><?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; text-align: center;">
                            <?php if (!empty($a['kategori_equipment'])): ?>
                                <span style="padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #E0E7FF; color: #3730A3;"><?= e($a['kategori_equipment']) ?></span>
                            <?php else: ?><span style="color: #CBD5E1;">-</span><?php endif; ?>
                        </td>
                        <td style="padding: 13px 14px; color: #64748B; font-size: 12.5px;"><?= e($a['lokasi_detail'] ?: '-') ?></td>
                        <td style="padding: 13px 14px; text-align: center; white-space: nowrap;">
                            <a href="aset_detail.php?id=<?= $a['id'] ?>" style="padding: 6px 14px; background: #F5F3FF; color: #7C3AED; border-radius: 6px; font-size: 11.5px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="bi bi-eye"></i> Detail
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

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

<script>
// AUTOCOMPLETE SEARCH
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInputAset');
    const dropdown    = document.getElementById('searchDropdownAset');
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
            el.addEventListener('click', () => { window.location.href = 'aset_detail.php?id=' + el.dataset.id; });
            el.addEventListener('mouseenter', () => {
                dropdown.querySelectorAll('.search-item').forEach(x => x.style.background = 'transparent');
                el.style.background = '#F8FAFC';
            });
        });
    }

    function fetchSearch(q) {
        fetch('api_search.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => renderResults(data))
            .catch(() => dropdown.style.display = 'none');
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
                e.preventDefault(); window.location.href = 'aset_detail.php?id=' + items[selectedIndex].dataset.id; return;
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