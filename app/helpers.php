<?php
/**
 * WhatsApp Template Parser Helper
 * Centralizes the logic for dynamic variable replacement in WhatsApp messages.
 */

if (!function_exists('parse_wa_template')) {
    function parse_wa_template($template, $data = []) {
        if (empty($template)) return "";

        // Ensure numeric values are formatted for display
        $format_rp = function($val) {
            return 'Rp ' . number_format(floatval($val), 0, ',', '.');
        };

        // Prepare variables with defaults
        $vars = [
            '{nama}'              => $data['name'] ?? 'Pelanggan',
            '{id_cust}'           => $data['id_cust'] ?? '-',
            '{paket}'             => $data['package'] ?? '-',
            '{bulan}'             => $data['period'] ?? date('M Y'),
            '{tagihan}'           => is_numeric($data['tagihan'] ?? '') ? $format_rp($data['tagihan']) : ($data['tagihan'] ?? 'Rp 0'),
            '{jatuh_tempo}'       => $data['due_date'] ?? '-',
            '{rekening}'          => $data['rekening'] ?? 'Hubungi Admin',
            '{tunggakan}'         => is_numeric($data['tunggakan'] ?? '') ? $format_rp($data['tunggakan']) : ($data['tunggakan'] ?? 'Rp 0'),
            '{total_harus}'       => is_numeric($data['total_payment'] ?? '') ? $format_rp($data['total_payment']) : ($data['total_payment'] ?? 'Rp 0'),
            '{total_bayar}'       => is_numeric($data['total_paid'] ?? '') ? $format_rp($data['total_paid']) : ($data['total_paid'] ?? 'Rp 0'),
            '{sisa_tunggakan}'    => is_numeric($data['sisa_tunggakan'] ?? '') ? $format_rp($data['sisa_tunggakan']) : ($data['sisa_tunggakan'] ?? 'Rp 0'),
            '{link_tagihan}'      => $data['portal_link'] ?? '',
            '{link_nota}'         => $data['nota_link'] ?? '',
            '{admin}'             => $data['admin_name'] ?? 'Admin',
            '{perusahaan}'        => $data['company_name'] ?? 'BILLING',
            '{waktu_bayar}'       => $data['payment_time'] ?? date('d/m/Y H:i') . ' WIB',
            '{status_pembayaran}' => $data['payment_status'] ?? 'LUNAS',
        ];

        // Apply replacements
        $message = str_replace(array_keys($vars), array_values($vars), $template);

        // Fallback: If {total_harus} is in template but not provided, but we have tagihan+tunggakan
        if (strpos($template, '{total_harus}') !== false && !isset($data['total_payment'])) {
            $calc_total = floatval($data['tagihan'] ?? 0) + floatval($data['tunggakan'] ?? 0);
            $message = str_replace('{total_harus}', $format_rp($calc_total), $message);
        }

        // Clean up double asterisk/bolding artifacts if any
        $message = str_replace('**', '*', $message);

        return $message;
    }
}

if (!function_exists('get_wa_delivery_status')) {
    function get_wa_delivery_status($db, $invoice_id) {
        try {
            $stmt = $db->prepare("
                SELECT * FROM wa_message_logs 
                WHERE invoice_id = ? 
                ORDER BY sent_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$invoice_id]);
            $log = $stmt->fetch();
            
            if ($log) {
                return [
                    'status' => $log['status'] ?? 'sent',
                    'sent_at' => $log['sent_at'] ?? '',
                    'customer_name' => $log['customer_name'] ?? '',
                    'phone' => $log['phone_number'] ?? ''
                ];
            }
            return null;
        } catch (Exception $e) {
            return null;
        }
    }
}

if (!function_exists('render_wa_status_badge')) {
    function render_wa_status_badge($db, $invoice_id) {
        $log = get_wa_delivery_status($db, $invoice_id);
        
        if (!$log) {
            return '<span style="font-size:11px; color:#999;">ℹ Belum terkirim</span>';
        }
        
        $sent_time = date('d M Y H:i', strtotime($log['sent_at']));
        $status = $log['status'] ?? 'sent';
        
        if ($status === 'sent') {
            return '<span style="font-size:11px; color:#10b981; font-weight:600;">✅ Terkirim ' . $sent_time . '</span>';
        } elseif ($status === 'delivered') {
            return '<span style="font-size:11px; color:#0ea5e9; font-weight:600;">✔✔ Diterima ' . $sent_time . '</span>';
        } elseif ($status === 'read') {
            return '<span style="font-size:11px; color:#8b5cf6; font-weight:600;">👁 Dibaca ' . $sent_time . '</span>';
        } else {
            return '<span style="font-size:11px; color:#ef4444;">❌ Gagal ' . $sent_time . '</span>';
        }
    }
}
