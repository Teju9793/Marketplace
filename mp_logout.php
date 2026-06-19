<?php
ob_start();
ini_set("display_errors", 0);
error_reporting(0); session_start();
session_unset();
session_destroy();
header("Location: marketplace.html");
exit;