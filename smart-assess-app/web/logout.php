<?php
require_once __DIR__ . '/auth/client_auth.php';
logout_client();
header('Location: /index.php');
exit;
