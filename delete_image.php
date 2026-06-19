<?php ob_start(); session_start();
// ============================================================
// delete_image.php — Remove a single image (login required)
// ============================================================
require "config/db.php";

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
$uid      = (int)$_SESSION["mp_user_id"];
$image_id = (int)($_POST["image_id"] ?? 0);

if (!$image_id)
    die(json_encode(["status"=>"error","msg"=>"Missing image ID"]));

// Check ownership via join
$chk = $conn->prepare("
    SELECT pi.image_path FROM product_images pi
    JOIN products p ON p.id = pi.product_id
    WHERE pi.id=? AND p.user_id=?
");
$chk->bind_param("ii", $image_id, $uid);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$row) die(json_encode(["status"=>"error","msg"=>"Image not found"]));

@unlink($row["image_path"]);
$del = $conn->prepare("DELETE FROM product_images WHERE id=?");
$del->bind_param("i", $image_id);
$del->execute();
$del->close();

echo json_encode(["status"=>"ok","msg"=>"Image deleted"]);
