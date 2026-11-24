<?php

use App\Http\Controllers\Worker\VideoWorkerJobController;
use Illuminate\Support\Facades\Route;

Route::post('/heartbeat', [VideoWorkerJobController::class, 'heartbeat']);
Route::post('/jobs/claim', [VideoWorkerJobController::class, 'claim']);
Route::get('/jobs/{job}/source', [VideoWorkerJobController::class, 'source']);
Route::get('/jobs/{job}/template', [VideoWorkerJobController::class, 'template']);
Route::put('/jobs/{job}/progress', [VideoWorkerJobController::class, 'progress']);
Route::put('/jobs/{job}/analysis', [VideoWorkerJobController::class, 'analysis']);
Route::post('/jobs/{job}/clips/{index}', [VideoWorkerJobController::class, 'uploadClip'])
    ->whereNumber('index');
Route::post('/jobs/{job}/fail', [VideoWorkerJobController::class, 'fail']);
Route::post('/jobs/{job}/complete', [VideoWorkerJobController::class, 'complete']);
