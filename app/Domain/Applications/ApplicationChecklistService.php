<?php

namespace App\Domain\Applications;

use App\Domain\Audit\AuditLogger;
use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Knowledge\RequirementConflicts;
use App\Domain\Knowledge\RequirementOverview;
use App\Domain\Shared\ChangeSource;
use App\Domain\Shared\StaleRevisionException;
use App\Models\AdmissionRequirement;
use App\Models\Application;
use App\Models\ApplicationItem;
use App\Models\CollegeListSchool;
use App\Models\KnowledgeSource;
use App\Models\OnboardingProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * The application checklist: built from the requirements the student can see (Phase 5 sources) plus a few
 * default steps, and changed only through this service, which enforces the completion rules — the
 * student's own work is done when they report it; third-party work is done only when they report the
 * portal shows it received or the sender confirmed it. Requesting it is never completion.
 */
final class ApplicationChecklistService
{
    /** Order of generated items by requirement type. */
    private const TYPE_ORDER = ['deadline', 'fee', 'test_policy', 'english_test', 'document', 'essay', 'recommendation', 'interview', 'aid', 'other'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InstitutionResolver $institutions,
        private readonly RequirementOverview $overview,
        private readonly RequirementConflicts $conflicts,
    ) {}

    /**
     * Build or refresh the checklist. Existing items keep their status; open generated items pick up
     * changed details; generated items whose requirement is gone are flagged, never deleted.
     *
     * @return array{created: int, updated: int, flagged: int}
     */
    public function build(Application $application, ChangeSource $source): array
    {
        $application->loadMissing('collegeListSchool', 'user');
        $school = $application->collegeListSchool;
        $student = $application->user;
        $institution = $this->institutions->findForSchool($school);
        $requirements = $institution !== null ? $this->overview->visibleRequirements($institution, $student) : collect();
        $cycle = $application->cycle_label
            ?: OnboardingProfile::query()->where('user_id', $student->getKey())->value('target_intake')
            ?: $school->admissions_cycle_label;

        // A requirement that restates a default step (e.g. the school transcript) replaces that step's draft...
        $drafts = array_values(collect([...$this->defaults($application, $school), ...$this->fromRequirements($requirements, $cycle)])
            ->keyBy('item_key')
            ->all());

        return DB::transaction(function () use ($application, $drafts, $source) {
            $existing = ApplicationItem::query()->where('application_id', $application->getKey())->lockForUpdate()->get()->keyBy('item_key');
            $counts = ['created' => 0, 'updated' => 0, 'flagged' => 0];
            $seen = [];
            $position = 0;

            foreach ($drafts as $draft) {
                $seen[$draft['item_key']] = true;
                $position++;
                $item = $existing->get($draft['item_key']);

                if ($item === null) {
                    $owner = $draft['owner_type'];
                    ApplicationItem::query()->create([
                        ...$draft,
                        'application_id' => $application->getKey(),
                        'user_id' => $application->user_id,
                        'owner_type' => $owner,
                        'status' => ChecklistStatus::initial($owner),
                        'position' => $position,
                    ]);
                    $counts['created']++;

                    continue;
                }

                // Finished items keep what the student saw when they finished them...
                if (! $item->status->isClosed()) {
                    $item->fill(array_intersect_key($draft, array_flip(['title', 'details', 'due_date', 'due_time', 'timezone', 'admission_requirement_id', 'owner_label', 'origin'])));
                }

                $item->source_missing_at = null;
                $item->position = $position;

                if ($item->isDirty(['title', 'details', 'due_date', 'due_time', 'timezone', 'admission_requirement_id', 'owner_label', 'origin', 'source_missing_at'])) {
                    $item->revision = (int) $item->revision + 1;
                    $counts['updated']++;
                }

                $item->save();
            }

            foreach ($existing as $key => $item) {
                if ($item->origin === ApplicationItem::ORIGIN_REQUIREMENT && ! isset($seen[$key]) && $item->source_missing_at === null) {
                    $item->forceFill(['source_missing_at' => now(), 'revision' => (int) $item->revision + 1])->save();
                    $counts['flagged']++;
                }
            }

            $application->forceFill(['checklist_built_at' => now()])->save();

            $this->audit->record((int) $application->user_id, $source, 'application.checklist_built', $application, (int) $application->revision, (int) $application->revision, [
                'checklist' => [null, $counts],
            ], ['requirements_used' => count(array_filter($drafts, fn (array $d) => $d['origin'] === ApplicationItem::ORIGIN_REQUIREMENT))]);

            return $counts;
        });
    }

