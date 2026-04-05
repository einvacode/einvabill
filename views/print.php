<?php
// Ambil profil perusahaan dari DB
$company = $db->query("SELECT * FROM settings WHERE id=1")->fetch();

$format = $_GET['format'] ?? 'thermal'; // thermal or a4

// Cek waktu pelunasan jika lunas
$payment_info = null;
if ($invoice['status'] === 'Lunas') {
    $payment_info = $db->query("SELECT payment_date FROM payments WHERE invoice_id = " . intval($invoice['id']) . " ORDER BY id DESC LIMIT 1")->fetch();
}

// Cek apakah ada tunggakan global (Semua Invoice yang belum lunas)
$tunggakan = $db->query("SELECT SUM(amount - discount) FROM invoices WHERE customer_id = " . intval($invoice['customer_id']) . " AND status = 'Belum Lunas' AND id != " . intval($invoice['id']))->fetchColumn() ?: 0;
$tunggakan_bulan = $db->query("SELECT COUNT(*) FROM invoices WHERE customer_id = " . intval($invoice['customer_id']) . " AND status = 'Belum Lunas' AND id != " . intval($invoice['id']))->fetchColumn() ?: 0;

$bulan_bayar = "Tagihan Bulan " . date('F Y', strtotime($invoice['due_date']));

// Terjemahan bulan
$bulan_indo = ['January'=>'Januari', 'February'=>'Februari', 'March'=>'Maret', 'April'=>'April', 'May'=>'Mei', 'June'=>'Juni', 'July'=>'Juli', 'August'=>'Agustus', 'September'=>'September', 'October'=>'Oktober', 'November'=>'November', 'December'=>'Desember'];
$bulan_bayar = str_replace(array_keys($bulan_indo), array_values($bulan_indo), $bulan_bayar);

