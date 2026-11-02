# AdmitMe build status

Tracks progress against [`phased-build-plan.md`](phased-build-plan.md). Architecture choices live in [`decisions.md`](decisions.md).

| Phase | Status | Gate |
| --- | --- | --- |
| 0 — Audit and runnable baseline | ✅ Complete (2026-10-31) | Passed — see evidence below |
| 1 — Domain records, permissions, workspace shell | ✅ Complete (2026-10-31) | Passed — see evidence below |
| 2 — Memory services and Ask-mode Mentor | ✅ Complete (2026-10-31) | Passed — see evidence below |
| 3 — Approval proposals and safe shared mutations | ✅ Complete (2026-10-31) | Passed — see evidence below |
| 4 — Documents and progressive onboarding | ✅ Complete (2026-10-31) | Passed — see evidence below |
| 5 — Knowledge base and source-backed research | ✅ Complete (2026-10-31) | Passed — see evidence below |
| 6 — College discovery and application workspace | ✅ Complete (2026-11-01) | Passed — see evidence below |
| 7 — Agent mode and durable task orchestration | ✅ Complete (2026-11-01) | Passed — see evidence below |
| 8 — Specialist delegation, concurrency, writing support | ✅ Complete (2026-11-01) | Passed — see evidence below |
| 9 — Exports and data lifecycle | ✅ Complete (2026-11-01) | Passed — see evidence below |
| 10 — Browser bridge and local extension | ✅ Complete (2026-11-01) | Passed — see evidence below |
| 11 — Evaluate, harden, founder's journey | ✅ Complete (2026-11-01) — real founder walk-through pending | Passed on synthetic data — see evidence below |
| Post-plan — Workspace redesign, plan-order onboarding, mentor panel | ✅ Complete (2026-11-01) | Verified in the browser and by feature tests — see the last section |

---

## Phase 0 — Audit the existing app and create a runnable baseline

### Working tree baseline

- Branch `chore/update-dependencies`, **uncommitted**. It already contains the dependency upgrade done on 2026-10-31 (laravel/ai 0.4.2 → 1.3.0 with conversation-table migrations, Laravel 13.2 → 13.39, Vite React plugin 6, TypeScript 6.0, lucide 1.x, framer-motion 14). AdmitMe phase work builds on that upgrade because the plan's SDK assumptions only hold for 1.x.
- Local database was backed up to `database/database.sqlite.pre-admitme-phase0.bak` (gitignored) before migrating.

### Stack (installed, verified from lockfiles)

| Component | Version |
| --- | --- |
| PHP (local) | 8.5.1 — CI matrix tests 8.3, 8.4, 8.5 |
| Laravel framework | 13.39.0 |
| `laravel/ai` | **1.3.0** (uses `prism-php/prism` internally) |
| Inertia | `inertiajs/inertia-laravel` 3.6, `@inertiajs/react` 3.10 |
| React / TypeScript / Vite | 19.3 / 6.0.3 / 8.3 (React Compiler via `@rolldown/plugin-babel`) |
| Auth | Fortify (email + password, email verification, 2FA), Socialite (Google) |
| Billing | Cashier 16.9 with `stripe/stripe-php` pinned `^17.4` (API `basil`) |
| Realtime | Reverb (`BROADCAST_CONNECTION=reverb`), laravel-echo |
| Tests | Pest 4 / PHPUnit 12, SQLite in-memory |

### Repository instructions

- No root `CLAUDE.md`, `AGENTS.md`, or Copilot instructions exist.
- `.cursor/plans/` holds two earlier feature plans (five-step onboarding, Gap+Fix MVP).
- Root docs: `mvp.md` (original revenue-led MVP), `todo.md`, `QUEUE.md`, `limit-features.md`, `laravel-stripe.md`, and `ai-sdk.md`. **`ai-sdk.md` documents the 0.x SDK and is stale for 1.3.0** — verify APIs against `vendor/laravel/ai` instead.
- Conventions observed: thin controllers + `app/Services/*` + `app/Support/*`; Form Requests; Inertia pages in `resources/js/pages` with layout chosen in `resources/js/app.tsx`; Wayfinder-generated route helpers; Phosphor icons in the workspace nav; shadcn/Radix UI in `resources/js/components/ui`.

### Infrastructure (local)

| Concern | Setting | Notes |
| --- | --- | --- |
| Database | SQLite `database/database.sqlite` | Production appears to be Postgres (a migration is guarded on `pgsql`); not verified from this machine. |
| Queue | `database` | `jobs` / `failed_jobs` tables present. `composer dev` runs `queue:listen`. |
| Cache / session | `database` / `database` | Redis is configured in `.env` but not the default. |
| Files | `local` disk → `storage/app/private`, `serve: true` (signed URLs) | Private by default. No S3 credentials configured. |
| Broadcasting | Reverb | Used for college-list council progress. |

Credentials present in `.env` (names only): `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, Stripe, Google OAuth, Reverb. AWS keys are empty. No secrets are committed or logged by these checks.

### AI providers and models

- Default provider `openai`. All 15 agents hard-code `#[Provider(Lab::OpenAI)]` + `#[Model('gpt-5-nano')]`; **models are not configurable** today.
- `ANTHROPIC_API_KEY` is set but no agent uses Anthropic.
- Provider-native web search is used through `Laravel\Ai\Providers\Tools\WebSearch` (OpenAI). `AllowedDomainHttpFetchTool` provides allow-listed fetching.
- Prompt caching: the installed SDK emits explicit cache breakpoints only for Anthropic and Bedrock; OpenAI caching is automatic provider-side. No context-compaction feature exists in 1.3.0.

### Installed SDK verification (against `vendor/laravel/ai` 1.3.0)

| Plan assumption | Installed behaviour | Consequence |
| --- | --- | --- |
| Child agents don't inherit parent history | ✅ `Tools\AgentTool` describes and runs the sub-agent "in isolation … no access to the parent conversation history". | Delegation must pass an explicit, server-built brief. |
| Approval-required tools need top-level handling | ✅ Tools implement `Contracts\Approvable` (`requireApproval()`); runs pause and resume with `Approvals\Decisions`. `AgentTool` itself is `Approvable`, so a whole delegation can be gated, but approvals raised *inside* a child are not surfaced to the parent. | Keep approval-requiring actions on the top-level agent or in our own durable proposal workflow. |
| Conversation continuation doesn't check ownership | ✅ `RemembersConversations::continue()` only stores the id. `DatabaseConversationStore` implements `VerifiesConversationOwnership::conversationBelongsTo()` but nothing calls it. | The app must authorize every conversation id (already done via `App\Support\ConversationOwnership`). |
| Conversation storage is not long-term memory | ✅ Store keeps turns as `steps` (one entry per model round trip; tool results stored on their call); loads the latest 100 rows by default. | Student memory, summaries and task state are application code. Trimming by whole stored rows keeps tool calls with their results. |
| Streaming / queueing / structured output / testing | ✅ `stream()` with `VercelDataProtocol`, `queue()`, `HasStructuredOutput`, `Agent::fake()`. Agent middleware (`HasMiddleware`) wraps each generation step. | Existing chat already streams via a `VercelDataProtocol` subclass. |

### Existing features mapped to the plan

