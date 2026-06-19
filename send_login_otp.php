<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(["status"=>"error","msg"=>"Server error: ".$err['message']." (line ".$err['line'].")"]);
    }
});

session_start();
require __DIR__ . "/db.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST")
    die(json_encode(["status"=>"error","msg"=>"Invalid request"]));

$whatsapp = trim($_POST["whatsapp"] ?? "");
if (!preg_match('/^[0-9]{10,}$/', $whatsapp))
    die(json_encode(["status"=>"error","msg"=>"Enter a valid WhatsApp number"]));

// Find user
$stmt = $conn->prepare("SELECT id, email FROM users WHERE whatsapp = ? LIMIT 1");
$stmt->bind_param("s", $whatsapp);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user)
    die(json_encode(["status"=>"error","msg"=>"No account found. Add a product first."]));

$email   = $user["email"];
$user_id = $user["id"];

// Rate limit: 60s — compare entirely inside MySQL to avoid timezone issues
$chk = $conn->prepare("SELECT UNIX_TIMESTAMP() - UNIX_TIMESTAMP(created_at) AS age FROM otp_codes WHERE email = ?");
$chk->bind_param("s", $email);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if ($row !== null) {
    $age = (int)$row["age"];
    if ($age < 60) {
        $wait = 60 - $age;
        die(json_encode(["status"=>"error","msg"=>"Wait {$wait}s before requesting another OTP."]));
    }
}


// Generate + save OTP
$otp = str_pad((string)random_int(0, 999999), 6, "0", STR_PAD_LEFT);
$us  = $conn->prepare("INSERT INTO otp_codes (email,otp,created_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE otp=?,created_at=NOW()");
$us->bind_param("sss", $email, $otp, $otp);
$us->execute();
$us->close();

// Mask email
$parts  = explode("@", $email, 2);
$local  = $parts[0]; $domain = $parts[1];
$masked = (strlen($local) <= 3)
        ? substr($local,0,1)."***@".$domain
        : substr($local,0,2)."***".substr($local,-1)."@".$domain;

// Save session
$_SESSION["mp_otp_email"]  = $email;
$_SESSION["mp_otp_uid"]    = $user_id;
$_SESSION["mp_otp_tries"]  = 0;

// ── PRODUCTION MODE SWITCH ───────────────────────────────────
// Set to true  = always send real email (InfinityFree / live server)
// Set to false = show OTP on screen if PHPMailer missing (localhost)
// PRODUCTION_MODE is now in config.php

$mailerFile = __DIR__ . "/mailer/src/PHPMailer.php";

if (!file_exists($mailerFile)) {
    if (PRODUCTION_MODE) {
        die(json_encode([
            "status" => "error",
            "msg"    => "Email service not available. Contact administrator."
        ]));
    } else {
        // LOCALHOST DEV MODE — OTP shown on screen
        die(json_encode([
            "status"  => "ok",
            "masked"  => $masked,
            "msg"     => "OTP ready",
            "dev_otp" => $otp
        ]));
    }
}

// PHPMailer found — send real email
require __DIR__ . "/mailer/src/Exception.php";
require __DIR__ . "/mailer/src/PHPMailer.php";
require __DIR__ . "/mailer/src/SMTP.php";

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
    $mail->addAddress($email);
    $mail->Subject = "🔐 Your Login OTP — Study Matrix Marketplace";
    $mail->isHTML(true);
    $mail->Body    = "
<div style='font-family:Arial,sans-serif;max-width:480px;margin:0 auto;background:#030d12;color:#d0fff5;border-radius:16px;overflow:hidden;'>
  <div style='background:linear-gradient(135deg,#00e5c0,#00b89c);padding:24px;text-align:center;'>
    <h2 style='margin:0;color:#030d12;font-size:20px;'>🛒 Study Matrix Marketplace</h2>
    <p style='margin:6px 0 0;color:#012;font-size:14px;'>Login OTP Verification</p>
  </div>
  <div style='padding:28px 24px;'>
    <p style='margin:0 0 16px;font-size:15px;'>Hello,</p>
    <p style='margin:0 0 20px;font-size:15px;'>Your One-Time Password (OTP) for login is:</p>
    <div style='background:#0a2030;border:2px solid #00e5c0;border-radius:12px;padding:20px;text-align:center;margin:0 0 24px;'>
      <div style='font-size:36px;font-weight:bold;letter-spacing:10px;color:#00e5c0;'>{$otp}</div>
      <p style='margin:8px 0 0;font-size:13px;color:#4a7a72;'>🔑 Your OTP Code</p>
    </div>
    <table style='width:100%;border-collapse:collapse;'>
      <tr>
        <td style='padding:10px 14px;background:#0a2030;border-radius:8px 8px 0 0;font-size:14px;'>
          ⏰ &nbsp;Valid for <strong>5 minutes</strong> only
        </td>
      </tr>
      <tr>
        <td style='padding:10px 14px;background:#071824;border-radius:0 0 8px 8px;font-size:14px;'>
          🔒 &nbsp;Do <strong>NOT</strong> share this OTP with anyone
        </td>
      </tr>
    </table>
    <p style='margin:24px 0 0;font-size:13px;color:#4a7a72;'>If you did not request this OTP, please ignore this email.</p>
  </div>
  <div style='background:#0a2030;padding:16px;text-align:center;border-top:1px solid #0d3040;'>
    <p style='margin:0;font-size:13px;color:#4a7a72;'>Study Matrix Marketplace</p>
    <p style='margin:4px 0 0;font-size:12px;color:#2a5a52;'>Your trusted study material platform</p>
  </div>
</div>";
    $plain_body =
        "STUDY MATRIX MARKETPLACE - Login OTP

" .
        "Your OTP: {$otp}

" .
        "Valid for 5 minutes only.
" .
        "Do NOT share this OTP with anyone.

" .
        "If you did not request this, please ignore.

" .
        "- Study Matrix Marketplace";
    $mail->AltBody = $plain_body;
    $mail->send();
} catch (\Exception $e) {
    error_log("OTP mail error: " . $mail->ErrorInfo);
    die(json_encode(["status"=>"error","msg"=>"Failed to send OTP email. Try again."]));
}

echo json_encode(["status"=>"ok","masked"=>$masked,"msg"=>"OTP sent to your email"]);