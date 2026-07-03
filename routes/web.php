<?php

use App\Http\Controllers\TravelPlannerController;
use Illuminate\Support\Facades\Route;

Route::get('/', [TravelPlannerController::class, 'home'])->name('home');
Route::get('/v2', [TravelPlannerController::class, 'home'])->name('travel.v2');
Route::get('/plan', [TravelPlannerController::class, 'home'])->name('travel.plan');
Route::get('/v2/plan', [TravelPlannerController::class, 'home'])->name('travel.v2.plan');
Route::post('/plan', [TravelPlannerController::class, 'legacyPlan'])->name('travel.plan.submit');
Route::post('/v2/plan', [TravelPlannerController::class, 'store'])->name('travel.v2.submit');
Route::get('/v2/result/{planId}', [TravelPlannerController::class, 'result'])->name('travel.v2.result');
Route::get('/health', [TravelPlannerController::class, 'health'])->name('health');
