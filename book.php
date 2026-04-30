<?php
require_once __DIR__ . '/includes/bootstrap.php';

$primary = primary_color();
$biz     = business_name();
$logo    = logo_url();
$font_stack = app_font_stack();
$font_url = app_font_stylesheet_url();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Book with <?= e($biz) ?></title>
    <meta name="description" content="Book an appointment online with <?= e($biz) ?>. Choose a service, pick a time, and confirm instantly.">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16"><circle cx="8" cy="8" r="8" fill="' . e($primary) . '"/></svg>') ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = { theme: { extend: { colors: { primary: '<?= e($primary) ?>' } } } };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="<?= e($font_url) ?>">
    <link rel="stylesheet" href="<?= e(asset('/assets/css/app.css')) ?>">
    <style>:root{--primary-color: <?= e($primary) ?>;--app-font-family: <?= $font_stack ?>;}</style>
</head>
<body class="min-h-screen bg-neutral-50 text-neutral-900"
      data-time-format="<?= e(time_format()) ?>"
      data-currency-symbol="<?= e(currency_symbol()) ?>"
      data-business-today="<?= e(business_today()) ?>">
<div id="toastRoot"></div>

<header class="booking-header bg-white border-b border-neutral-200 sticky top-0 z-30">
    <div class="booking-header-inner max-w-3xl mx-auto flex items-center justify-between px-4 py-2.5 md:py-3 gap-3">
        <div class="flex items-center gap-2.5 md:gap-3 min-w-0">
            <?php if ($logo): ?>
                <img src="<?= e($logo) ?>" alt="<?= e($biz) ?>" class="booking-logo h-9 md:h-10 max-w-[118px] md:max-w-[140px] object-contain">
            <?php else: ?>
                <div class="w-9 h-9 md:w-10 md:h-10 rounded-full flex-shrink-0" style="background: <?= e($primary) ?>"></div>
            <?php endif; ?>
            <div class="booking-brand-copy min-w-0">
                <div class="font-semibold truncate"><?= e($biz) ?></div>
                <div class="text-xs text-neutral-500">Online booking</div>
            </div>
        </div>
        <a href="/admin/login.php" class="text-xs text-neutral-500 hover:text-neutral-800 whitespace-nowrap">Staff login</a>
    </div>
</header>

