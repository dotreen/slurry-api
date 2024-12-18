<?php

use App\Http\Controllers\WechatController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return "welcome";
});

Route::post('/wechat/session', [WechatController::class, 'getSession']);
Route::post('/wechat/login', [WechatController::class, 'login']);
Route::post('/wechat/logout', [WechatController::class, 'logout']);
