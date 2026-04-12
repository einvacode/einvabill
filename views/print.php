<?php
// Ambil profil perusahaan dari DB
$company = $db->query("SELECT * FROM settings WHERE id=1")->fetch();

// Branding Override for Partners
if (($invoice['created_by'] ?? 0) != 0) {
    $partner_id = intval($invoice['created_by']);
    $partner_brand = $db->query("SELECT brand_name, brand_logo, brand_qris, brand_address, brand_contact, brand_bank, brand_rekening FROM users WHERE id = $partner_id")->fetch();
    
    if ($partner_brand && !empty($partner_brand['brand_name'])) {
        $company['company_name'] = $partner_brand['brand_name'];
        if (!empty($partner_brand['brand_address'])) $company['company_address'] = $partner_brand['brand_address'];
        if (!empty($partner_brand['brand_contact'])) $company['company_contact'] = $partner_brand['brand_contact'];
        if (!empty($partner_brand['brand_logo'])) $company['company_logo'] = $partner_brand['brand_logo'];
        if (!empty($partner_brand['brand_qris'])) $company['company_qris'] = $partner_brand['brand_qris'];
        if (!empty($partner_brand['brand_bank'])) $company['company_bank'] = $partner_brand['brand_bank'];
        if (!empty($partner_brand['brand_rekening'])) $company['company_rekening'] = $partner_brand['brand_rekening'];
    }
}

$format = $_GET['format'] ?? 'thermal'; // thermal or a4

// Cek waktu pelunasan jika lunas
$payment_info = null;
if ($invoice['status'] === 'Lunas') {
    $payment_info = $db->query("SELECT payment_date FROM payments WHERE invoice_id = " . intval($invoice['id']) . " ORDER BY id DESC LIMIT 1")->fetch();
}

// Cek apakah ada tunggakan global (Semua Invoice yang belum lunas)
$tunggakan = $db->query("SELECT SUM(amount - discount) FROM invoices WHERE customer_id = " . intval($invoice['customer_id']) . " AND status = 'Belum Lunas' AND id != " . intval($invoice['id']))->fetchColumn() ?: 0;
$tunggakan_bulan = $db->query("SELECT COUNT(*) FROM invoices WHERE customer_id = " . intval($invoice['customer_id']) . " AND status = 'Belum Lunas' AND id != " . intval($invoice['id']))->fetchColumn() ?: 0;

$bulan_bayar = "Tagihan Bulan " . (!empty($invoice['due_date']) ? date('m/Y', strtotime($invoice['due_date'])) : date('m/Y', strtotime($invoice['created_at'] ?? 'now')));

// Fetch items for itemized billing
$invoice_items = $db->query("SELECT * FROM invoice_items WHERE invoice_id = " . intval($invoice['id']))->fetchAll();

// Defensive: if invoice doesn't already include customer fields, try to populate them
if (empty($invoice['name']) && !empty($invoice['customer_id'])) {
    try {
        $cstmt = $db->prepare("SELECT id, name, address, contact, package_name, type, created_by FROM customers WHERE id = ? LIMIT 1");
        $cstmt->execute([intval($invoice['customer_id'])]);
        $custRow = $cstmt->fetch();
        if ($custRow) {
            // only set fields that are empty to avoid overwriting provider-specific branding set earlier
            foreach (['name','address','contact','package_name','type','created_by'] as $k) {
                if (empty($invoice[$k]) && isset($custRow[$k])) $invoice[$k] = $custRow[$k];
            }
        }
    } catch (Exception $e) {
        // ignore
    }
}

// Detect if this invoice was created via the quick-invoice flow:
// - customer_id absent (0) OR customers.type == 'note' (temporary note customer)
$is_quick_invoice = false;
try {
    $cid = intval($invoice['customer_id'] ?? 0);
    if ($cid <= 0) {
        $is_quick_invoice = true;
    } else {
        $cust = $db->prepare("SELECT type FROM customers WHERE id = ?");
        $cust->execute([$cid]);
        $c = $cust->fetch();
        if ($c && ($c['type'] === 'note' || $c['type'] === 'temp')) $is_quick_invoice = true;
    }
} catch (Exception $e) { $is_quick_invoice = false; }

