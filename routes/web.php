<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PayController;

Route::get('/pay/{id}', [PayController::class, 'show']);

Route::get('/login', function () {
    return view('auth.login');
});

Route::get('/pos', function () {
    return view('pos.index');
});

//userログイン
Route::get('/user/login', function () {
    return view('user.login');
});

Route::get('/user/home', function () {
    return view('user.home');
});

Route::get('/user/payments', function () {
    return view('user.payments');
});