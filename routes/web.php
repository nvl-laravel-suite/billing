<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Nvl\Billing\Http\Controllers\BillingWebhookController;

Route::post('nvl/billing/stripe/webhook', [BillingWebhookController::class, 'handleWebhook'])
    ->middleware(VerifyWebhookSignature::class)
    ->name('nvl.billing.webhook');
