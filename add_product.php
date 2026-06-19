<?php
ob_start();
ini_set('display_errors', 0);  // NEVER show HTML errors — always return JSON
error_reporting(E_ALL);

require __DIR__ . "/db.php";

header("Content-Type: application/json");

// Catch any PHP fatal/warning that slipped through ob_start
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        echo json_encode(["status"=>"error","msg"=>"PHP Error: " . $err['message'] . " in " . basename($err['file']) . " line " . $err['line']]);
    }
});

if ($_SERVER["REQUEST_METHOD"] !== "POST")
    die(json_encode(["status"=>"error","msg"=>"Invalid request"]));

$name        = trim($_POST["name"]        ?? "");
$description = trim($_POST["description"] ?? "");
$price       = trim($_POST["price"]       ?? "");
$email       = trim($_POST["email"]       ?? "");
$whatsapp    = trim($_POST["whatsapp"]    ?? "");

if ($name === "" || $price === "" || $email === "" || $whatsapp === "")
    die(json_encode(["status"=>"error","msg"=>"All fields are required"]));
if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    die(json_encode(["status"=>"error","msg"=>"Invalid email address"]));
if (!is_numeric($price) || (float)$price <= 0)
    die(json_encode(["status"=>"error","msg"=>"Enter a valid price"]));
if (!preg_match('/^[0-9]{10,}$/', $whatsapp))
    die(json_encode(["status"=>"error","msg"=>"Enter a valid 10-digit WhatsApp number"]));

// Safe check for files
$hasFiles = !empty($_FILES["images"]["name"]) && is_array($_FILES["images"]["name"]) && $_FILES["images"]["name"][0] !== "";
if (!$hasFiles)
    die(json_encode(["status"=>"error","msg"=>"Upload at least one image"]));

// Find or create user
$stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
    $user_id = $row["id"];
    $upd = $conn->prepare("UPDATE users SET whatsapp=? WHERE id=?");
    $upd->bind_param("si", $whatsapp, $user_id);
    $upd->execute();
    $upd->close();
} else {
    $ins = $conn->prepare("INSERT INTO users (email, whatsapp) VALUES (?, ?)");
    $ins->bind_param("ss", $email, $whatsapp);
    if (!$ins->execute()) die(json_encode(["status"=>"error","msg"=>"User insert failed: ".$ins->error]));
    $user_id = $ins->insert_id;
    $ins->close();
}

// Insert product
$price_val = (float)$price;
$stmt = $conn->prepare("INSERT INTO products (user_id, name, description, price, whatsapp) VALUES (?, ?, ?, ?, ?)");
if (!$stmt) die(json_encode(["status"=>"error","msg"=>"Product prepare failed: ".$conn->error]));
$stmt->bind_param("issds", $user_id, $name, $description, $price_val, $whatsapp);
if (!$stmt->execute()) die(json_encode(["status"=>"error","msg"=>"Product insert failed: ".$stmt->error]));
$product_id = $stmt->insert_id;
$stmt->close();

// Upload images
$uploadDir   = __DIR__ . "/uploads/products/";
$webPath     = "uploads/products/";
$allowedMime = ["image/jpeg","image/png","image/webp","image/gif"];
$extMap      = ["image/jpeg"=>"jpg","image/png"=>"png","image/webp"=>"webp","image/gif"=>"gif"];
$uploaded    = 0;
$errors      = [];

if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
if (!is_writable($uploadDir)) {
    $conn->prepare("DELETE FROM products WHERE id=?")->bind_param("i",$product_id)->execute();
    die(json_encode(["status"=>"error","msg"=>"uploads/products/ is not writable. Create the folder manually in File Manager."]));
}

foreach ($_FILES["images"]["tmp_name"] as $i => $tmp) {
    if ($uploaded >= 5) break;
    if ($_FILES["images"]["error"][$i] !== UPLOAD_ERR_OK) {
        $errors[] = "Image ".($i+1).": upload error ".$_FILES["images"]["error"][$i];
        continue;
    }
    if ($_FILES["images"]["size"][$i] > 2*1024*1024) {
        $errors[] = "Image ".($i+1).": over 2MB";
        continue;
    }
    $mime = function_exists("mime_content_type") ? mime_content_type($tmp) : (@getimagesize($tmp)["mime"] ?? "");
    if (!in_array($mime, $allowedMime)) {
        $errors[] = "Image ".($i+1).": invalid type ($mime)";
        continue;
    }
    $ext      = $extMap[$mime];
    $filename = "p".$product_id."_".time()."_".$i.".".$ext;
    $dest     = $uploadDir.$filename;
    if (move_uploaded_file($tmp, $dest)) {
        @chmod($dest, 0644);
        $is = $conn->prepare("INSERT INTO product_images (product_id, image_path) VALUES (?, ?)");
        $imgPath = $webPath . $filename;
        $is->bind_param("is", $product_id, $imgPath);
        $is->execute();
        $is->close();
        $uploaded++;
    } else {
        $errors[] = "Image ".($i+1).": move_uploaded_file failed";
    }
}

if ($uploaded === 0) {
    $conn->prepare("DELETE FROM products WHERE id=?")->bind_param("i",$product_id)->execute();
    die(json_encode(["status"=>"error","msg"=>"No images saved. ".implode(" | ", $errors)]));
}

$msg = "Product added!";
if (!empty($errors)) $msg .= " (Some skipped: ".implode(", ", $errors).")";
echo json_encode(["status"=>"ok","msg"=>$msg,"id"=>$product_id]);