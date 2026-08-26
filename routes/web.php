<?php

use App\Http\Controllers\DevMailPreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Local only — render leave mail templates in the browser without sending.
Route::get('/dev/mail', [DevMailPreviewController::class, 'index']);
Route::get('/dev/mail/{template}', [DevMailPreviewController::class, 'show']);
