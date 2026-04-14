<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class ProviderRedirectController extends Controller
{
    /**
     * Redirect the user to the OAuth provider authentication page.
     */
    public function __invoke(string $provider): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        if (! in_array($provider, ['google'])) {
            abort(404);
        }

        return Socialite::driver($provider)->redirect();
    }
}
