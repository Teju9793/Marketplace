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
$name       = trim($_POST["name"]        ?? "");
$desc       = trim($_POST["description"] ?? "");
$price      = trim($_POST["price"]       ?? "");

if (!$product_id || $name === "" || $price === "")
    die(json_encode(["status"=>"error","msg"=>"Missing fields"]));
if (!is_numeric($price) || (float)$price <= 0)
    die(json_encode(["status"=>"error","msg"=>"Invalid price"]));

$chk = $conn->prepare("SELECT id FROM products WHERE id=? AND user_id=?");
$chk->bind_param("ii", $product_id, $uid);
$chk->execute();
if (!$chk->get_result()->fetch_assoc())
    die(json_encode(["status"=>"error","msg"=>"Product not found"]));
$chk->close();

$price_val = (float)$price;
$stmt = $conn->prepare("UPDATE products SET name=?,description=?,price=? WHERE id=? AND user_id=?");
$stmt->bind_param("ssdii", $name, $desc, $price_val, $product_id, $uid);
$stmt->execute();
$stmt->close();

if (!empty($_FILES["images"]["name"][0])) {
    $uploadDir   = __DIR__ . "/uploads/products/";
    $webPath     = "uploads/products/";
    $allowedMime = ["image/jpeg","image/png","image/webp","image/gif"];
    $extMap      = ["image/jpeg"=>"jpg","image/png"=>"png","image/webp"=>"webp","image/gif"=>"gif"];
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    foreach ($_FILES["images"]["tmp_name"] as $i => $tmp) {
        if ($_FILES["images"]["error"][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES["images"]["size"][$i] > 2*1024*1024) continue;
        $mime = function_exists("mime_content_type") ? mime_content_type($tmp) : (@getimagesize($tmp)["mime"] ?? "");
        if (!in_array($mime, $allowedMime)) continue;
        $ext  = $extMap[$mime];
        $fn   = "p".$product_id."_edit_".time()."_".$i.".".$ext;
        $dest = $uploadDir.$fn;
        if (move_uploaded_file($tmp, $dest)) {
            @chmod($dest, 0644);
            $is = $conn->prepare("INSERT INTO product_images (product_id, image_path) VALUES (?,?)");
            $imgPath = $webPath . $fn;
            $is->bind_param("is", $product_id, $imgPath);
            $is->execute();
            $is->close();
        }
    }
}

echo json_encode(["status"=>"ok","msg"=>"Product updated"]);