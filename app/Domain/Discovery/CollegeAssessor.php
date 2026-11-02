<?php

namespace App\Domain\Discovery;

use App\Domain\Knowledge\RequirementConflicts;
use App\Domain\Knowledge\RequirementOverview;
use App\Models\AdmissionRequirement;
use App\Models\CollegeListSchool;
use App\Models\Institution;
use App\Models\KnowledgeSource;
use App\Models\OnboardingProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Checks a college against one student's constraints and explains every reason with where it came from:
 * the student's own profile, a page on file (Phase 5 sources), or an AI research estimate that nobody has
 * verified. Nothing here calls a model, and missing information is reported as missing — an affordability
 * view without a sourced cost and a budget in the same currency is marked incomplete, never guessed.
 */
final class CollegeAssessor
{
    private const KEY_TYPES = ['deadline', 'fee', 'test_policy'];

    private const GENERIC_WORDS = ['and', 'the', 'of', 'in', 'for', 'studies', 'program', 'programme', 'programs', 'department', 'school', 'college', 'major', 'majors', 'degree', 'bachelor', 'ba', 'bs', 'bsc'];

    public function __construct(
        private readonly RequirementConflicts $conflicts,
        private readonly RequirementOverview $overview,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function assess(User $student, CollegeListSchool $school): array
    {
        return $this->assessMany($student, collect([$school]))[(string) $school->id];
    }

    /**
     * @param  Collection<int, CollegeListSchool>  $schools
     * @return array<string, array<string, mixed>> Keyed by college choice id.
     */
    public function assessMany(User $student, Collection $schools): array
    {
        if ($schools->isEmpty()) {
            return [];
        }

        $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->first();
        $institutions = Institution::query()->whereIn('slug', $schools->pluck('school_slug')->filter()->unique()->values())->get()->keyBy('slug');
        $requirements = AdmissionRequirement::query()
            ->whereIn('institution_id', $institutions->pluck('id'))
            ->visibleTo($student)
            ->active()
            ->with('source')
            ->get()
            ->groupBy('institution_id');

        $assessments = [];

        foreach ($schools as $school) {
            $institution = $institutions->get($school->school_slug);
            $assessments[(string) $school->id] = $this->assessOne($profile, $school, $institution, $institution !== null ? $requirements->get($institution->id, collect()) : collect());
        }

        return $assessments;
    }

    /**
     * @param  Collection<int, AdmissionRequirement>  $requirements
     * @return array<string, mixed>
     */
    private function assessOne(?OnboardingProfile $profile, CollegeListSchool $school, ?Institution $institution, Collection $requirements): array
    {
        $cycle = $profile?->target_intake ?: $school->admissions_cycle_label;
        $country = $this->collegeCountry($school, $institution);
        $reasons = array_values(array_filter([
            $this->destination($profile, $country),
            $this->interests($profile, $school),
            $this->grades($profile, $school),
            $this->testing($profile, $school, $requirements, $cycle),
            $this->english($requirements, $cycle),
        ]));

        $affordability = $this->affordability($profile, $school, $requirements, $cycle, $country);
        array_push($reasons, ...$affordability['reasons']);
        unset($affordability['reasons']);

        $conflictIds = RequirementConflicts::ids($this->conflicts->groups($requirements));
        $missingKeys = array_values(array_diff(self::KEY_TYPES, $requirements->pluck('requirement_type')->unique()->all()));

        return [
            'reasons' => $reasons,
            'affordability' => $affordability,
            'counts' => [
                'good' => count(array_filter($reasons, fn (array $r) => $r['status'] === 'good')),
                'concern' => count(array_filter($reasons, fn (array $r) => $r['status'] === 'concern')),
                'unknown' => count(array_filter($reasons, fn (array $r) => $r['status'] === 'unknown')),
            ],
            'requirements' => [
                'onFile' => $requirements->count(),
                'conflicts' => count($conflictIds),
                'missingKeyTypes' => $missingKeys,
            ],
            'cycle' => $cycle,
            'country' => $country,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function destination(?OnboardingProfile $profile, ?string $country): ?array
    {
        $targets = array_values(array_filter((array) ($profile?->target_countries_json ?? []), fn ($c) => is_string($c) && trim($c) !== ''));

        if ($targets === []) {
            return null;
        }

        $list = implode(', ', $targets);

        if ($country === null) {
            return $this->reason('destination', 'unknown', "Country not on file, so it can't be checked against your destinations ({$list}).", 'missing');
        }

        $inTargets = collect($targets)->contains(fn (string $target) => $this->countryKey($target) === $this->countryKey($country));

        return $inTargets
            ? $this->reason('destination', 'good', "In {$country}, one of your destinations.", 'estimate')
            : $this->reason('destination', 'concern', "In {$country}, which is not one of your destinations ({$list}).", 'estimate');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function interests(?OnboardingProfile $profile, CollegeListSchool $school): ?array
    {
        $majors = array_values(array_filter((array) ($profile?->intended_majors_json ?? []), fn ($m) => is_string($m) && trim($m) !== ''));

        if ($majors === []) {
            return null;
        }

        $programs = array_values(array_filter((array) ($school->notable_programs ?? []), fn ($p) => is_string($p) && trim($p) !== ''));

        if ($programs === []) {
            return $this->reason('interests', 'unknown', 'Its programmes are not on file — check the course catalogue for '.implode(', ', $majors).'.', 'missing');
        }

        $matched = array_values(array_filter($majors, fn (string $major) => collect($programs)->contains(fn (string $program) => $this->overlaps($major, $program))));

        return $matched !== []
            ? $this->reason('interests', 'good', 'Lists programmes in '.implode(', ', $matched).', from your intended majors.', 'estimate')
            : $this->reason('interests', 'unknown', 'None of your intended majors ('.implode(', ', $majors).') appear in the short programme list on file — check the course catalogue.', 'estimate');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function grades(?OnboardingProfile $profile, CollegeListSchool $school): ?array
    {
        if ($school->median_gpa === null) {
            return null;
        }

        $median = (float) $school->median_gpa;
        $value = $profile?->gpa_value !== null ? (float) $profile->gpa_value : null;
        $scale = $profile?->gpa_scale !== null ? (float) $profile->gpa_scale : null;

        if ($value === null || $scale === null || $scale <= 0 || $median > 4.0) {
            $curriculum = $profile?->curriculum ? " ({$profile->curriculum})" : '';

            return $this->reason('grades', 'unknown', "Reported median GPA is {$this->num($median)}, but there's no GPA on your profile to compare{$curriculum}.", 'estimate');
        }

        $normalized = round($value / $scale * 4, 2);
        $yours = $scale == 4.0 ? $this->num($value) : "{$this->num($value)}/{$this->num($scale)} ≈ {$this->num($normalized)}/4.0";

        return $normalized >= $median
            ? $this->reason('grades', 'good', "Your GPA {$yours} is at or above the reported median {$this->num($median)}.", 'estimate')
            : $this->reason('grades', 'concern', "Your GPA {$yours} is below the reported median {$this->num($median)}.", 'estimate');
    }

    /**
     * @param  Collection<int, AdmissionRequirement>  $requirements
     * @return array<string, mixed>|null
     */
    private function testing(?OnboardingProfile $profile, CollegeListSchool $school, Collection $requirements, ?string $cycle): ?array
    {
        $hasScore = $profile?->sat_score !== null || $profile?->act_score !== null;
        $policy = $this->pick($requirements->where('requirement_type', 'test_policy'), $cycle);

        if ($policy !== null) {
            $text = Str::lower((string) $policy->value_text.' '.$policy->label.' '.$policy->quote);
            $optional = str_contains($text, 'optional') || str_contains($text, 'blind') || str_contains($text, 'free');
            $value = $policy->value_text ?: $policy->label;

            return match (true) {
                $optional && ! $hasScore => $this->reason('testing', 'good', "{$value}: you can apply without SAT/ACT scores.", 'source', $policy),
                ! $optional && str_contains($text, 'requir') && ! $hasScore => $this->reason('testing', 'concern', "{$value}, and there are no SAT/ACT scores on your profile.", 'source', $policy),
                default => $this->reason('testing', 'info', "Testing: {$value}.", 'source', $policy),
            };
        }

        if ($school->median_sat !== null && $profile?->sat_score !== null) {
            return (int) $profile->sat_score >= (int) $school->median_sat
                ? $this->reason('testing', 'good', "Your SAT {$profile->sat_score} is at or above the reported median {$school->median_sat}.", 'estimate')
                : $this->reason('testing', 'concern', "Your SAT {$profile->sat_score} is below the reported median {$school->median_sat}.", 'estimate');
        }

        return $this->reason('testing', 'unknown', 'Testing policy not on file — add the official admissions page.', 'missing');
    }

    /**
     * @param  Collection<int, AdmissionRequirement>  $requirements
     * @return array<string, mixed>|null
     */
    private function english(Collection $requirements, ?string $cycle): ?array
    {
        $english = $this->pick($requirements->where('requirement_type', 'english_test'), $cycle);

        if ($english === null) {
            return null;
        }

        $conditions = $english->conditions ? " ({$english->conditions})" : '';

        return $this->reason('english', 'info', 'English proficiency: '.($english->value_text ?: $english->label).$conditions.'. AdmitMe does not store English test scores yet.', 'source', $english);
    }

    /**
     * @param  Collection<int, AdmissionRequirement>  $requirements
     * @return array{status: string, complete: bool, summary: string, missing: list<string>, budget: ?array<string, mixed>, cost: ?array<string, mixed>, reasons: list<array<string, mixed>>}
     */
    private function affordability(?OnboardingProfile $profile, CollegeListSchool $school, Collection $requirements, ?string $cycle, ?string $country): array
    {
        $budgetAmount = $profile?->budget_amount;
        $budgetCurrency = $profile?->budget_currency ? strtoupper((string) $profile->budget_currency) : null;
        $budget = $budgetAmount !== null && $budgetCurrency !== null ? ['amount' => (int) $budgetAmount, 'currency' => $budgetCurrency] : null;
        $international = $country !== null && $profile?->location_country && $this->countryKey((string) $profile->location_country) !== $this->countryKey($country);

        $cost = $this->pickCost($requirements, $international, $country !== null, $cycle);
        $aid = $requirements->where('requirement_type', 'aid')->values();

        $reasons = [];
        $missing = [];

        if ($cost !== null) {
            $scope = $cost->applicant_scope !== 'all' ? " for {$cost->applicant_scope} students" : '';
            $reasons[] = $this->reason('cost', 'info', ($this->isTotalCost($cost) ? 'Cost of attendance' : 'Listed cost')."{$scope}: {$cost->currency} ".number_format((float) $cost->amount).' per year'.($cost->value_text ? " ({$cost->value_text})" : '').'.', 'source', $cost);
        }

        foreach ($aid->take(2) as $policy) {
            $reasons[] = $this->reason('aid', 'info', 'Aid: '.($policy->value_text ?: $policy->label).($policy->conditions ? " ({$policy->conditions})" : '').'.', 'source', $policy);
        }

        if ($aid->isEmpty() && $school->aid_estimate_band !== null) {
            $reasons[] = $this->reason('aid', 'unknown', "Aid outlook: {$school->aid_estimate_band}.", 'estimate');
        }

        if ($budget === null) {
            $missing[] = 'your yearly budget (amount and currency, in Profile setup)';
        }

        if ($cost === null) {
            $missing[] = "a cost figure from the college's own site (add its cost or tuition page as a source)";
        } elseif (! $this->isTotalCost($cost)) {
            $missing[] = 'the total cost of attendance — the source gives tuition only, without living costs';
        }

        if ($budget !== null && $cost !== null && $cost->currency !== $budget['currency']) {
            $missing[] = "a cost in {$budget['currency']} — the source gives {$cost->currency}, and AdmitMe doesn't convert currencies";
        }

        $comparable = $budget !== null && $cost !== null && $cost->currency === $budget['currency'];
        $amount = $cost !== null ? (float) $cost->amount : null;

        if (! $comparable) {
            $status = 'incomplete';
            $summary = 'Affordability is incomplete: missing '.implode('; ', $missing).'.';
        } elseif ($amount <= $budget['amount']) {
            $status = 'within_budget';
            $summary = "{$cost->currency} ".number_format($amount).' per year is within your budget of '.$budget['currency'].' '.number_format($budget['amount']).'.'
                .($this->isTotalCost($cost) ? '' : ' That figure is tuition only, so living costs are not counted yet.');
            $reasons[] = $this->reason('budget', 'good', $summary, 'source', $cost);
        } else {
            $gap = $amount - $budget['amount'];
            $gapText = $budget['currency'].' '.number_format($gap);

            if ($aid->isNotEmpty()) {
                $status = 'needs_aid';
                $missing[] = 'an aid offer or the college\'s net price estimate for you';
                $summary = "Over your budget by {$gapText} per year; affordable only with at least that much aid, which isn't known until an aid offer.";
            } else {
                $status = 'over_budget';
                $missing[] = 'the college\'s aid policy for students like you (add its financial-aid page)';
                $summary = "Over your budget by {$gapText} per year, and no aid policy is on file.";
            }

            $reasons[] = $this->reason('budget', 'concern', $summary, 'source', $cost);
        }

        if ($profile?->financial_aid_need_percent !== null && (int) $profile->financial_aid_need_percent >= 50 && $aid->isEmpty()) {
            $reasons[] = $this->reason('aid', 'unknown', "You said you need about {$profile->financial_aid_need_percent}% of costs covered by aid; no aid policy from the college is on file.", 'profile');
        }

        return [
            'status' => $status,
            'complete' => $missing === [],
            'summary' => $summary,
            'missing' => $missing,
            'budget' => $budget,
            'cost' => $cost !== null ? ['amount' => $amount, 'currency' => $cost->currency, 'totalCost' => $this->isTotalCost($cost)] : null,
            'reasons' => $reasons,
        ];
    }

    /**
     * Prefer a requirement for the student's cycle (or one that names no cycle) from the most trusted source.
     *
     * @param  Collection<int, AdmissionRequirement>  $candidates
     */
    private function pick(Collection $candidates, ?string $cycle): ?AdmissionRequirement
    {
        $rank = fn (AdmissionRequirement $r) => [
            $cycle !== null && $r->cycle_label !== null && $this->sameCycle($r->cycle_label, $cycle) ? 0 : ($r->cycle_label === null ? 1 : 2),
            match ($r->source?->source_type) {
                KnowledgeSource::TYPE_OFFICIAL => 0,
                KnowledgeSource::TYPE_REFERENCE => 1,
                default => 2,
            },
        ];

        return $candidates->values()->sort(fn (AdmissionRequirement $a, AdmissionRequirement $b) => $rank($a) <=> $rank($b))->first();
    }

    /**
     * The cost figure to compare with the budget: one for the student's applicant group when known (else any),
     * a total cost of attendance over a single component, the student's cycle, the most trusted source — and,
     * among several totals, the larger one, so affordability is never overstated.
     *
     * @param  Collection<int, AdmissionRequirement>  $requirements
     */
    private function pickCost(Collection $requirements, bool $international, bool $countryKnown, ?string $cycle): ?AdmissionRequirement
    {
        $costs = $requirements->where('requirement_type', 'cost')->filter(fn (AdmissionRequirement $r) => $r->amount !== null && $r->currency !== null);
        $preferredScope = $countryKnown ? ($international ? 'international' : 'domestic') : null;

        return $costs->sortBy(fn (AdmissionRequirement $r) => [
            match (true) {
                $preferredScope !== null && $r->applicant_scope === $preferredScope => 0,
                $r->applicant_scope === 'all' => 1,
                // The college's country is unknown, so either group's figure may be the student's...
                $preferredScope === null => 1,
                default => 2,
            },
            $this->isTotalCost($r) ? 0 : 1,
            $cycle !== null && $r->cycle_label !== null && $this->sameCycle($r->cycle_label, $cycle) ? 0 : ($r->cycle_label === null ? 1 : 2),
            match ($r->source?->source_type) {
                KnowledgeSource::TYPE_OFFICIAL => 0,
                KnowledgeSource::TYPE_REFERENCE => 1,
                default => 2,
            },
            -(float) $r->amount,
        ])->first();
    }

    private function isTotalCost(AdmissionRequirement $r): bool
    {
        $text = Str::lower($r->label.' '.$r->value_text);

        if (str_contains($text, 'tuition only')) {
            return false;
        }

        // "Cost of attendance: tuition" is a component, not the total...
        $component = preg_match('/\b(tuition|housing|meals|room|board|books|insurance|fees)\b/', $text) === 1;

        return str_contains($text, 'total') || str_contains($text, 'all costs') || (str_contains($text, 'attendance') && ! $component);
    }

    private function collegeCountry(CollegeListSchool $school, ?Institution $institution): ?string
    {
        if ($institution?->country) {
            return $institution->country;
        }

        // Research rows carry a two-letter US state code only for US colleges...
        return is_string($school->location_state) && preg_match('/^[A-Z]{2}$/', $school->location_state) === 1 ? 'United States' : null;
    }

    private function countryKey(string $country): string
    {
        $key = Str::of($country)->lower()->replaceMatches('/[^a-z ]+/', '')->squish()->toString();

        return match ($key) {
            'us', 'usa', 'u s', 'united states of america', 'america', 'united states' => 'united states',
            'uk', 'u k', 'great britain', 'britain', 'england', 'scotland', 'wales', 'northern ireland', 'united kingdom' => 'united kingdom',
            default => $key,
        };
    }

    private function overlaps(string $major, string $program): bool
    {
        $words = fn (string $text) => array_values(array_diff(
            array_filter(preg_split('/[^a-z]+/', Str::lower($text)) ?: [], fn (string $w) => strlen($w) >= 3),
            self::GENERIC_WORDS,
        ));

        $majorWords = $words($major);

        return $majorWords !== [] && array_intersect($majorWords, $words($program)) === $majorWords;
    }

    private function sameCycle(string $a, string $b): bool
    {
        $normalize = fn (string $value) => Str::of($value)->lower()->replace(['entry', 'intake', 'admission', 'admissions'], '')->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return $normalize($a) === $normalize($b);
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * @return array<string, mixed>
     */
    private function reason(string $kind, string $status, string $text, string $basis, ?AdmissionRequirement $requirement = null): array
    {
        $source = $requirement?->source;

        return [
            'kind' => $kind,
            // good | concern | unknown | info
            'status' => $status,
            'text' => $text,
            // profile | source | estimate | missing
            'basis' => $basis,
            'basisLabel' => match ($basis) {
                'profile' => 'From your profile',
                'source' => $source !== null ? 'From '.($source->title ?: $source->host).' — '.$this->overview->sourceLabel($source) : 'From a source on file',
                'estimate' => 'AI research estimate — not verified',
                default => 'Not on file',
            },
            'source' => $source !== null ? [
                'url' => $source->url,
                'title' => $source->title,
                'type' => $source->source_type,
                'private' => $source->isPrivate(),
                'checkedAt' => $source->checked_at?->toIso8601String(),
            ] : null,
            'quote' => $requirement?->quote,
        ];
    }
}
