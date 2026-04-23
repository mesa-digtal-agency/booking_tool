<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_method('GET');

$rows = db_all(
    "SELECT id, name, description, duration_minutes, price, category
     FROM services WHERE is_active = 1
     ORDER BY category ASC, name ASC"
);

// Normalize types for JSON.
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['duration_minutes'] = (int)$r['duration_minutes'];
    $r['price'] = (float)$r['price'];
}
unset($r);

json_response(['services' => $rows]);