    /**
     * @param  array<string, mixed>  $changes  Any of: status, status_note, completion_basis, document_id, due_date, owner_label, title, details.
     *
     * @throws ValidationException
     * @throws StaleRevisionException
     */
    public function update(ApplicationItem $item, array $changes, ?int $expectedRevision, ChangeSource $source): ApplicationItem
    {
        $owner = $item->owner_type;

        $validated = Validator::make($changes, [
            'status' => ['sometimes', Rule::in(array_map(fn (ChecklistStatus $s) => $s->value, ChecklistStatus::for($owner)))],
            'status_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'completion_basis' => ['sometimes', 'nullable', new Enum(CompletionBasis::class)],
            'document_id' => ['sometimes', 'nullable', 'string', Rule::exists('documents', 'id')->where('user_id', $item->user_id)],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'owner_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'title' => ['sometimes', 'string', 'min:2', 'max:200'],
            'details' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ], [
            'status.in' => $owner === ChecklistOwner::ThirdParty
                ? 'Someone else does this step, so it can only be not requested, requested, received, blocked or not needed.'
                : 'That is not a valid status for this step.',
            'document_id.exists' => 'Choose one of your own documents.',
        ])->validate();

        if ($item->origin === ApplicationItem::ORIGIN_REQUIREMENT && array_intersect_key($validated, array_flip(['due_date', 'title', 'details'])) !== []) {
            throw ValidationException::withMessages(['due_date' => 'This step comes from a source on file; its wording and date follow the source. Add your own step instead.']);
        }

        if ($owner === ChecklistOwner::Student) {
            unset($validated['owner_label']);
        }

        return DB::transaction(function () use ($item, $validated, $expectedRevision, $source, $owner) {
            /** @var ApplicationItem $locked */
            $locked = ApplicationItem::query()->lockForUpdate()->findOrFail($item->getKey());
            $revisionBefore = (int) $locked->revision;

            if ($expectedRevision !== null && $expectedRevision !== $revisionBefore) {
                throw new StaleRevisionException('application_item', $expectedRevision, $revisionBefore);
            }

            $wasComplete = $locked->status->isComplete();
            $locked->fill(array_diff_key($validated, array_flip(['status', 'completion_basis'])));

            if (array_key_exists('status', $validated)) {
                $status = ChecklistStatus::from($validated['status']);

                if ($status->needsNote() && trim((string) ($validated['status_note'] ?? '')) === '') {
                    throw ValidationException::withMessages(['status_note' => $status === ChecklistStatus::Blocked ? 'Say what is blocking this step.' : 'Say why this step is not needed.']);
                }

                if ($status->isComplete()) {
                    $basis = $owner === ChecklistOwner::Student
                        ? CompletionBasis::StudentReported
                        : (isset($validated['completion_basis']) ? CompletionBasis::from($validated['completion_basis']) : null);

                    if ($basis === null || ! in_array($basis, CompletionBasis::for($owner), true)) {
                        throw ValidationException::withMessages(['completion_basis' => 'Someone else sends this. Mark it received only when the portal shows it arrived or the sender confirmed it — asking for it is not the same as it being done.']);
                    }

                    $locked->completion_basis = $basis;
                    $locked->completed_at = $wasComplete ? $locked->completed_at : now();
                } else {
                    $locked->completion_basis = null;
                    $locked->completed_at = null;

                    if (! $status->needsNote() && ! array_key_exists('status_note', $validated)) {
                        $locked->status_note = null;
                    }
                }

                $locked->status = $status;
            }

            $changes = [];

            foreach (array_keys($locked->getDirty()) as $key) {
                $changes[$key] = [$this->plain($locked->getOriginal($key)), $this->plain($locked->getAttribute($key))];
            }

            if ($changes === []) {
                return $locked;
            }

            $locked->revision = $revisionBefore + 1;
            $locked->save();

            $this->audit->record((int) $locked->user_id, $source, 'application_item.updated', $locked, $revisionBefore, $locked->revision, $changes, ['title' => $locked->title]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data  title, kind?, owner_type?, owner_label?, due_date?, details?
     *
     * @throws ValidationException
     */
    public function addManual(Application $application, array $data, ChangeSource $source): ApplicationItem
    {
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'kind' => ['sometimes', Rule::in(ApplicationItem::KINDS)],
            'owner_type' => ['sometimes', new Enum(ChecklistOwner::class)],
            'owner_label' => ['nullable', 'string', 'max:120', 'required_if:owner_type,third_party'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'details' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ], ['owner_label.required_if' => 'Say who does this step (for example "Math teacher").'])->validate();

        $owner = ChecklistOwner::from($validated['owner_type'] ?? ChecklistOwner::Student->value);

        return DB::transaction(function () use ($application, $validated, $owner, $source) {
            $position = 1000 + (int) ApplicationItem::query()->where('application_id', $application->getKey())->where('origin', ApplicationItem::ORIGIN_MANUAL)->count();

            $item = ApplicationItem::query()->create([
                'application_id' => $application->getKey(),
                'user_id' => $application->user_id,
                'item_key' => 'manual:'.Str::lower((string) Str::ulid()),
                'kind' => $validated['kind'] ?? 'other',
                'title' => trim($validated['title']),
                'details' => $validated['details'] ?? null,
                'origin' => ApplicationItem::ORIGIN_MANUAL,
                'owner_type' => $owner,
                'owner_label' => $owner === ChecklistOwner::ThirdParty ? trim((string) ($validated['owner_label'] ?? '')) : null,
                'status' => ChecklistStatus::initial($owner),
                'due_date' => $validated['due_date'] ?? null,
                'position' => $position,
            ]);

            $this->audit->record((int) $application->user_id, $source, 'application_item.added', $item, null, 1, [
                'title' => [null, $item->title],
                'owner_type' => [null, $owner->value],
            ]);

            return $item;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaults(Application $application, CollegeListSchool $school): array
    {
        $portal = $application->portal_url ?: $school->application_portal_url;

        return [
            $this->draft('default:portal_account', 'portal_step', 'Create your account on the application portal', $portal
                ? "Portal on file: {$portal} (from research — confirm it on the college's admissions site)."
                : 'The portal is not on file yet. Find it on the college\'s admissions site and save it on this application.', ApplicationItem::ORIGIN_DEFAULT),
            $this->draft('default:application_form', 'form', 'Complete the application form', 'Fill in every section in the portal. With the AdmitMe browser extension the mentor can fill it in your browser (you log in; it asks before submitting). Mark this done when the portal shows it finished.', ApplicationItem::ORIGIN_DEFAULT),
            $this->draft('default:school_documents', 'document', 'School transcript and school report', 'Usually sent by your school, not by you. Check the college\'s requirements for exactly what it needs.', ApplicationItem::ORIGIN_DEFAULT, ChecklistOwner::ThirdParty, 'School counselor'),
        ];
    }

    /**
     * One draft per requirement, keyed so that differently worded or re-fetched versions of the same
     * requirement update the same item. Where sources disagree, the most trusted one is used and the
     * disagreement is spelled out in the details.
     *
     * @param  Collection<int, AdmissionRequirement>  $requirements
     * @return list<array<string, mixed>>
     */
    private function fromRequirements(Collection $requirements, ?string $cycle): array
    {
        $conflictIds = RequirementConflicts::ids($this->conflicts->groups($requirements));
        $drafts = [];

        $groups = $requirements
            ->reject(fn (AdmissionRequirement $r) => $r->requirement_type === 'cost' || ($r->requirement_type === 'aid' && $r->deadline_date === null))
            ->groupBy(fn (AdmissionRequirement $r) => RequirementConflicts::key($r))
            ->sortBy(fn (Collection $group) => array_search($group->first()->requirement_type, self::TYPE_ORDER, true));

        foreach ($groups as $key => $group) {
            $chosen = $this->preferred($group, $cycle);
            $disagreeing = $group->filter(fn (AdmissionRequirement $r) => in_array($r->id, $conflictIds, true) && $r->id !== $chosen->id);
            $draft = $this->draftFor($chosen, 'req:'.(strlen($key) > 150 ? sha1($key) : $key));

            if ($disagreeing->isNotEmpty()) {
                $others = $disagreeing->map(fn (AdmissionRequirement $r) => $this->overview->value($r).' per '.$this->overview->sourceLabel($r->source))->unique()->implode('; ');
                $draft['details'] = trim(($draft['details'] ?? '')."\nSources disagree — also stated: {$others}. Check the official site before relying on this.");
            }

            $drafts[] = $draft;
        }

        return $drafts;
    }

    /**
     * @return array<string, mixed>
     */
    private function draftFor(AdmissionRequirement $r, string $key): array
    {
        $value = $this->overview->value($r);
        $text = Str::lower($r->label.' '.$r->value_text.' '.$r->quote);
        $conditions = $r->conditions ? "Conditions: {$r->conditions}" : null;
        $cycle = $r->cycle_label ? "Cycle: {$r->cycle_label}" : 'Cycle: not stated on the source';
        $notes = fn (?string ...$parts) => implode("\n", array_filter($parts)) ?: null;
        $due = $r->deadline_date !== null ? ['due_date' => $r->deadline_date->toDateString(), 'due_time' => $r->deadline_time, 'timezone' => $r->timezone] : [];

        $schoolDocuments = $r->requirement_type === 'document' && preg_match('/transcript|school report|counsel(l)?or|secondary school/', $text) === 1;

        $draft = match ($r->requirement_type) {
            'deadline' => $this->draft($key, 'deadline', "{$r->label}: {$value}", $notes($cycle, $conditions, 'Applying in a different round? Mark this one "not needed".')),
            'fee' => $r->amount !== null
                ? $this->draft($key, 'fee', 'Pay the '.$this->lowerFirst($r->label)." ({$value})", $notes($conditions, $cycle))
                : $this->draft($key, 'fee', $r->label, $notes($r->value_text, $conditions, 'If this does not apply to you, mark it "not needed".')),
            'test_policy' => str_contains($text, 'optional') || str_contains($text, 'blind') || str_contains($text, 'free')
                ? $this->draft($key, 'test', "Decide whether to send SAT/ACT scores ({$value})", $notes($conditions, $cycle))
                : $this->draft($key, 'test', "Official SAT/ACT score report ({$value})", $notes($conditions, 'Ordered from the testing agency; mark it received when the portal shows it.'), ApplicationItem::ORIGIN_REQUIREMENT, ChecklistOwner::ThirdParty, 'Testing agency (College Board / ACT)'),
            'english_test' => $this->draft($key, 'english_test', "Official English test score report ({$value})", $notes($conditions, 'Ordered from the testing agency; mark it received when the portal shows it.'), ApplicationItem::ORIGIN_REQUIREMENT, ChecklistOwner::ThirdParty, 'Testing agency (ETS / IELTS)'),
            // Restates the default "school documents" step, so it reuses that step's key instead of adding a duplicate...
            'document' => $schoolDocuments
                ? $this->draft('default:school_documents', 'document', $r->label, $notes($r->value_text, $conditions, 'Usually sent by your school, not by you.'), ApplicationItem::ORIGIN_REQUIREMENT, ChecklistOwner::ThirdParty, 'School counselor')
                : $this->draft($key, 'document', $r->label, $notes($r->value_text, $conditions, 'Attach the document you will use from your Documents.')),
            'essay' => $this->draft($key, 'essay', "Write: {$r->label}", $notes($r->value_text ? "Prompt: {$r->value_text}" : "From the source: “{$r->quote}”", $conditions)),
            'recommendation' => $this->draft($key, 'recommendation', $r->label, $notes($r->value_text, $conditions), ApplicationItem::ORIGIN_REQUIREMENT, ChecklistOwner::ThirdParty, match (true) {
                str_contains($text, 'counsel') => 'School counselor',
                str_contains($text, 'teacher') => 'Teacher',
                default => 'Recommender',
            }),
            'interview' => $this->draft($key, 'interview', $r->label, $notes($r->value_text, $conditions)),
            'aid' => $this->draft($key, 'financial_aid', $r->label, $notes($r->value_text, $conditions)),
            default => $this->draft($key, 'other', $r->label, $notes($r->value_text, $conditions)),
        };

        return [...$draft, ...$due, 'admission_requirement_id' => $r->id];
    }

    /**
     * @param  Collection<int, AdmissionRequirement>  $group
     */
    private function preferred(Collection $group, ?string $cycle): AdmissionRequirement
    {
        $normalize = fn (?string $value) => Str::of((string) $value)->lower()->replace(['entry', 'intake', 'admission', 'admissions'], '')->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return $group->sortBy(fn (AdmissionRequirement $r) => [
            match ($r->source?->source_type) {
                KnowledgeSource::TYPE_OFFICIAL => 0,
                KnowledgeSource::TYPE_REFERENCE => 1,
                default => 2,
            },
            $cycle !== null && $r->cycle_label !== null && $normalize($r->cycle_label) === $normalize($cycle) ? 0 : 1,
            // A statement with an exact date or amount beats one without...
            $r->deadline_date !== null || $r->amount !== null ? 0 : 1,
            $r->id,
        ])->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function draft(string $key, string $kind, string $title, ?string $details, string $origin = ApplicationItem::ORIGIN_REQUIREMENT, ChecklistOwner $owner = ChecklistOwner::Student, ?string $ownerLabel = null): array
    {
        return [
            'item_key' => $key,
            'kind' => $kind,
            'title' => Str::limit($title, 200, '…'),
            'details' => $details,
            'origin' => $origin,
            'owner_type' => $owner,
            'owner_label' => $ownerLabel,
            'admission_requirement_id' => null,
            'due_date' => null,
            'due_time' => null,
            'timezone' => null,
        ];
    }

    private function lowerFirst(string $label): string
    {
        // "Application fee" → "application fee", but keep acronyms such as "UCAS fee".
        return preg_match('/^[A-Z][a-z]/', $label) === 1 ? Str::lcfirst($label) : $label;
    }

    private function plain(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : ($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value);
    }
}
