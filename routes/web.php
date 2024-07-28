<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\YouTubeController;

Route::get('/', function () {
    return view('front');
});

Route::post('/session-clear', [YouTubeController::class, 'sessionClear'])->name('session-clear');

Route::post('/convert', [YouTubeController::class, 'convert'])->name('convert');
Route::get('/get-progress1', [YouTubeController::class, 'getProgress1']);


Route::post('/convert-to-mp3', [YouTubeController::class, 'convertToMp3'])->name('convert-to-mp3');
Route::get('/get-progress2', [YouTubeController::class, 'getProgress2']);
Route::get('/job-status', [YouTubeController::class, 'getJobStatus']);

Route::post('/split-mp3', [YouTubeController::class, 'splitMp3'])->name('split-mp3');

Route::get('/get-progress3', [YouTubeController::class, 'getProgress3']);
