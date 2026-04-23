<?php
/**
 * Returns the current session's CSRF token as JSON.
 * Called by the static /api-test.html page to bootstrap its POSTs.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
json_response(['csrf_token' => csrf_token()]);
