<?php

namespace App\Domain\Students;

use App\Domain\Audit\AuditLogger;
use App\Domain\Shared\ChangeSource;
use App\Models\OnboardingProfile;

/**
 * Keeps profile writes that bypass StudentProfileService honest: they still bump the revision
 * (so a stale manual edit is detected) and are recorded with their route as provenance.
 */
final class ProfileProvenanceObserver
{
    /**
     * Routes whose profile writes are produced by an AI extractor rather than typed by the student.
     *
     * @var list<string>
     */
    public const AI_EXTRACTION_ROUTES = [
        'profile-completion.dump',
        'profile-completion.resume',
        'profile-completion.refresh-insights',
    ];

    public function __construct(
        private readonly StudentFactRecorder $facts,
        private readonly AuditLogger $audit,
    ) {}

    public function saving(OnboardingProfile $profile): void
    {
        if ($profile->provenanceHandled) {
            return;
        }

        $changes = [];

        foreach (StudentFieldRegistry::fields() as $key => $field) {
            $value = $profile->getAttribute($field['column']);

            // A brand-new profile's empty fields carry no information worth recording...
            if (! $profile->exists && ($value === null || $value === '' || $value === [])) {
                continue;
            }

            if ($profile->isDirty($field['column'])) {
                $changes[$key] = [
                    $profile->exists ? $profile->getOriginal($field['column']) : null,
                    $profile->getAttribute($field['column']),
                ];
            }
        }

        if ($changes === []) {
            return;
        }

        $revisionBefore = $profile->exists ? (int) $profile->getOriginal('revision') : null;

        if ($profile->exists) {
            $profile->revision = $revisionBefore + 1;
        }

        $profile->pendingProvenance = ['changes' => $changes, 'revision_before' => $revisionBefore];
    }

    public function saved(OnboardingProfile $profile): void
    {
        $pending = $profile->pendingProvenance;

        $profile->pendingProvenance = null;
        $profile->provenanceHandled = false;

        if ($pending === null) {
            return;
        }

        $source = $this->legacySource($profile);
        $revision = (int) ($profile->revision ?? 1);

        $this->facts->record((int) $profile->user_id, $pending['changes'], $source, $revision);

        $this->audit->record(
            (int) $profile->user_id,
            $source,
            $profile->wasRecentlyCreated ? 'profile.created' : 'profile.updated',
            $profile,
            $pending['revision_before'],
            $revision,
            $pending['changes'],
        );
    }

    private function legacySource(OnboardingProfile $profile): ChangeSource
    {
        $route = request()->route()?->getName();
        $byStudent = $route !== null && (int) auth()->id() === (int) $profile->user_id;

        return new ChangeSource(
            $byStudent ? ChangeSource::ACTOR_STUDENT : ChangeSource::ACTOR_SYSTEM,
            $byStudent ? (string) auth()->id() : null,
            in_array($route, self::AI_EXTRACTION_ROUTES, true) ? ChangeSource::SOURCE_AI_EXTRACTION : ChangeSource::SOURCE_LEGACY_FLOW,
            array_filter(['route' => $route]),
        );
    }
}
