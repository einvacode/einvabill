<?php
$db_file = __DIR__ . '/../database.sqlite';

try {
    $db = new PDO('sqlite:' . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Running migration...<br>";

    $queries = [
        "ALTER TABLE customers ADD COLUMN area TEXT;",
        "ALTER TABLE users ADD COLUMN area TEXT;",
        "ALTER TABLE users ADD COLUMN customer_id INTEGER;"
    ];

    foreach ($queries as $query) {
        try {
            $db->exec($query);
            echo "Successfully executed: " . $query . "<br>";
        } catch (PDOException $e) {
            echo "Skipping/Error on query: " . $query . " - Error: " . $e->getMessage() . "<br>";
        }
    }

    echo "Migration 5 completed.<br>";

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?>
