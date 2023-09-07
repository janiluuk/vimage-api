<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\VideojobController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/csrf-token', function() {
    return response()->json([
        'csrfToken' => csrf_token(),
    ]);
});
Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});
Route::post('/upload', [VideojobController::class, 'upload'])->middleware('auth:api');
Route::post('/cancelJob/{id}', [VideojobController::class, 'cancelJob'])->middleware('auth:api');
Route::post('/submit', [VideojobController::class, 'submit'])->middleware('auth:api');
Route::post('/finalize', [VideojobController::class, 'finalize']);
Route::get('/status/{id}', [VideojobController::class, 'status']);
ctf0\MediaManager\MediaRoutes::routes();

                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                
