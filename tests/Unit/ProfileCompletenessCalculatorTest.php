<?php

namespace Tests\Unit;

use App\Models\OnboardingProfile;
use App\Services\Profile\ProfileCompletenessCalculator;
use PHPUnit\Framework\TestCase;

class ProfileCompletenessCalculatorTest extends TestCase
{
    public function test_empty_profile_scores_low(): void
    {
        $p = new OnboardingProfile;
        $calc = new ProfileCompletenessCalculator;
        $out = $calc->calculate($p);

        $this->assertLessThan(50, $out['score']);
        $this->assertNotEmpty($out['callouts']);
    }
}