<main class="max-w-3xl mx-auto px-4 py-4 pb-24">
    <!-- Step indicator -->
    <ol id="steps" class="booking-steps flex items-center mb-6 text-xs text-neutral-500">
        <li data-step="1" class="step flex-1 text-center border-b-2 pb-2 border-primary text-primary font-medium">1<span class="hidden sm:inline"> · Service</span></li>
        <li data-step="2" class="step flex-1 text-center border-b-2 pb-2 border-neutral-200">2<span class="hidden sm:inline"> · Stylist</span></li>
        <li data-step="3" class="step flex-1 text-center border-b-2 pb-2 border-neutral-200">3<span class="hidden sm:inline"> · Date &amp; time</span></li>
        <li data-step="4" class="step flex-1 text-center border-b-2 pb-2 border-neutral-200">4<span class="hidden sm:inline"> · Details</span></li>
        <li data-step="5" class="step flex-1 text-center border-b-2 pb-2 border-neutral-200">5<span class="hidden sm:inline"> · Confirm</span></li>
    </ol>

    <!-- Step 1: Services -->
    <section data-panel="1" class="panel">
        <h2 class="text-xl font-semibold mb-3">Choose a service</h2>
        <div id="servicesList" class="space-y-3"></div>
    </section>

    <!-- Step 2: Stylist -->
    <section data-panel="2" class="panel hidden">
        <h2 class="text-xl font-semibold mb-3">Choose a stylist</h2>
        <div id="staffList" class="grid grid-cols-2 md:grid-cols-3 gap-3"></div>
        <div class="mt-4">
            <button data-back class="text-sm text-neutral-500 hover:text-neutral-800">← Back</button>
        </div>
    </section>

    <!-- Step 3: Date & time -->
    <section data-panel="3" class="panel hidden">
        <h2 class="text-xl font-semibold mb-3">Pick a date &amp; time</h2>
        <div class="booking-date-row flex items-center gap-3 mb-4">
            <button id="prevDay" class="px-3 py-2 rounded-lg border border-neutral-200 hover:bg-neutral-100">←</button>
            <input type="date" id="dateInput" class="px-3 py-2 rounded-lg border border-neutral-200 flex-1 min-w-0">
            <button id="nextDay" class="px-3 py-2 rounded-lg border border-neutral-200 hover:bg-neutral-100">→</button>
        </div>
        <div id="slotsList" class="grid grid-cols-3 md:grid-cols-4 gap-2"></div>
        <div id="slotsEmpty" class="text-sm text-neutral-500 mt-3 hidden">No available times for this day.</div>
        <div class="mt-4">
            <button data-back class="text-sm text-neutral-500 hover:text-neutral-800">← Back</button>
        </div>
    </section>

    <!-- Step 4: Details -->
    <section data-panel="4" class="panel hidden">
        <h2 class="text-xl font-semibold mb-3">Your details</h2>
        <form id="detailsForm" class="space-y-3" autocomplete="on">
            <label class="block">
                <span class="text-sm text-neutral-600">Full name *</span>
                <input required name="customer_name" class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20">
            </label>
            <div class="grid md:grid-cols-2 gap-3">
                <label class="block">
                    <span class="text-sm text-neutral-600">Phone *</span>
                    <div class="mt-1" data-phone-input data-name="customer_phone" data-required data-default-cc="<?= e(default_phone_country_code()) ?>"></div>
                </label>
                <label class="block">
                    <span class="text-sm text-neutral-600">Email *</span>
                    <input required name="customer_email" type="email" class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20">
                </label>
            </div>
            <label class="block">
                <span class="text-sm text-neutral-600">Notes (optional)</span>
                <textarea name="notes" rows="3" class="w-full mt-1 px-3 py-2 rounded-lg border border-neutral-200 focus:border-primary focus:ring focus:ring-primary/20"></textarea>
            </label>
            <div class="flex items-center justify-between mt-2">
                <button type="button" data-back class="text-sm text-neutral-500 hover:text-neutral-800">← Back</button>
                <button type="submit" class="px-5 py-2 rounded-lg text-white font-medium" style="background: <?= e($primary) ?>">Continue →</button>
            </div>
        </form>
    </section>

    <!-- Step 5: Confirm -->
    <section data-panel="5" class="panel hidden">
        <h2 class="text-xl font-semibold mb-3">Review &amp; confirm</h2>
        <div id="reviewBox" class="bg-white border border-neutral-200 rounded-xl p-4 space-y-2 mb-4"></div>
        <div class="flex items-center justify-between">
            <button type="button" data-back class="text-sm text-neutral-500 hover:text-neutral-800">← Back</button>
            <button id="confirmBtn" class="px-5 py-2 rounded-lg text-white font-medium" style="background: <?= e($primary) ?>">Confirm booking</button>
        </div>
    </section>

    <!-- Step 6: Success -->
    <section data-panel="success" class="panel hidden">
        <div class="bg-white border border-neutral-200 rounded-xl p-6 text-center">
            <div class="w-14 h-14 rounded-full mx-auto flex items-center justify-center mb-3 success-pop" style="background: <?= e($primary) ?>20; color: <?= e($primary) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </div>
            <h2 class="text-xl font-semibold">You're booked!</h2>
            <p class="text-sm text-neutral-500 mt-1">A confirmation email is on its way.</p>
            <div id="successDetails" class="text-left mt-4 space-y-1 text-sm"></div>
            <p class="text-xs text-neutral-500 mt-4">Use your email link to reschedule or cancel.</p>
        </div>
    </section>

    <!-- Sticky sidebar-ish cart on desktop, floating footer on mobile -->
    <aside id="cart" class="fixed bottom-0 inset-x-0 bg-white border-t border-neutral-200 p-3 hidden">
        <div class="max-w-3xl mx-auto flex items-center justify-between gap-3">
            <div id="cartSummary" class="text-sm text-neutral-700 truncate"></div>
            <button id="cartContinue" class="px-4 py-2 rounded-lg text-white font-medium whitespace-nowrap" style="background: <?= e($primary) ?>">Continue →</button>
        </div>
    </aside>
</main>

<script src="<?= e(asset('/assets/js/toast.js')) ?>"></script>
<script src="<?= e(asset('/assets/js/phone-input.js')) ?>"></script>
<script src="<?= e(asset('/assets/js/booking-flow.js')) ?>"></script>
</body>
</html>
