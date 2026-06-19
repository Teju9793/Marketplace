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
if ($_SERVER["REQUEST_METHOD"] !== "POST")
    die(json_encode(["status"=>"error","msg"=>"Invalid request"]));

$_SESSION["mp_last_active"] = time();
$uid        = (int)$_SESSION["mp_user_id"];
$product_id = (int)($_POST["product_id"] ?? 0);

if (!$product_id)
    die(json_encode(["status"=>"error","msg"=>"Missing product ID"]));

$chk = $conn->prepare("SELECT id FROM products WHERE id=? AND user_id=?");
$chk->bind_param("ii", $product_id, $uid);
$chk->execute();
if (!$chk->get_result()->fetch_assoc())
    die(json_encode(["status"=>"error","msg"=>"Product not found"]));
$chk->close();

$imgs = $conn->prepare("SELECT image_path FROM product_images WHERE product_id=?");
$imgs->bind_param("i", $product_id);
$imgs->execute();
$ir = $imgs->get_result();
while ($img = $ir->fetch_assoc()) {
    $path = __DIR__ . "/" . $img["image_path"];
    if (file_exists($path)) @unlink($path);
}
$imgs->close();

$del = $conn->prepare("DELETE FROM products WHERE id=? AND user_id=?");
$del->bind_param("ii", $product_id, $uid);
$del->execute();
$del->close();

echo json_encode(["status"=>"ok","msg"=>"Product deleted"]);