<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\ZapPay\ZapPay;

Route::post('/extensions/gateways/zappay/webhook', [ZapPay::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.zappay.webhook');

Route::get('/extensions/gateways/zappay/check', [ZapPay::class, 'check'])
    ->name('extensions.gateways.zappay.check');

Route::get('/extensions/gateways/zappay/return', [ZapPay::class, 'return'])
    ->name('extensions.gateways.zappay.return');
