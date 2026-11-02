<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Stripe\Util\LoggerInterface;
use Tests\TestCase;

/**
 * The billing page shows when a subscription renews. Since Stripe API `basil` (the version the installed
 * stripe-php sends) that date is on each subscription item, not on the subscription, so reading
 * `subscription.current_period_end` found nothing: the page never showed it and stripe-php logged an
 * "Undefined property" notice. Only Stripe's HTTP layer is faked here, with basil-shaped JSON; Cashier,
 * stripe-php and the controller run for real.
 */
class BillingNextBillingDateTest extends TestCase
{
    use RefreshDatabase;

    private object $stripeLog;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashier.secret' => 'sk_test_fake_for_tests']);

        $this->stripeLog = new class implements LoggerInterface
        {
            /** @var list<string> */
            public array $messages = [];

            public function error($message, array $context = [])
            {
                $this->messages[] = (string) $message;
            }
        };
        Stripe::setLogger($this->stripeLog);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Stripe::setLogger(null);

        parent::tearDown();
    }

    public function test_the_renewal_date_comes_from_the_subscription_item(): void
    {
        $user = $this->subscriber(itemCount: 1);
        $this->fakeStripe(['GET /v1/subscriptions/sub_test' => $this->basilSubscription([
            Carbon::parse('2027-01-15 12:00:00', 'UTC')->timestamp,
        ])]);

        $this->actingAs($user)->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/billing')
                ->where('subscribed', true)
                ->where('nextBilling', 'Jan 15, 2027'));

        $this->assertSame([], array_values(array_filter(
            $this->stripeLog->messages,
            fn (string $message) => str_contains($message, 'Undefined property'),
        )));
    }

    public function test_with_several_items_the_latest_period_end_is_shown(): void
    {
        $user = $this->subscriber(itemCount: 2);
        $this->fakeStripe(['GET /v1/subscriptions/sub_test' => $this->basilSubscription([
            Carbon::parse('2027-01-15 12:00:00', 'UTC')->timestamp,
            Carbon::parse('2027-02-15 12:00:00', 'UTC')->timestamp,
        ])]);

        $this->actingAs($user)->get(route('billing.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('nextBilling', 'Feb 15, 2027'));
    }

    private function subscriber(int $itemCount): User
    {
        $user = User::factory()->create(['stripe_id' => 'cus_test']);

        /** @var Subscription $subscription */
        $subscription = $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test',
            'stripe_status' => 'active',
            'stripe_price' => $itemCount === 1 ? 'price_test_0' : null,
            'quantity' => $itemCount === 1 ? 1 : null,
        ]);

        for ($i = 0; $i < $itemCount; $i++) {
            $subscription->items()->create([
                'stripe_id' => 'si_test_'.$i,
                'stripe_product' => 'prod_test_'.$i,
                'stripe_price' => 'price_test_'.$i,
                'quantity' => 1,
            ]);
        }

        return $user;
    }

    /**
     * A subscription as Stripe API `basil` returns it: the billing period is on each item only.
     *
     * @param  list<int>  $itemPeriodEnds
     * @return array<string, mixed>
     */
    private function basilSubscription(array $itemPeriodEnds): array
    {
        $items = [];
        foreach ($itemPeriodEnds as $i => $end) {
            $items[] = [
                'id' => 'si_test_'.$i,
                'object' => 'subscription_item',
                'current_period_start' => $end - 30 * 86400,
                'current_period_end' => $end,
                'quantity' => 1,
                'price' => ['id' => 'price_test_'.$i, 'object' => 'price', 'recurring' => ['interval' => 'month']],
            ];
        }

        return [
            'id' => 'sub_test',
            'object' => 'subscription',
            'customer' => 'cus_test',
            'status' => 'active',
            'items' => [
                'object' => 'list',
                'data' => $items,
                'has_more' => false,
                'url' => '/v1/subscription_items?subscription=sub_test',
            ],
        ];
    }

    /**
     * Answers stripe-php's requests from a map of "METHOD /path" => JSON body; anything else is a 404.
     *
     * @param  array<string, array<string, mixed>>  $responses
     */
    private function fakeStripe(array $responses): void
    {
        ApiRequestor::setHttpClient(new class($responses) implements ClientInterface
        {
            /** @param  array<string, array<string, mixed>>  $responses */
            public function __construct(private array $responses) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $key = strtoupper((string) $method).' '.parse_url((string) $absUrl, PHP_URL_PATH);

                if (! isset($this->responses[$key])) {
                    return [(string) json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Not faked: '.$key]]), 404, []];
                }

                return [(string) json_encode($this->responses[$key]), 200, []];
            }
        });
    }
}
