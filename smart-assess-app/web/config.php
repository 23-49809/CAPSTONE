<?php
/**
 * SMART ASSESS - application configuration.
 * Edit the DB_* and AI_CHECKER_BASE_URL values for your environment.
 * Local defaults here match database/schema.sql and the Django dev server
 * started with: python manage.py runserver 127.0.0.1:8001
 */

// The office is in Mabini, Batangas (PH) — every date/time the app renders
// or compares (announcement scheduling, timestamps) uses this zone so PHP's
// clock agrees with MySQL's SYSTEM time zone instead of defaulting to UTC.
date_default_timezone_set('Asia/Manila');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'smart_assess');
define('DB_USER', 'smart_assess_app');
define('DB_PASS', getenv('SMART_ASSESS_DB_PASS') ?: 'change-me');

// Internal AI Rule-Based Requirement Checker service (Django).
define('AI_CHECKER_BASE_URL', 'http://127.0.0.1:8001/api');

// Where uploaded documents/IDs are stored, relative to this file.
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MAX_UPLOAD_BYTES', 15 * 1024 * 1024); // 15 MB

// Office info shown in the footer / SMS copy.
define('OFFICE_NAME', "Mabini Assessor Office");
define('OFFICE_PHONE', '(043) 487-0123');
define('OFFICE_EMAIL', 'assessor@mabini.gov.ph');
