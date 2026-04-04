<?php
// Migration script to add indexes for better performance with SQLite
try {
    $db = new PDO('sqlite:' . __DIR__ . '/../database.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Memulai optimasi database (Indexing)...\n";

    // Customers Table Indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_name ON customers(name)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_customer_code ON customers(customer_code)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_area ON customers(area)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_type ON customers(type)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_billing_date ON customers(billing_date)");

    // Invoices Table Indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_customer_id ON invoices(customer_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_due_date ON invoices(due_date)");

    // Payments Table Indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_payments_invoice_id ON payments(invoice_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_payments_payment_date ON payments(payment_date)");

    echo "Optimasi selesai! Database sekarang lebih responsif untuk ribuan data.\n";

} catch (Exception $e) {
    echo "Gagal: " . $e->getMessage();
}
