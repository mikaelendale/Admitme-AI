<?php

use App\Http\Controllers\BrowserAgentController;
use App\Http\Controllers\BrowserBridgeController;
use App\Http\Middleware\AuthenticateExtension;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| The browser extension's API (Phase 10). Stateless: a bearer token issued by account linking, no cookies,
| no CSRF. Extension pages call it with host permissions, so no CORS allowance is needed for web pages.
*/

Route::prefix('api/browser')->middleware([SubstituteBindings::class, 'throttle:browser-bridge'])->group(function () {
    Route::post('link', [BrowserBridgeController::class, 'link'])->middleware('throttle:10,1')->name('browser.link');

    Route::middleware(AuthenticateExtension::class)->group(function () {
        Route::get('applications', [BrowserBridgeController::class, 'applications'])->name('browser.applications');
        Route::post('sessions', [BrowserBridgeController::class, 'startSession'])->name('browser.sessions.store');
        Route::get('sessions/{session}', [BrowserBridgeController::class, 'showSession'])->name('browser.sessions.show')->whereUlid('session');
        Route::delete('sessions/{session}', [BrowserBridgeController::class, 'closeSession'])->name('browser.sessions.close')->whereUlid('session');
        Route::post('sessions/{session}/snapshots', [BrowserBridgeController::class, 'snapshot'])->name('browser.snapshots.store')->whereUlid('session');
        Route::post('sessions/{session}/fill-proposal', [BrowserBridgeController::class, 'fillProposal'])->name('browser.fill-proposal')->whereUlid('session');
        Route::get('sessions/{session}/commands', [BrowserBridgeController::class, 'commands'])->name('browser.commands.lease')->whereUlid('session');
        Route::post('commands/{command}/result', [BrowserBridgeController::class, 'result'])->name('browser.commands.result')->whereUlid('command');
        Route::post('approvals/{proposal}/decision', [BrowserBridgeController::class, 'decide'])->name('browser.approvals.decide')->whereUlid('proposal');

        // Mentor browser control (browser-extension/src/shared/agent.ts). Stopping is DELETE sessions/{session} above.
        Route::post('agent-sessions', [BrowserAgentController::class, 'start'])->name('browser.agent.sessions.store');
        Route::get('agent-sessions/{session}/next', [BrowserAgentController::class, 'next'])->name('browser.agent.next')->whereUlid('session');
        Route::post('agent-commands/{command}/result', [BrowserAgentController::class, 'result'])->name('browser.agent.result')->whereUlid('command');
    });
});
