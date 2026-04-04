<?php
$db_file = __DIR__ . '/../database.sqlite';

try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Running migration...<br>";

    // Add router fields to settings
    $queries = [
        "ALTER TABLE settings ADD COLUMN router_ip TEXT;",
        "ALTER TABLE settings ADD COLUMN router_user TEXT;",
        "ALTER TABLE settings ADD COLUMN router_pass TEXT;",
        "ALTER TABLE settings ADD COLUMN router_port TEXT DEFAULT '8728';"
    ];

    foreach ($queries as $query) {
        try {
            $db->exec($query);
            echo "Successfully executed: " . $query . "<br>";
        } catch (PDOException $e) {
            echo "Skipping/Error on query: " . $query . " - Error: " . $e->getMessage() . "<br>";
        }
    }

    echo "Migration 4 completed.<br>";

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>
