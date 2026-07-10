<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\UserAuthController;
use App\Http\Controllers\Api\UserPaymentController;
use App\Http\Controllers\Api\PaymentController;

// 公開認証API
Route::post('/staff/login', [StaffAuthController::class, 'login']);
Route::post('/user/register', [UserAuthController::class, 'register']);
Route::post('/user/login', [UserAuthController::class, 'login']);

// 決済情報表示
Route::get('/payments/{id}', [PaymentController::class, 'show']);

// ユーザー認証必須
Route::middleware('auth:sanctum')->group(function () {
    Route::post(
        '/payments/{id}/confirm',
        [PaymentController::class, 'confirm']
    );

    Route::get(
        '/user/payments',
        [UserPaymentController::class, 'index']
    );

    Route::get('/user/me', [UserAuthController::class, 'me']);
    Route::post('/user/logout', [UserAuthController::class, 'logout']);
});

// スタッフ認証必須
Route::middleware([
    'auth:sanctum',
    'abilities:payment:create'
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