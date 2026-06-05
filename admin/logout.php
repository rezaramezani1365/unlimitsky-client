<?php
require_once __DIR__ . '/lib/auth.php';
USK_Admin_Auth::boot();
USK_Admin_Auth::logout();
header('Location: login.php');
exit;
