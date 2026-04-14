<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AddOnsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $catalog = config('billing.addon_catalog.boost', []);
        $bullets = $catalog['bullets'] ?? [];
        if (! is_array($bullets)) {
            $bullets = [];
        }

        $normalizedBullets = [];
        foreach ($bullets as $line) {
            if (is_string($line) && $line !== '') {
                $normalizedBullets[] = $line;
            }
        }

        $trialOnly = (bool) config('billing.trial_only_mode', false);

        return Inertia::render('settings/add-ons', [
            'trial_only_mode' => $trialOnly,
            'boost' => [
                'checkout_configured' => ! $trialOnly && $this->priceConfigured('boost'),
                'headline' => is_string($catalog['headline'] ?? null) ? $catalog['headline'] : 'Boost Pack',
                'price_line' => is_string($catalog['price_line'] ?? null) ? $catalog['price_line'] : 'One-time add-on',
                'summary' => is_string($catalog['summary'] ?? null) ? $catalog['summary'] : '',
                'bullets' => $normalizedBullets,
            ],
            'checkout_success' => $request->query('checkout') === 'success',
            'checkout_cancelled' => $request->query('checkout') === 'cancel',
        ]);
    }

    private function priceConfigured(string $key): bool
    {
        $id = config('billing.prices.'.$key);

        return is_string($id) && $id !== '';
    }
}
