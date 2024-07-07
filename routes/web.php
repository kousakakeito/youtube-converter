<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\YouTubeController;

Route::get('/', function () {
    return view('front');
});

Route::post('/convert', [YouTubeController::class, 'convert'])->name('convert');
Route::post('/convert-to-mp3', [YouTubeController::class, 'convertToMp3'])->name('convert-to-mp3');
Route::post('/split-mp3', [YouTubeController::class, 'splitMp3'])->name('split-mp3');