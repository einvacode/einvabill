<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SESSION['user_name'] = 'CLI-Admin';
$_GET['page'] = 'admin_reports';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../index.php';
