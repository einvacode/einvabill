<?php
/**
 * Invoice Reminder Module
 * Get invoices approaching due date (H-3, H-5, H-7, etc)
 * Used for dashboard alerts and WhatsApp bulk send
 */

function get_invoices_reminder($db, $tenant_id = 1, $days_before = 3, $user_id = 0, $user_role = 'admin') {
    /**
     * Get invoices approaching due date
     * 
     * @param PDO $db Database connection
     * @param int $tenant_id Tenant ID
     * @param int $days_before How many days before due date (default 3)
     * @param int $user_id Current user ID (for scope filtering)
     * @param string $user_role Current user role (admin/partner/collector)
     * @return array List of invoices with customer info
     */
    
    $today = date('Y-m-d');
    $reminder_date = date('Y-m-d', strtotime("+{$days_before} days"));
    
    // Base query - get unpaid invoices that will be due within X days
    $query = "
        SELECT 
            i.id,
            i.customer_id,
            i.amount,
            i.discount,
            i.due_date,
            i.status,
            i.created_at,
            c.name as customer_name,
            c.contact as customer_contact,
            c.customer_code,
            c.package_name,
            c.monthly_fee,
            c.created_by,
            c.collector_id,
            CAST((julianday(i.due_date) - julianday(date('now'))) AS INTEGER) as days_until_due
        FROM invoices i
        JOIN customers c ON i.customer_id = c.id
        WHERE i.tenant_id = $tenant_id
        AND i.status = 'Belum Lunas'
        AND i.due_date >= " . $db->quote($today) . "
        AND i.due_date <= " . $db->quote($reminder_date);
    
    // Apply scope filtering based on role
    if ($user_role === 'partner') {
        $query .= " AND c.created_by = $user_id";
    } elseif ($user_role === 'collector') {
        $query .= " AND c.collector_id = $user_id";
    }
    // Admin: no additional filter (can see all)
    
    $query .= " ORDER BY i.due_date ASC";
    
    $invoices = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    return $invoices;
}

function get_reminder_statistics($db, $tenant_id = 1, $user_id = 0, $user_role = 'admin') {
    /**
     * Get statistics for dashboard widget
     * Shows summary of reminders by urgency
     */
    
    $today = date('Y-m-d');
    
    $stats = [
        'h_1' => 0,   // Due tomorrow
        'h_3' => 0,   // Due in 3 days
        'h_5' => 0,   // Due in 5 days
        'h_7' => 0,   // Due in 7 days
        'overdue' => 0, // Already past due
        'total_amount' => 0
    ];
    
    try {
        // Overdue (past due date)
        $overdue_q = "
            SELECT COUNT(*) as cnt, COALESCE(SUM(i.amount - COALESCE(i.discount, 0)), 0) as amt
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.tenant_id = $tenant_id
            AND i.status = 'Belum Lunas'
            AND i.due_date < " . $db->quote($today);
        
        if ($user_role === 'partner') {
            $overdue_q .= " AND c.created_by = $user_id";
        } elseif ($user_role === 'collector') {
            $overdue_q .= " AND c.collector_id = $user_id";
        }
        
        $overdue = $db->query($overdue_q)->fetch();
        $stats['overdue'] = (int)($overdue['cnt'] ?? 0);
        $stats['total_amount'] += (float)($overdue['amt'] ?? 0);
        
        // H-1 (tomorrow)
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $h1_q = "
            SELECT COUNT(*) as cnt, COALESCE(SUM(i.amount - COALESCE(i.discount, 0)), 0) as amt
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.tenant_id = $tenant_id
            AND i.status = 'Belum Lunas'
            AND i.due_date = " . $db->quote($tomorrow);
        
        if ($user_role === 'partner') {
            $h1_q .= " AND c.created_by = $user_id";
        } elseif ($user_role === 'collector') {
            $h1_q .= " AND c.collector_id = $user_id";
        }
        
        $h1 = $db->query($h1_q)->fetch();
        $stats['h_1'] = (int)($h1['cnt'] ?? 0);
        $stats['total_amount'] += (float)($h1['amt'] ?? 0);
        
        // H-3
        $in3days = date('Y-m-d', strtotime('+3 days'));
        $h3_q = "
            SELECT COUNT(*) as cnt, COALESCE(SUM(i.amount - COALESCE(i.discount, 0)), 0) as amt
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.tenant_id = $tenant_id
            AND i.status = 'Belum Lunas'
            AND i.due_date <= " . $db->quote($in3days) . "
            AND i.due_date >= " . $db->quote($tomorrow);
        
        if ($user_role === 'partner') {
            $h3_q .= " AND c.created_by = $user_id";
        } elseif ($user_role === 'collector') {
            $h3_q .= " AND c.collector_id = $user_id";
        }
        
        $h3 = $db->query($h3_q)->fetch();
        $stats['h_3'] = (int)($h3['cnt'] ?? 0);
        $stats['total_amount'] += (float)($h3['amt'] ?? 0);
        
        // H-5
        $in5days = date('Y-m-d', strtotime('+5 days'));
        $h5_q = "
            SELECT COUNT(*) as cnt, COALESCE(SUM(i.amount - COALESCE(i.discount, 0)), 0) as amt
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.tenant_id = $tenant_id
            AND i.status = 'Belum Lunas'
            AND i.due_date <= " . $db->quote($in5days) . "
            AND i.due_date > " . $db->quote($in3days);
        
        if ($user_role === 'partner') {
            $h5_q .= " AND c.created_by = $user_id";
        } elseif ($user_role === 'collector') {
            $h5_q .= " AND c.collector_id = $user_id";
        }
        
        $h5 = $db->query($h5_q)->fetch();
        $stats['h_5'] = (int)($h5['cnt'] ?? 0);
        $stats['total_amount'] += (float)($h5['amt'] ?? 0);
        
        // H-7
        $in7days = date('Y-m-d', strtotime('+7 days'));
        $h7_q = "
            SELECT COUNT(*) as cnt, COALESCE(SUM(i.amount - COALESCE(i.discount, 0)), 0) as amt
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.tenant_id = $tenant_id
            AND i.status = 'Belum Lunas'
            AND i.due_date <= " . $db->quote($in7days) . "
            AND i.due_date > " . $db->quote($in5days);
        
        if ($user_role === 'partner') {
            $h7_q .= " AND c.created_by = $user_id";
        } elseif ($user_role === 'collector') {
            $h7_q .= " AND c.collector_id = $user_id";
        }
        
        $h7 = $db->query($h7_q)->fetch();
        $stats['h_7'] = (int)($h7['cnt'] ?? 0);
        $stats['total_amount'] += (float)($h7['amt'] ?? 0);
        
    } catch (Exception $e) {
        // Return default stats if error
    }
    
    return $stats;
}

function format_days_until($due_date) {
    /**
     * Format days until due date
     * Returns: "Hari ini", "Besok", "3 hari lagi", "Overdue 2 hari", etc
     */
    
    $today = date('Y-m-d');
    $due = new DateTime($due_date);
    $now = new DateTime($today);
    
    $diff = $now->diff($due);
    $days = $diff->days;
    
    if ($diff->invert) {
        // Past due
        return "Overdue " . $days . " hari";
    } elseif ($days === 0) {
        return "Hari ini";
    } elseif ($days === 1) {
        return "Besok";
    } else {
        return "$days hari lagi";
    }
}

?>
