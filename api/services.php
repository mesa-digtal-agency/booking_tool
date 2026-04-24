<?php
require_once __DIR__ . '/../includes/bootstrap.php';

require_method('GET');

$rows = db_all(
    "SELECT id, name, description, duration_minutes, price, category, image
     FROM services WHERE is_active = 1
     ORDER BY category ASC, name ASC"
);

// Normalize types and expose a web path for the image, if any.
foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['duration_minutes'] = (int)$r['duration_minutes'];
    $r['price'] = (float)$r['price'];
    $r['image'] = $r['image'] ? '/assets/services/' . basename($r['image']) : null;
}
unset($r);

json_response(['services' => $rows]);
