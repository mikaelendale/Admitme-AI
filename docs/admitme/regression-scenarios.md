# Regression scenarios

The plan's minimum end-to-end scenarios (section 8), and where each is checked. Tests live in `tests/Feature/AdmitMe/`; the browser smokes are the real-browser runs recorded in [`build-status.md`](build-status.md).

| # | Scenario | Required behaviour | Automated test | Real-browser smoke |
| --- | --- | --- | --- | --- |
| 1 | Ask-mode request says to change GPA | No canonical write; explain or suggest Agent mode | `AskModeMentorTest::test_an_ask_mode_request_to_change_the_gpa_writes_and_proposes_nothing`, `…ask_mode_receives_only_read_only_tools` | Phases 1–3 (Ask mode refuses a list change) |
| 2 | Two students use the same document title | Retrieval, caches, exports and conversations stay isolated | `KnowledgeResearchTest::test_private_document_results_stay_with_their_owner` (both titled "Transcript"), `DataLifecycleTest::test_exports_never_include_another_students_items`, `StudentDataIsolationTest` (4 tests) | Phase 9 (second student gets 404) |
| 3 | Transcript contains an ambiguous grade | Stays an unresolved candidate, never a verified grade | `DocumentReviewTest::test_an_ambiguous_grade_stays_an_unresolved_candidate_and_is_never_a_fact` | Phase 4 |
| 4 | New transcript conflicts with confirmed facts | Both kept; the student resolves | `DocumentReviewTest::test_replacing_a_transcript_keeps_history_and_flags_conflicts_without_overwriting` | Phase 4 |
| 5 | Student changes the budget during research | Affected work is re-evaluated before use | `DelegationAndWritingTest::test_a_changed_budget_reevaluates_a_pending_shortlist_and_replaces_its_proposal`, `TaskOrchestrationTest::test_a_budget_changed_mid_plan_is_used_before_proposing` | Phase 8 (old proposal → stale, new one proposed) |
| 6 | Student approves six of ten colleges | Exactly six distinct entries saved | `ApprovalProposalTest::test_approving_six_of_ten_proposed_colleges_adds_exactly_six` | Phases 1–3 |
| 7 | Approval clicked twice or retried | One internal action, one receipt | `ApprovalProposalTest::test_approving_a_profile_edit_changes_exactly_the_reviewed_fields_once`, `TaskOrchestrationTest::test_repeating_the_request_or_reopening_the_panel_never_duplicates_work`, `BrowserBridgeTest::test_a_fill_runs_only_after_approval_is_read_back_and_never_repeats` | Phases 3, 7, 10 |
| 8 | Model suggests writing directly to the database | Unauthorized tool or path is unavailable and rejected | `AskModeMentorTest::test_an_ask_mode_attempt_to_change_the_list_is_rejected_server_side`, `ApprovalProposalTest::test_agent_mode_proposes_instead_of_writing_and_the_card_reappears_after_reload`, `TaskOrchestrationTest::test_plans_are_validated_before_anything_is_created` | Phases 1–3 |
| 9 | A retrieved page tells the agent to ignore instructions | Treated as untrusted page content | `EvaluationSetTest::test_research_cases_pass_only_when_expected_facts_are_quoted_and_injected_values_are_not_kept`; live: `php artisan admitme:eval research` case `instructions-inside-the-page` | — |
| 10 | Queue worker crashes and chat reloads | State resumes without duplicate effects | `TaskOrchestrationTest::test_a_stopped_worker_is_recovered_once_and_repeated_stops_fail_the_step`, `…repeating_the_request_or_reopening_the_panel_never_duplicates_work` | Phase 7 (real worker) |
| 11 | Source deadlines conflict or lack a cycle | Uncertainty shown; readiness never falsely declared | `KnowledgeResearchTest::test_missing_conflicting_and_stale_requirements_are_shown_as_unresolved`, `ApplicationWorkspaceTest::test_next_actions_put_disagreeing_sources_and_third_party_requests_first` | Phases 5, 6 |
| 12 | Specialist hits its budget or the provider fails | Visible partial result or blocker; no invented completion | `TaskOrchestrationTest::test_provider_failures_retry_with_backoff_then_fail_and_budgets_are_enforced`, `…a_step_blocks_instead_of_guessing_and_continues_after_retry`, `DelegationAndWritingTest::test_the_global_limit_and_the_provider_rate_limit_hold_work_back_without_failing_it` | Phase 7 |
| 13 | Browser navigates after an approval | Stale commands rejected; a new observation is needed | `BrowserBridgeTest::test_wrong_tabs_and_changed_pages_are_refused` | Phase 10 (field added → step stale; other origin → 409) |
| 14 | Browser action succeeds but the acknowledgement is lost | Page state reconciled before any retry | `BrowserBridgeTest::test_a_lost_acknowledgement_is_reconciled_and_a_mismatch_is_frozen` | Phase 10 (acks dropped, panel reloaded, typed once) |
| 15 | Student cancels a run | Future work stops; completed actions reported | `TaskOrchestrationTest::test_cancelling_stops_future_work_without_undoing_anything`, `…a_pending_proposal_survives_cancelling_and_the_students_decision_still_counts` | Phase 7 |
| 16 | An export runs while documents change | Archive uses its recorded snapshot and immutable versions | `DataLifecycleTest::test_an_export_matches_the_snapshot_taken_when_it_was_requested` | Phase 9 |

Integrity checks that guard against false success on real data (all should be 0) run with `php artisan admitme:metrics`, which exits non-zero when one fails (`OperationsTest::test_metrics_report_measures_and_fail_on_an_impossible_success_state`).

## Turning failures into cases

When something goes wrong for a student or in an evaluation:

1. Reproduce it with synthetic data. Never copy real student content into fixtures.
2. Add it where it belongs:
   - a deterministic behaviour → a feature test here;
   - a model-quality problem → a case in `resources/evals/research.json` or `writing.json`.
3. Before changing a prompt, tool or model, run `php artisan admitme:eval all --save`, make the change, then run `php artisan admitme:eval all --compare=evals/<saved file>`. Release only if no case regresses.
4. Student feedback and corrections are reviewed by a person. They are never used as training data or written into anyone's profile automatically.
