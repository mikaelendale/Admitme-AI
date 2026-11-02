<?php

namespace App\Domain\Students;

/**
 * How much a recorded value can be trusted. Whether a fact is still current is tracked separately
 * by `superseded_at`, so a superseded fact keeps the status it had when it was recorded.
 */
enum FactStatus: string
{
    /** The student entered or approved this value. */
    case Confirmed = 'confirmed';

    /** Extracted from a document or text and awaiting the student's review (Phase 4). */
    case Candidate = 'candidate';

    /** Written by an automated path (AI extraction, legacy flow, system) without student confirmation. */
    case Inferred = 'inferred';

    /** Disagrees with another source and needs the student to resolve it. */
    case Conflict = 'conflict';
}
