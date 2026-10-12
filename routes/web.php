<?php

use Illuminate\Support\Facades\Route;

Route::get('/{any?}', function () {
    $index = public_path('index.html');
    abort_unless(is_file($index), 404);
    return response()->file($index);
})->where('any', '^(?!api(?:/|$)).*$');
