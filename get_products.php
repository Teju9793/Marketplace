<?php
ob_start();
ini_set("display_errors", 0);
error_reporting(0);
require __DIR__ . "/db.php";

header("Content-Type: application/json");

$products = [];
$res = $conn->query("SELECT id, name, description, price, whatsapp, created_at FROM products ORDER BY id DESC");
if (!$res) die(json_encode([]));

while ($row = $res->fetch_assoc()) {
    $pid = (int)$row["id"];
    $is = $conn->prepare("SELECT image_path FROM product_images WHERE product_id = ?");
    $is->bind_param("i", $pid);
    $is->execute();
    $ir = $is->get_result();
    $imgs = [];
    while ($img = $ir->fetch_assoc()) $imgs[] = $img["image_path"];
    $is->close();
    $row["images"] = $imgs;
    $products[] = $row;
}
echo json_encode($products);