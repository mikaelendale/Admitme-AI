<?php

use App\Http\Controllers\Settings\AddOnsController;
use App\Http\Controllers\Settings\AiPreferencesController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\NotificationPreferencesController;
use App\Http\Controllers\Settings\PrivacyController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'onboarded', 'platform.access'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/notifications', [NotificationPreferencesController::class, 'edit'])
        ->name('settings.notifications.edit');
    Route::patch('settings/notifications', [NotificationPreferencesController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('settings.notifications.update');

    Route::get('settings/ai', [AiPreferencesController::class, 'edit'])->name('settings.ai.edit');
    Route::patch('settings/ai', [AiPreferencesController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('settings.ai.update');
    Route::post('settings/ai/regenerate-context', [AiPreferencesController::class, 'regenerate'])
        ->middleware('throttle:3,1')
        ->name('settings.ai.regenerate');

    Route::get('settings/privacy', [PrivacyController::class, 'edit'])->name('settings.privacy.edit');
    Route::post('settings/privacy/export', [PrivacyController::class, 'export'])
        ->middleware('throttle:3,60')
        ->name('settings.privacy.export');
    Route::post('settings/privacy/delete-responses', [PrivacyController::class, 'deleteResponses'])
        ->middleware(['throttle:3,1', 'password.confirm'])
        ->name('settings.privacy.delete-responses');
    Route::post('settings/privacy/delete-essays', [PrivacyController::class, 'deleteEssays'])
        ->middleware(['throttle:6,1', 'password.confirm'])
        ->name('settings.privacy.delete-essays');
    Route::delete('settings/privacy/account', [PrivacyController::class, 'destroyAccount'])
        ->middleware('throttle:3,1')
        ->name('settings.privacy.destroy-account');

});

Route::middleware(['auth', 'onboarded', 'verified'])->group(function () {
    Route::get('settings/add-ons', AddOnsController::class)->name('addons');
    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');
    Route::post('settings/billing/checkout', [BillingController::class, 'checkout'])
        ->middleware('throttle:10,1')
        ->name('billing.checkout');
    Route::post('settings/billing/subscribe', [BillingController::class, 'subscribe'])
        ->middleware('throttle:10,1')
        ->name('billing.subscribe');
    Route::post('settings/billing/portal', [BillingController::class, 'portal'])
        ->middleware('throttle:10,1')
        ->name('billing.portal');
    Route::post('settings/billing/swap', [BillingController::class, 'swap'])
        ->middleware('throttle:10,1')
        ->name('billing.swap');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});
