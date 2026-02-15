<?php

use App\Http\Controllers\YandexIntegrationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('yandex.integration');
    }

    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('yandex', [YandexIntegrationController::class, 'index'])
        ->name('yandex.integration');

    Route::put('yandex', [YandexIntegrationController::class, 'update'])
        ->name('yandex.update');

    Route::post('yandex/refresh', [YandexIntegrationController::class, 'refresh'])
        ->name('yandex.refresh');

    Route::post('yandex/load-more', [YandexIntegrationController::class, 'loadMore'])
        ->name('yandex.load-more');
});

require __DIR__.'/settings.php';
