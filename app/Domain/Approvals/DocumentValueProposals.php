<?php

namespace App\Domain\Approvals;

use App\Domain\Approvals\Concerns\BuildsChangeItems;
use App\Domain\Documents\CandidateReviewService;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\FactStatus;
use App\Domain\Students\StudentFieldRegistry;
use App\Models\Document;
use App\Models\OnboardingProfile;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Agent proposals that decide values read from the student's documents ({@see Proposal::TYPE_DOCUMENT_VALUES}):
 * accept one (as read, or with the student's correction) or reject it. Approving runs the same review as the
 * Documents page ({@see CandidateReviewService}), so an accepted value becomes a confirmed fact citing its
 * document page, and a value reviewed meanwhile or read from a replaced file is never decided twice.
 */
final class DocumentValueProposals
{
    use BuildsChangeItems;

    /** @var list<string> */
    public const ACTIONS = ['accept', 'reject'];

    /** What a rejected item shows as its new value. */
    public const REJECTED = 'Rejected — not saved';

    /** The decision endpoint accepts at most 50 selected items. */
    private const MAX_ITEMS = 50;

    public function __construct(private readonly CandidateReviewService $reviews) {}

    /**
     * The values waiting for the student's review: their own, read from the current version of a document
     * they still have, for a field the profile tracks — the ones the Documents page lets them decide.
     *
     * @return Collection<int, array{fact: StudentFact, document: Document, label: string, page: int|null, quote: string|null}>
     */
    public function pending(User $student): Collection
    {
        $facts = StudentFact::query()
            ->where('user_id', $student->getKey())
            ->whereIn('status', [FactStatus::Candidate, FactStatus::Conflict])
            ->where('review_state', StudentFact::REVIEW_PENDING)
            ->orderBy('id')
            ->get();

        $documents = Document::query()
            ->where('user_id', $student->getKey())
            ->whereIn('id', $facts->map(fn (StudentFact $fact) => $fact->source_ref['document_id'] ?? null)->filter()->unique()->values())
            ->get()
            ->keyBy(fn (Document $document) => (string) $document->getKey());

        return $facts
            ->map(function (StudentFact $fact) use ($documents) {
                $document = $documents->get((string) ($fact->source_ref['document_id'] ?? ''));

                if ($document === null || $document->current_version_id !== ($fact->source_ref['document_version_id'] ?? null) || ! StudentFieldRegistry::has($fact->field_key)) {
                    return null;
                }

                return [
                    'fact' => $fact,
                    'document' => $document,
                    'label' => StudentFieldRegistry::get($fact->field_key)['label'],
                    'page' => is_numeric($fact->source_ref['page'] ?? null) ? (int) $fact->source_ref['page'] : null,
                    'quote' => is_string($fact->source_ref['quote'] ?? null) && $fact->source_ref['quote'] !== '' ? $fact->source_ref['quote'] : null,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * One item per value: key `fact:{id}`, before = the profile value now, after = the value to save (or
     * "Rejected — not saved"), with the document's quote as evidence.
     *
     * @param  list<array<string, mixed>>  $decisions  Each: value_id, action (accept|reject), edited_value
     *                                                 (accept only; null saves the value as read), reason.
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException with every problem at once, so the agent can fix them in one retry.
     */
    public function items(User $student, array $decisions): array
    {
        Validator::make(['decisions' => $decisions], [
            'decisions' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'decisions.*' => ['required', 'array'],
            'decisions.*.value_id' => ['required', 'integer'],
            'decisions.*.action' => ['required', Rule::in(self::ACTIONS)],
            'decisions.*.reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $pending = $this->pending($student)->keyBy(fn (array $row) => (string) $row['fact']->getKey());
        $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->first();

        $items = [];
        $errors = [];

        foreach (array_values($decisions) as $index => $decision) {
            $row = $pending->get((string) (int) $decision['value_id']);

            if ($row === null) {
                $errors["decisions.{$index}"][] = $this->notPending($student, (int) $decision['value_id']);

                continue;
            }

            try {
                $item = $this->item($row, $decision, $profile);
            } catch (ValidationException $e) {
                $errors["decisions.{$index}"] = [...($errors["decisions.{$index}"] ?? []), ...array_merge(...array_values($e->errors()))];

                continue;
            }

            if (isset($items[$item['key']]) && ($items[$item['key']]['action'] !== $item['action'] || ! $this->same($items[$item['key']]['after'], $item['after']))) {
                $errors["decisions.{$index}"][] = "{$item['label']} is decided twice in different ways. Decide it once.";
            }

            $items[$item['key']] = $item;
        }

        // Two values for one field cannot both be saved: the second would overwrite the first...
        foreach (collect($items)->where('action', 'accept')->groupBy('field') as $group) {
            if ($group->count() > 1) {
                $errors['decisions'][] = "More than one value for {$group->first()['field_label']} is proposed for saving (".$group->pluck('label')->implode('; ').'). Accept one and reject the others.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_values($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function title(array $items): string
    {
        $documents = array_values(array_unique(array_column($items, 'document')));
        $where = count($documents) === 1 ? $documents[0] : 'your documents';
        $count = self::plural(count($items), 'value');

        return match (array_values(array_unique(array_column($items, 'action')))) {
            ['accept'] => "Save {$count} from {$where} to your profile",
            ['reject'] => "Reject {$count} read from {$where}",
            default => "Review {$count} read from {$where}",
        };
    }

    /**
     * Decide each selected value through the Documents review. A value reviewed meanwhile, read from a
     * replaced file, or whose profile field changed since the proposal is `stale` and left as it is. The
     * review records its own provenance (the student, from the document page).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: ProposalStatus, 1: array<string, string>}
     */
    public function apply(User $student, array $items, ChangeSource $source): array
    {
        $outcomes = [];

        foreach ($items as $item) {
            $outcomes[$item['key']] = $this->applyOne($student, $item);
        }

        return [$this->statusFor($outcomes), $outcomes];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function applyOne(User $student, array $item): string
    {
        $fact = StudentFact::query()->where('user_id', $student->getKey())->find($item['fact_id'] ?? null);

        if ($fact === null) {
            return 'stale';
        }

        // Saving over a profile value that changed after the proposal would overwrite the newer edit...
        if (($item['action'] ?? null) === 'accept') {
            $profile = OnboardingProfile::query()->where('user_id', $student->getKey())->first();

            if (! $this->same($profile?->getAttribute(StudentFieldRegistry::column($fact->field_key)), $item['before'] ?? null)) {
                return 'stale';
            }
        }

        return $this->attempt(function () use ($student, $fact, $item) {
            $accept = ($item['action'] ?? null) === 'accept';

            $this->reviews->decide($student, $fact, $accept ? 'accept' : 'reject', $accept ? ($item['edited_value'] ?? null) : null, null);

            return 'applied';
        });
    }

    /**
     * @param  array{fact: StudentFact, document: Document, label: string, page: int|null, quote: string|null}  $row
     * @param  array<string, mixed>  $decision
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function item(array $row, array $decision, ?OnboardingProfile $profile): array
    {
        $fact = $row['fact'];
        $accept = $decision['action'] === 'accept';
        $edited = $accept ? $this->edited($fact, $row['label'], $decision['edited_value'] ?? null) : null;
        $page = $row['page'] !== null ? ", p.{$row['page']}" : '';

        return [
            'key' => "fact:{$fact->getKey()}",
            'label' => "{$row['label']} · {$row['document']->title}{$page}",
            'before' => $profile?->getAttribute(StudentFieldRegistry::column($fact->field_key)),
            'after' => $accept ? ($edited ?? $fact->value) : self::REJECTED,
            ...$this->explanation([
                'reason' => $decision['reason'] ?? null,
                'evidence' => $row['quote'] !== null ? [[
                    'text' => '“'.$row['quote'].'”',
                    'basis' => $row['document']->title.($row['page'] !== null ? ", page {$row['page']}" : '').' — read as '.self::shown($fact->value),
                    'url' => null,
                ]] : [],
            ]),
            'action' => $accept ? 'accept' : 'reject',
            'fact_id' => (int) $fact->getKey(),
            'field' => $fact->field_key,
            'field_label' => $row['label'],
            'document' => (string) $row['document']->title,
            'edited_value' => $edited,
        ];
    }

    /**
     * The student's correction, checked like a profile edit; null when there is no correction.
     *
     * @throws ValidationException
     */
    private function edited(StudentFact $fact, string $label, mixed $value): mixed
    {
        $value = self::text($value);

        if ($value === null) {
            return null;
        }

        $key = $fact->field_key;
        $type = StudentFieldRegistry::get($key)['type'];

        if ($type === 'json') {
            throw ValidationException::withMessages(['edited_value' => "{$label} cannot be corrected here; accept or reject it as read."]);
        }

        $coerced = match ($type) {
            'integer' => is_numeric($value) ? (int) $value : $value,
            'list' => is_array($value) ? $value : array_map('trim', preg_split('/[,\n]/', (string) $value) ?: []),
            default => is_scalar($value) ? (string) $value : $value,
        };

        $validator = Validator::make([$key => $coerced], StudentFieldRegistry::rules([$key]));

        if ($validator->fails()) {
            throw ValidationException::withMessages(['edited_value' => "{$label}: \"".self::shown($value).'" is not a valid value — '.$validator->errors()->first()]);
        }

        $normalized = StudentFieldRegistry::normalize($key, $coerced);

        return $this->same($normalized, $fact->value) ? null : $normalized;
    }

    private function notPending(User $student, int $id): string
    {
        $fact = StudentFact::query()->where('user_id', $student->getKey())->find($id);

        return match (true) {
            $fact === null || $fact->source_type !== ChangeSource::SOURCE_DOCUMENT => "Value {$id} is not one of the student's document values waiting for review.",
            $fact->review_state === StudentFact::REVIEW_SUPERSEDED => "Value {$id} was read from a version of the document that has since been replaced; it can no longer be decided.",
            $fact->review_state !== StudentFact::REVIEW_PENDING => "Value {$id} was already {$fact->review_state} by the student.",
            default => "Value {$id} can no longer be decided: its document was replaced or removed.",
        };
    }

    private static function shown(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => '—',
            is_array($value) => implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : (json_encode($item) ?: ''), $value)),
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
