<?php
/**
 * Fallback front controller.
 *
 * Only used if the subdomain Document Root points at the repository root
 * instead of the recommended public/ folder. Sends visitors to the real app.
 */
header('Location: public/', true, 302);
echo 'Redirecting to the onboarding form…';
