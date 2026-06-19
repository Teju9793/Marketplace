<?php
ob_start();
ini_set("display_errors", 0);
error_reporting(0); session_start();
require __DIR__ . "/db.php";

header("Content-Type: application/json");

if (!empty($_SESSION["mp_last_active"]) && (time() - $_SESSION["mp_last_active"] > 1800)) {
    session_unset(); session_destroy();
    die(json_encode(["status"=>"auth","msg"=>"Session expired"]));
}
if (empty($_SESSION["mp_user_id"]))
    die(json_encode(["status"=>"auth","msg"=>"Login required"]));

$_SESSION["mp_last_active"] = time();
$uid = (int)$_SESSION["mp_user_id"];

$stmt = $conn->prepare("SELECT id,name,description,price,whatsapp,created_at FROM products WHERE user_id=? ORDER BY id DESC");
$stmt->bind_param("i", $uid);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$products = [];
while ($row = $res->fetch_assoc()) {
    $pid = (int)$row["id"];
    $is = $conn->prepare("SELECT image_path FROM product_images WHERE product_id=?");
    $is->bind_param("i", $pid);
    $is->execute();
    $imgs = [];
    $ir = $is->get_result();
    while ($img = $ir->fetch_assoc()) $imgs[] = $img["image_path"];
    $is->close();
    $row["images"] = $imgs;
    $products[] = $row;
}

echo json_encode(["status"=>"ok","products"=>$products]);