<?php
ob_start();
ini_set("display_errors", 0);
error_reporting(0);

require_once __DIR__ . "/config.php";

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

if ($conn->connect_error) {
    error_log("DB Error: " . $conn->connect_error);
    header("Content-Type: application/json");
    die(json_encode(["status"=>"error","msg"=>"Database connection failed"]));
}
$conn->set_charset("utf8mb4");