| Plan area | Already in the app | Gap |
| --- | --- | --- |
| Student profile | `onboarding_profiles` (typed academics, test scores, residency, majors, countries, budget band, aid %, activities/awards JSON, AI-built `ai_context`); onboarding wizard; profile-completion flows (paste/resume PDF extraction, clarify, goals, review) | No revisions, no provenance per value, no audit trail; GPA normalised to a 4.0 scale without recording the method (conflicts with the plan's "no universal GPA equivalence"). |
| College choices | `college_list_schools` (tier, fit, deadlines, aid band, portal URL, cycle label, `application_status`, notes, soft-remove) | No suggested/shortlisted/applying states, no revisions; AI council and chat tools write straight into the saved list. |
| Applications | Only `college_list_schools.application_status` | No application record, requirements, or submission evidence. |
| Tasks | `job_tracking`, `college_list_runs` (+ events), `essay_runs` | No dependencies, budgets, or orchestrator. |
| Writing | Essay suite (brainstorm, drafts, roast, strength score, Why-this-school, supplements) | AI drafting exists; plan requires authorship-preserving, institution-aware assistance (Phase 8). |
| Documents | Resume PDF parsed for profile extraction | Originals not stored; no versions/extraction records. |
| Chat | Streaming mentor (`AdmitMeGeneralAgent`) with conversation history and job cards | No Ask/Agent modes; tools write directly (list upserts, starting jobs); no approvals. |
| Exports / data control | `settings/privacy` export and delete actions | Not snapshot-consistent; Phase 9. |
| Browser bridge | None | Phase 10. |

### Baseline checks (2026-10-31)

| Check | Result |
| --- | --- |
| `php artisan test` | ✅ 175 passed (740 assertions) |
| `vendor/bin/pint --test` | ✅ passed |
| `npm run types:check` | ✅ passed |
| `npm run build` | ✅ built |
| `npm run lint:check` | ❌ pre-existing: 560 problems (556 on `master`; +15 from stricter `eslint-plugin-react-hooks` 7.1 rules) |
| `npm run format:check` | ❌ pre-existing: 42 files |
| `composer update` | ⚠️ `post-update-cmd` calls `boost:update` but Laravel Boost is not installed; use `--no-scripts` + `composer dump-autoload`. |

### Phase 0 gate evidence

- **App runs locally** — served `public/index.php`: `/up` 200, `/login` 200 (Inertia component `auth/login`), `/register` 200, `/dashboard` 302 → `/login` for guests.
- **Migrations** — local SQLite migrated, including the pending Postgres-guarded migration (no-op on SQLite) and the two SDK 1.x conversation migrations. All 44 local messages have valid `steps`; 15 kept their tool calls; all 10 local conversations replay through the 1.x store without errors.
- **Queued job completes** — `Artisan::queue('inspire')` on the `database` connection was claimed and finished `DONE` by `queue:work --once`; `jobs` and `failed_jobs` were empty afterwards. (A first probe using a closure defined in tinker failed because eval'd closures cannot be rehydrated by a separate worker; that probe's failed-job row was removed.)
- **SDK approach matches installed interfaces** — table above.

### Known issues carried forward (not introduced by this work)

- Stripe API `basil` moved `current_period_end` to subscription items and `invoice.subscription` to `invoice.parent.subscription_details`; `BillingController` and `NotifyUserOfInvoicePaymentFailure` still read the old fields.
- Frontend lint/format debt listed above.
- Payments are out of scope for the plan's first journey but remain in the app; they are left untouched.

---

## Phase 1 — Domain records, permissions, and workspace shell

### Implemented behaviour

- **Versioned profile with provenance.** `onboarding_profiles.revision` plus `student_facts` (value, status `confirmed | candidate | inferred | conflict`, source, evidence reference, revision, `superseded_at`) and `audit_events`. `App\Domain\Students\StudentProfileService` is the write path for manual edits: it validates against `StudentFieldRegistry`, enforces the expected revision, recomputes derived values (normalised GPA, completeness, modes), and records facts + an audit event in one transaction. Derived/unknown fields are rejected.
- **Older writers are not silent.** `ProfileProvenanceObserver` bumps the revision and records provenance for any save that bypasses the service: onboarding writes are `legacy_flow` (confirmed when the student made them); paste/resume/insights writes are `ai_extraction` and `inferred` (not student-confirmed).
- **College choices.** `choice_status` (`suggested → shortlisted → applying → archived`, enforced transitions) and `revision` on `college_list_schools`; `archived` mirrors the existing `is_removed`. `App\Domain\Applications\CollegeListService` handles manual add (duplicates rejected, archived ones restored), updates, archive, and the chat tool's upsert — so chat and manual edits share validation, revisions and audit.
- **Applications.** New `applications` table and `ApplicationService`. Moving a choice to `applying` opens exactly one application. Status transitions are enforced; `submitted` requires `portal_receipt` or `student_confirmation` evidence and records `submitted_at`.
- **Authorization.** `CollegeListSchoolPolicy` and `ApplicationPolicy` (404 for non-owners) and an `access-conversation` gate (403, as before) used by the chat page and chat endpoint.
- **Workspace.** New Applications page (start from shortlisted colleges, edit status/system/portal/cycle/notes/evidence with revision checks) and Tasks page (genuine `job_tracking` records). College detail page shows list status, "Start application", and a reload prompt on 409. Profile forms send `expected_revision`; conflicts surface through the existing error toasts. Nav gained Applications and Tasks.
- **Fixtures.** Factories for profiles, college choices and applications; `AdmitMeSyntheticStudentSeeder` (synthetic international A-level student) — `php artisan db:seed --class=AdmitMeSyntheticStudentSeeder`. All data is invented.

### Gate evidence

| Gate | Evidence |
| --- | --- |
| Save and reopen profile manually | `ProfileManualEditTest::test_student_saves_profile_fields_and_sees_them_after_reopening` |
| Save and reopen list manually | `CollegeListManualTest::test_student_adds_a_college_and_sees_it_after_reopening_the_list`, `…notes_save_with_the_current_revision…` |
| Two users cannot access each other's data | `StudentDataIsolationTest` — college choices, applications, conversations (open + continue; agent never prompted), council runs, supplements, tasks |
| Stale conflicting edits detected | Profile (incl. a write from an older flow), college choice (409 + unchanged), application (session error + unchanged) |
| Also covered | One application per choice under repeated requests; submitted needs evidence; invalid transitions rejected; chat tool writes attributed to `agent`; derived fields not writable |

Checks: `php artisan test` → **196 passed**; `pint --test` passed; `npm run types:check` passed; `npm run build` passed; new pages lint-clean. Seeder verified on a scratch SQLite database (idempotent). Local dev database migrated.

### Limitations

- Agent writes are still direct (D-007) until Phase 3.
- The college-list council still writes AI results straight into the saved list as `shortlisted`; Phase 3/6 change this to suggestions.
- Documents nav is intentionally absent until Phase 4 (D-005).
- UI was verified by type-check, build and Inertia prop tests, not by clicking through in a browser.

---

## Phase 2 — Memory services and a useful Ask-mode Mentor

### Implemented behaviour

- **Trusted runtime instructions** in `resources/ai` (`AGENTS.md`, `agents/mentor.md`, `agents/summarizer.md`, `modes/ask.md`, `modes/agent.md`), loaded only through `App\Ai\Context\InstructionLoader`'s allow-list; its fingerprint is recorded per run.
- **MemoryReader** (`App\Domain\Memory`) — student-scoped projections of each profile section with value, provenance ("confirmed by the student", "extracted by AI … not yet confirmed", "source not recorded"), confirmation flag and missing fields; college list (marked unverified); applications. Profile projections are cached under student + section + profile revision + schema version, so edits are visible immediately.
- **Read tools** — `ReadStudentProfileTool`, `ReadCollegeListTool`, `ReadApplicationTool`; existing read tools are marked `ReadOnlyTool`. The student always comes from the authenticated run.
- **Ask/Agent enforcement** — `ChatMode` + `MentorToolPolicy`: Ask runs receive only read-only tools (asserted), Agent runs keep the existing tools. The chat request takes `mode` (default Ask) and the chat UI has an Ask/Agent toggle that applies to the next message.
- **ContextBuilder** — shared rules + one role + mode rules + student brief (≈1,800-token budget) + derived AI notes (flagged when facts changed afterwards) + attributed summary (flagged when written at an older profile revision). Mentor provider/model are configurable (`config/admitme.php`, defaults unchanged: OpenAI `gpt-5-nano`).
- **History budget** — `messages()` replays only the newest whole stored turns within `history_tokens` (min two), so tool calls stay with their results.
- **Summaries** — `SummarizeConversationJob` (unique per conversation) runs when enough turns fall out of the window; summary points cite real message ids (invented ids dropped) and record the profile revision.
- **Instrumentation** — `ai_context_snapshots`: mode, profile revision, instruction version, tools, estimated tokens per part, history rows used/total, summary used, provider usage, outcome (`completed`/`failed`).

### Gate evidence

| Gate | Evidence |
| --- | --- |
| Mentor can accurately describe saved facts | `MentorMemoryContextTest`: the brief states `SAT score: 1510 (confirmed by the student)` and `Curriculum: IB Diploma (extracted by AI…)`; the read tool returns the same values, flags and revision |
| Identifies missing information | Brief's "Not on file" list and the tool's `missing` array name graduation year and budget band |
| Continuity after reload | `AskModeMentorTest::test_conversation_continues_with_earlier_turns_after_a_reload` — the second request carries only the conversation id and replays the earlier turn; the reloaded chat page shows all four messages |
| Ask-mode mutation rejected server-side | Ask runs only receive read-only tools; a model call to `UpsertStudentCollegeList` in Ask mode writes nothing, reports the failure instead of success, and records a `failed` run. The same call works only in Agent mode. Requests without a mode run as Ask |
| Corrected facts supersede older summaries | After a correction, the context states the new fact and flags the summary as written at the older revision, with the brief declared correct |
| Active tool histories remain valid | Trimmed history starts on a turn boundary and every replayed tool result follows its call |
| Also covered | Summary references only real message ids; summary queued when turns fall out of history; read tools never return another student's application; cache follows revision; per-run snapshot contents |

Checks: `php artisan test` → **210 passed**; `pint --test` passed; `npm run types:check` and `npm run build` passed; changed frontend files have no new lint problems (chat component 112 = 112 on `master`).

### Limitations

- Tests use `Agent::fake()`. Whether the live model *phrases* answers well is not proven by these tests; a small live evaluation set belongs to Phase 11.
- Token figures are estimates (characters/4), not provider counts; provider usage is recorded after each run.
- The chat UI toggle and new pages were type-checked and built, not clicked through in a browser.
- Agent mode still writes the college list directly (D-007) — Phase 3 replaces this with proposals.

---

## Phase 3 — Approval proposals and safe shared mutations

### Implemented behaviour

- **Proposals** (`proposals`, `proposal_decisions`; `App\Domain\Approvals`). Each proposal records actor, type (`profile.update`, `college_list.add`), exact items (`key`, `label`, `before`, `after`, `reason`), target revision, proposal revision, payload hash, expiry (7 days, configurable) and status (`pending → applied | rejected | expired | stale | failed`). Creation and every decision are audited.
- **Decisions** authorize the reviewed revision + hash only; support item selection and (profile) value edits that create a new proposal revision; are recorded once per proposal (unique constraint + idempotency key — double clicks and retries return the same receipt); and apply transactionally through the Phase 1 services. Approved profile values are recorded as `agent_proposal` facts, confirmed by the student, with the proposal id as evidence.
- **Stale handling.** If any selected profile value changed since it was proposed, nothing is applied and the proposal becomes `stale`; unrelated manual edits do not block it. College additions never duplicate (`already_present` in the receipt).
- **Agent mode** proposes instead of writing: `ProposeProfileChangesTool`, `ProposeCollegeListAdditionsTool`; their results say nothing has changed. The mentor's context lists pending proposals and recent outcomes so the next turn resumes accurately.
- **AI list research** now creates suggestions and can no longer archive the student's saved or applying colleges (D-012); suggestions get an "Add to my shortlist" action.
- **UI.** `ProposalCard` (diff, reasons, per-item selection, edit boxes, Approve / Approve N selected / Reject, receipts) used inline in chat — live from the stream and again after reload via stored tool results — and on the new **Approvals** page (pending + recently decided). Nav shows a pending-approval badge.

### Gate evidence (`ApprovalProposalTest`, `ResearchSuggestionsTest`)

| Gate | Evidence |
| --- | --- |
| Approving a profile edit changes exactly the reviewed fields once | Approve SAT only from a SAT+ACT proposal → SAT changes, ACT and GPA untouched, revision +1; same-key replay and a different-key retry create no second change, decision or audit event |
| Rejection changes nothing | Values and revision unchanged |
| Approving six of ten colleges adds exactly six without duplicates | Six distinct shortlisted rows after two approval requests |
| A concurrent manual edit makes a conflicting proposal stale | Manual SAT edit → proposal `stale`, receipt `stale`, manual value kept, unrelated proposed field not applied either |
| Also covered | Unrelated edit does not block; student edit creates revision 2 and applies the edited value; hash mismatch and expiry → 409 with nothing changed; another student gets 404; inbox and nav badge survive refresh; mentor context reflects pending and rejected outcomes; Agent-mode chat creates a pending proposal (no list row) and the card reappears after reload; research never removes saved/applying colleges |

Checks: `php artisan test` → **223 passed**; `pint --test` passed; `npm run types:check` and `npm run build` passed; new frontend files lint-clean; edited files have no new lint problems.

### Limitations

- Council results are suggestions promoted one at a time; the batch "approve 6 of 10 researched colleges" flow for research output comes with Phase 6.
- Only profile and college-addition proposals exist. Application edits and uploads will use the same proposal system when agents can perform them (Phases 4/7/8).
- The approval UI was type-checked, built and covered by server tests, not clicked through in a browser.

---

## End-to-end verification of Phases 1–3 (real browser, real model)

A browser harness (puppeteer-core driving the installed Chrome, headless) runs against a separate e2e SQLite database seeded with the synthetic student — never the dev database (the setup script refuses any other path). It logs in through the UI, visits every workspace page, performs the flows, captures console errors / 5xx responses / screenshots, and tees the chat SSE stream to assert on exactly what the server sent.

**Result: 19/19 steps pass, 0 browser errors.** Covered: login; Home, College lists, Applications, Tasks, Approvals, Profile, Essays, Gap + Fix, Settings render; start an application from a college; move it to ready-to-submit and to submitted with student-confirmation evidence; submitted-without-evidence is refused with the message shown; approve one of two profile fields; approve 2 of 3 proposed colleges; nav badge clears; **real-model chat** — Ask answers from saved facts *with provenance* ("…recorded and you haven't confirmed it yet"), Ask refuses a list change and offers the two honest routes, Agent mode reads the list and creates a proposal whose approval card appears in chat.

### Findings fixed during verification

| Finding | Fix |
| --- | --- |
| **The OpenAI key in `.env` is rejected (HTTP 401); the Anthropic key works.** Every agent was hard-wired to OpenAI, so all AI features were down locally. | All 16 agents now take provider/model from `config/admitme.php` (`ADMITME_AI_PROVIDER`, per-provider default models, per-agent overrides) via `UsesConfiguredModel` + `AiModelResolver`. Defaults unchanged (OpenAI `gpt-5-nano`). The e2e run uses `ADMITME_AI_PROVIDER=anthropic` (`claude-haiku-5-5`). **Your `.env` was not changed** — set `ADMITME_AI_PROVIDER=anthropic` or replace the OpenAI key. |
| The Ask/Agent toggle was missing on the Home composer (it lives in `pages/dashboard.tsx`). | Toggle passed through the empty-state helpers and shown on Home too. |
| Live chat text from consecutive model steps ran together ("approval.I've"); the stored message was fine. | `MentorChatStreamProtocol` separates steps exactly as `TextDelta::combine` stores them. |
| College-addition proposals rendered as "— → College (match)". | Shown as "Adds College (match) to your saved list". |
| Agent-mode hint still said it "can edit your college list"; new tools showed as "Working on it". | Copy updated ("propose changes — nothing changes until you approve"); activity labels added. |
| Synthetic student showed "0% complete" and hit paywalls (the UI deliberately keeps paywalls when billing enforcement is off). | Seeder computes derived completeness and gives the synthetic student a synthetic 90-day pass. |

---

## Phase 4 — Documents and progressive onboarding

### Implemented behaviour

- **Documents** (`documents`, `document_versions`, `document_pages`; `App\Domain\Documents`): upload PDF / Word (.docx) / text (images accepted but need OCR) up to 10 MB; immutable versions with SHA-256 on private storage; identical re-uploads rejected; authorized download; processing as a queued job (`ProcessDocumentVersionJob`, also listed on Tasks) with states `queued → processing → needs_review | ready | failed`, `superseded` on replacement; retry for failed versions.
- **Extraction → candidates** (D-013): `DocumentFactExtractorAgent` (trusted `resources/ai/agents/document-extractor.md`; keep original scales, quote verbatim, flag ambiguity, treat the document as data) → `CandidateExtractionService` verifies quotes against the cited page, flags misfit/ambiguous/multiple values and conflicts with the current profile.
- **Review screen** (`/documents/{id}`): page text with the quoted passages highlighted beside each value; Accept / Edit / Reject; conflicts show the current profile value; earlier versions' values listed as retired. Accepting confirms the value through the shared profile service with document/page provenance.
- **Visible failure** (D-014): images and text-less PDFs fail with a plain reason and an "Enter values manually" route; extraction-provider errors keep the page text and offer retry.
- **Guided, resumable onboarding** (`/profile-setup`): situation & intake, curriculum & grades, interests, destinations, budget (amount + the student's own currency) & aid, optional documents, review with task readiness ("Researching colleges", "Preparing applications"). Every field accepts "don't know / not applicable"; those count as answered, are shown to the mentor as unknown rather than missing, and clear automatically when a real answer is saved. New profile fields: situation, first-generation, target intake, budget amount/currency.
- Nav gained **Documents**; Profile links to guided setup and Documents. Mentor brief reports how many document values await review without treating them as facts.

### Gate evidence

| Gate | Automated (`DocumentReviewTest`, `GuidedOnboardingTest`) | Real browser + real model (`smoke-phase4`, 8/8) |
| --- | --- | --- |
| Transcript → reviewable, sourced academic candidates | Candidates carry page + verbatim quote; an unsourced value is dropped | The live model read the synthetic transcript: GPA 3.85, scale 4.0, graduation year 2027 (flagged "expected, not confirmed"), curriculum "IB Diploma Programme" flagged as conflicting with the saved A-Levels — all with page quotes |
| Confirmed values reach the profile | Accept → profile updated, fact `confirmed` with `document` source and page; reject changes nothing | Accepting the GPA showed 3.85 on Profile |
| A bad scan fails visibly | PNG → OCR-not-set-up reason; text-less PDF → "No readable text" reason; extractor never called | Failure banner with "Enter values manually" |
| Replacing a transcript preserves history and flags conflicts | v1 superseded with file kept; its pending value retired and refused (409); v2 value is a `conflict`; confirmed GPA unchanged | v2 3.91 shown as conflicting with confirmed 3.85; both versions listed |
| Onboarding resumes after reload | Saving "situation" redirects to "academics"; reload resumes there | Same, through the UI |
| Also covered | Ambiguous grade stays an unresolved candidate and never reaches the mentor as a fact; identical file rejected; another student gets 404 on view/download/replace/review; "don't know" handling; currency kept as entered | "Don't know" for SAT/ACT saved; Tasks shows completed and failed document jobs |

Checks: `php artisan test` → **235 passed**; types/build pass; Pint clean.

### Limitations

- No OCR engine (D-014); scans need manual entry.
- Course-level grades are not modelled yet (only profile-level curriculum/GPA/scale/tests); the full transcript text is stored and visible.
- Extraction covers academic fields; test-score PDFs work for SAT/ACT only.

---

## Phase 5 — Knowledge base and source-backed research

### Implemented behaviour

- **Records** (`institutions`, `knowledge_sources`, `knowledge_source_versions`, `knowledge_passages`, `admission_requirements`, `research_answers`; `App\Domain\Knowledge`). A student's college choice links to a shared institution record by slug (`InstitutionResolver`).
- **Authorized ingestion** (`KnowledgeIngestionService`, `SourceUrlPolicy`): https only; no embedded credentials; hosts must resolve to public addresses and the connection is pinned to the checked address (`CURLOPT_RESOLVE`); redirects are refused rather than followed; size and time limits; scripts and navigation stripped (`HtmlText`). An unchanged page only records the check; a changed page becomes a new version with new passages, and the older version's requirements are retired.
- **Trust and visibility (D-016)**: pages on the institution's *operator-verified* domain are `official` and pages on known reference domains (Common App, UCAS, College Board…) are `reference`; both are shared by every student with that college. Any other page is accepted only when the student ticks "This is the college's official website", and then only as that student's **private, unverified** source (`owner_user_id` on the source, its passages and its requirements; `claimed_official` recorded). A student's tick never changes the shared institution. Operators verify domains with `php artisan admitme:institution-domain {slug} {domain}`.
- **Structured requirements** (`RequirementExtractorAgent` + `resources/ai/agents/requirements-extractor.md` → `RequirementExtractionService`): deadline date/time/IANA timezone, amount + ISO currency, conditions, programme, cycle, applicant scope and a verbatim quote. A requirement is kept only if its quote appears in the page; unparseable dates/times/timezones/currencies stay empty instead of guessed.
- **Unresolved, not guessed** (`RequirementOverview`, `RequirementConflicts`): key requirements with no source, sources for a different cycle, disagreeing sources and stale sources (default 30 days) are listed as unresolved. Conflicts are computed per viewer at read time from the sources that viewer can see; differently worded labels ("RD application deadline") are compared by meaning; both values are shown with their source and neither is picked.
- **Search with filters before ranking** (`KnowledgeSearch`): keyword passage search restricted in the query to one institution's current versions visible to the viewer; private document search restricted to the student's own non-superseded documents.
- **Researcher** (`ResearcherAgent` + `resources/ai/agents/researcher.md` → `ResearchService`): answers from labelled evidence only ([R#] requirements, [S#] passages). Server-side validation drops citations to labels that don't exist, marks uncited claims `unresolved` and claims citing conflicting evidence `conflicting`; the answer, claims, unresolved items and an evidence snapshot are stored. With no sources the model is not called.
- **Mentor tools**: `ReadCollegeRequirementsTool` (requirements with cycle match, status, quote and source, plus unresolved items) and `SearchMyDocumentsTool`, in Ask and Agent modes.
- **UI** (`components/admitme/college-knowledge.tsx` on each college page): requirements with status, cycle ("not your cycle" flagged), quote and source link; unresolved list; "Ask about this college" with per-claim citations and inspectable evidence; sources with kind, check date, staleness and failures; an add-source form that says what is shared and what stays private.

### Gate evidence

| Gate | Automated (`KnowledgeResearchTest` 11, `RequirementLabelTest`) | Real browser + real model (`smoke-phase5`, 6/6) |
| --- | --- | --- |
| Questions about a selected college get supported answers with inspectable sources and cycle labels | Cited claims keep their cycle; a claim citing a non-existent label becomes unresolved; evidence keeps URL + quote | Live extraction of the synthetic admissions page: EA 1 Nov 2026, RD 1 Jan 2027 23:59 America/New_York, fee USD 75, test-optional, English tests — all "Fall 2027 entry". All three researcher claims supported and citing the official page; "unresolved" empty |
| Private document results stay isolated | Each student's document search returns only their pages; another student's private source is invisible in the panel, passage search, research and the mentor tool | A second student with the same college saw the shared official facts only — not the first student's private page or its conflict |
| Missing or conflicting requirements are unresolved, not guessed | Missing fee listed; official vs reference deadline shown as a conflict with both values and sources; stale source flagged; a private disagreeing page creates a conflict only for its owner | "Sources disagree on Regular Decision deadline: 1 Jan 2027 23:59 (America/New_York) per 127.0.0.1 (official site) vs 15 Jan 2027 per localhost (your source, not verified)" |
| Also covered | localhost/loopback/private IPs, http, credentials and unrelated sites refused with nothing fetched; unchanged refetch stores no version; changed page retires old requirements; only the student's own colleges are reachable (404); operator domain command validates input | Mentor chat in Ask mode called `ReadCollegeRequirementsTool` and answered "USD 75 … from the university's official admissions page" |

Found and fixed while verifying: the first version let a student's "official" tick set the shared institution's domain and shared their page with every student — fixed by D-016 before the gate was recorded.

Checks: `php artisan test` → **247 passed**; `tsc --noEmit` and `vite build` pass; Pint clean.

### Limitations

- No provider-native web search (D-017): the Researcher only knows pages that were added. Finding new pages is manual (student or operator).
- Domain verification is an operator command, not an admin screen. Until an operator verifies a college's domain, every page a student adds for it stays private to them.
- Keyword search only (no embeddings), per the plan's "start with full-text"; fine for one college's pages, not yet evaluated at scale.
- No reviewer UI for marking requirements `verified`; everything extracted is labelled "from the source — not independently reviewed".
- No caching layer; overviews are computed from the database per request (cheap at current volumes).
- Only HTML/text pages are fetched; PDFs linked from admissions pages are not.

---

## Phase 6 — College discovery and the application workspace

### Implemented behaviour

- **Transparent fit checks** (`App\Domain\Discovery\CollegeAssessor`, D-018): every college on the list (suggested or saved) is checked against the student's constraints — destination, intended majors, grades, testing, English requirement, cost and aid — in deterministic code, with no model call. Each reason states its basis: *from your profile*, *from a source on file* (Phase 5, with link and the source's trust label), *AI research estimate — not verified*, or *not on file*.
- **Honest affordability**: `within_budget`, `needs_aid`, `over_budget` or `incomplete`, plus `complete` and a list of what is missing. Only a sourced cost figure compared with a budget in the same currency counts (no currency conversion); a tuition-only figure, an unknown aid outcome or a missing budget keep the check incomplete. New requirement types `cost` and `aid` let the extractor record cost-of-attendance figures and aid policies from college pages.
- **Suggestions are task artifacts** (D-019): research suggestions stay `suggested`, grouped by the research run that produced them, in a "Suggestions to review" panel with their reasons; the student adds any subset to the shortlist and dismisses others in one decision (`POST /college-lists/suggestions/review`, idempotent, audited; anything already decided is reported as skipped). The Tasks page shows "N suggestions awaiting your review" on the run's task. Mentor proposals can now promote a suggestion (previously refused as "already on the list").
- **Filter and compare**: the saved list (shortlisted/applying only) filters by list status and affordability; ticking two to four colleges opens `/college-lists/compare` (status, location, next deadline with its basis, affordability, fit check, requirements on file/conflicts/missing, application progress and next action). Suggested → shortlisted → applying transitions run through `CollegeListService` as before.
- **Application workspace** (`/applications/{id}`; `application_items`; `ApplicationChecklistService`, `ApplicationWorkspace`, D-020): opening an application builds a checklist from the requirements the student can see — deadlines (date/time/timezone), fees, testing decision, English test reports, documents (with document assignment), essays (with the prompt), recommendations, interviews and aid forms — plus standard steps (portal account, application form, school documents). Each step shows its source and quote; disagreeing sources are spelled out in the step and listed as blockers. "Update from requirements" refreshes open steps, keeps progress, never duplicates, and flags steps whose requirement disappeared.
- **Owners and completion**: third-party steps (recommendations, school documents, official score reports) have an owner and go not requested → requested → received; "requested" is never completion, and "received" needs a basis (*portal shows it received* or *sender confirmed*). The student's own steps are "done (you marked it)". Blocked and not-needed steps need a reason. Deadlines, overdue steps, blockers and an ordered list of next actions are computed in one place.
- **Manual handoff, stated plainly**: the portal link (from research, or confirmed and saved by the student in the workspace) opens in a new tab; the page says AdmitMe cannot open, fill in or submit portals yet; submission still needs recorded evidence.
- **Chat parity**: `ReadApplicationTool` returns the same workspace read model the screen renders; `ReadCollegeListTool` includes the same fit check; `GetStudentCollegeList` now labels each row's `list_status` and whether it is on the saved list.

### Gate evidence

| Gate | Automated (`CollegeDiscoveryTest` 10, `ApplicationWorkspaceTest` 10, `RequirementLabelTest`) | Real browser + real model (`smoke-phase6`, 8/8) |
| --- | --- | --- |
| Build a defensible shortlist | Reasons carry their basis; affordability incomplete without budget/cost; `needs_aid` with sourced cost + aid; currency mismatch and tuition-only stay incomplete; private sources only shape their owner's check | Three run suggestions shown with reasons ("Lists programmes in Economics… — AI research estimate — not verified"); live extraction of the synthetic cost page made Lakeside "Over your budget by USD 57,500 per year; affordable only with at least that much aid" |
| Approve it | Add 2, dismiss 1, skip already-decided and other students' ids, idempotent, audited; a mentor proposal promotes a suggestion | Approved 2 of 3, dismissed the third; filtered to "Applying"; compared three colleges side by side |
| Open an application showing real requirements and next steps | Checklist from sourced requirements (deadline with timezone, fee, testing decision, English report, teacher recommendation, essay prompt, transcript merged into the school-documents step); rebuild keeps progress and flags removed requirements; next actions put disagreeing sources and third-party requests first | Workspace built from the live-extracted page: EA/RD deadlines with source, "Pay the application fee (USD 75)", third-party transcript/English/recommendation steps, CSS Profile deadline |
| Third-party work never complete because requested | `done` refused for third-party steps; `received` refused without a basis; student-reported completion recorded as such | "I've asked them" → requested, 0 received; "Portal shows it received" → received |
| Chat and manual screens show identical state | Tool output equals the screen's next actions, blockers, checklist titles/statuses and deadline | Mentor (Ask) called `ReadApplicationTool` and gave the same next steps (counselor transcript, English score report, deadlines, CSS Profile) |

Found and fixed during end-to-end runs: (1) the assessor ignored international-scoped costs when a college's country was unknown; (2) an "application fee waiver" was treated as the same requirement as the fee and replaced it; (3) the legacy list tool presented research suggestions as saved, so the mentor told the student a suggestion was "already on your saved list". Each now has a regression test. All earlier smokes re-ran green on the final code: phases 1–3 19/19, phase 4 8/8, phase 5 6/6.

Checks: `php artisan test` → **267 passed**; `tsc --noEmit` and `vite build` pass; Pint clean.

### Limitations

- Recommendations rank and explain the colleges research suggested; there is no college database to search for new candidates by constraint (the council remains the candidate source).
- College country comes only from a US state code on research rows or an operator-set institution country; otherwise the destination check says "not on file".
- No currency conversion, by design; budgets and costs in different currencies stay incomplete.
- The mentor can read and explain the checklist but not change it yet (Agent-mode task tools arrive in Phases 7–8).
- Essay steps link to the existing essay suite; drafts are not created from a checklist step automatically.

---

## Phase 7 — Agent mode and durable task orchestration

### Implemented behaviour

- **Durable tasks** (`agent_tasks`, `agent_task_dependencies`, `agent_task_events`, `agent_runs`; `App\Domain\Tasks`, D-021): a goal owns typed steps with dependencies. Every transition is a compare-and-set on the row plus an event with its reason, and every model call is an agent run with its model and token usage. Task state never lives in a worker's memory or the chat stream.
- **Orchestrator** (`Orchestrator`): validated plans with idempotency keys; dependency-aware dispatch within a per-student limit (default 3 running); queue jobs (`RunAgentTaskJob`) claim steps with a lease, and expired leases are recovered exactly once (schedule `admitme:tasks:recover` every minute, and whenever the student opens their tasks); automatic retries with backoff for provider errors; per-step model-call budgets; `blocked` (e.g. no budget on file — never guessed), `paused`, `failed` (dependents blocked with the reason) and `cancelled` (queued steps stop at once; a running step stops at its next checkpoint; finished work is kept; a pending proposal stays in Approvals).
- **Validated plans, not instructions** (`TaskRegistry`, `TaskPlanValidator`): only four registered step types — `college_research` (one budgeted structured call to `CollegeResearchAgent`; results become suggestions linked to the step), `affordability_check` (deterministic Phase 6 assessor; blocks without a budget), `propose_shortlist` (one batch proposal, ranked by affordability, over-budget colleges left out, each item carrying its reasons and source links as evidence; re-checks with current data and records a `revision_changed` event if the profile changed since planning) and `refresh_sources` (re-fetches pages on file through the Phase 5 policy, runs independently). At most 6 steps; inputs validated per type; dependencies only on earlier steps.
- **Mentor planning** (`PlanTasksTool`, Agent mode only): the mentor writes a plan, which Laravel validates and schedules (invalid plans return errors and start nothing). `ReadTasksTool` (Ask and Agent) and a "Tasks" context section give the mentor the outcome of finished, failed, blocked or waiting steps in later turns.
- **Proposals know their task**: `proposals.agent_task_id`; deciding (or expiry/staleness) moves the step on — approved/rejected → done with the decision as its summary, stale/expired → blocked with "retry for a fresh proposal".
- **Live task panel** (`TaskGoalCard`, in the chat turn that started the plan and on Tasks): goal, steps, status and reason, results, the inline approval card, attempts, and Pause / Resume / Retry / two-step Cancel; it polls the stored state (2.5s while moving, 6s while waiting). The Tasks page can also start the "affordable shortlist" playbook through the same orchestrator.
- **Resumable**: chat history reloads the card (`task_goal_ids`); nothing depends on the original HTTP stream.

### Gate evidence

| Gate | Automated (`TaskOrchestrationTest` 11) | Real browser + real queue worker + real model (`smoke-phase7`, 8/8) |
| --- | --- | --- |
| "Research affordable options and prepare a shortlist" creates visible tasks | Playbook runs to `awaiting_approval`; research results are suggestions linked to the step; independent refresh finished meanwhile | Agent-mode chat → `PlanTasksTool` → card with research/afford/shortlist/refresh; the worker researched 8 colleges, checked 14 against USD 25,000 |
| …produces sourced proposals | One proposal, over-budget Elm excluded, Pine's cost evidence links to its page | Proposal led with Synthetic Cedar College, citing the live-extracted cost page (USD 22,000, within budget) |
| …waits for review and applies approved changes | Approve 1 of 2 → only that college saved, step and goal done | Approved 5 of 6 in the task card → exactly those 5 shortlisted; step says "You approved 5 of 6: …" |
| Refreshing does not lose work or duplicate execution | Same idempotency key → same goal; repeated dispatch/recover/panel loads → 1 agent run, 1 proposal | Reloaded the conversation: same plan, every step ran once (attempts 1,1,1,1), one plan on the Tasks page |
| Switching to Ask does not terminate a run | Ask has `ReadTasksTool` but not `PlanTasksTool`; state unchanged by reads | Switched to Ask: plan still waiting; the mentor (via `ReadTasksTool`) said the shortlist was waiting for approval |
| Also covered | Dead worker recovered once, repeated deaths fail the step and block dependents; cancel stops queued steps and a claimed step before its model call; a proposal survives cancel and the student's later decision still applies; no budget → blocked → retry continues; provider errors back off then fail; model-call budget enforced; budget lowered mid-plan → re-checked and Pine excluded; invalid plans create nothing; endpoints are private | Cancel from the panel after research finished → remaining step cancelled, its proposal still pending; cancel while research was in flight → "Cancelled while running; nothing after that point was done", no colleges added |

Found and fixed during verification: a goal showed "Running" when its only queued step waited behind a blocked one (now derived from what can actually progress); a decided proposal step kept its "waiting for your approval" summary; e2e harness: database resets now refuse to run while the server/worker hold the file (a partial delete corrupted it once).

Checks: `php artisan test` → **278 passed**; `tsc --noEmit` and `vite build` pass; Pint clean.

### Limitations

- Production needs a queue worker and the scheduler running (`queue:work`, `schedule:run` for recovery); with the `sync` driver steps run inline in the request.
- Four step types only. Essays, gap analysis and the list council still run as their own background jobs, shown under "Background jobs".
- Budgets count model calls; no token or cost caps yet. A cancel during a model call takes effect after the call returns, so that call's usage is still incurred and recorded.
- One active mentor turn per conversation and global/provider concurrency limits are Phase 8.
- The panel polls; no broadcasting yet.
- Research candidates are AI suggestions. Until a college's own pages are added, its affordability stays "incomplete", and proposals say so per item.

---

## Phase 8 — Specialist delegation, concurrency, and writing support

### Implemented behaviour

- **Delegation envelope** (`App\Domain\Tasks\Delegation\Handoff`, D-023): built server-side per specialist call with the task id, the authorized student (fixed by the server), goal, profile revision, labelled evidence, output contract, allowed tools (none) and budget. The specialist sees only `prompt()` (never the parent conversation). The envelope is stored on its `agent_runs` row, and `knownRefs()` drops any evidence label it was not given. Research and the application planner both run through it.
- **Limits**: per-student running steps (3), a global limit (`max_running_global`, 20), and a provider rate limit for task model calls (`provider_calls_per_minute`, 30) that defers a step without using up an attempt. Plus one active mentor turn per conversation: a cache lock held for the stream, and a second send gets HTTP 409 `turn_in_progress`.
- **Revision-aware re-evaluation**: when the student changes budget, currency, aid need, destinations or majors, `Orchestrator::profileChanged()` marks any pending task-made shortlist proposal **stale** (it can no longer be approved), re-runs the affordability check, and produces a fresh proposal with the new values. Queued and running steps already read current data, and the proposal step records a `revision_changed` event.
- **Attributable results**: every AI step lists its runs — agent, model, outcome, tokens — in the task panel and API, and its suggestions carry the step id.
- **Writing workspace** (`/writing`, `writing_pieces`, `writing_versions`, `writing_feedback`; `WritingService`, `WritingCoach`, `WritingCoachAgent`, D-024): student-owned pieces with prompt and word limit. Versions are immutable (updates throw; unchanged text is not a new version; a stale editor gets a conflict) and authored by the student. Feedback is always about one saved version; every comment must quote the draft verbatim, and comments that don't are dropped and counted. The editor highlights the quoted words.
- **Assistance policies** per college (`institutions.writing_policy`, operator command `admitme:institution-writing-policy`): *no AI assistance* (the coach is never called — the server refuses), *feedback only* (default: comments and questions, all replacement text stripped), *light editing* (language fixes of at most 12 words). The policy is fixed on the piece when it is created and shown on every page.
- **From the checklist**: an application's essay step has "Write it", which opens a piece with the step's prompt and word limit (reused on later clicks).
- **Application planner** (`application_plan` task type, `ApplicationPlannerAgent`, `application_form_plans`, workspace "Form preparation"): a field-by-field plan against a synthetic first-year form snapshot, labelled per field as *from your profile / writing / checklist* (copied), *check* (deterministic validation: missing required values, scale-less GPA, past graduation year, essay over the limit, a round not chosen), *AI judgment* (test reporting, activity order — with the evidence labels it rests on) or *AI suggestion — review* (optional additional information, never filled in). The step refuses an application id that isn't the student's own.

### Gate evidence

| Gate | Automated (`DelegationAndWritingTest` 10) | Real browser + real queue worker + real model (`smoke-phase8`, 6/6) |
| --- | --- | --- |
| Independent research runs within limits and returns attributable results | 4 independent steps → 3 run, the 4th starts when one finishes; each run records the handoff (student, contract, evidence refs) and its model; each suggestion traces to its step. Global limit holds a second student's step; the provider limit defers without using an attempt | The research step lists `CollegeResearchAgent · claude-haiku-5-5 · completed · 3,329 tokens` |
| A changed budget re-evaluates affected work | Lowering the budget makes the pending proposal stale, re-runs affordability (2 starts) and proposes again without the now-unaffordable college | Lowered the budget in Profile setup: old proposal pending → stale, a new proposal replaced it |
| Writing feedback preserves source text and authorship | Unknown-quote comments dropped; replacement text stripped under feedback-only; ≤12-word language fixes only under light editing; version body unchanged; no version created by the coach; versions immutable; stale editor refused | 5 comments from the real coach, every quote verbatim from the draft, no rewrites, version 1 unchanged |
| Child agents cannot bypass scope or approval rules | A plan naming another student's application fails in the step; the planner's unknown evidence labels are dropped; shortlist changes still arrive as proposals | No-AI college: feedback button disabled and a forced request refused (422); form plan judgments cite only labels the planner was given ("Based on: P1.") |

Found and fixed during verification: `institutions.writing_policy` was not mass-assignable, so a recorded policy was silently ignored. The form-plan section did not refresh when its background step finished; the task card now reports completion so the page reloads its data.

Checks: `php artisan test` → **288 passed** (including all earlier phases' feature tests); `tsc --noEmit` and `vite build` pass; Pint clean. The earlier browser smokes are re-run together in Phase 11.

### Limitations

- The one-turn lock can't be shown in the browser smoke: PHP's single-threaded dev server serializes requests. It is covered by a feature test.
- The provider rate limit applies to task steps; chat turns use the existing per-plan quotas.
- Writing policies are recorded by an operator; AdmitMe does not yet extract a college's AI policy from its pages.
- The form plan uses a synthetic snapshot of a typical first-year application, not any specific portal (that needs the Phase 10 bridge).
- No separate verifier agent (D-025): the failures seen (quotes not in the text, unknown evidence labels) are caught deterministically, and none occurred in the real-model runs.

---

## Phase 9 — Exports and user-controlled data lifecycle

### Implemented behaviour

- **Exports** (`data_exports`; `App\Domain\Exports\ExportService`, `BuildExportJob`, `/exports` "Your data", D-026): the student picks sections (profile, college list, applications and checklists, tasks, writing, documents) and specific documents and writing pieces. All mutable records are read in **one transaction at request time** into a private snapshot, and documents and writing are referenced by immutable version id. A queued job then packages a ZIP containing `data.json`, CSVs (college list, application checklists, tasks; formula-safe), the exact document files (re-verified against their recorded SHA-256), the writing versions with their feedback, `manifest.json` (schema version, capture time, snapshot revisions, every included file with its SHA-256, every omitted item with the reason, and the categories never included), and a README. The same service handles requests from the workspace and from chat (`RequestExportTool`, Agent mode).
- **Authorization and expiry**: only the student's own items are captured; anything else is listed as omitted, never included. A download needs the owner's session **and** a temporary signed URL that expires with the export (24 h by default). An expired or deleted export returns 410. `admitme:prune-data` (hourly) deletes expired archives and unfinished leftovers.
- **Never included**: passwords, two-factor secrets and sessions; billing identifiers; provider keys, internal tokens and cookies; other students' data and the shared knowledge base.
- **Deletion across derived stores** (`App\Domain\Privacy\DataLifecycleService`):
  - **document** — every version's file, its page text (so private search no longer finds it), values it proposed that weren't confirmed, and every export containing it. Values the student confirmed stay on the profile with the link to the deleted file removed.
  - **writing piece** — its versions, feedback and any export containing it.
  - **private knowledge page** — its passages and requirements, plus research answers that quote it (shared official pages can't be deleted by a student).
  - **account deletion** — now also removes document files, export archives, the student's storage folders, conversations (stored polymorphically, so cascades missed them) and cached memory projections.
  - Every deletion is audited.
- **Correction**: profile corrections already flow through the profile service. Pending task proposals re-evaluate (Phase 8), exports keep the snapshot they captured, and memory projections are keyed by profile revision.
- **Retention**: export archives expire (24 h), operational context snapshots are kept 90 days (`ADMITME_CONTEXT_SNAPSHOT_DAYS`), and application logs rotate daily for 14 days (`LOG_DAILY_DAYS`). Database backups are the hosting provider's responsibility (documented as a limitation).

### Gate evidence

| Gate | Automated (`DataLifecycleTest` 9) | Real browser + real queue worker (`smoke-phase9`, 5/5) |
| --- | --- | --- |
| Export reconciles with the selected snapshot and file versions | Profile, writing and document changes made after the request (but before the build) are not in the archive; revision, file hashes and version ids match the snapshot; CSV row counts match; no credentials | Archive downloaded from the signed link: data, CSVs, `documents/e2e-transcript-v1.txt`, `writing/personal-statement-v1.txt`; every manifest SHA-256 matched its file; profile revision matched the snapshot |
| Another user cannot download it | Other user → 404; unsigned link → 403 | Second student → 404; forged signature → 403 |
| Expired links fail | After expiry the signature fails (403); pruning deletes the archive; a fresh signature for an expired export → 410 | Expired and pruned: archive gone, link → 410 |
| Deletion prevents retrieval from active derived stores | Document: file, page text, private search, pending values and containing exports gone, confirmed value unlinked. Private page: passages, requirements and answers quoting it gone. Writing: versions and exports gone. Account: files, conversations, exports gone | Deleting the document: stored file gone, its export deleted (link → 410), document page → 404 |

Found and fixed during verification: plain `throttle:N,1` route limits share one counter per user across routes, so a few downloads blocked a new export request (429). Exports and downloads now have named limiters (regression test). Account deletion left an empty per-student export folder; erasure now removes the student's whole storage folders.

Checks: `php artisan test` → **297 passed**; `tsc --noEmit` and `vite build` pass; Pint clean.

### Limitations

- Backups are outside the app: retention of database and storage backups depends on the hosting provider and must be configured there (recommended: 30 days, with deletions propagating when backups roll over).
- An export captures the current state only; it is not a full change history (the audit log is not exported).
- The e2e harness shares the app's storage folder with local development, so the browser smoke never deletes accounts. Account erasure is covered by a feature test on fake storage.

## Phase 10 — Browser bridge and local extension

### Implemented behaviour

- **Linking** (D-027): Settings → Browser extension creates a one-time code (10 minutes, single use). The side panel exchanges it at `POST /api/browser/link` for a scoped, revocable bearer token (`browser:session` only, 30 days). Only its SHA-256 is stored. Revoking it in Settings signs the extension out at once and ends its sessions. The extension never sends college passwords or cookies, and the API uses no cookies or CSRF; the panel calls it with host permissions.
- **Sessions and observations**: "Use this tab" binds a session to the student, the device (token), one tab and its origin, plus the application chosen in the panel. "Observe this page" sends a filtered snapshot. Visible inputs, selects and textareas are kept; password, hidden and file inputs are never reported. Each field gets a temporary id (`f1…`), and the snapshot gets a structure hash (labels, names, types, options — not values). Snapshots from another origin are refused.
- **Preparing a fill** (D-029): from the panel, the application workspace ("Prepare fill from the observed page") or chat (`PrepareBrowserFillTool`, Agent mode). All three call `BrowserBridge::proposeFillFromPlan()`, which matches the observed fields to the application's form plan and offers **only literal profile answers** (fields marked `"fill": true` in the form definition: name, email, country, citizenship, year, graduation year, curriculum, test scores, entry term, major). A select only takes one of its own options. Essays, activities, checklist status, AI judgments and suggestions are never offered. The result is a `browser.fill` proposal bound to the session, snapshot, page hash, tab and origin. It appears in the panel, the workspace, Settings, Approvals and chat, and approving it anywhere uses the shared decision flow.
- **Running approved steps** (D-028): approval only *queues* commands (`fill_field` / `select_option`), each with an idempotency key `{proposal}:{field}:r{revision}`. The open panel polls. It runs steps only while the bound tab is the one in front, re-observes first (a changed structure is reported, so old steps go stale), then leases the steps for that tab, origin and page hash. The content script sets each value (native setter + input/change events) and reads it back. The server marks a step `completed` only when the read-back equals the approved value; otherwise it is `frozen` ("check the page"). If an acknowledgement is lost, the step is handed out again after the lease (60 s; `ADMITME_BROWSER_LEASE_SECONDS`) with `reconcile`: the field is read first and only confirmed, not typed again. A different tab or origin gets `409 wrong_tab` and nothing. The extension never clicks, submits, pays or uploads (`click_supported_control` is in the contract but refused).
- **Reconnects**: state lives on the server. The panel stores only the link and session id (`chrome.storage.local`), so a closed or reloaded panel resumes. A session not heard from for two minutes shows "Waiting for the browser", never success. The open panel's refresh is the heartbeat.
- **The extension** (`browser-extension/`): Manifest V3, React side panel (`src/sidepanel`), minimal service worker (`background.ts`, opens the panel; nothing depends on it staying alive), content script (`content.ts`, built as a classic script), versioned command types (`src/shared/commands.ts`). Build with `npm run build:extension` (type-check + Vite) → `browser-extension/dist`, then chrome://extensions → Developer mode → Load unpacked.
- **Inspectable and bounded**: every step shows its read-back in the panel, the workspace and Settings. Data exports include each application's `browser_fills` (field, value, status, read-back, site, time). Observations hold what a form showed, so they are pruned after 30 days (`ADMITME_BROWSER_SNAPSHOT_DAYS`); the record of what was typed stays. Expired link codes are pruned too.
- **Honest copy updated**: the workspace, the default "Complete the application form" step, `ReadApplicationTool` and the form-plan notes no longer say AdmitMe cannot fill portals. They now say it can type approved profile details with the extension and never submits.

### Gate evidence

| Gate | Automated (`BrowserBridgeTest`, 8) | Real Chrome + the unpacked extension (`smoke-phase10`, 9/9) |
| --- | --- | --- |
| Fills a supported form after approval and verifies values | Nothing leases before approval; approving 2 of 3 queues exactly 2; completion only on matching read-back | Approved in the web app while the form tab was in the background: the panel waited ("Switch to the form tab") and typed nothing. With the tab in front, all 6 fields were filled, each typed once (input-event counter = 1) and read back; essay, password and file untouched; form not submitted; the workspace showed 6 completed steps |
| Survives reconnects | Lost ack → re-leased with `reconcile`, confirmed without retyping, `attempts = 2`; silence → `waiting_for_browser` | Acknowledgements dropped at the network layer, panel reloaded: the step was reconciled to `completed` on attempt 2; the field was typed **once** |
| Rejects stale and wrong-tab commands | Wrong tab id / origin → 409, nothing leased; changed page → `stale`; approving against an old page → proposal `stale`, no commands | A field added to the page before the student returned → step `stale`, value never typed, panel says so. Other tab id / other origin → 409; the same tab moved to `localhost` → panel shows the server's refusal and nothing is typed there |
| Does not repeat completed actions | Completed steps are never leased again; late or duplicate results don't change them | Every value typed exactly once across approval, reconnect and stale paths |
| Same approvals and records in the web app and side panel | The workspace, Settings and the panel API show the same proposal and steps; the chat tool creates the same proposal (Agent mode only) | The proposal created in the panel appeared in the workspace and Approvals and was approved there; steps visible in both |
| Credentials and scope | One-use, expiring codes; only token hashes stored; other students/devices → 404; revoked → 401 | Code reuse → 422; no password/hidden values in the stored observation; revoking in Settings signed the panel out within seconds and ended the session |

Found and fixed during verification:
- The form plan's "writing", "checklist" and activities entries are **summaries** (e.g. "Personal statement: version 2, 540 words"), and the first draft would have typed them into the form. Fills are now limited to fields declared as literal answers.
- The panel's refresh did not count as a heartbeat, so an open, idle panel looked disconnected after two minutes.

Checks: `php artisan test` (final count under Phase 11), `tsc` for the app and the extension, ESLint, `vite build` and `npm run build:extension` all pass; Pint clean.

### Limitations

- **Supported forms are narrow.** Fields are matched by label against the practice form's labels. Real portals (Common App, Coalition, college portals) use other labels, custom widgets, iframes and shadow DOM, so on most of them nothing will match yet; the panel then says so. The plan's next step — one real portal with the founder, then site adapters — is not done and needs the founder's own portal account. All portals are not claimed to work.
- Only text inputs, textareas and selects are filled. No checkboxes, radios, date pickers or rich-text editors. Values are not reformatted for portal-specific formats (phones, dates).
- Login, verification codes, CAPTCHA, declarations, payment, uploads and final submission stay with the student. The extension has no control-clicking at all.
- The extension is not in the Chrome Web Store; it is loaded unpacked. Production use needs HTTPS for the AdmitMe address (the manifest allows any `https://` site plus local `http://127.0.0.1`/`localhost` for development).
- Steps run only while the side panel is open and the form tab is in front (deliberate: nothing types while the student isn't looking).
- The e2e test opens the panel page in its own window, bound to the form tab with `?tab=`, because Chrome's side-panel UI cannot be driven by automation. It is the same page and code.

## Phase 11 — Evaluate, harden, and complete the founder's journey

### Implemented behaviour

- **Instrumentation** (D-031, migration `2026_11_01_960001`):
  - **Mentor turns** record provider, model, instruction version, profile revision, duration, provider usage, cited public URLs, and each tool call as name + outcome label.
  - **Specialist runs** record provider, model, a hash of the specialist's code, duration, usage and the handoff.
  - **Never stored:** message text, tool arguments or tool results. Errors are redacted (`RunInstrumentation::redact`) before they are stored or logged.
- **Evaluation set** (D-032): `resources/evals/research.json` (4 cases, including instructions planted in a page) and `writing.json` (3 cases, one per policy).
  - `php artisan admitme:eval {research|writing|all} [--save] [--compare=…]` runs them through the production prompts and validators with real model calls and no database writes. Results record provider, model and code version, so changes can be compared before release.
  - The extractor and coach were refactored (`ask()`/`validate()`, `review()`) so production and the evaluation share one code path.
- **Measures** (D-033): `php artisan admitme:metrics [--days] [--json]` reports, from stored records:
  - mentor and specialist failure rates, p50/p90 latency and tokens;
  - the source-supported share of research claims;
  - document-extraction corrections (accepted as read / corrected / rejected);
  - approvals (partial approvals, student edits, stale, expired);
  - task completion, retries, steps needing the student, tokens per completed goal;
  - browser fills (verified vs wrong or unverified);
  - **cost**, only when the operator enters prices (`admitme.pricing`), never guessed;
  - integrity checks for "false success" states, each of which must be 0. It exits non-zero if one is found.
- **Operations**:
  - `php artisan admitme:health` checks queue backlog and failed jobs, stuck task steps and exports, stale shared sources, whether a provider key is present (never printed) and private storage. The scheduler runs it hourly with `--log`.
  - The runbook is [`operations.md`](operations.md): processes, backups and restoration, provider failure behaviour (no automatic failover that could replay side effects), budgets, retention and freshness.
  - All Phase 5–11 settings are documented in `.env.example`.
- **Regression map**: [`regression-scenarios.md`](regression-scenarios.md) maps all 16 plan scenarios to automated tests and browser smokes. A GPA-change-in-Ask-mode test was added, and the doc describes how failures become cases.
- **Browser smokes kept in the repo**: `tests/e2e/` holds the harness with portable paths, a README, and `run-all.sh`, which runs every smoke on a fresh e2e database with its own server and worker. Smokes 4–6 now wait for queue-worker processing; before, they only passed with the synchronous queue.
- **Security fix found during hardening**: the Google sign-in callback logged the OAuth access and refresh tokens and the raw profile at debug level, and `.env.example` sets `LOG_LEVEL=debug`. It now logs only whether an id and email were received.
- **Founder's journey**: [`founder-journey.md`](founder-journey.md) is the checklist for the real walk-through. The synthetic rehearsal is every smoke run back to back on the final code (below).

### Gate evidence

| Gate item | Evidence |
| --- | --- |
| Get an application ready | Phases 1–10 smokes on the final code: profile → documents → shortlist → sourced requirements → workspace and checklist → form plan → writing feedback → approved browser fill |
| Explain where important facts came from | Provenance on profile facts, quoted requirements and research claims (smokes 4–6); `admitme:eval` research 4/4, with no injected value kept |
| Inspect every agent change | Approvals, task events and run records with model and code version (smokes 3, 7, 8, 10); integrity checks all 0 on e2e data |
| Recover interrupted work | Worker stop/restart and cancellation (smoke 7); lost browser acknowledgement (smoke 10) |
| Export their data | Smoke 9; exports now include browser fills |
| Browser execution independently verified | Smoke 10 in real Chrome with the unpacked extension (9/9) |

Real-model evaluation (`claude-haiku-5-5`): research 4/4 (7/7 expected requirements found, 0 forbidden kept, 0 quotes dropped); writing 3/3 (14 comments, 0 dropped, no replacement text under feedback-only, no call under no-AI).

**Every browser smoke on the final code**: each ran on a fresh e2e database with the real queue worker (database queue) and the real model — **69/69 steps**.

| Smoke | Steps | Notes |
| --- | --- | --- |
| Phases 1–3 | 19/19 | |
| Phase 4 | 8/8 | |
| Phase 5 | 6/6 | |
| Phase 6 | 8/8 | |
| Phase 7 | 8/8 | |
| Phase 8 | 6/6 | one console entry: the deliberate 422 when the coach is forced for a no-AI college |
| Phase 9 | 5/5 | console entries: the deliberate 403 (forged signature), 410 (expired, deleted) and 404 checks |
| Phase 10 | 9/9 | |

Phases 4–6 were re-run from `tests/e2e/`. Their first pass failed 4/8, 5/6 and 6/8. The cause was the smokes, not the app: they read results right after an upload or a new source. That only works with the synchronous queue they were written for; with a worker, the result arrives seconds later, and later steps in the same runs saw it. They now wait (bounded) for processing.

Checks: `php artisan test` → **312 passed**; `tsc` (app + extension), `vite build`, `npm run build:extension` pass; Pint clean. ESLint: no new errors; the repo already had ~570 at HEAD, untouched files unchanged.

### Limitations

- **The real founder walk-through has not been done**: it needs the founder's own data, portal accounts and production keys. The checklist is ready.
- **Backups are outside the app** and must be configured with the host (see the runbook).
- **The OpenAI key in the local `.env` is rejected (401).** The app's default provider is still `openai`; set `ADMITME_AI_PROVIDER=anthropic` or fix the key.
- Evaluation cases are few and synthetic; they grow as real failures are turned into cases. Live evaluation runs cost a small amount and are not part of CI.
- Cost estimates need operator-entered prices; until then only tokens are reported.

---

## Post-plan — Workspace redesign, plan-order onboarding, mentor side panel (2026-11-01)

Requested after Phase 11: make the UI match the founder's reference images, move the chat aside into its own panel, and make first-run onboarding follow the plan. Decisions D-034…D-037.

### Implemented behaviour

- **Shell** (`layouts/app-layout.tsx`, `components/shell/app-nav.tsx`):
  - **Navigation.** A narrow icon rail on a light-gray canvas: logo tile, journey-grouped icons, the active page as a raised white tile, approval and notification badges, and an account menu with plan status, settings, extension, data export, theme and sign out.
  - **Window.** Pages sit in a rounded white window. The header shows the page title and a short count (e.g. "Approvals • 2 waiting for you"). Phones get a drawer instead of the rail.
- **Mentor panel** (`components/mentor/*`, D-035):
  - **Layout.** Docked beside every page on wide screens, an overlay on laptops, full screen on phones. It keeps its conversation across page visits and continues the last conversation after a reload.
  - **Conversations.** History list, new chat, and download as Markdown.
  - **Answers.** Each answer shows a thinking/steps timeline (tool steps with the references they returned), then the answer with copy and retry. Approval, task, council, essay and Gap+Fix cards render inside the panel.
  - **Suggestions.** The empty panel suggests questions for the page you are on.
  - **Composer.** Attachment chips with a hover card (type, size, pages, reading state and the first page's text), Ask/Agent switch, the real model name, and stop.
- **Attachments** (D-036):
  - Upload in place or pick an existing document. The mentor gets that document's text for one turn as untrusted data.
  - Foreign ids are ignored. Files still being read, or failed, are described honestly.
  - The previous composer's attach button, which silently dropped files, is gone.
- **Home** (`pages/dashboard.tsx`, `App\Support\DashboardInbox`):
  - **Toolbar.** An "Ask mentor" pill and Needs you / Deadlines / Activity tabs.
  - **Mentor card.** The model name and how much of the profile the mentor knows, as a bar meter.
  - **Inbox.** Built only from real records: pending approvals, document values to check, deadlines (soonest first, "Soon" within 14 days), failed or waiting tasks, notifications and nudges. It opens with a "next step" banner chosen from the student's actual state.
  - **Side cards.** Readiness, college list balance, applications by status, and tools.
- **Onboarding** (`/onboarding`, D-034):
  - **Steps.** In plan order, each with its own hairline figure. Option cards replace dropdowns; lists are typed as chips with suggestions; every field has "don't know"; errors appear as pills.
  - **Documents and review.** In-place document upload with live reading status. The review shows readiness per task, targeted open questions and a plain-words summary with Edit links.
  - **Plan.** The plan step appears only when billing enforcement applies.
  - **Profile setup.** Uses the same components after onboarding.
- **Design system** (D-037):
  - Neutral tokens for light and dark (canvas, surface, panel, brand) and Geist / Geist Mono.
  - Mono section labels, square checkboxes, hint and error pills.
  - Settings in pill tabs inside one card.
  - Solid cards replace translucent ones across pages.
- **Figures** (`components/figures/`): a small isometric hairline kit and figures for the Home banner (campus with a flag that is raised once), the eight onboarding steps and empty states. They draw in once, then stay still; motion is off under reduced motion.

### Checks

- **Feature tests** (`FirstRunOnboardingTest`, 7):
  - plan order, with consent first;
  - nothing saved before consent;
  - section saves carry provenance `via: onboarding.situation`;
  - resume after reload;
  - targeted answers;
  - onboarding uploads use the document pipeline and never write the profile;
  - finishing requires consent;
  - the plan step only appears under enforcement.
- **Feature tests** (`MentorPanelTest`, 5): history and switching scoped to the student (another student's conversation is 403); attachment line round-trip; an attached document's text reaches the mentor's instructions for that turn while a foreign document's does not; composer upload and listing as JSON; the inbox built from the student's own records, with an urgent deadline first.
- **Browser smokes:** `smoke-onboarding.mjs` (a fresh student completes onboarding, including a real transcript read by the queue worker) and `smoke-mentor-panel.mjs` (ask from College lists with the real model; the conversation survives navigation and reload; an attached transcript's GPA is quoted with the "raw document text" caveat; history, new chat, hide/show, laptop overlay, phone full screen). The existing smokes were updated for the panel and the new form controls.
- **UI tour:** `tests/e2e/ui-tour.mjs` screenshots every main screen at desktop, laptop and phone widths, in light and dark. It fails on console errors, 5xx responses and sideways overflow.
- **Hydration:** the server-rendered HTML and the first client render match. The panel's open state and local times are decided after hydration.

### Limitations

- The Home "next step" uses simple rules from saved state (readiness, list size, applications, completeness); it does not rank by deadline yet.
- Conversation titles come from the SDK's automatic title; a new chat shows its first question until then.
- Pages other than Home, Onboarding, Profile setup and Settings received the shell, tokens and header changes but not a structural redesign.
- The hairline figures are illustrative only; they show no data.


## Post-plan — Redesign v2, a smarter mentor, specialists, and agent changes through approvals (2026-11-01)

Requested after the first redesign: an expanded-sidebar, minimalist UI with the mentor as its own panel; tools shown where they ran instead of in a top "thinking" bar; leaner, faster approval cards; a resizable mentor panel; visible specialists ("researcher and others"); and a mentor that is smarter and can change anything the student could change. Decisions D-038…D-042. This supersedes the shell, composer and timeline described in the previous section.

### Implemented behaviour

- **Shell and pages** (D-038):
  - An expanded, collapsible sidebar; a rounded content panel with a breadcrumb header; and a separate mentor panel, docked at ≥1280px and an overlay below that.
  - Every page was rebuilt on `components/page/kit.tsx`: college lists and compare, applications, approvals, tasks, documents, writing and essays, profile, settings, notifications and exports.
- **Mentor replies** (D-039):
  - **Order.** Text, tool rows and cards appear in the order they happened. Repeated tools merge into one row (e.g. "Searched the web ×3", with the queries listed); long finished runs fold into one line naming the tools. "Thinking…" shows only while nothing visible moves.
  - **Approval cards.** The card's data arrives inside the stream (`data-proposal`), so it renders immediately. The chat card is compact. Approving reloads the page behind it, so Home's deadlines and the list update in place.
  - **Streaming.** Text still streams token by token.
- **Resizable dock** (D-039):
  - Drag the panel's left edge; with the edge focused, arrow keys step it and Home/End jump to the limits; double-click resets.
  - The width is kept per browser and applied after hydration.
- **Model** (D-040): the mentor runs on Claude Sonnet 5.5 when an Anthropic key is configured, otherwise on `gpt-5.5`. Background agents keep the cheaper default.
- **Specialists** (D-041):
  - `researcher`, `college_scout`, `application_planner` and `writing_coach` are SDK sub-agents, offered in both modes.
  - Each gets its own card that streams its work, then folds to a one-line summary. Plan cards show which specialist worked on each step.
- **Agent changes through approvals** (D-042):
  - **Profile:** any field.
  - **College list:** add; change deadlines, tier, notes, status, portal or cycle; remove; start an application; add an official source.
  - **Applications:** status (with evidence), notes, portal, system, checklist steps and new steps.
  - **Document values:** accept, edit or reject values read from uploads.

  The mentor can also pause, resume, retry or cancel background plans; cancelling needs the student to confirm. The source page is read by a queued job after approval.

### Checks

- **Feature tests:**
  - `MentorReplyPartsTest` (5): stored turns come back in order with a specialist's task and report; another student's proposal is never filled in; the stream sends card data only for the student's own proposal; specialists are offered in both modes on the mentor's model; without web search only the offline specialists remain.
  - `AgentCrudProposalsTest` (19): deadlines reach the tracker; remove; start an application; stale and all-stale outcomes; reject; another student's college; Ask mode excludes the tools; checklist rules; submitted needs evidence; source URL policy at proposal and approval time; queued reads.
  - `AiModelResolverTest` (4).
  - `AgentDocumentAndTaskToolsTest` (12): reading pending document values; accept (including an edited value) and reject; stale when reviewed elsewhere, edited by hand or the document was replaced; foreign values; tool lists per mode; the approvals context line; pause, resume, retry and cancel with confirmation; another student's plans.
- **Full suite:** 361 passed (2479 assertions). `tsc`, `eslint .` and `prettier --check resources/` are clean; the client and SSR builds succeed.
- **Browser smokes (real model):**
  - `smoke-mentor-inline.mjs`:
    - tools are shown before the card, which appears ~2.3s before the reply ends with no extra request;
    - text streams in;
    - the Researcher card streams 43 partial updates and then folds;
    - a reopened chat keeps the order;
    - drag resize is remembered.
  - `smoke-dock-resize.mjs`: drag, reload, keyboard, double-click reset, and a single click that does not resize.
  - `smoke-agent-crud.mjs`: the model proposes a deadline change and starting an application; approving in the chat card changes the saved records; nothing changes before approval.

### Limitations

- A specialist's live output is its text; its own web searches are not shown as separate rows.
- Earlier turns' web searches are not replayed to the model (the SDK does not resend provider tool calls). The mentor is told not to treat their absence as proof that it never searched.

### Follow-up — background replies, live specialists, deadline tracker (2026-11-01, evening)

- **Background replies** (D-043):
  - A refresh or navigation mid-reply no longer blocks or loses it. The reply runs in a queued job, is followed with short reads, comes back after a refresh, and is always saved.
  - Verified in the real dev app (Chrome):
    - navigation and a remount mid-reply;
    - the reply finishing after the remount;
    - an approval without a page reload (badge 2 → 1, same DOM);
    - a full reload showing the Researcher card ("8 searches · 76 results"), the approved deadlines card and every tool step.
- **Tools and specialists** (D-043):
  - Quick tools report as soon as they finish, even while a specialist in the same step works; the proposal card showed during the Researcher's run.
  - The Researcher's searches stream into its card.
  - After its report, the mentor proposed the deadlines it found for approval.
- **Deadline tracker** (D-044):
  - `/deadlines` with calendar and list views, rolling admission, and a "needs a deadline" panel with an editor that requires at least one deadline.
  - Home asks for missing deadlines; the mentor fills them through approval.
  - Verified in the dev app: added Regular Decision Jan 1, 2027 for a college ("3 colleges need a deadline" → 2), list view, and the "Ask the mentor to find them" composer prompt.
- **Checks.**
  - **PHP:** 375 tests pass, including `MentorTurnsTest` (7), `DeadlineTrackerTest` (5) and `MentorReplyPartsTest` (7).
  - **Frontend:** `tsc`, `eslint .` and `prettier --check resources/` are clean; the client and SSR builds pass.
  - **New smokes:** `smoke-mentor-turns.mjs`:
    - navigate while a specialist works;
    - refresh mid-reply and the reply comes back;
    - after it finishes, the reply is stored with the specialist's activity.
- **Dev performance note.** On the founder's machine each request to `php artisan serve` took 1.3–2.6s because `opcache.enable_cli=0` in `C:\php-8.5\php.ini`: every request recompiles every PHP file. Setting `opcache.enable_cli=1` and restarting `composer run dev` is recommended; it is not changed by the app.

### Follow-up — provider failover, chat restore fixes, the extension end to end (2026-11-01, night)

- **Provider failover** (D-045). The Anthropic account ran out of credits mid-session. Agents now go on to the next provider with a key (the mentor to OpenAI `gpt-5.5`) when a provider cannot answer, and each failover is logged. Verified live: a mentor reply failed over from Anthropic to OpenAI and answered correctly.
- **Chat restore.**
  - `GET …/turns/active` now returns the stored conversation with the running turn. A refresh no longer asks `/dashboard/chat/{id}/messages` for a new conversation that is not stored yet: that request answered 403 and showed in the console.
  - A new conversation is stored when its first reply ends. If that first reply was stopped, or failed before anything was stored, the next message or a Retry was refused ("You do not have access to this conversation"). Now:
    - `MentorTurnService::conversationFor` starts a new conversation for such an id. Only the student's own turns count.
    - A finished turn reports `conversation_stored`, and the chat keeps a conversation in this browser only once it is stored.
- **Browser extension, end to end.**
  - `smoke-phase10`: the extension is installed in an isolated Chrome. It links with a one-time code, binds the form tab, observes 7 fields (never passwords, hidden fields or uploads) and prepares a 6-field fill. Approved in the web app, it typed each value once and read it back, and nothing was submitted.
  - It also passed these checks:
    - a fill approved for an older version of the page was not run;
    - another tab or origin got nothing;
    - revoking the device signed the panel out.
  - Two steps failed on the test itself, both now fixed:
    - a selector for the "Browser assistance" section that the redesign changed;
    - a click on the panel's Approve button while it was still disabled.
  - **Against the dev app** (`localhost:8000`, the demo account, the Berea College application): prepared the form plan (20 fields), linked the extension with a link code from Settings, then observed the practice portal and prepared a 2-field fill (legal name, email). Approved on the application page; both steps showed "Typed and checked" in the panel and in the web app, and the form was not submitted. The two demo devices were revoked afterwards.
- **Checks.** PHP: 378 tests pass, including the new `MentorTurnsTest` cases. Frontend: `tsc` and `prettier --check resources/` are clean.

### Follow-up — mentor browser control (2026-11-01, late night; D-046)

- **What changed.** The founder wanted the mentor to work in their own browser: go to the portal, ask them to log in, then read and fill the pages itself, without the plan → observe → approve-each-fill flow.
  - The side panel now offers "Let the mentor use this browser" and shows a live activity list, an Allow/Deny card for final actions, and Stop.
  - In Agent mode the mentor has five Browser tools: open, read, fill many fields at once, click, press a key.
  - The approve-each-value flow from Phase 10 is still there ("Use this tab").
- **Server.**
  - `BrowserAgentService` and the `agent-sessions`/`agent-commands` API (contract `browser-extension/src/shared/agent.ts`).
  - `PageFormatter` sends the page to the mentor as compact, untrusted text.
  - A context line says whether the mentor has control.
  - While control is on, a reply may take up to 40 model calls.
  - The queue's `retry_after` defaults to 960s, longer than a mentor turn.
- **Extension.**
  - `control.tsx` runs the command loop and the activity list. If a tab predates the extension, the content script is injected again (`scripting` permission).
  - `content.ts` reads pages (ids, labels, values except passwords, options, validation messages, login/CAPTCHA detection) and fills, clicks and presses keys. Final controls (submit/pay/sign/certify…) go through Allow.
- **Practice portal.** `tests/e2e/site/lakeside/portal/` has a login page, personal details, education, and a review page with a certify box and Submit.
- **Checks.**
  - **`smoke-browser-agent` 6/6** (real model, real extension, fresh DB):
    1. The student gave the mentor the portal tab.
    2. Asked in Agent mode, the mentor opened the portal, saw the login, asked the student to log in, and the password field stayed empty.
    3. After the student logged in, it read, filled, saved, filled and saved again. Everything the profile supports was saved on the portal (name, email, Nigeria, Nigerian citizen, A-Levels, 2027, Economics, aid yes). It stopped on the review page and did not submit.
    4. Asked to submit, its click on the certify box raised the panel's Allow card; Deny → nothing ticked or submitted, and the mentor said so.
    5. After Stop, its next read was refused and it said control was stopped.
  - **`smoke-phase10` 9/9** on the refactored panel.
  - **PHP:** `BrowserAgentTest` (17) passes; the full suite passed (395) before the last wording changes, and the related tests pass after them.
  - **Extension builder's checks:** content script 51/51 and panel loop 37/37 (scratchpad scripts).
- **Notes.** The dev stack (`composer run dev`) was found stopped at the end of this session: restart it to use the app. Reload the unpacked extension in Chrome after `npm run build:extension` (it now asks for `scripting` too).
- **Two fixes found by the browser-control smoke.**
  - **Shared rate limit.** Laravel keys an unnamed `throttle:N,M` by the user alone, so every throttled route shared one counter. The chat's reads (`throttle:600,1`) used up "send a message" (`throttle:30,1`), and the next message after a long reply failed with 429, which the chat showed as a plan limit. `ThrottleRequestsPerRoute` (registered as `throttle`) adds the route to the key. Named limiters are unchanged. Tested in `RouteThrottleTest`.
  - **Name and email.** The mentor sometimes filled a portal without the student's name and email: only `GetStudentAdmissionContextTool` had them. `ReadStudentProfileTool` now returns the account's name and email with "identity" and "all", and `agent.md` says to load the profile once before filling. Tested in `ReadStudentProfileToolTest`.
  - Full PHP suite after both: 398 passed.
