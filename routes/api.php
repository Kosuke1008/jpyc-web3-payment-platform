<?php

use App\Http\Controllers\Api\PaymentAuthenticationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\UserAuthController;
use App\Http\Controllers\Api\UserPaymentController;
use Illuminate\Support\Facades\Route;

// 公開認証API
Route::post('/staff/login', [StaffAuthController::class, 'login']);
Route::post('/user/register', [UserAuthController::class, 'register']);
Route::post('/user/login', [UserAuthController::class, 'login']);
Route::post('/payment/login', [PaymentAuthenticationController::class, 'login'])
    ->middleware('throttle:6,1');

// 決済情報表示
Route::get('/payments/{id}', [PaymentController::class, 'show']);

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
});

Route::get(
    '/payments/status/{id}',
    [PaymentController::class, 'status']
);
