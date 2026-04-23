<?php
require_once __DIR__ . '/includes/bootstrap.php';

$token = (string)($_GET['token'] ?? '');
$valid = (bool)preg_match('/^[0-9a-f-]{36}$/', $token);
$primary = primary_color();
$biz = business_name();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manage booking · <?= e($biz) ?></title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="booking-token" content="<?= e($token) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = { theme: { extend: { colors: { primary: '<?= e($primary) ?>' } } } };
    </script>
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900">
<div id="toastRoot"></div>
<header class="bg-white border-b border-neutral-200">
    <div class="max-w-2xl mx-auto flex items-center justify-between px-4 py-4">
        <div class="font-semibold"><?= e($biz) ?></div>
        <a href="/book.php" class="text-xs text-neutral-500 hover:text-neutral-800">New booking</a>
    </div>
</header>
<main class="max-w-2xl mx-auto p-4">
    <?php if (!$valid): ?>
        <div class="bg-white border border-neutral-200 rounded-xl p-5 text-center">
            <h1 class="text-lg font-semibold">Invalid link</h1>
            <p class="text-sm text-neutral-500 mt-1">Please use the management link from your confirmation email.</p>
        </div>
    <?php else: ?>
        <h1 class="text-xl font-semibold mb-4">Manage your booking</h1>
        <div id="bookingBox" class="bg-white border border-neutral-200 rounded-xl p-5 text-sm text-neutral-500">Loading…</div>

        <div id="actions" class="mt-4 hidden">
            <button id="rescheduleBtn" class="px-4 py-2 rounded-lg text-white font-medium hidden" style="background: <?= e($primary) ?>">Reschedule</button>
            <button id="cancelBtn" class="px-4 py-2 rounded-lg bg-white border border-red-500 text-red-600 font-medium hover:bg-red-50">Cancel booking</button>
        </div>

        <div id="rescheduleUI" class="hidden mt-4 bg-white border border-neutral-200 rounded-xl p-5">
            <h2 class="font-semibold mb-3">Pick a new time</h2>
            <div class="flex items-center gap-3 mb-4">
                <input id="newDate" type="date" class="px-3 py-2 rounded-lg border border-neutral-200 flex-1">
            </div>
            <div id="newSlots" class="grid grid-cols-3 md:grid-cols-4 gap-2"></div>
            <div id="newSlotsEmpty" class="text-sm text-neutral-500 mt-3 hidden">No times available on this day.</div>
        </div>

        <div id="feedback" class="mt-4 text-sm"></div>
    <?php endif; ?>
</main>
<script src="<?= e(asset('/assets/js/toast.js')) ?>"></script>
<script src="<?= e(asset('/assets/js/manage.js')) ?>"></script>
</body>
</html>
