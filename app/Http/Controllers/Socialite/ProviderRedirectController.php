<?php

namespace App\Http\Controllers\Socialite;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class ProviderRedirectController extends Controller
{
    private const SUPPORTED_PROVIDERS = ['github', 'google'];

    public function __invoke(string $provider): RedirectResponse
    {
        if (! in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            Log::warning('Unsupported provider attempted', ['provider' => $provider]);

            return redirect()->route('login')->withErrors([
                'provider' => 'Unsupported authentication provider.',
            ]);
        }

        try {
            // Store referral code if provided
            if (request()->has('ref')) {
                $referralCode = request('ref');
                if (preg_match('/^[a-zA-Z0-9]{6,12}$/', $referralCode)) {
                    session(['referral_code' => encrypt($referralCode)]);
                }
            }

            Log::info('Initiating social authentication', [
                'provider' => $provider,
                'user_ip' => request()->ip(),
            ]);

            return Socialite::driver($provider)->redirect();
        } catch (\InvalidArgumentException $e) {
            Log::error('Invalid socialite configuration', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('login')->withErrors([
                'provider' => "Authentication service for {$provider} is not properly configured.",
            ]);
        } catch (\Exception $e) {
            Log::error('Socialite redirect error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('login')->withErrors([
                'provider' => 'Unable to connect to authentication service. Please try again.',
            ]);
        }
    }
}
