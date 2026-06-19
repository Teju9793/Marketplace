<?php
ob_start(); session_start();
require __DIR__ . "/db.php";

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST")
    die(json_encode(["status"=>"error","msg"=>"Invalid request"]));

if (empty($_SESSION["mp_otp_email"]))
    die(json_encode(["status"=>"error","msg"=>"Session expired. Please login again."]));

$email   = $_SESSION["mp_otp_email"];
$user_id = $_SESSION["mp_otp_uid"];
$otp_in  = trim($_POST["otp"] ?? "");

if (!preg_match('/^[0-9]{6}$/', $otp_in))
    die(json_encode(["status"=>"error","msg"=>"Enter a valid 6-digit OTP"]));

if (($_SESSION["mp_otp_tries"] ?? 0) >= 5) {
    unset($_SESSION["mp_otp_email"], $_SESSION["mp_otp_uid"], $_SESSION["mp_otp_tries"]);
    die(json_encode(["status"=>"error","msg"=>"Too many attempts. Request a new OTP."]));
}

$stmt = $conn->prepare("SELECT otp FROM otp_codes WHERE email = ? AND created_at >= NOW() - INTERVAL 5 MINUTE");
$stmt->bind_param("s", $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    unset($_SESSION["mp_otp_email"], $_SESSION["mp_otp_uid"], $_SESSION["mp_otp_tries"]);
    die(json_encode(["status"=>"error","msg"=>"OTP expired. Request a new one."]));
}

if (!hash_equals($row["otp"], $otp_in)) {
    $_SESSION["mp_otp_tries"] = ($_SESSION["mp_otp_tries"] ?? 0) + 1;
    $left = 5 - $_SESSION["mp_otp_tries"];
    die(json_encode(["status"=>"error","msg"=>"Wrong OTP. {$left} attempt(s) left."]));
}

$del = $conn->prepare("DELETE FROM otp_codes WHERE email = ?");
$del->bind_param("s", $email);
$del->execute();
$del->close();

session_regenerate_id(true);
$_SESSION["mp_user_id"]     = $user_id;
$_SESSION["mp_user_email"]  = $email;
$_SESSION["mp_last_active"] = time();
unset($_SESSION["mp_otp_email"], $_SESSION["mp_otp_uid"], $_SESSION["mp_otp_tries"]);

echo json_encode(["status"=>"ok","msg"=>"Login successful"]);