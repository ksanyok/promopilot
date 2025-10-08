<?php
// Redirect all traffic to the public front controller
require __DIR__ . '/includes/init.php';
// Preserve query string (e.g., ?ref=CODE) so referral capture works on /public/
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
$target = 'public/' . ($qs !== '' ? ('?' . $qs) : '');
redirect($target);
?>