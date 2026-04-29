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
