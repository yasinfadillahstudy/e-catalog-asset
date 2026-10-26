<?php
session_start();
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helper.php';
cek_login();

$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file_csv']['tmp_name'])) {
    $tmp = $_FILES['file_csv']['tmp_name'];
    $content = file_get_contents($tmp);
    
    // Buang BOM UTF-8
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") $content = substr($content, 3);
    
    $lines = preg_split('/\r\n|\r|\n/', $content);
    if (count($lines) < 2) {
        $error = 'File kosong atau tidak ada data.';
    } else {
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
        
        $find_col = function($names) use ($header) {
            foreach ($names as $n) {
                $idx = array_search(strtoupper($n), $header);
                if ($idx !== false) return $idx;
            }
            return false;
        };
        
        $idx = [
            'no_tag'          => $find_col(['NO TAG', 'NO_TAG', 'NOTAG']),
            'kode_uu'         => $find_col(['KODE UU', 'KODE_UU', 'KODE UU CODE']),
            'descp'           => $find_col(['DESCP', 'NAMA BARANG', 'NAMA_BARANG', 'NAMA']),
            'kategori'        => $find_col(['KATEGORI', 'KATEGORI ELECTRICAL']),
            'merek'           => $find_col(['MEREK', 'MERK']),
            'lokasi'          => $find_col(['LOKASI', 'LOKASI DETAIL']),
            'tgl_perolehan'   => $find_col(['TGL PEROLEHAN', 'TANGGAL PEROLEHAN', 'TGL_PEROLEHAN']),
            'harga'           => $find_col(['HARGA PEROLEHAN', 'HARGA', 'NILAI PEROLEHAN']),
            'kondisi'         => $find_col(['KONDISI']),
        ];
        
        if ($idx['no_tag'] === false || $idx['kode_uu'] === false || $idx['descp'] === false) {
            $error = 'Kolom wajib: NO TAG, KODE UU, dan DESCP (atau NAMA BARANG)';
        } else {
            // Cache toko & kategori & barang
            $cache_toko = [];
            $stmt = $pdo->query("SELECT kode_uu, id FROM toko");
            while ($r = $stmt->fetch()) $cache_toko[strtoupper($r['kode_uu'])] = $r['id'];
            
            $cache_kat = [];
            $stmt = $pdo->query("SELECT id, UPPER(nama_kategori) AS n FROM kategori");
            while ($r = $stmt->fetch()) $cache_kat[$r['n']] = $r['id'];
            
            $cache_barang = [];
            $stmt = $pdo->query("SELECT id, UPPER(nama_barang) AS n, kategori_id FROM barang");
            while ($r = $stmt->fetch()) $cache_barang[$r['n']] = $r;
            
            $stmt_kat_ins = $pdo->prepare("INSERT INTO kategori (nama_kategori) VALUES (?)");
            $stmt_bar_ins = $pdo->prepare("INSERT INTO barang (kode_barang, nama_barang, kategori_id) VALUES (?, ?, ?)");
            
            $stmt_ins = $pdo->prepare("
                INSERT INTO aset (no_tag, kode_uu, barang_id, nama_barang, kategori_id, status, kondisi, 
                                  lokasi_detail, tgl_perolehan, harga_perolehan, created_by)
                VALUES (?, ?, ?, ?, ?, 'AKTIF', ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    kode_uu = VALUES(kode_uu),
                    nama_barang = VALUES(nama_barang),
                    kategori_id = VALUES(kategori_id),
                    kondisi = VALUES(kondisi),
                    lokasi_detail = VALUES(lokasi_detail),
                    tgl_perolehan = VALUES(tgl_perolehan),
                    harga_perolehan = VALUES(harga_perolehan),
                    updated_at = NOW()
            ");
            
            $sukses = 0; $skip = 0; $failed = [];
            $new_kat = 0; $new_barang = 0;
            
            $user_id = $_SESSION['user_id'];
            
            foreach (array_slice($lines, 1) as $line_num => $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $d = explode($delim, $line);
                $no_tag  = trim($d[$idx['no_tag']] ?? '');
                $kode_uu = trim($d[$idx['kode_uu']] ?? '');
                $descp   = trim($d[$idx['descp']] ?? '');
                
                $row_num = $line_num + 2;
                
                if (empty($no_tag) || empty($kode_uu) || empty($descp)) {
                    $skip++;
                    $failed[] = ['row' => $row_num, 'alasan' => 'Data kosong'];
                    continue;
                }
                
                // Cek toko
                $kode_upper = strtoupper($kode_uu);
                if (!isset($cache_toko[$kode_upper])) {
                    $skip++;
                    $failed[] = ['row' => $row_num, 'alasan' => "Kode UU '$kode_uu' tidak terdaftar"];
                    continue;
                }
                
                // Cek kategori (auto-create kalau ada nama & belum ada)
                $kat_id = null;
                $kat_nama = $idx['kategori'] !== false ? trim($d[$idx['kategori']] ?? '') : '';
                if ($kat_nama) {
                    $kat_upper = strtoupper($kat_nama);
                    if (isset($cache_kat[$kat_upper])) {
                        $kat_id = $cache_kat[$kat_upper];
                    } else {
                        try {
                            $stmt_kat_ins->execute([$kat_nama]);
                            $kat_id = $pdo->lastInsertId();
                            $cache_kat[$kat_upper] = $kat_id;
                            $new_kat++;
                        } catch (Exception $e) {}
                    }
                }
                
                // Cek barang (auto-create kalau ada descp)
                $barang_id = null;
                $descp_upper = strtoupper($descp);
                if (isset($cache_barang[$descp_upper])) {
                    $barang_id = $cache_barang[$descp_upper]['id'];
                    if (!$kat_id) $kat_id = $cache_barang[$descp_upper]['kategori_id'];
                } else {
                    try {
                        $kode_bar = 'IMP-' . strtoupper(substr(md5($descp), 0, 6));
                        $stmt_bar_ins->execute([$kode_bar, $descp, $kat_id]);
                        $barang_id = $pdo->lastInsertId();
                        $cache_barang[$descp_upper] = ['id' => $barang_id, 'kategori_id' => $kat_id];
                        $new_barang++;
                    } catch (Exception $e) {}
                }
                
                $merek = $idx['merek'] !== false ? trim($d[$idx['merek']] ?? '') : '';
                $lokasi = $idx['lokasi'] !== false ? trim($d[$idx['lokasi']] ?? '') : '';
                $tgl = $idx['tgl_perolehan'] !== false ? trim($d[$idx['tgl_perolehan']] ?? '') : '';
                $harga = $idx['harga'] !== false ? (float)str_replace(['.', ','], ['', '.'], $d[$idx['harga']] ?? 0) : 0;
                $kondisi = $idx['kondisi'] !== false ? trim($d[$idx['kondisi']] ?? 'BAIK') : 'BAIK';
                
                // Normalize tanggal
                if ($tgl) {
                    $ts = strtotime($tgl);
                    if ($ts !== false) $tgl = date('Y-m-d', $ts);
                    else $tgl = null;
                } else {
                    $tgl = null;
                }
                
                try {
                    $stmt_ins->execute([
                        $no_tag, $kode_uu, $barang_id, $descp, $kat_id,
                        $kondisi ?: 'BAIK', $lokasi ?: null, $tgl, $harga, $user_id
                    ]);
                    $sukses++;
                } catch (Exception $e) {
                    $skip++;
                    $failed[] = ['row' => $row_num, 'alasan' => 'DB error: ' . substr($e->getMessage(), 0, 60)];
                }
            }
            
            $result = [
                'total' => $sukses + $skip,
                'sukses' => $sukses,
                'skip' => $skip,
                'new_kat' => $new_kat,
                'new_barang' => $new_barang,
                'failed' => array_slice($failed, 0, 20),
            ];
        }
    }
}

$page_title = 'Import Aset dari Excel/CSV';
$page_icon  = 'upload';
require_once __DIR__ . '/includes/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-3">
    <div>
        <h2 style="font-size: 20px; font-weight: 700; color: #0F172A; margin: 0 0 4px;">Import Aset dari Excel/CSV</h2>
        <p style="font-size: 13px; color: #64748B; margin: 0;">
            Import massal data aset existing ke database
        </p>
    </div>
</div>

<?php if ($error): ?>
    <div style="padding: 14px 18px; background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; border-radius: 10px; margin-bottom: 20px;">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if ($result): ?>
    <div class="card border-0 shadow-sm mb-3" style="background: #ECFDF5; border: 1px solid #A7F3D0 !important;">
        <div style="padding: 24px;">
            <h3 style="font-size: 16px; font-weight: 700; color: #065F46; margin: 0 0 16px;">
                <i class="bi bi-check-circle-fill"></i> Import Selesai
            </h3>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 18px;">
                <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                    <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">Total Baris</div>
                    <div style="font-size: 28px; font-weight: 700; color: #0F172A;"><?= number_format($result['total']) ?></div>
                </div>
                <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                    <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">✅ Sukses</div>
                    <div style="font-size: 28px; font-weight: 700; color: #059669;"><?= number_format($result['sukses']) ?></div>
                </div>
                <div style="background: white; padding: 16px; border-radius: 10px; text-align: center;">
                    <div style="font-size: 11px; color: #64748B; text-transform: uppercase; font-weight: 700;">⚠️ Skip</div>
                    <div style="font-size: 28px; font-weight: 700; color: #D97706;"><?= number_format($result['skip']) ?></div>
                </div>
            </div>
            
            <div style="font-size: 12.5px; color: #065F46;">
                📌 Kategori baru dibuat: <strong><?= $result['new_kat'] ?></strong> · 
                Barang baru dibuat: <strong><?= $result['new_barang'] ?></strong>
            </div>
            
            <?php if (!empty($result['failed'])): ?>
                <div style="margin-top: 16px; padding: 14px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px;">
                    <div style="font-size: 12.5px; font-weight: 700; color: #92400E; margin-bottom: 8px;">
                        Baris yang di-skip (max 20):
                    </div>
                    <table style="width: 100%; font-size: 11.5px; color: #78350F;">
                        <?php foreach ($result['failed'] as $f): ?>
                            <tr>
                                <td style="padding: 4px 0;">Baris <?= $f['row'] ?></td>
                                <td style="padding: 4px 0; text-align: right;"><?= e($f['alasan']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 18px;">
                <a href="aset.php" style="padding: 10px 20px; background: #2563EB; color: white; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="bi bi-grid"></i> Lihat Katalog Aset
                </a>
            </div>
        </div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start;">
    
    <!-- FORM UPLOAD -->
    <div class="card border-0 shadow-sm">
        <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9;">
            <h3 style="font-size: 14.5px; font-weight: 700; color: #0F172A; margin: 0;">
                <i class="bi bi-file-earmark-spreadsheet" style="color: #2563EB;"></i> Upload File
            </h3>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <div style="padding: 24px;">
                <div style="background: #EFF6FF; border: 1px solid #BFDBFE; padding: 14px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 12.5px; color: #1E40AF; line-height: 1.7;">
                    <strong><i class="bi bi-info-circle-fill"></i> Format CSV:</strong><br>
                    <strong>Wajib:</strong> NO TAG, KODE UU, DESCP<br>
                    <strong>Opsional:</strong> KATEGORI, MEREK, LOKASI, TGL PEROLEHAN, HARGA PEROLEHAN, KONDISI<br>
                    <strong>Auto-create:</strong> Kategori & Barang baru dibuat otomatis<br>
                    <strong>Update:</strong> Kalau NO TAG sudah ada, data akan diupdate
                </div>
                
                <label style="display: block; font-size: 12.5px; font-weight: 600; color: #374151; margin-bottom: 8px;">
                    <i class="bi bi-file-earmark-spreadsheet"></i> File CSV
                </label>
                <input type="file" name="file_csv" accept=".csv,.txt" required
                       style="width: 100%; padding: 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px;">
                
                <button type="submit" style="width: 100%; margin-top: 18px; padding: 12px; background: #2563EB; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer;">
                    <i class="bi bi-upload"></i> Import Sekarang
                </button>
                
                <div style="margin-top: 12px; padding: 10px 14px; background: #FEF3C7; border: 1px solid #FDE68A; border-radius: 8px; font-size: 11.5px; color: #92400E;">
                    💡 <strong>Excel:</strong> Save As → CSV (Comma delimited) → Upload di sini
                </div>
            </div>
        </form>
    </div>
    
    <!-- CONTOH CSV -->
    <div class="card border-0 shadow-sm">
        <div style="padding: 18px 24px; border-bottom: 1px solid #F1F5F9;">
            <h3 style="font-size: 14.5px; font-weight: 700; color: #0F172A; margin: 0;">
                <i class="bi bi-file-text" style="color: #2563EB;"></i> Contoh Format CSV
            </h3>
        </div>
        <div style="padding: 20px 24px;">
            <div style="background: #0F172A; color: #E2E8F0; padding: 14px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 11px; overflow-x: auto; line-height: 1.7;">
                NO TAG,KODE UU,DESCP,KATEGORI,MEREK,LOKASI,TGL PEROLEHAN,HARGA PEROLEHAN,KONDISI<br>
                TAG-001,6A03,AC Split 1PK,Elektronik,Daikin,Rak depan kasir,2024-01-15,5000000,BAIK<br>
                TAG-002,6A03,TV LED 32",Elektronik,LG,Area kasir,2023-06-20,3500000,BAIK<br>
                TAG-003,6A05,Kulkas Display,Elektronik,Sharp,Dapur belakang,2022-11-10,7000000,CUKUP
            </div>
            
            <div style="margin-top: 16px; font-size: 12.5px; color: #64748B; line-height: 1.7;">
                <strong>Penjelasan:</strong>
                <ul style="margin: 6px 0 0 18px; padding: 0;">
                    <li>Baris pertama = header (wajib)</li>
                    <li>Delimiter otomatis (Koma / Titik-koma / Tab)</li>
                    <li>Tanggal format: <code>YYYY-MM-DD</code></li>
                    <li>Angka tanpa titik/koma di CSV ideal</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>