<?php
require_once __DIR__ . '/../auth/auth.php';
$me = require_role(['admin']);
require __DIR__ . '/../includes/internal_profile.php';
