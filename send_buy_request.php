<?php
ob_start();
require __DIR__ . "/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: marketplace.html");
    exit;
}

$product_id     = (int)($_POST["product_id"]    ?? 0);
$buyer_name     = trim($_POST["buyer_name"]     ?? "");
$buyer_whatsapp = trim($_POST["buyer_whatsapp"] ?? "");

if ($product_id <= 0 || $buyer_name === "" || !preg_match('/^[0-9]{10,}$/', $buyer_whatsapp)) {
    header("Location: marketplace.html");
    exit;
}

// ── Fetch product + seller ───────────────────────────────────
$stmt = $conn->prepare("
    SELECT p.name AS product_name, p.price, p.whatsapp AS seller_whatsapp,
           u.email AS seller_email
    FROM products p
    JOIN users u ON u.id = p.user_id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: marketplace.html");
    exit;
}

$product_name    = $product["product_name"];
$price           = number_format((float)$product["price"], 2);
$seller_whatsapp = $product["seller_whatsapp"];
$seller_email    = $product["seller_email"];

// ── Save to DB ───────────────────────────────────────────────
$ins = $conn->prepare("
    INSERT INTO buy_requests (product_id, buyer_name, buyer_whatsapp, created_at)
    VALUES (?, ?, ?, NOW())
");
if ($ins) {
    $ins->bind_param("iss", $product_id, $buyer_name, $buyer_whatsapp);
    $ins->execute();
    $ins->close();
}

// ── Email to seller ──────────────────────────────────────────
$mailerFile = __DIR__ . "/mailer/src/PHPMailer.php";
if (!empty($seller_email) && file_exists($mailerFile)) {
    require __DIR__ . "/mailer/src/Exception.php";
    require __DIR__ . "/mailer/src/PHPMailer.php";
    require __DIR__ . "/mailer/src/SMTP.php";

    $email_subject = "🛍️ New Buy Request — Study Matrix Marketplace";

    $email_body =
        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
        "   🛒  STUDY MATRIX MARKETPLACE\n" .
        "   New Buy Request Notification\n" .
        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
        "Hello,\n\n" .
        "Great news! You have received a new buy request for your listed product.\n\n" .
        "📦  PRODUCT DETAILS\n" .
        "───────────────────────────────\n" .
        "🏷️  Product Name  :  {$product_name}\n" .
        "💰  Price         :  Rs. {$price}\n\n" .
        "👤  BUYER DETAILS\n" .
        "───────────────────────────────\n" .
        "🙍  Buyer Name    :  {$buyer_name}\n" .
        "📱  WhatsApp No.  :  {$buyer_whatsapp}\n\n" .
        "───────────────────────────────\n" .
        "📲  Please contact the buyer directly on WhatsApp\n" .
        "    to proceed with the sale.\n\n" .
        "Thank you for listing on Study Matrix Marketplace!\n\n" .
        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
        "  Study Matrix Marketplace\n" .
        "  Your trusted study material platform\n" .
        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = MAIL_PORT;
        $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
        $mail->addAddress($seller_email);
        $mail->isHTML(true);
        $mail->Subject = $email_subject;
        $mail->Body    = nl2br(htmlspecialchars($email_body, ENT_QUOTES));
        $mail->AltBody = $email_body;
        $mail->send();
    } catch (\Exception $e) {
        error_log("Buy request email error: " . $mail->ErrorInfo);
    }
}

// ── WhatsApp message ─────────────────────────────────────────
$wa_clean = preg_replace('/[^0-9]/', '', $seller_whatsapp);
if (strlen($wa_clean) === 10) $wa_clean = '91' . $wa_clean;

$wa_message =
    "━━━━━━━━━━━━━━━━━━━━━━\n" .
    "🛒  *STUDY MATRIX MARKETPLACE*\n" .
    "      New Buy Request\n" .
    "━━━━━━━━━━━━━━━━━━━━━━\n\n" .
    "Hello! 👋 You have received a new buy request.\n\n" .
    "📦 *PRODUCT DETAILS*\n" .
    "──────────────────────\n" .
    "🏷️ Product  :  *{$product_name}*\n" .
    "💰 Price    :  *Rs. {$price}*\n\n" .
    "👤 *BUYER DETAILS*\n" .
    "──────────────────────\n" .
    "🙍 Name      :  *{$buyer_name}*\n" .
    "📱 WhatsApp  :  *{$buyer_whatsapp}*\n\n" .
    "──────────────────────\n" .
    "📲 Please contact the buyer directly to proceed.\n\n" .
    "✅ _Sent via Study Matrix Marketplace_";

$whatsapp_url = "https://api.whatsapp.com/send?phone=" . $wa_clean
              . "&text=" . rawurlencode($wa_message);

// ── Redirect to WhatsApp ─────────────────────────────────────
header("Location: " . $whatsapp_url);
exit;