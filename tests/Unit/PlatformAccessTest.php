<?php

namespace Tests\Unit;

use App\Support\PlatformAccess;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    public function test_pass_full_days_remaining_floors_elapsed_seconds(): void
    {
        config()->set('app.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2025-06-01 10:00:00', 'UTC'));

        $justUnderThreeDays = Carbon::parse('2025-06-04 09:59:59', 'UTC');
        $this->assertSame(2, PlatformAccess::passFullDaysRemaining($justUnderThreeDays));

        $exactlyThreeDays = Carbon::parse('2025-06-04 10:00:00', 'UTC');
        $this->assertSame(3, PlatformAccess::passFullDaysRemaining($exactlyThreeDays));
    }

    public function test_pass_full_days_remaining_zero_when_expired(): void
    {
        config()->set('app.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2025-06-10 12:00:00', 'UTC'));

        $this->assertSame(0, PlatformAccess::passFullDaysRemaining(Carbon::parse('2025-06-09 12:00:00', 'UTC')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
