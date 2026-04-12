<?php

namespace App\Services\CollegeListCouncil;

use App\Models\CollegeListRun;
use App\Models\CollegeListRunEvent;
use App\Models\User;
use App\Services\CollegeList\CollegeListAssemblyService;

/**
 * Deterministic pipeline for automated tests (no external LLM).
 */
class FakeCollegeListCouncilOrchestrator implements CollegeListCouncilOrchestratorContract
{
    public function __construct(
        protected CollegeListRunRecorder $recorder,
        protected CollegeListAssemblyService $assembly,
    ) {}

    public function execute(CollegeListRun $run, User $user): void
    {
        $this->recorder->transitionStatus($run, CollegeListRun::STATUS_PREFLIGHT);

        $preflight = [
            'strategic_constraints' => ['Test constraint: verify council job wiring.'],
            'inconsistencies' => [],
            'non_negotiables' => [],
            'auditor_insights' => ['Auditor: profile looks consistent for a smoke test.'],
            'info_gaps' => [],
        ];
        $run->forceFill(['preflight_payload' => $preflight])->save();

        $this->recorder->appendEvent(
            $run,
            'preflight',
            CollegeListRunEvent::SEVERITY_INFO,
            'Contextual Auditor',
            'Strategic constraints recorded for the council.',
        );

        $this->recorder->transitionStatus($run, CollegeListRun::STATUS_SCOUTING);

        $scout = [
            'candidates' => [
                [
                    'school_name' => 'Test Safety University',
                    'school_slug' => 'test-safety-university',
                    'scout_note' => 'Hypothesis: strong curriculum fit for testing.',
                    'bucket_guess' => 'safety',
                ],
                [
                    'school_name' => 'Test Match College',
                    'school_slug' => 'test-match-college',
                    'scout_note' => 'Hypothesis: balanced profile alignment.',
                    'bucket_guess' => 'match',
                ],
            ],
        ];
        $run->forceFill(['scout_payload' => $scout])->save();

        $this->recorder->appendEvent(
            $run,
            'scout',
            CollegeListRunEvent::SEVERITY_MATCH,
            'Scout: Test Safety University',
            'Candidate identified for assembly smoke test.',
            'test-safety-university',
        );

        $this->recorder->transitionStatus($run, CollegeListRun::STATUS_JUDGING);

        $verdicts = [
            [
                'school_name' => 'Test Safety University',
                'school_slug' => 'test-safety-university',
                'verdict' => 'advance',
                'tier' => 'safety',
                'fit_score' => 88,
                'why_blurb' => 'Solid academic alignment for the test scenario.',
                'honest_blurb' => 'Low risk for the synthetic profile used in CI.',
                'acceptance_rate' => null,
                'median_gpa' => null,
                'median_sat' => null,
                'median_act' => null,
                'ea_deadline' => null,
                'ed_deadline' => null,
                'rd_deadline' => null,
                'aid_estimate_band' => 'moderate',
                'notable_programs' => ['Computer Science'],
                'campus_size' => 'medium',
                'location_city' => 'Testville',
                'location_state' => 'TS',
            ],
            [
                'school_name' => 'Test Match College',
                'school_slug' => 'test-match-college',
                'verdict' => 'debate',
                'tier' => 'match',
                'fit_score' => 72,
                'why_blurb' => 'Interesting option but watch aid assumptions.',
                'honest_blurb' => 'Financial fit unclear without real NPC data.',
                'acceptance_rate' => null,
                'median_gpa' => null,
                'median_sat' => null,
                'median_act' => null,
                'ea_deadline' => null,
                'ed_deadline' => null,
                'rd_deadline' => null,
                'aid_estimate_band' => 'limited',
                'notable_programs' => [],
                'campus_size' => 'large',
                'location_city' => null,
                'location_state' => null,
            ],
        ];

        $run->forceFill(['judge_payload' => ['verdicts' => $verdicts]])->save();

        $this->recorder->appendEvent(
            $run,
            'judge',
            CollegeListRunEvent::SEVERITY_DEBATE,
            'Judge debating: Test Match College',
            'Financial fit unclear without real NPC data.',
            'test-match-college',
        );

        $this->recorder->transitionStatus($run, CollegeListRun::STATUS_ASSEMBLING);

        $schools = [
            [
                'school_name' => 'Test Safety University',
                'school_slug' => 'test-safety-university',
                'tier' => 'safety',
                'fit_score' => 88,
                'why_blurb' => 'Solid academic alignment for the test scenario.'."\n\n".'Low risk for the synthetic profile used in CI.',
                'acceptance_rate' => null,
                'median_gpa' => null,
                'median_sat' => null,
                'median_act' => null,
                'ea_deadline' => null,
                'ed_deadline' => null,
                'rd_deadline' => null,
                'application_portal_url' => 'https://example.edu/apply',
                'admissions_cycle_label' => '2026-27',
                'aid_estimate_band' => 'moderate',
                'notable_programs' => ['Computer Science'],
                'campus_size' => 'medium',
                'location_city' => 'Testville',
                'location_state' => 'TS',
            ],
        ];

        $intent = $run->intent;
        $action = in_array($intent, ['generate'], true) ? 'generate' : 'refine';

        $this->assembly->persistFromAgentPayload(
            $user,
            $intent,
            [
                'action' => $action,
                'schools' => $schools,
                'removed_slugs' => [],
                'chat_response' => '',
            ],
            (string) $run->id,
        );

        $this->recorder->complete($run, 'Fake council finished: saved test schools for CI.');
    }
}
