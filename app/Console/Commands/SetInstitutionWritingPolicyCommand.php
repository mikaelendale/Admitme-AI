<?php

namespace App\Console\Commands;

use App\Domain\Writing\WritingPolicy;
use App\Models\Institution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Operator-only: record a college's policy on AI help with application writing (from its published rules).
 * New writing pieces for that college use it; students cannot change it.
 */
class SetInstitutionWritingPolicyCommand extends Command
{
    protected $signature = 'admitme:institution-writing-policy
        {institution : Institution slug or exact name}
        {policy : no_ai_assistance, feedback_only or light_editing (or "default" to clear)}';

    protected $description = 'Set the AI writing-assistance policy for an institution';

    public function handle(): int
    {
        $key = trim((string) $this->argument('institution'));
        $institution = Institution::query()->where('slug', $key)->orWhere('name', $key)->first();

        if ($institution === null) {
            $this->error("No institution matches \"{$key}\".");

            return self::FAILURE;
        }

        $value = (string) $this->argument('policy');
        $policy = $value === 'default' ? null : WritingPolicy::tryFrom($value);

        if ($value !== 'default' && $policy === null) {
            $this->error('Policy must be one of: '.implode(', ', array_map(fn (WritingPolicy $p) => $p->value, WritingPolicy::cases())).', or default.');

            return self::FAILURE;
        }

        $institution->update(['writing_policy' => $policy?->value]);
        Log::info('Institution writing policy set', ['institution_id' => $institution->id, 'policy' => $policy?->value]);
        $this->info("{$institution->name}: ".($policy?->label() ?? 'default policy').'. Applies to writing pieces created from now on.');

        return self::SUCCESS;
    }
}
