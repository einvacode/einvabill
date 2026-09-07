<?php
/**
 * Auto Invoice Generator
 *
 * Creates the monthly invoice for every company customer and POP a few days
 * BEFORE its billing date (lead days, default 3), so the H-3 WhatsApp
 * reminders on the dashboard can go out before the due date. One invoice per
 * customer per month; due_date = the customer's billing date in that month.
 *
 * Runs from the Auto Tagihan page, automatically when an admin opens the
 * dashboard (see auto_invoice_autorun), or from cron:
 *   php app/auto_invoice_generator.php [--simulate]
 */

function auto_invoice_settings(PDO $db, int $tenant_id): array {
    try {
        $s = $db->prepare("SELECT auto_invoice_enabled, auto_invoice_lead_days FROM settings WHERE tenant_id = ?");
        $s->execute([$tenant_id]);
        $row = $s->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { $row = []; }
    return ['enabled' => isset($row['auto_invoice_enabled']) ? (int)$row['auto_invoice_enabled'] : 1, 'lead_days' => max(0, min(15, (int)($row['auto_invoice_lead_days'] ?? 3)))];
}

/** Due date for a billing day inside a month, clamped to that month's last day. */
function auto_invoice_due(string $ym, int $day): string {
    $last = (int)date('t', strtotime($ym . '-01'));
    return $ym . '-' . str_pad((string)min(max(1, $day), $last), 2, '0', STR_PAD_LEFT);
}

function generate_invoices_auto($db, $tenant_id = 1, $simulate = false, ?int $lead_days = null) {
    $tenant_id = (int)$tenant_id;
    if ($lead_days === null) $lead_days = auto_invoice_settings($db, $tenant_id)['lead_days'];
    $report = [
        'success' => true, 'simulate' => $simulate, 'timestamp' => date('Y-m-d H:i:s'), 'tenant_id' => $tenant_id, 'lead_days' => $lead_days,
        'customers_processed' => 0, 'invoices_created' => 0, 'invoices_skipped' => 0, 'skip_reasons' => [], 'errors' => [], 'details' => [],
    ];

    try {
        $today = date('Y-m-d');
        $months = [date('Y-m'), date('Y-m', strtotime('first day of next month'))];

        // Company customers and POPs only; customers registered by partners are billed by the partner.
        $partner_ids = $db->query("SELECT id FROM users WHERE role = 'partner' AND tenant_id = $tenant_id")->fetchAll(PDO::FETCH_COLUMN);
        $partner_list = !empty($partner_ids) ? implode(',', array_map('intval', $partner_ids)) : '0';
        $customers = $db->query("SELECT id, name, billing_date, monthly_fee, type FROM customers
            WHERE tenant_id = $tenant_id AND type IN ('customer', 'partner') AND monthly_fee > 0
              AND (created_by NOT IN ($partner_list) OR created_by = 0 OR created_by IS NULL)
            ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

        $exists = $db->prepare("SELECT id FROM invoices WHERE customer_id = ? AND tenant_id = ? AND strftime('%Y-%m', due_date) = ? LIMIT 1");
        $insert = $db->prepare("INSERT INTO invoices (customer_id, amount, due_date, status, created_at, discount, tenant_id) VALUES (?, ?, ?, 'Belum Lunas', CURRENT_TIMESTAMP, 0, ?)");

        foreach ($customers as $customer) {
            $cust_id = (int)$customer['id'];
            $cust_name = $customer['name'];
            $billing_day = max(1, min(31, (int)($customer['billing_date'] ?: 10)));
            $monthly_fee = (float)$customer['monthly_fee'];
            $report['customers_processed']++;
            $handled = false;

            foreach ($months as $ym) {
                $due_date = auto_invoice_due($ym, $billing_day);
                $generate_from = date('Y-m-d', strtotime($due_date . " -$lead_days days"));
                if ($today < $generate_from) continue; // not yet time for this month

                $exists->execute([$cust_id, $tenant_id, $ym]);
                if ($exists->fetchColumn()) {
                    if ($ym === $months[0]) { $report['invoices_skipped']++; $report['skip_reasons'][$cust_id] = 'Invoice already exists for this month';
                        $report['details'][] = ['customer_id' => $cust_id, 'customer_name' => $cust_name, 'billing_date' => $billing_day, 'due_date' => $due_date, 'status' => 'SKIPPED', 'reason' => 'Tagihan bulan ini sudah ada', 'amount' => $monthly_fee]; }
                    $handled = true;
                    continue;
                }
                if (!$simulate) {
                    try { $insert->execute([$cust_id, $monthly_fee, $due_date, $tenant_id]); }
                    catch (Exception $e) { $report['errors'][] = "Customer $cust_name (ID: $cust_id): " . $e->getMessage(); continue; }
                }
                $report['invoices_created']++;
                $report['details'][] = ['customer_id' => $cust_id, 'customer_name' => $cust_name, 'billing_date' => $billing_day, 'due_date' => $due_date, 'status' => $simulate ? 'WOULD BE CREATED' : 'CREATED', 'amount' => $monthly_fee];
                $handled = true;
            }

            if (!$handled) {
                $due_date = auto_invoice_due($months[0], $billing_day);
                $report['invoices_skipped']++;
                $report['skip_reasons'][$cust_id] = 'Billing date not yet reached';
                $report['details'][] = ['customer_id' => $cust_id, 'customer_name' => $cust_name, 'billing_date' => $billing_day, 'due_date' => $due_date, 'status' => 'WAITING', 'reason' => "Dibuat mulai " . date('d/m/Y', strtotime($due_date . " -$lead_days days")), 'amount' => $monthly_fee];
            }
        }
        $report['success'] = true;
    } catch (Exception $e) {
        $report['success'] = false;
        $report['errors'][] = "System error: " . $e->getMessage();
    }
    return $report;
}

/**
 * Called from the admin dashboard: runs the generator at most once per hour
 * per session when auto mode is on, logs runs that created invoices, and
 * returns the number created (0 when nothing to do).
 */
function auto_invoice_autorun(PDO $db, int $tenant_id): int {
    $cfg = auto_invoice_settings($db, $tenant_id);
    if (!$cfg['enabled']) return 0;
    $key = 'auto_invoice_last_run_' . $tenant_id;
    if (!empty($_SESSION[$key]) && time() - (int)$_SESSION[$key] < 3600) return 0;
    $_SESSION[$key] = time();
    $report = generate_invoices_auto($db, $tenant_id, false, $cfg['lead_days']);
    if ($report['invoices_created'] > 0) {
        try { $db->prepare("INSERT INTO auto_invoice_logs (tenant_id, report_json, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)")->execute([$tenant_id, json_encode(['auto' => true] + $report)]); } catch (Exception $e) {}
    }
    return (int)$report['invoices_created'];
}

// If called directly from command line
if (php_sapi_name() === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once __DIR__ . '/init.php';
    $simulate = in_array('--simulate', $argv);
    $report = generate_invoices_auto($db, 1, $simulate);
    echo "\n=== AUTO INVOICE GENERATOR REPORT ===\n";
    echo "Timestamp: " . $report['timestamp'] . "\n";
    echo "Mode: " . ($simulate ? "SIMULATION" : "PRODUCTION") . " (lead " . $report['lead_days'] . " days)\n";
    echo "Customers Processed: " . $report['customers_processed'] . "\n";
    echo "Invoices Created: " . $report['invoices_created'] . "\n";
    echo "Invoices Skipped: " . $report['invoices_skipped'] . "\n";
    if (!empty($report['errors'])) { echo "\nErrors:\n"; foreach ($report['errors'] as $err) echo "  - $err\n"; }
    echo "\n";
}
