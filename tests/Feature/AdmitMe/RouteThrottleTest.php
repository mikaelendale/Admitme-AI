<?php

namespace Tests\Feature\AdmitMe;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `throttle:N,M` limits each route by its own requests. Laravel's default keys an unnamed throttle by the user
 * alone, so the chat's frequent reads (600 a minute) used up "send a message" (30 a minute) and the next message
 * after a long reply failed with 429.
 */
class RouteThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_busy_route_does_not_use_up_another_routes_limit(): void
    {
        Route::middleware(['web', 'auth', 'throttle:600,1'])->get('/_throttle-test/poll', fn () => 'ok')->name('throttle-test.poll');
        Route::middleware(['web', 'auth', 'throttle:3,1'])->post('/_throttle-test/send', fn () => 'ok')->name('throttle-test.send');

        $this->actingAs(User::factory()->create());

        for ($i = 0; $i < 10; $i++) {
            $this->get('/_throttle-test/poll')->assertOk();
        }

        // Ten reads did not count against "send"…
        $this->post('/_throttle-test/send')->assertOk();
        $this->post('/_throttle-test/send')->assertOk();
        $this->post('/_throttle-test/send')->assertOk();

        // …and its own limit still holds.
        $this->post('/_throttle-test/send')->assertStatus(429);
    }

    public function test_each_student_has_their_own_count(): void
    {
        Route::middleware(['web', 'auth', 'throttle:2,1'])->post('/_throttle-test/send', fn () => 'ok')->name('throttle-test.send');

        $this->actingAs(User::factory()->create());
        $this->post('/_throttle-test/send')->assertOk();
        $this->post('/_throttle-test/send')->assertOk();
        $this->post('/_throttle-test/send')->assertStatus(429);

        $this->actingAs(User::factory()->create());
        $this->post('/_throttle-test/send')->assertOk();
    }
}