// Define Status Text Logic
$status_label = strtoupper($invoice['status'] ?? 'BELUM LUNAS');
if (($invoice['status'] ?? '') === 'Lunas') {
    $status_label = ($tunggakan > 0) ? 'LUNAS (SEBAGIAN)' : 'LUNAS (SEPENUHNYA)';
}
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
                Jatuh Tempo: <?= (!empty($invoice['due_date']) ? date('d/m/Y', strtotime($invoice['due_date'])) : '-') ?><br>
                Dicetak: <?= date('d/m/Y H:i') ?>
            </div>
            
            <div class="divider"></div>
            
                <strong>Nama:</strong> <?= htmlspecialchars($invoice['name']) ?><br>
                <?php if(empty($invoice_items)): ?>
                    <strong>Paket:</strong> <?= htmlspecialchars($invoice['package_name']) ?><br>
                <?php endif; ?>
                <?php
                    $inv_addr = $invoice['billing_address'] ?? $invoice['address'] ?? '';
                    $inv_phone = $invoice['billing_phone'] ?? $invoice['contact'] ?? '';
                    $inv_email = $invoice['billing_email'] ?? '';
                ?>
                <?php if($inv_addr): ?>
                    <strong>Alamat:</strong> <?= htmlspecialchars($inv_addr) ?><br>
                <?php elseif($invoice['address']): ?>
                    <strong>Alamat:</strong> <?= htmlspecialchars($invoice['address']) ?><br>
                <?php endif; ?>
                <?php if($inv_phone): ?>
                    <strong>Telp/WA:</strong> <?= htmlspecialchars($inv_phone) ?><br>
                <?php endif; ?>
                <?php if($inv_email): ?>
                    <strong>Email:</strong> <?= htmlspecialchars($inv_email) ?><br>
                <?php endif; ?>
            </div>
            
            <div class="divider"></div>

            <?php if(!empty($invoice_items)): ?>
                <div style="font-size:12px; margin-bottom:10px;">
                    <div style="display:flex; font-weight:700; border-bottom:1px dashed #000; padding-bottom:6px;">
                        <div style="flex:1; min-width:120px;">KETERANGAN</div>
                        <div style="flex:0 0 22%; text-align:right;">HARGA</div>
                        <div style="flex:0 0 12%; text-align:center;">JML</div>
                        <div style="flex:0 0 22%; text-align:right;">TOTAL</div>
                    </div>
                    <?php foreach($invoice_items as $item): ?>
                        <?php
                            $qty = intval($item['qty'] ?? $item['quantity'] ?? 1);
                            $unit = isset($item['unit_price']) ? floatval($item['unit_price']) : (isset($item['unit']) ? floatval($item['unit']) : null);
                            if (empty($unit)) {
                                $unit = ($qty > 0) ? round(floatval($item['amount']) / $qty) : floatval($item['amount']);
                            }
                            $line_total = floatval($item['amount']);
                        ?>
                        <div style="display:flex; padding:6px 0; border-bottom:1px dotted #ddd; align-items:center;">
                            <div style="flex:1; min-width:120px;"><?= htmlspecialchars($item['description']) ?></div>
                            <div style="flex:0 0 22%; text-align:right;">Rp <?= number_format($unit, 0, ',', '.') ?></div>
                            <div style="flex:0 0 12%; text-align:center;"><?= $qty ?></div>
                            <div style="flex:0 0 22%; text-align:right;">Rp <?= number_format($line_total, 0, ',', '.') ?></div>
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
                STATUS: <span style="color: <?= ($invoice['status'] === 'Lunas') ? '#10b981' : '#ef4444' ?>;"><?= $status_label ?></span>
            </div>
            
            <?php if($invoice['status'] === 'Lunas' && $payment_info): ?>
            <div class="mb-2" style="font-size:12px; margin-top:5px;">
                Tanggal Dibayar: <?= date('d/m/Y', strtotime($payment_info['payment_date'])) ?><br>
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
            
            <?php if(!$is_quick_invoice && (!empty($company['bank_account']) || !empty($company['company_qris']) || !empty($company['company_bank']))): ?>
            <div style="font-size:12px; margin-bottom:10px;">
                <?php if(!empty($company['company_bank']) && !empty($company['company_rekening'])): ?>
                    <div class="text-center" style="margin-top:10px; border:1px dashed #000; padding:8px; border-radius:5px;">
                        <strong>TRANSFER BANK:</strong><br>
                        <?= htmlspecialchars($company['company_bank']) ?>: <?= htmlspecialchars($company['company_rekening']) ?>
                    </div>
                <?php elseif(!empty($company['bank_account'])): ?>
                    <strong>PEMBAYARAN VIA TRANSFER:</strong><br>
                    <?= nl2br(htmlspecialchars($company['bank_account'])) ?><br>
                <?php endif; ?>

                <?php if(!empty($company['company_qris'])): ?>
                    <div class="text-center" style="margin-top:15px; margin-bottom:10px;">
                        <div style="font-size:10px; margin-bottom:5px; opacity:0.6;">SCAN QRIS PEMBAYARAN:</div>
                        <img src="<?= $company['company_qris'] ?>" style="max-height:150px; border:1px solid #ddd; padding:5px; border-radius:5px;">
                    </div>
                <?php endif; ?>
            </div>
            <div class="divider"></div>
            <?php endif; ?>

            <?php if(!empty($invoice['payment_instructions'])): ?>
            <div style="margin-top:10px; font-size:12px;">
                <strong>Instruksi Pembayaran:</strong><br>
                <?= nl2br(htmlspecialchars($invoice['payment_instructions'])) ?>
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
                    <div style="color:#64748b; font-size:11px; margin-top:2px;">Terbit: <?= (!empty($invoice['created_at']) ? date('d/m/Y', strtotime($invoice['created_at'])) : '-') ?></div>
                    <div style="color:#ef4444; font-weight:bold; font-size:11px; margin-top:2px;">Jatuh Tempo: <?= (!empty($invoice['due_date']) ? date('d/m/Y', strtotime($invoice['due_date'])) : '-') ?></div>
                </div>
            </div>

            <div class="details-grid">
                <div>
                    <div style="color:#64748b; margin-bottom:3px; font-size:12px; font-weight:bold;">Ditagihkan Kepada:</div>
                    <div style="font-size:16px; font-weight:bold;"><?= htmlspecialchars($invoice['name']) ?></div>
                    <div style="font-size:13px; color:#475569; margin-top:2px;"><?= htmlspecialchars($invoice['address'] ?: '-') ?></div>
                    <div style="font-size:13px; color:#475569;">WA/Telp: <?= htmlspecialchars($invoice['contact'] ?: '-') ?></div>
                    <?php if(!empty($invoice['billing_email']) || !empty($invoice['billing_phone']) || !empty($invoice['billing_address'])): ?>
                        <div style="margin-top:8px; font-size:13px; color:#475569;">
                            <strong>Kontak Penagihan:</strong><br>
                            <?= htmlspecialchars($invoice['billing_address'] ?? '-') ?><br>
                            <?= !empty($invoice['billing_phone']) ? 'Telp: '.htmlspecialchars($invoice['billing_phone']) . '<br>' : '' ?>
                            <?= !empty($invoice['billing_email']) ? 'Email: '.htmlspecialchars($invoice['billing_email']) . '<br>' : '' ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div style="text-align:right;">
                    <?php if($invoice['status'] === 'Lunas' && $payment_info): ?>
                        <div style="color:#64748b; margin-bottom:3px; font-size:11px; font-weight:bold;">Informasi Pelunasan:</div>
                        <div style="font-size:13px;">Lunas: <?= date('d/m/Y H:i', strtotime($payment_info['payment_date'])) ?> WIB</div>
                        <div style="font-size:13px; font-weight:bold; color:#3b82f6;"><?= htmlspecialchars($bulan_bayar) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top:10px; display:flex; justify-content:flex-end; gap:20px; align-items:center;">
                <div style="text-align:right; font-size:13px; color:#475569;">
                    <div><strong>Dibuat Oleh:</strong></div>
                    <div><?= htmlspecialchars($invoice['issued_by_name'] ?? ($_SESSION['user_name'] ?? '-')) ?></div>
                </div>
            </div>

            <div style="margin-top:8px; display:flex; justify-content:space-between; align-items:center; gap:20px;">
                <div style="font-size:13px; color:#475569;">
                    <?php if(!empty($invoice['billing_address']) || !empty($invoice['billing_phone']) || !empty($invoice['billing_email'])): ?>
                        <strong>Kontak Penagihan:</strong><br>
                        <?= htmlspecialchars($invoice['billing_address'] ?? '-') ?><br>
                        <?= !empty($invoice['billing_phone']) ? 'Telp: '.htmlspecialchars($invoice['billing_phone']) . '<br>' : '' ?>
                        <?= !empty($invoice['billing_email']) ? 'Email: '.htmlspecialchars($invoice['billing_email']) . '<br>' : '' ?>
                    <?php endif; ?>
                </div>
                <div style="text-align:right; font-size:13px; color:#475569;">
                    <div><strong>Dibuat Oleh:</strong></div>
                    <div><?= htmlspecialchars($invoice['issued_by_name'] ?? ($_SESSION['user_name'] ?? '-')) ?></div>
                </div>
            </div>

            <table style="width:100%; margin-top:20px; border-collapse:collapse; font-size:14px;">
                <thead>
                    <tr style="background:#f8fafc; border-bottom:1px solid #cbd5e1;">
                        <th style="padding:10px; text-align:left;">Deskripsi</th>
                        <th style="padding:10px; text-align:center; width:90px;">Jumlah</th>
                        <th style="padding:10px; text-align:right; width:140px;">Harga Satuan</th>
                        <th style="padding:10px; text-align:right; width:160px;">Total Harga</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($invoice_items)): ?>
                        <tr>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0;">Layanan Internet: <strong><?= htmlspecialchars($invoice['package_name']) ?></strong></td>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0; text-align:center;">1</td>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0; text-align:right;">Rp <?= number_format($invoice['amount'], 0, ',', '.') ?></td>
                            <td style="padding:12px 10px; border-bottom:1px solid #e2e8f0; text-align:right; font-weight:bold;">Rp <?= number_format($invoice['amount'], 0, ',', '.') ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($invoice_items as $item): ?>
                            <?php
                                $qty = intval($item['qty'] ?? $item['quantity'] ?? 1);
                                $unit = isset($item['unit_price']) ? floatval($item['unit_price']) : (isset($item['unit']) ? floatval($item['unit']) : null);
                                if (empty($unit)) {
                                    $unit = ($qty > 0) ? round(floatval($item['amount']) / $qty) : floatval($item['amount']);
                                }
                                $line_total = floatval($item['amount']);
                            ?>
                            <tr>
                                <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0;"><?= htmlspecialchars($item['description']) ?></td>
                                <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:center;"><?= $qty ?></td>
                                <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:right;">Rp <?= number_format($unit, 0, ',', '.') ?></td>
                                <td style="padding:8px 10px; border-bottom:1px solid #e2e8f0; text-align:right; font-weight:bold;">Rp <?= number_format($line_total, 0, ',', '.') ?></td>
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
                    <span style="color:#10b981; border:2px solid #10b981; padding:4px 15px; border-radius:8px; display:inline-block;"><?= $status_label ?></span>
                <?php else: ?>
                    <span style="color:#ef4444; border:2px solid #ef4444; padding:4px 15px; border-radius:8px; display:inline-block;">UNPAID / BELUM LUNAS</span>
                <?php endif; ?>
            </div>

            <?php if($tunggakan > 0): ?>
            <div style="margin-top:15px; padding:10px; background:rgba(239, 68, 68, 0.05); border:1px solid rgba(239, 68, 68, 0.2); border-radius:6px; color:#ef4444; font-size:12px;">
                <strong><i class="fas fa-exclamation-triangle"></i> CATATAN:</strong> Terdapat tunggakan <strong><?= $tunggakan_bulan; ?> Bulan</strong> sebelumnya (Rp <?= number_format($tunggakan, 0, ',', '.') ?>). Dokumen ini mengacu pada satu periode tagihan berjalan.
            </div>
            <?php endif; ?>

            <?php if(!$is_quick_invoice && (!empty($company['company_bank']) || !empty($company['company_qris']) || !empty($company['bank_account']))): ?>
            <div style="margin-top:20px; padding:20px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                <div style="display:flex; gap:30px; align-items:center;">
                    <div style="flex:1;">
                        <?php if(!empty($company['company_bank']) && !empty($company['company_rekening'])): ?>
                            <div style="font-weight:800; font-size:11px; color:#64748b; text-transform:uppercase; margin-bottom:8px;">Pembayaran via Transfer:</div>
                            <div style="background:#fff; border:1px solid #e2e8f0; padding:12px; border-radius:10px;">
                                <div style="font-size:14px; font-weight:800; color:#0f172a;"><?= htmlspecialchars($company['company_bank']) ?></div>
                                <div style="font-size:20px; font-weight:900; color:#3b82f6; letter-spacing:1px; margin-top:2px;"><?= htmlspecialchars($company['company_rekening']) ?></div>
                                <div style="font-size:12px; color:#94a3b8; margin-top:4px;">Atas Nama: <?= htmlspecialchars($company['company_name']) ?></div>
                            </div>
                        <?php elseif(!empty($company['bank_account'])): ?>
                            <div style="font-weight:800; font-size:11px; color:#64748b; text-transform:uppercase; margin-bottom:8px;">Metode Pembayaran:</div>
                            <div style="color:#475569; font-size:14px; line-height:1.4;">
                                <?= nl2br(htmlspecialchars($company['bank_account'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if(!empty($company['company_qris'])): ?>
                    <div style="text-align:center; padding:12px; background:#fff; border-radius:12px; border:1px solid #e2e8f0; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                        <div style="font-weight:800; font-size:10px; color:#64748b; margin-bottom:8px; text-transform:uppercase;">Scan QRIS:</div>
                        <img src="<?= $company['company_qris'] ?>" style="height:120px; width:120px; object-fit:contain;">
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if(!empty($invoice['payment_instructions'])): ?>
            <div style="margin-top:18px; padding:12px; background:#fff8e6; border:1px solid #f1e7c8; border-radius:8px; font-size:13px;">
                <strong>Instruksi Pembayaran:</strong><br>
                <?= nl2br(htmlspecialchars($invoice['payment_instructions'])) ?>
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
