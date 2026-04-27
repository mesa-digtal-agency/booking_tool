<?php
/**
 * Returns the current session's CSRF token as JSON.
 * Used by frontends that need a token before posting JSON to the API.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
json_response(['csrf_token' => csrf_token()]);
