<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class ProviderCallbackController extends Controller
{
    /**
     * Handle the OAuth provider callback.
     */
    public function __invoke(string $provider): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        if (! in_array($provider, ['google'])) {
            abort(404);
        }

        try {
            $providerUser = Socialite::driver($provider)->user();
        } catch (Throwable $e) {
            return redirect()->route('login')->with('error', 'Google sign-in was cancelled or failed. Try again.');
        }

        // Case 1: User with this provider_id already exists
        $user = User::where('provider', $provider)
            ->where('provider_id', $providerUser->getId())
            ->first();

        if ($user) {
            Auth::login($user);

            return $user->onboarded
                ? redirect()->route('dashboard')
                : redirect()->route('onboarding');
        }

        // Case 2: User with this email exists but no provider_id (link accounts)
        $user = User::where('email', $providerUser->getEmail())->first();

        if ($user) {
            $user->update([
                'provider' => $provider,
                'provider_id' => $providerUser->getId(),
                'avatar' => $providerUser->getAvatar(),
            ]);

            Auth::login($user);

            return $user->onboarded
                ? redirect()->route('dashboard')
                : redirect()->route('onboarding');
        }

        // Case 3: Brand new user - create account
        $user = User::create([
            'name' => $providerUser->getName(),
            'email' => $providerUser->getEmail(),
            'provider' => $provider,
            'provider_id' => $providerUser->getId(),
            'avatar' => $providerUser->getAvatar(),
            'password' => null,
            'email_verified_at' => now(),
            'onboarded' => false,
        ]);

        Auth::login($user);

        return redirect()->route('onboarding');
    }
}
