<?php

// use App\Http\Controllers\ProfileController;
// use Illuminate\Support\Facades\Route;
// use App\Http\Controllers\SettingsController;

// /*
// |--------------------------------------------------------------------------
// | Web Routes
// |--------------------------------------------------------------------------
// |
// | Here is where you can register web routes for your application. These
// | routes are loaded by the RouteServiceProvider and all of them will
// | be assigned to the "web" middleware group. Make something great!
// |
// */

// // Make settings page the home page (no auth required)
// Route::get('/', [SettingsController::class, 'index'])->name('settings');

// // Settings routes (no auth required)
// Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
// Route::post('/settings/update', [SettingsController::class, 'update'])->name('settings.update');
// Route::post('/settings/create-client', [SettingsController::class, 'createClient'])->name('settings.createClient');
// Route::post('/settings/delete-client', [SettingsController::class, 'deleteClient'])->name('settings.deleteClient');
// Route::post('/settings/import-products', [SettingsController::class, 'importProducts'])->name('settings.importProducts');
// Route::get('/import-products', [SettingsController::class, 'importProducts'])->name('import.products');
// Route::get('/import-logs', [SettingsController::class, 'showImportLogs'])->name('import.logs');

// // Optional: Keep the original welcome page if needed
// Route::get('/welcome', function () {
//     return view('welcome');
// })->name('welcome');

// // Dashboard route (requires auth)
// Route::get('/dashboard', function () {
//     return view('dashboard');
// })->middleware(['auth', 'verified'])->name('dashboard');

// // Profile routes (requires auth)
// Route::middleware('auth')->group(function () {
//     Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
//     Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
//     Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
// });

// // Shopify routes
// Route::middleware(['allow.shopify.iframe'])->group(function () {
//     Route::get('/shopify/app', [\App\Http\Controllers\ShopifyController::class, 'app'])->name('shopify.app');
// });

// require __DIR__ . '/auth.php';


// before app bridge code
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SettingsController;



/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Route::get('/', function () {
//     return view('welcome');
// });
Route::get('/', [SettingsController::class, 'index'])->name('settings');

// Route::get('/dashboard', function () {
//     return view('dashboard');
// })->middleware(['auth', 'verified'])->name('dashboard');

// Route::middleware('auth')->group(function () {
//     Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
//     Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
//     Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
// });

//Route::middleware(['auth'])->group(function () {
Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
Route::post('/settings/update', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/settings/create-client', [SettingsController::class, 'createClient'])->name('settings.createClient');
Route::post('/settings/delete-client', [SettingsController::class, 'deleteClient'])->name('settings.deleteClient');
Route::post('/settings/import-products', [SettingsController::class, 'importProducts'])->name('settings.importProducts');

// new work
//Route::post('/import-products', [SettingsController::class, 'importProducts'])->name('import.products');
//Route::post('/import-products', [SettingsController::class, 'importProducts'])->name('import.products');
//Route::match(['get', 'post'], '/import-products', [SettingsController::class, 'importProducts'])->name('import.products');
Route::get('/import-products', [SettingsController::class, 'importProducts'])->name('import.products');
Route::get('/import-logs', [SettingsController::class, 'showImportLogs'])->name('import.logs');
//});
// routes/web.php
// Route::middleware(['allow.shopify.iframe'])->group(function () {
//     Route::get('/shopify/app', [\App\Http\Controllers\ShopifyController::class, 'app'])->name('shopify.app');
// });
// require __DIR__ . '/auth.php';