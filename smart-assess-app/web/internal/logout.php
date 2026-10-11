<?php
require_once __DIR__ . '/../auth/auth.php';
logout_user();
header('Location: /internal/login.php');
exit;
