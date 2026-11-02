<?php

namespace App\Domain\Approvals;

use App\Domain\Applications\ApplicationStatus;
use App\Domain\Applications\ChecklistStatus;
use App\Domain\Applications\CompletionBasis;
use App\Domain\Applications\SubmissionEvidence;
use App\Domain\Students\StudentFieldRegistry;
use App\Models\Proposal;
use Carbon\Carbon;

/**
 * One JSON shape for a proposal, shared by the chat card and the Approvals page.
 */
final class ProposalPresenter
{
    /** How application systems read on a card. */
    private const SYSTEMS = [
        'common_app' => 'Common App',
        'coalition' => 'Coalition',
        'ucas' => 'UCAS',
        'institution_portal' => "The college's own portal",
        'other' => 'Other',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function present(Proposal $proposal): array
    {
        $decision = $proposal->relationLoaded('decision') ? $proposal->decision : $proposal->decision()->first();

        return [
            'id' => (string) $proposal->id,
            'type' => $proposal->type,
            'status' => $proposal->status->value,
            'title' => $proposal->title,
            'revision' => (int) $proposal->revision,
            'payloadHash' => $proposal->payload_hash,
            'createdAt' => $proposal->created_at?->toIso8601String(),
            'expiresAt' => $proposal->expires_at->toIso8601String(),
            'items' => array_map(fn (array $item) => [
                'key' => (string) $item['key'],
                'label' => (string) $item['label'],
                'before' => self::side($proposal, $item, 'before'),
                'after' => self::side($proposal, $item, 'after'),
                'editValue' => self::editValue($proposal, $item),
                'reason' => $item['reason'] ?? null,
                'evidence' => array_values((array) ($item['evidence'] ?? [])),
                'editedByStudent' => (bool) ($item['edited_by_student'] ?? false),
            ], $proposal->items),
            'taskId' => $proposal->agent_task_id,
            'decision' => $decision === null ? null : [
                'decision' => $decision->decision,
                'selectedKeys' => $decision->selected_keys,
                'receipt' => $decision->receipt,
                'decidedAt' => $decision->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * One side of an item as the student reads it. Field changes to colleges and applications store raw
     * values (dates as YYYY-MM-DD, status codes) and are shown in words here; empty is shown as "—".
     *
     * @param  array<string, mixed>  $item
     */
    private static function side(Proposal $proposal, array $item, string $side): string
    {
        $value = $item[$side] ?? null;
        $field = $item['field'] ?? null;

        if ($field === 'rolling_admission' && is_bool($value)) {
            return $value ? 'Rolling admission' : 'Fixed deadlines';
        }

        if (! in_array($proposal->type, [Proposal::TYPE_COLLEGE_LIST_UPDATE, Proposal::TYPE_APPLICATION_UPDATE], true) || ! is_string($field) || $value === null || $value === '') {
            return self::display($value);
        }

        $text = is_scalar($value) ? (string) $value : (json_encode($value) ?: '');

        $shown = match (true) {
            str_ends_with($field, '_deadline') => self::date($text),
            $field === 'tier' => ucfirst($text),
            $field === 'application_status' => ucfirst(str_replace('_', ' ', $text)),
            $field === 'status' => ApplicationStatus::tryFrom($text)?->label() ?? $text,
            $field === 'checklist_status' => ChecklistStatus::tryFrom($text)?->label() ?? $text,
            $field === 'application_system' => self::SYSTEMS[$text] ?? $text,
            default => $text,
        };

        return $side === 'after' ? $shown.self::afterDetail($item) : $shown;
    }

    /**
     * What else the change records with the new value: the submission evidence, or a step's note and how
     * the student knows it is complete.
     *
     * @param  array<string, mixed>  $item
     */
    private static function afterDetail(array $item): string
    {
        $note = is_string($item['note'] ?? null) && $item['note'] !== '' ? $item['note'] : null;

        if (($item['field'] ?? null) === 'status' && is_string($item['submission_evidence_type'] ?? null)) {
            $evidence = SubmissionEvidence::tryFrom($item['submission_evidence_type'])?->label() ?? $item['submission_evidence_type'];
            $said = is_string($item['submission_evidence_note'] ?? null) && $item['submission_evidence_note'] !== '' ? ': “'.$item['submission_evidence_note'].'”' : '';

            return " — evidence: {$evidence}{$said}";
        }

        if (($item['field'] ?? null) === 'checklist_status') {
            $basis = is_string($item['completion_basis'] ?? null) ? CompletionBasis::tryFrom($item['completion_basis'])?->label() : null;

            return ($basis !== null ? " — {$basis}" : '').($note !== null ? " — “{$note}”" : '');
        }

        return '';
    }

    private static function date(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $value;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value)->format('M j, Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private static function display(mixed $value): string
    {
        return match (true) {
            $value === null || $value === [] || $value === '' => '—',
            is_array($value) && isset($value['school_name']) => $value['school_name'].' ('.($value['tier'] ?? '?').')',
            is_array($value) => implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : (json_encode($item) ?: ''), $value)),
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }

    /**
     * The value shown in an edit box, or null when the item cannot be edited before approval.
     *
     * @param  array<string, mixed>  $item
     */
    private static function editValue(Proposal $proposal, array $item): ?string
    {
        if ($proposal->type !== Proposal::TYPE_PROFILE_UPDATE || ! StudentFieldRegistry::has((string) $item['key'])) {
            return null;
        }

        $after = $item['after'] ?? null;

        return match (StudentFieldRegistry::get((string) $item['key'])['type']) {
            'json' => null,
            'list' => is_array($after) ? implode(', ', $after) : '',
            default => $after === null ? '' : (string) $after,
        };
    }
}
