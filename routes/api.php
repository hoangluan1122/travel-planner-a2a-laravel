<?php

use App\Http\Controllers\TravelPlannerController;
use Illuminate\Support\Facades\Route;

Route::post('/plan', [TravelPlannerController::class, 'apiPlan']);
Route::get('/debug-plan', [TravelPlannerController::class, 'debugPlan']);
Route::get('/providers-status', [TravelPlannerController::class, 'providersStatus']);
Route::get('/reverse-origin', [TravelPlannerController::class, 'reverseOrigin']);
