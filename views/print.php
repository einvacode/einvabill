<?php
// Ambil profil perusahaan dari DB
$company = $db->query("SELECT * FROM settings WHERE id=1")->fetch();

$format = $_GET['format'] ?? 'thermal'; // thermal or a4

// Cek waktu pelunasan jika lunas
$payment_info = null;
if ($invoice['status'] === 'Lunas') {
    $payment_info = $db->query("SELECT payment_date FROM payments WHERE invoice_id = " . intval($invoice['id']) . " ORDER BY id DESC LIMIT 1")->fetch();
}

// Cek apakah ada tunggakan masa lalu (Invoice sebelum invoice ini yang belum lunas)
$tunggakan = $db->query("SELECT SUM(amount) FROM invoices WHERE customer_id = " . intval($invoice['customer_id']) . " AND status = 'Belum Lunas' AND id != " . intval($invoice['id']) . " AND due_date < '" . $invoice['due_date'] . "'")->fetchColumn() ?: 0;
$tunggakan_bulan = $db->query("SELECT COUNT(*) FROM invoices WHERE customer_id = " . intval($invoice['customer_id']) . " AND status = 'Belum Lunas' AND id != " . intval($invoice['id']) . " AND due_date < '" . $invoice['due_date'] . "'")->fetchColumn() ?: 0;

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
            background: #e2e8f0;
            color: #1e293b;
            padding: 40px;
        }
        .print-container {
            width: 100%;
            max-width: 800px; /* A4 width roughly */
            margin: 0 auto;
            background: #fff;
            padding: 40px;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }
        .text-center { text-align: center; }
        .header { display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #cbd5e1; padding-bottom:20px; margin-bottom:20px; }
        .invoice-title { font-size:32px; font-weight:700; color: #3b82f6; }
        .divider { border-bottom: 1px solid #e2e8f0; margin: 20px 0; }
        .flex-between { display: flex; justify-content: space-between; }
        .mb-2 { margin-bottom: 12px; }
        .details-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
        .total-box { background:#f1f5f9; padding:20px; border-radius:8px; margin-top:20px; display:flex; justify-content:space-between; font-size:20px; font-weight:bold; }
        <?php endif; ?>

        @media print {
            @page { margin: 0; }
            body { padding: 10px; background: #fff; }
            .print-container { box-shadow: none; max-width: 100%; border:none; padding:10px; }
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
            
            <?php if($tunggakan > 0): ?>
            <div class="mb-2 flex-between" style="color:red;">
                <span style="font-size:13px;">Total Tunggakan:</span>
                <span style="font-size:13px;">Rp <?= number_format($tunggakan, 0, ',', '.') ?></span>
            </div>
            <?php endif; ?>
            
            <div class="divider"></div>
            <div class="mb-2 flex-between" style="font-weight:bold; font-size:16px;">
                <span>TOTAL HARUS:</span>
                <span>Rp <?= number_format($invoice['amount'] + $tunggakan, 0, ',', '.') ?></span>
            </div>
            <div class="divider"></div>
            
            <div class="divider"></div>
            <div class="mb-2 flex-between" style="font-weight:bold; font-size:16px;">
                <span>TOTAL HARUS:</span>
                <span>Rp <?= number_format($invoice['amount'] + $tunggakan, 0, ',', '.') ?></span>
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
            <!-- Layaout A4 -->
            <div class="header">
                <div style="display:flex; align-items:center; gap:20px;">
                    <?php if(!empty($company['company_logo'])): 
                        $logo_src = preg_match('/^http/', $company['company_logo']) ? $company['company_logo'] : '/' . str_replace(' ', '%20', $company['company_logo']); // fallback urlencode
                    ?>
                        <img src="<?= $logo_src ?>" style="max-height:80px; max-width:150px; object-fit:contain;">
                    <?php endif; ?>
                    <div>
                        <div style="font-size:24px; font-weight:bold;"><?= htmlspecialchars($company['company_name']) ?></div>
                        <div style="color:#64748b;"><?= htmlspecialchars($company['company_tagline'] ?? '') ?></div>
                        <div style="margin-top:10px; font-size:14px; max-width:250px;">
                            <?= htmlspecialchars($company['company_address']) ?><br>
                            Telp. <?= htmlspecialchars($company['company_contact']) ?>
                        </div>
                    </div>
                </div>
                <div style="text-align:right;">
                    <div class="invoice-title">INVOICE</div>
                    <div style="font-size:16px; font-weight:bold; margin-top:10px;">#INV-<?= str_pad($invoice['id'], 5, "0", STR_PAD_LEFT) ?></div>
                    <div style="color:#64748b; margin-top:5px;">Terbit: <?= date('d M Y', strtotime($invoice['created_at'])) ?></div>
                    <div style="color:#ef4444; font-weight:bold; margin-top:5px;">Jatuh Tempo: <?= date('d M Y', strtotime($invoice['due_date'])) ?></div>
                </div>
            </div>

            <div class="details-grid">
                <div>
                    <div style="color:#64748b; margin-bottom:5px; font-weight:bold;">Ditagihkan Kepada:</div>
                    <div style="font-size:18px; font-weight:bold;"><?= htmlspecialchars($invoice['name']) ?></div>
                    <div style="margin-top:5px;"><?= htmlspecialchars($invoice['address'] ?: '-') ?></div>
                    <div style="margin-top:5px;">WA/Telp: <?= htmlspecialchars($invoice['contact'] ?: '-') ?></div>
                </div>
                
                <div style="text-align:right;">
                    <?php if($invoice['status'] === 'Lunas' && $payment_info): ?>
                        <div style="color:#64748b; margin-bottom:5px; font-weight:bold;">Informasi Real-Time Pelunasan:</div>
                        <div style="font-size:15px;">Tanggal Lunas: <?= date('d M Y', strtotime($payment_info['payment_date'])) ?></div>
                        <div style="font-size:15px; margin-top:5px;">Jam Klik Bayar: <?= date('H:i:s', strtotime($payment_info['payment_date'])) ?> WIB</div>
                        <div style="font-size:15px; margin-top:5px; font-weight:bold; color:var(--primary);"><?= htmlspecialchars($bulan_bayar) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <table style="width:100%; margin-top:40px; border-collapse:collapse;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0;">
                        <th style="padding:15px; text-align:left;">Deskripsi Layanan</th>
                        <th style="padding:15px; text-align:right;">Total Harga</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($invoice_items)): ?>
                        <tr>
                            <td style="padding:20px 15px; border-bottom:1px solid #e2e8f0; font-size:18px;">Biaya Langganan: <strong><?= htmlspecialchars($invoice['package_name']) ?></strong></td>
                            <td style="padding:20px 15px; border-bottom:1px solid #e2e8f0; text-align:right; font-size:18px;">Rp <?= number_format($invoice['amount'], 0, ',', '.') ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($invoice_items as $item): ?>
                            <tr>
                                <td style="padding:15px; border-bottom:1px solid #e2e8f0; font-size:16px;"><?= htmlspecialchars($item['description']) ?></td>
                                <td style="padding:15px; border-bottom:1px solid #e2e8f0; text-align:right; font-size:16px;">Rp <?= number_format($item['amount'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top:40px; display:flex; justify-content:flex-end;">
                <div style="width:350px;">
                    <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #e2e8f0;">
                        <div style="color:#64748b;">Tagihan Bulan Ini</div>
                        <div style="font-weight:bold;">Rp <?= number_format($invoice['amount'], 0, ',', '.') ?></div>
                    </div>
                    <?php if($tunggakan > 0): ?>
                    <div style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid #e2e8f0; color:#ef4444;">
                        <div>Total Tunggakan Lainnya</div>
                        <div style="font-weight:bold;">Rp <?= number_format($tunggakan, 0, ',', '.') ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="total-box" style="margin-top:10px; background:var(--primary); color:white;">
                        <div>TOTAL HARUS DIBAYAR</div>
                        <div>Rp <?= number_format($invoice['amount'] + $tunggakan, 0, ',', '.') ?></div>
                    </div>
                </div>
            </div>
            
            <div style="margin-top:30px; text-align:right; font-size:20px; font-weight:bold;">
                STATUS: 
                <?php if($invoice['status'] == 'Lunas'): ?>
                    <span style="color:#10b981; padding:5px 15px; border:2px solid #10b981; border-radius:8px;">LUNAS</span>
                <?php else: ?>
                    <span style="color:#ef4444; padding:5px 15px; border:2px solid #ef4444; border-radius:8px;">BELUM LUNAS</span>
                <?php endif; ?>
            </div>

            <?php if($tunggakan > 0): ?>
            <div style="margin-top:20px; padding:15px; background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); border-radius:8px; color:#ef4444;">
                <div style="font-weight:bold; margin-bottom:5px;"><i class="fas fa-exclamation-triangle"></i> PERHATIAN: Terdapat Tunggakan Bulan Sebelumnya</div>
                <div style="font-size:15px;">Pelanggan ini masih memiliki tunggakan akumulasi sejumlah <strong><?= $tunggakan_bulan; ?> Bulan</strong> (Total Tunggakan: <strong>Rp <?= number_format($tunggakan, 0, ',', '.') ?></strong>). Dokumen yang Anda lihat ini HANYA membayarkan satu bulan saja.</div>
            </div>
            <?php endif; ?>

            <?php if(!empty($company['bank_account']) || !empty($company['company_qris'])): ?>
            <div style="margin-top:30px; padding:20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                <div style="font-weight:bold; margin-bottom:15px;">INFORMASI PEMBAYARAN</div>
                <div style="display:flex; gap:30px; align-items:flex-start;">
                    <?php if(!empty($company['bank_account'])): ?>
                    <div style="flex:1;">
                        <div style="font-weight:bold; font-size:13px; color:#64748b; margin-bottom:5px;">TRANSFER BANK / MANUAL:</div>
                        <div style="color:#475569; font-size:15px; line-height:1.5;">
                            <?= nl2br(htmlspecialchars($company['bank_account'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if($invoice['type'] !== 'partner' && !empty($company['company_qris'])): 
                        $qris_src_a4 = preg_match('/^http/', $company['company_qris']) ? $company['company_qris'] : '/' . str_replace(' ', '%20', $company['company_qris']);
                    ?>
                    <div style="text-align:center; padding:10px; background:#fff; border-radius:8px; border:1px solid #e2e8f0;">
                        <div style="font-weight:bold; font-size:13px; color:#64748b; margin-bottom:8px;">SCAN QRIS PEMBAYARAN:</div>
                        <img src="<?= $qris_src_a4 ?>" style="max-height:160px; max-width:160px; object-fit:contain;">
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div style="margin-top:40px; text-align:center; color:#64748b;">
                <strong>TERIMA KASIH</strong><br>
                Dokumen ini merupakan bukti tagihan yang sah dan diterbitkan secara digital oleh sistem.
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        window.onload = function() { window.print(); }
    </script>
</body>
</html>
