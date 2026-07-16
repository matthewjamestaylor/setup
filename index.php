<?php
/**
 * Fallback front controller.
 *
 * Only used if the subdomain Document Root points at the repository root
 * instead of the recommended public/ folder. Sends visitors to the real app,
 * preserving the query string (e.g. the ?test= token).
 */
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
header('Location: public/' . ($qs !== '' ? '?' . $qs : ''), true, 302);
echo 'Redirecting to the onboarding form…';
