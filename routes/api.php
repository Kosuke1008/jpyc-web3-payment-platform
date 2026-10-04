<?php

use App\Http\Controllers\Api\PaymentAuthenticationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentSponsorshipController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\StaffPosController;
use App\Http\Controllers\Api\UserAuthController;
use App\Http\Controllers\Api\UserPaymentController;
use Illuminate\Support\Facades\Route;

// 公開認証API
Route::post('/staff/login', [StaffAuthController::class, 'login'])
    ->middleware('throttle:staff-authentication');
Route::post('/user/register', [UserAuthController::class, 'register']);
Route::post('/user/login', [UserAuthController::class, 'login'])
    ->middleware('throttle:user-authentication');
Route::post('/payment/login', [PaymentAuthenticationController::class, 'login'])
    ->middleware('throttle:user-authentication');

// 決済情報表示
Route::get('/payments/{id}', [PaymentController::class, 'show']);
Route::get(
    '/payments/{id}/sponsorship',
    [PaymentController::class, 'sponsorshipAvailability']
);

// ユーザー認証必須
Route::middleware('auth:sanctum')->group(function () {
    Route::get(
        '/payment/session',
        [PaymentAuthenticationController::class, 'session']
    )->middleware('abilities:payment:confirm');

    Route::post(
        '/payments/{id}/confirm',
        [PaymentController::class, 'confirm']
    )->middleware('abilities:payment:confirm');

    Route::post(
        '/payments/{id}/sponsor',
        PaymentSponsorshipController::class
    )->middleware([
        'abilities:payment:confirm',
        'throttle:6,1',
    ]);

    Route::get(
        '/user/payments',
        [UserPaymentController::class, 'index']
    )->middleware('abilities:user:read');

    Route::get('/user/me', [UserAuthController::class, 'me'])
        ->middleware('abilities:user:read');
    Route::post('/user/logout', [UserAuthController::class, 'logout']);
});

// スタッフ認証必須
Route::middleware([
    'auth:sanctum',
    'abilities:payment:create',
])->group(function () {
    Route::post(
        '/payments/create',
        [PaymentController::class, 'create']
    );
    Route::get('/staff/pos/context', [StaffPosController::class, 'context']);
    Route::get('/staff/payments', [StaffPosController::class, 'history']);
});

Route::get(
    '/payments/status/{id}',
    [PaymentController::class, 'status']
);
