<?php
require_once __DIR__ . '/app/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['test'] = 'Session works!';
    session_write_close();
    header("Location: test_session.php");
    exit;
}

echo "<h1>Test Session</h1>";
if (isset($_SESSION['test'])) {
    echo "<p>✅ Session works: " . $_SESSION['test'] . "</p>";
} else {
    echo "<p>❌ Session not working. Try submitting the form.</p>";
    echo "<form method='post'><button type='submit'>Test Session</button></form>";
}

echo "<p>Session save path: " . ini_get('session.save_path') . "</p>";
echo "<p>Session ID: " . session_id() . "</p>";
?>