// Fetch items for itemized billing
$invoice_items = $db->query("SELECT * FROM invoice_items WHERE invoice_id = " . intval($invoice['id']))->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struk Pembayaran / Nota Tagihan</title>
    <style>
        <?php if($format == 'thermal'): ?>
        @import url('https://fonts.googleapis.com/css2?family=Courier+Prime:wght@400;700&display=swap');
        body {
            font-family: 'Courier Prime', monospace;
            background: #e2e8f0;
            color: #000;
            padding: 20px;
        }
        .print-container {
            width: 100%;
            max-width: 350px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        .text-center { text-align: center; }
        .divider { border-bottom: 1px dashed #000; margin: 15px 0; }
        .flex-between { display: flex; justify-content: space-between; }
        .mb-2 { margin-bottom: 8px; }
        <?php else: ?>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap');
        body {
            font-family: 'Outfit', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            padding: 20px;
        }
        .print-container {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 30px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            border-radius: 8px;
        }
        .text-center { text-align: center; }
        .header { display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:15px; margin-bottom:15px; }
        .invoice-title { font-size:28px; font-weight:800; color: #3b82f6; line-height:1; }
        .divider { border-bottom: 1px solid #f1f5f9; margin: 15px 0; }
        .flex-between { display: flex; justify-content: space-between; }
        .mb-2 { margin-bottom: 8px; }
        .details-grid { display:grid; grid-template-columns:1fr 1fr; gap:15px; }
        .total-box { background:var(--primary); color:white; padding:15px; border-radius:8px; margin-top:10px; display:flex; justify-content:space-between; font-size:18px; font-weight:bold; }
        <?php endif; ?>

        @media print {
            @page { margin: 1cm; size: A4; }
            body { padding: 0; background: #fff; }
            .print-container { box-shadow: none; max-width: 100%; border:none; padding:0; }
            .total-box { -webkit-print-color-adjust: exact; print-color-adjust: exact; background-color: #3b82f6 !important; color: white !important; }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <?php if($format == 'thermal'): ?>
            <!-- Layaout Thermal -->
            <div class="text-center mb-2">
                <?php if(!empty($company['company_logo'])): 
                    $logo_src = preg_match('/^http/', $company['company_logo']) ? $company['company_logo'] : '/' . str_replace(' ', '%20', $company['company_logo']);
                ?>
                    <img src="<?= $logo_src ?>" style="max-height:60px; margin-bottom:5px;">
                    <br>
                <?php endif; ?>
                <strong style="font-size:18px;"><?= htmlspecialchars($company['company_name']) ?></strong><br>
                <?php if($company['company_tagline']): ?><span style="font-size:12px;"><?= htmlspecialchars($company['company_tagline']) ?></span><br><?php endif; ?>
                <span style="font-size:12px;">Telp/WA: <?= htmlspecialchars($company['company_contact']) ?></span><br>
                <span style="font-size:11px;"><?= htmlspecialchars($company['company_address']) ?></span>
            </div>
            
            <div class="divider"></div>
            
            <div class="mb-2" style="font-size:12px;">
                Nomor: INV-<?= str_pad($invoice['id'], 5, "0", STR_PAD_LEFT) ?><br>
                Tanggal: <?= date('d/m/Y', strtotime($invoice['created_at'])) ?><br>
                Dicetak: <?= date('d/m/Y H:i') ?>
            </div>
            
            <div class="divider"></div>
            
                <strong>Nama:</strong> <?= htmlspecialchars($invoice['name']) ?><br>
                <?php if(empty($invoice_items)): ?>
                    <strong>Paket:</strong> <?= htmlspecialchars($invoice['package_name']) ?><br>
                <?php endif; ?>
                <?php if($invoice['address']): ?>
                    <strong>Alamat:</strong> <?= htmlspecialchars($invoice['address']) ?>
                <?php endif; ?>
            </div>
            
            <div class="divider"></div>

            <?php if(!empty($invoice_items)): ?>
                <div style="font-size:13px; margin-bottom:10px;">
                    <?php foreach($invoice_items as $item): ?>
                        <div class="flex-between">
                            <span><?= htmlspecialchars($item['description']) ?></span>
                            <span><?= number_format($item['amount'], 0, ',', '.') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="divider"></div>
            <?php endif; ?>
            
            <div class="mb-2 flex-between">
                <span style="font-size:13px;">Tagihan Bulan Ini:</span>
                <span style="font-size:13px;">Rp <?= number_format($invoice['amount'], 0, ',', '.') ?></span>
            </div>
            
            <?php if(($invoice['discount'] ?? 0) > 0): ?>
            <div class="mb-2 flex-between" style="color:red;">
                <span style="font-size:13px;">Potongan / Restitusi:</span>
                <span style="font-size:13px;">-Rp <?= number_format($invoice['discount'], 0, ',', '.') ?></span>
            </div>
            <?php endif; ?>

            <?php if($tunggakan > 0): ?>
            <div class="mb-2 flex-between" style="color:red;">
                <span style="font-size:13px;">Total Tunggakan:</span>
                <span style="font-size:13px;">Rp <?= number_format($tunggakan, 0, ',', '.') ?></span>
            </div>
            <?php endif; ?>
            
            <div class="divider"></div>
            <div class="mb-2 flex-between" style="font-weight:bold; font-size:16px;">
                <span>TOTAL HARUS:</span>
                <span>Rp <?= number_format($invoice['amount'] - ($invoice['discount'] ?? 0) + $tunggakan, 0, ',', '.') ?></span>
            </div>
            <div class="divider"></div>
            
            <div class="mb-2" style="font-size:14px; font-weight:bold; text-align:center;">
                STATUS: <?= strtoupper($invoice['status']) ?>
            </div>
            
            <?php if($invoice['status'] === 'Lunas' && $payment_info): ?>
            <div class="mb-2" style="font-size:12px; margin-top:5px;">
                Tgl Dibayar: <?= date('d/m/Y', strtotime($payment_info['payment_date'])) ?><br>
                Jam Lunas: <?= date('H:i:s', strtotime($payment_info['payment_date'])) ?> WIB<br>
                Ket: <?= htmlspecialchars($bulan_bayar) ?>
            </div>
            <?php endif; ?>

            <?php if($tunggakan > 0): ?>
            <div class="mb-2" style="font-size:11px; line-height:1.4; color:red;">
                *Terdapat sisa <?= $tunggakan_bulan; ?> bulan tunggakan lainnya yang belum lunas.
            </div>
            <?php endif; ?>
            
            <div class="divider"></div>
            
            <?php if(!empty($company['bank_account']) || !empty($company['company_qris'])): ?>
            <div style="font-size:12px; margin-bottom:10px;">
                <?php if(!empty($company['bank_account'])): ?>
                    <strong>PEMBAYARAN VIA TRANSFER:</strong><br>
                    <?= nl2br(htmlspecialchars($company['bank_account'])) ?><br><br>
                <?php endif; ?>
                
                <?php if($invoice['type'] !== 'partner' && !empty($company['company_qris'])): 
                    $qris_src = preg_match('/^http/', $company['company_qris']) ? $company['company_qris'] : '/' . str_replace(' ', '%20', $company['company_qris']);
                ?>
                    <div class="text-center">
                        <strong>SCAN QRIS:</strong><br>
                        <img src="<?= $qris_src ?>" style="max-width:150px; margin-top:5px; border:1px solid #eee; padding:5px;">
                    </div>
                <?php endif; ?>
            </div>
            <div class="divider"></div>
            <?php endif; ?>
            
            <div class="text-center" style="font-size:12px; margin-top:20px;">
                <strong>TERIMA KASIH</strong><br>
                Telah Mempercayakan Koneksi Anda<br>
                Harap simpan struk ini sebagai bukti pembayaran yang sah.
            </div>

        <?php else: ?>
            <!-- Layaout A4 Compact -->
            <div class="header">
                <div style="display:flex; align-items:center; gap:15px;">
                    <?php if(!empty($company['company_logo'])): 
                        $logo_src = preg_match('/^http/', $company['company_logo']) ? $company['company_logo'] : '/' . str_replace(' ', '%20', $company['company_logo']);
                    ?>
                        <img src="<?= $logo_src ?>" style="max-height:60px; max-width:120px; object-fit:contain;">
                    <?php endif; ?>
                    <div>
                        <div style="font-size:20px; font-weight:bold;"><?= htmlspecialchars($company['company_name']) ?></div>
                        <div style="color:#64748b; font-size:12px;"><?= htmlspecialchars($company['company_tagline'] ?? '') ?></div>
                        <div style="margin-top:5px; font-size:12px; color:#475569; max-width:250px; line-height:1.3;">
                            <?= htmlspecialchars($company['company_address']) ?><br>
                            Telp. <?= htmlspecialchars($company['company_contact']) ?>
                        </div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div class="invoice-title">INVOICE</div>
                    <div style="font-size:14px; font-weight:bold; margin-top:5px;">#INV-<?= str_pad($invoice['id'], 5, "0", STR_PAD_LEFT) ?></div>
                    <div style="color:#64748b; font-size:11px; margin-top:2px;">Terbit: <?= date('d M Y', strtotime($invoice['created_at'])) ?></div>
                    <div style="color:#ef4444; font-weight:bold; font-size:11px; margin-top:2px;">Jatuh Tempo: <?= date('d M Y', strtotime($invoice['due_date'])) ?></div>
                </div>
            </div>

            <div class="details-grid">
                <div>
                    <div style="color:#64748b; margin-bottom:3px; font-size:12px; font-weight:bold;">Ditagihkan Kepada:</div>
                    <div style="font-size:16px; font-weight:bold;"><?= htmlspecialchars($invoice['name']) ?></div>
                    <div style="font-size:13px; color:#475569; margin-top:2px;"><?= htmlspecialchars($invoice['address'] ?: '-') ?></div>
                    <div style="font-size:13px; color:#475569;">WA/Telp: <?= htmlspecialchars($invoice['contact'] ?: '-') ?></div>
                </div>
                
                <div style="text-align:right;">
                    <?php if($invoice['status'] === 'Lunas' && $payment_info): ?>
                        <div style="color:#64748b; margin-bottom:3px; font-size:11px; font-weight:bold;">Informasi Pelunasan:</div>
                        <div style="font-size:13px;">Lunas: <?= date('d M, H:i', strtotime($payment_info['payment_date'])) ?> WIB</div>
                        <div style="font-size:13px; font-weight:bold; color:#3b82f6;"><?= htmlspecialchars($bulan_bayar) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <table style="width:100%; margin-top:20px; border-collapse:collapse; font-size:14px;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1;">
                        <th style="padding:10px; text-align:left;">Deskripsi Layanan</th>
                        <th style="padding:10px; text-align:right;">Total Harga</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($invoice_items)): ?>
                        <tr>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0;">Layanan Internet: <strong><?= htmlspecialchars($invoice['package_name']) ?></strong></td>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0; text-align:right; font-weight:bold;">Rp <?= number_format($invoice['amount'], 0, ',', '.') ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($invoice_items as $item): ?>
                            <tr>
                                <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0;"><?= htmlspecialchars($item['description']) ?></td>
                                <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:right; font-weight:bold;">Rp <?= number_format($item['amount'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top:20px; display:flex; justify-content:flex-end;">
                <div style="width:300px; font-size:13px;">
                    <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9;">
                        <div style="color:#64748b;">Tagihan Utama</div>
                        <div style="font-weight:bold;">Rp <?= number_format($invoice['amount'], 0, ',', '.') ?></div>
                    </div>
                    <?php if(($invoice['discount'] ?? 0) > 0): ?>
                    <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; color:#f59e0b;">
                        <div>Diskon / Restitusi</div>
                        <div style="font-weight:bold;">-Rp <?= number_format($invoice['discount'], 0, ',', '.') ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if($tunggakan > 0): ?>
                    <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f1f5f9; color:#ef4444;">
                        <div>Tunggakan Lawas</div>
                        <div style="font-weight:bold;">Rp <?= number_format($tunggakan, 0, ',', '.') ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="total-box">
                        <div style="font-size:14px; opacity:0.9;">TOTAL AKHIR</div>
                        <div>Rp <?= number_format($invoice['amount'] - ($invoice['discount'] ?? 0) + $tunggakan, 0, ',', '.') ?></div>
                    </div>
                </div>
            </div>
            
            <div style="margin-top:15px; text-align:right; font-size:16px; font-weight:900;">
                STATUS: 
                <?php if($invoice['status'] == 'Lunas'): ?>
                    <span style="color:#10b981; border:2px solid #10b981; padding:2px 10px; border-radius:6px;">PAID / LUNAS</span>
                <?php else: ?>
                    <span style="color:#ef4444; border:2px solid #ef4444; padding:2px 10px; border-radius:6px;">UNPAID / BELUM LUNAS</span>
                <?php endif; ?>
            </div>

            <?php if($tunggakan > 0): ?>
            <div style="margin-top:15px; padding:10px; background:rgba(239, 68, 68, 0.05); border:1px solid rgba(239, 68, 68, 0.2); border-radius:6px; color:#ef4444; font-size:12px;">
                <strong><i class="fas fa-exclamation-triangle"></i> CATATAN:</strong> Terdapat tunggakan <strong><?= $tunggakan_bulan; ?> Bulan</strong> sebelumnya (Rp <?= number_format($tunggakan, 0, ',', '.') ?>). Dokumen ini mengacu pada satu periode tagihan berjalan.
            </div>
            <?php endif; ?>

            <?php if(!empty($company['bank_account']) || !empty($company['company_qris'])): ?>
            <div style="margin-top:20px; padding:15px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                <div style="display:flex; gap:20px; align-items:center;">
                    <?php if(!empty($company['bank_account'])): ?>
                    <div style="flex:1;">
                        <div style="font-weight:bold; font-size:11px; color:#64748b; margin-bottom:5px;">METODE PEMBAYARAN:</div>
                        <div style="color:#475569; font-size:13px; line-height:1.4;">
                            <?= nl2br(htmlspecialchars($company['bank_account'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if($invoice['type'] !== 'partner' && !empty($company['company_qris'])): 
                        $qris_src_a4 = preg_match('/^http/', $company['company_qris']) ? $company['company_qris'] : '/' . str_replace(' ', '%20', $company['company_qris']);
                    ?>
                    <div style="text-align:center; padding:8px; background:#fff; border-radius:6px; border:1px solid #e2e8f0;">
                        <div style="font-weight:bold; font-size:10px; color:#64748b; margin-bottom:5px;">SCAN QRIS:</div>
                        <img src="<?= $qris_src_a4 ?>" style="height:100px; width:100px; object-fit:contain;">
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div style="margin-top:25px; text-align:center; color:#94a3b8; font-size:11px;">
                <strong>TERIMA KASIH</strong> — Dokumen digital sah diterbitkan oleh <?= htmlspecialchars($company['company_name']) ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        window.onload = function() { window.print(); }
    </script>
</body>
</html>
