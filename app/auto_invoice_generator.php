<?php
/**
 * Auto Invoice Generator
 * Automatically creates invoices for customers based on their billing_date
 * Can be called via:
 * 1. Direct HTTP request: /index.php?page=admin_auto_invoice_run
 * 2. Admin Dashboard button
 * 3. Command line: php auto_invoice_generator.php
 */

function generate_invoices_auto($db, $tenant_id = 1, $simulate = false) {
    /**
     * Generate invoices automatically for all customers
     * that need billing this month based on their billing_date
     * 
     * @param PDO $db Database connection
     * @param int $tenant_id Tenant ID to process
     * @param bool $simulate If true, don't create invoices, just report what would happen
     * @return array Report with statistics and details
     */
    
    $report = [
        'success' => true,
        'simulate' => $simulate,
        'timestamp' => date('Y-m-d H:i:s'),
        'tenant_id' => $tenant_id,
        'customers_processed' => 0,
        'invoices_created' => 0,
        'invoices_skipped' => 0,
        'skip_reasons' => [],
        'errors' => [],
        'details' => []
    ];
    
    try {
        $today = date('Y-m-d');
        $current_month = date('Y-m');
        $current_day = (int)date('d');
        
        // Get all ACTIVE customers for this tenant
        $customers = $db->query("
            SELECT id, name, billing_date, monthly_fee, type, created_by, collector_id
            FROM customers 
            WHERE tenant_id = $tenant_id 
            AND type IN ('customer', 'partner')
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($customers as $customer) {
            $cust_id = $customer['id'];
            $cust_name = $customer['name'];
            $billing_date = (int)($customer['billing_date'] ?? 10);
            $monthly_fee = (float)$customer['monthly_fee'];
            
            // Normalize billing_date (1-28 to handle month-end edge cases)
            if ($billing_date > 28) $billing_date = 28;
            if ($billing_date < 1) $billing_date = 1;
            
            $report['customers_processed']++;
            
            // Check if today is on or after billing date AND invoice not yet created for this month
            if ($current_day >= $billing_date) {
                // Construct due_date: current month + billing_date
                $due_date = $current_month . '-' . str_pad($billing_date, 2, '0', STR_PAD_LEFT);
                
                // Verify it's a valid date (handle Feb 29, 30, 31 edge cases)
                $check_date = DateTime::createFromFormat('Y-m-d', $due_date);
                if (!$check_date) {
                    // If date is invalid (e.g., Feb 30), use last day of month
                    $last_day = date('t', strtotime($current_month . '-01'));
                    $due_date = $current_month . '-' . str_pad($last_day, 2, '0', STR_PAD_LEFT);
                }
                
                // Check if invoice already exists for this customer this month
                $existing = $db->query("
                    SELECT id FROM invoices 
                    WHERE customer_id = $cust_id 
                    AND tenant_id = $tenant_id
                    AND strftime('%Y-%m', due_date) = " . $db->quote($current_month)
                )->fetchColumn();
                
                if ($existing) {
                    $report['invoices_skipped']++;
                    $report['skip_reasons'][$cust_id] = 'Invoice already exists for this month';
                    $report['details'][] = [
                        'customer_id' => $cust_id,
                        'customer_name' => $cust_name,
                        'billing_date' => $billing_date,
                        'due_date' => $due_date,
                        'status' => 'SKIPPED',
                        'reason' => 'Invoice already exists',
                        'amount' => $monthly_fee
                    ];
                    continue;
                }
                
                // Create invoice
                if (!$simulate) {
                    try {
                        $stmt = $db->prepare("
                            INSERT INTO invoices 
                            (customer_id, amount, due_date, status, created_at, discount, tenant_id)
                            VALUES (?, ?, ?, 'Belum Lunas', CURRENT_TIMESTAMP, 0, ?)
                        ");
                        $stmt->execute([$cust_id, $monthly_fee, $due_date, $tenant_id]);
                        
                        $report['invoices_created']++;
                        $report['details'][] = [
                            'customer_id' => $cust_id,
                            'customer_name' => $cust_name,
                            'billing_date' => $billing_date,
                            'due_date' => $due_date,
                            'status' => 'CREATED',
                            'amount' => $monthly_fee
                        ];
                    } catch (Exception $e) {
                        $report['errors'][] = "Customer $cust_name (ID: $cust_id): " . $e->getMessage();
                        $report['details'][] = [
                            'customer_id' => $cust_id,
                            'customer_name' => $cust_name,
                            'billing_date' => $billing_date,
                            'due_date' => $due_date,
                            'status' => 'ERROR',
                            'reason' => $e->getMessage(),
                            'amount' => $monthly_fee
                        ];
                    }
                } else {
                    // Simulate mode - just report
                    $report['invoices_created']++;
                    $report['details'][] = [
                        'customer_id' => $cust_id,
                        'customer_name' => $cust_name,
                        'billing_date' => $billing_date,
                        'due_date' => $due_date,
                        'status' => 'WOULD BE CREATED',
                        'amount' => $monthly_fee
                    ];
                }
            } else {
                // Not yet time to bill this customer
                $report['invoices_skipped']++;
                $report['skip_reasons'][$cust_id] = 'Billing date not yet reached this month';
                $report['details'][] = [
                    'customer_id' => $cust_id,
                    'customer_name' => $cust_name,
                    'billing_date' => $billing_date,
                    'due_date' => $current_month . '-' . str_pad($billing_date, 2, '0', STR_PAD_LEFT),
                    'status' => 'WAITING',
                    'reason' => "Billing date: $billing_date (Today: $current_day)",
                    'amount' => $monthly_fee
                ];
            }
        }
        
        $report['success'] = true;
        
    } catch (Exception $e) {
        $report['success'] = false;
        $report['errors'][] = "System error: " . $e->getMessage();
    }
    
    return $report;
}

// If called directly from command line
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/init.php';
    
    $simulate = in_array('--simulate', $argv);
    $report = generate_invoices_auto($db, 1, $simulate);
    
    echo "\n=== AUTO INVOICE GENERATOR REPORT ===\n";
    echo "Timestamp: " . $report['timestamp'] . "\n";
    echo "Mode: " . ($simulate ? "SIMULATION" : "PRODUCTION") . "\n";
    echo "Customers Processed: " . $report['customers_processed'] . "\n";
    echo "Invoices Created: " . $report['invoices_created'] . "\n";
    echo "Invoices Skipped: " . $report['invoices_skipped'] . "\n";
    
    if (!empty($report['errors'])) {
        echo "\nErrors:\n";
        foreach ($report['errors'] as $err) {
            echo "  - $err\n";
        }
    }
    
    echo "\n";
}
