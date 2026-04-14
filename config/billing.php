<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trial-only mode (cost control)
    |--------------------------------------------------------------------------
    |
    | When true, only the $1 trial checkout path stays available for new
    | purchases. Sprint, Pro subscription, Boost, and onboarding Sprint/Pro are
    | hidden and blocked server-side. Existing Pro subscribers can still swap
    | plans and use the billing portal.
    |
    */
    'trial_only_mode' => filter_var(
        env('BILLING_TRIAL_ONLY', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    |--------------------------------------------------------------------------
    | Stripe Price IDs
    |--------------------------------------------------------------------------
    |
    | Create matching products/prices in Stripe Dashboard, then set env vars.
    | Leave null to disable that plan until configured.
    |
    */

    'prices' => [
        'trial' => env('STRIPE_PRICE_TRIAL'),
        'sprint' => env('STRIPE_PRICE_SPRINT'),
        'pro_monthly' => env('STRIPE_PRICE_PRO_MONTHLY'),
        'pro_annual' => env('STRIPE_PRICE_PRO_ANNUAL'),
        'boost' => env('STRIPE_PRICE_BOOST'),
    ],

    /*
    |--------------------------------------------------------------------------
    | In-app plan changes (Cashier swap + Stripe proration)
    |--------------------------------------------------------------------------
    |
    | Keys must exist in "prices" with non-empty env values to appear on billing.
    | swapAndInvoice uses Stripe proration_behavior always_invoice.
    |
    */
    'swappable_plan_keys' => [
        'pro_monthly',
        'pro_annual',
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time purchase access (trial / sprint)
    |--------------------------------------------------------------------------
    |
    | Days granted after a successful Stripe Checkout payment mode session.
    | Subscriptions (Pro) are not affected — access is via Cashier subscription.
    |
    */
    'purchase_access_days' => [
        'trial' => (int) env('BILLING_TRIAL_ACCESS_DAYS', 3),
        'sprint' => (int) env('BILLING_SPRINT_ACCESS_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforce platform access (subscription or active pass)
    |--------------------------------------------------------------------------
    |
    | When true, middleware blocks workspace routes unless the user has a valid
    | default subscription or platform_pass_ends_at in the future.
    |
    */
    'enforcement' => [
        'enabled' => filter_var(
            env('BILLING_ENFORCE_PURCHASE_ACCESS', 'true'),
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pass plan labels (sidebar / tooltips)
    |--------------------------------------------------------------------------
    */
    'pass_display' => [
        'trial' => [
            'headline' => '$1 trial',
            'price_line' => '3-day access',
        ],
        'sprint' => [
            'headline' => 'Admissions sprint',
            'price_line' => '90-day access',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | One-time add-ons (Checkout payment mode, not platform pass)
    |--------------------------------------------------------------------------
    |
    | Copy for /settings/add-ons. Stripe price: billing.prices.boost
    |
    */
    'addon_catalog' => [
        'boost' => [
            'headline' => 'Boost Pack',
            'price_line' => 'Human touch + faster turnaround',
            'summary' => 'Add-on for essay review queue priority and counselor-adjacent support when configured. Billed once via Stripe; does not extend workspace access time.',
            'bullets' => [
                'Priority in human essay review queue',
                'Structured feedback aligned to your AdmitMe profile',
                'Fulfilled outside subscription billing',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile resume PDF (Step 1 upload → parse + AI extraction)
    |--------------------------------------------------------------------------
    |
    | Active Pro subscribers (Cashier default subscription) get unlimited PDF
    | imports. Trial/sprint one-time passes are limited per user (lifetime count).
    |
    */
    'profile_resume_pdf' => [
        'max_parses_for_pass_users' => max(0, (int) env('BILLING_PROFILE_MAX_PDF_RESUME_PARSES_PASS', 3)),
    ],

    'plan_catalog' => [
        'pro_monthly' => [
            'headline' => 'Pro',
            'price_line' => '$15 / month',
            'summary' => 'Flexible monthly billing. Upgrade or downgrade anytime; Stripe applies prorated credits or charges.',
            'badge' => null,
            'tier' => 10,
        ],
        'pro_annual' => [
            'headline' => 'Pro',
            'price_line' => '$150 / year',
            'summary' => 'Best value vs paying monthly. Changing plans prorates the unused time on your current price.',
            'summary_unconfigured' => 'Switch to yearly billing in the Stripe portal, or set STRIPE_PRICE_PRO_ANNUAL to enable one-click prorated upgrades from this page.',
            'badge' => 'Best value',
            'tier' => 20,
        ],
    ],

];
