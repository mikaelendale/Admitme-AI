<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Billing\ProfileResumePdfImportLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Tests\TestCase;

class ProfileResumePdfImportLimiterTest extends TestCase
{
    use RefreshDatabase;

    public function test_pass_user_remaining_decreases_with_count(): void
    {
        config(['billing.profile_resume_pdf.max_parses_for_pass_users' => 3]);

        $user = User::factory()->create([
            'profile_pdf_resume_parse_count' => 1,
        ]);

        $l = new ProfileResumePdfImportLimiter(3);

        $this->assertTrue($l->canRunNewPdfExtraction($user));
        $this->assertSame(2, $l->remainingForDisplay($user));
    }

    public function test_pass_user_at_limit_cannot_import(): void
    {
        config(['billing.profile_resume_pdf.max_parses_for_pass_users' => 2]);

        $user = User::factory()->create([
            'profile_pdf_resume_parse_count' => 2,
        ]);

        $l = new ProfileResumePdfImportLimiter(2);

        $this->assertFalse($l->canRunNewPdfExtraction($user));
        $this->assertSame(0, $l->remainingForDisplay($user));
    }

    public function test_active_subscription_is_unlimited(): void
    {
        config(['billing.profile_resume_pdf.max_parses_for_pass_users' => 1]);

        $user = User::factory()->create([
            'profile_pdf_resume_parse_count' => 99,
        ]);

        Subscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_test_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
        ]);

        $user->refresh();

        $l = new ProfileResumePdfImportLimiter(1);

        $this->assertTrue($l->hasUnlimitedImports($user));
        $this->assertTrue($l->canRunNewPdfExtraction($user));
        $this->assertNull($l->remainingForDisplay($user));
    }
}
