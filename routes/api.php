<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\StaffAuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\UserAuthController;
use App\Http\Controllers\Api\UserPaymentController;

// 顧客ユーザー認証
Route::post('/user/register', [UserAuthController::class, 'register']);
Route::post('/user/login', [UserAuthController::class, 'login']);

// スタッフログイン
Route::post('/staff/login', [StaffAuthController::class, 'login']);

// 公開：payページ表示用
Route::get('/payments/{id}', [PaymentController::class, 'show']);

// 顧客ログイン必須：支払い確定
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/payments/{id}/confirm', [PaymentController::class, 'confirm']);
    Route::get('/user/payments', [UserPaymentController::class, 'index']);
});

Route::post('/user/register', [UserAuthController::class, 'register']);
Route::post('/user/login', [UserAuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/payments/{id}/confirm', [PaymentController::class, 'confirm']);
    Route::get('/user/payments', [UserPaymentController::class, 'index']);
    Route::post('/user/logout', [UserAuthController::class, 'logout']);
    Route::get('/user/me', [UserAuthController::class, 'me']);
});

// 店舗/スタッフログイン必須：決済作成・状態確認
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/payments/create', [PaymentController::class, 'create']);
    Route::get('/payments/status/{id}', [PaymentController::class, 'status']);
});

