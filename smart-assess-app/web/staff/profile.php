<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);
require __DIR__ . '/../includes/internal_profile.php';
