# AdmitMe Phased Architecture and Build Plan

Version: 1.0  
Prepared: 31 October 2026  
Stack: Laravel 13, Laravel AI SDK (`laravel/ai`), Inertia.js, React, TypeScript

## 1. Product context and first success

AdmitMe helps students preparing their first undergraduate applications: students finishing high school, high school graduates, and gap-year applicants. International, domestic, and first-generation students share the same core workflow, with requirements adapted to their circumstances.

The founder is the first real user. Build a useful application journey before optimizing acquisition, monetization, or seasonal retention.

The first success is: a student creates a verified profile, finds suitable colleges, approves a shortlist, understands requirements, prepares materials, and gets a real application ready for submission. Browser assistance extends this journey when available.

The coding agent must preserve these decisions:

- Support the full undergraduate application journey, including college-specific portals. Common App is one supported system, not the product boundary.
- Chat and manual screens operate on the same underlying records and business services.
- Ask mode answers and performs authorized read-only lookups. It does not change student/application data or launch persistent background workflows. Conversation logging still persists.
- Agent mode plans, delegates, tracks, and performs permitted work. It is an operator, with visible progress and results.
- Approval required is the initial Agent-mode policy. Student memory changes, adding researched colleges to saved lists, application edits, uploads, and consequential external actions require review.
- Show approvals inside chat and the workspace; later show the same approvals in the extension side panel.
- Manual student edits remain available and use the same validation, revision, and audit mechanisms.
- Memory must be traceable, correctable, scoped to the student, and loaded selectively.
- The extension is a later implementation phase. The core app must work with clearly identified manual browser steps first.

Initial exclusions: grade 9–11 development coaching, graduate admissions, transfers, acceptance-probability predictions, automatic model training, payments/subscriptions, email integration, and Chrome Web Store publication. These can be reconsidered after the first working journey.

## 2. Instructions for the coding agent

1. Read this plan, existing repository instructions, and actual source before editing. No source repository was available when this plan was written; proposed paths and schemas must be reconciled with the real app.
2. Inspect `composer.lock` and frontend dependencies. Verify installed SDK interfaces against that version. Do not assume every feature in the latest documentation exists locally.
3. Preserve existing auth, database, component conventions, and working features. Do not recreate a working Laravel application or migrate databases solely to match this document.
4. Implement phases in dependency order. Complete a phase's behavior and verification gate before relying on it in later phases. Deliver working slices, not disconnected scaffolding.
5. Treat class names, endpoints, token budgets, and schema layouts here as proposed application contracts, not claims that Laravel provides them automatically.
6. Record decisions, completed phases, validation, and unresolved blockers in `docs/admitme/build-status.md`. Record material architecture choices in `docs/admitme/decisions.md`.
7. Use synthetic data in fixtures and model evaluations. Keep actual student documents, credentials, and private profile data out of git.
8. Verify consequential behavior with meaningful integration tests. Avoid tests that merely mirror trivial implementation details.
9. If an external capability is unavailable, make its status explicit and provide a manual step. Never present a placeholder browser action or model response as completed work.

Recommended per-phase handoff: outcome, changed areas, checks run, known limits, and next dependency. Resolve routine choices from repository conventions; surface only genuine blockers.

## 3. Architecture boundaries

| Layer | Responsibility | Authority |
| --- | --- | --- |
| Inertia/React workspace | Chat, onboarding, lists, documents, writing, task progress, approvals | Displays state and submits authorized requests |
| Laravel domain services | Profile edits, college lists, applications, proposals, exports | Authoritative validation and persistence |
| Task orchestrator and queues | Dependencies, dispatch, retries, cancellation, budgets, recovery | Controls execution and durable task state |
| Laravel AI SDK agents | Reasoning, tool selection, research, writing feedback, action proposals | May invoke only tools allowed for this run |
| Memory and retrieval services | Relevant student facts, task context, source passages | Reads authorized records and constructs context |
| Browser bridge | Browser sessions, snapshots, commands, receipts | Validates approved browser execution |
| Chrome extension | Observes supported pages and executes predefined commands | Acts only within granted access and bound session |

Laravel owns truth and permissions. Models propose interpretations and actions. The orchestrator, memory architecture, application knowledge base, browser bridge, and export system are application code we build.

### Agents

| Agent | Input | Output | Limits |
| --- | --- | --- | --- |
| Mentor | Student request, selected memory, task progress | Answer, proposed plan, delegated work, progress explanation | No unrestricted writes; cannot invent successful execution |
| Researcher | Explicit question, constraints, source references | Findings, citations, unresolved conflicts | Research results do not automatically become verified facts |
| Writing coach | Student-authored text, prompt, relevant background, institution policy | Permitted feedback and suggested corrections | Preserve authorship; avoid invented experiences or prohibited drafting |
| Application agent | Page snapshot, confirmed facts, requirements | Structured field mappings and action proposals | Approval-required execution stays at top level or in our explicit workflow |

Document extraction, retrieval, calculations, exports, and database operations begin as tools/jobs rather than additional agents. Add another reasoning agent only when evaluations show a distinct need.

### SDK constraints to account for

The documentation reviewed for this plan describes isolated sub-agent calls, tools, structured output, conversation persistence, streaming, queueing, and approval support. Specifically:

- Child agents do not inherit the parent's conversation history.
- Approval-required tools currently need top-level handling; the documented sub-agent approval flow has a limitation.
- Conversation continuation does not itself check ownership; application authorization is required.
- Conversation storage does not supply our complete long-term memory or task orchestration.
- Prompt caching is provider-dependent and is separate from memory retrieval.

Verify these behaviors against the installed SDK before implementing adapters. Preserve resumable tool history when approvals pause a run.

## 4. Proposed code and instruction layout

Use existing repository naming where equivalent components already exist. Create components in the phase that needs them, rather than generating every class upfront.

| Path | Purpose |
| --- | --- |
| `app/Ai/Agents/` | Mentor, Researcher, WritingCoach, ApplicationAgent |
| `app/Ai/Tools/` | Thin, authorized adapters to domain services |
| `app/Ai/Context/` | ContextBuilder, InstructionLoader, context budgets |
| `app/Domain/Students/` | Verified facts, preferences, revisions, onboarding |
| `app/Domain/Documents/` | Private files, versions, extraction and review |
| `app/Domain/Knowledge/` | Sources, requirements, passage retrieval, freshness |
| `app/Domain/Applications/` | Saved colleges, applications, documents, writing |
| `app/Domain/Approvals/` | Change proposals, decisions, validated application |
| `app/Domain/Tasks/` | Orchestrator, dependencies, runs, events, budgets |
| `app/Domain/Browser/` | Sessions, snapshots, command validation and results |
| `app/Domain/Exports/` | Authorized snapshots, archives, download lifecycle |
| `app/Jobs/` | Queued extraction, research, agent runs, exports |
| `resources/ai/AGENTS.md` | Short shared runtime rules and context index |
| `resources/ai/agents/mentor.md` | Mentor responsibilities and delegation |
| `resources/ai/agents/researcher.md` | Evidence and research rules |
| `resources/ai/agents/writing.md` | Institution-aware writing assistance |
| `resources/ai/agents/application.md` | Form interpretation and browser rules |
| `resources/ai/guides/` | Detailed task procedures, loaded on demand |
| `resources/js/pages/` | Workspace pages following repository conventions |
| `resources/js/components/admitme/` | Reusable cards, editors, task and approval UI |
| `extension/` | Later, separately built Manifest V3 extension |
| `docs/admitme/` | This plan, decisions, build status, evaluation notes |

`resources/ai/AGENTS.md` contains runtime instructions for the product's agents. It is separate from any root `AGENTS.md` used by coding tools. Our code explicitly loads runtime Markdown into agent instructions; filenames alone do not activate it.

Keep shared rules short. Include permission boundaries, evidence rules, memory update rules, truthful reporting, and pointers to optional guides. Load one role file per agent invocation. User uploads and retrieved pages never become trusted instruction files.

## 5. Memory and data contracts

### Authoritative records and projections

Store student truth in typed, versioned database records. Provide generated, readable views through authorized tools:

| Logical view | Content |
| --- | --- |
| `profile.json` | Academics, curriculum, identity details, verification state |
| `preferences.json` | Budget and currency, destinations, program interests, constraints |
| `applications/{id}.json` | Requirements, progress, decisions, blockers |
| `documents/index.json` | Available document versions and extraction references |
| `conversations/{id}/summary.md` | Derived discussion summary with message references |

These paths describe tool-visible projections, not a second writable source of truth. Agents cannot edit arbitrary paths or overwrite a student's complete memory file.

A fact includes its field key, typed value, scope, source/evidence references, status, revision, and timestamps. Distinguish student-confirmed information, extracted candidates, inferences, conflicts, and superseded values. Extraction confidence is not verification.

Preserve original curricula, grades, currencies, and grading scales. Any derived conversion needs an explicit method and source. Do not invent a universal GPA equivalence.

### Context assembly

`ContextBuilder` assembles stable shared instructions, one role, a compact task brief, relevant facts at a recorded revision, recent messages, an attributed summary where needed, and selected evidence passages.

Initial configurable target: approximately 8,000 tokens of assembled content for ordinary tasks, subject to the selected model's actual limits. Account separately for tool definitions, output reserve, and longer specialist tasks. Mandatory permissions and active tool/approval history must survive trimming. Never slice a tool call away from its result.

Keep an application-defined compact history implementation or adapter if needed. Verify SDK behavior before choosing custom conversation messages versus built-in history loading; do not accidentally disable or duplicate history.

Expose bounded read tools such as `ReadStudentSection`, `ReadApplication`, and `SearchKnowledge`. Derive the student identity and permitted scope from the authenticated run, never from model-selected ownership arguments.

### Knowledge base

Separate shared admissions knowledge from private student material. Store original content in private or appropriate shared storage, metadata in the database, and searchable passages linked to originals.

Each admissions assertion needs institution/program/intake scope, source URL, checked date, application cycle, and review status. Model responses and semantic matches are not authoritative source records. Store exact dates, costs, currencies, conditions, and document requirements in structured fields where possible. Preserve conflicting sources for review.

Start with the existing database's full-text/search capabilities and exact metadata filters. Add embeddings when retrieval evaluations justify them. Authorization filters apply before retrieval, not just after results are returned. Cache and derived indexes inherit ownership and deletion rules.

### Caching

- Keep stable rules and tool definitions stable for provider prompt caching; keep timestamps and student updates out of that prefix where possible.
- Cache memory projections by student ID, section, revision, and schema version. An approved edit or manual edit changes the revision.
- Cache shared college information by institution/program/intake/source version, with freshness checks and expiry.
- Include authorization scope in private retrieval caches. Do not share private answer caches between students.
- Summaries remain derived context. Verified facts win over an outdated summary; regenerate affected summaries or mark them stale.

## 6. Execution, permissions, and state contracts

### Modes and approvals

Ask mode exposes only permitted read tools and synchronous read-only lookups. Switching modes affects new requests. Existing Agent-mode runs remain visible and can be paused or cancelled explicitly; switching the selector must not silently cancel them.

Agent mode can plan, launch specialists, and create proposals. Initial approval policy requires review before agent changes to canonical student facts, saved college lists, application data, uploads, and consequential external actions. Research and task-local drafts may be saved as artifacts without changing the canonical profile or saved list.

A proposal contains actor/task ownership, action type, exact payload, before/after values, evidence, target resource revisions, expiry, payload hash, and status. The student can approve, edit, reject, or select items from a batch. Editing creates a new proposal revision. A decision authorizes the exact reviewed payload, not arbitrary later actions.

Apply approved internal changes transactionally through the same services used by manual edits. Record the decision and resulting receipt. Use idempotency keys and resource revisions. A stale proposal returns to review rather than overwriting a newer student edit.

For external browser actions, record intended execution before dispatch, then reconcile the observed result. A database transaction cannot make a remote website action atomic. An uncertain result needs inspection; never blindly retry a submission or payment.

Use SDK approval/resume support where verified, or an explicit durable application workflow. Avoid two disconnected approval systems. A pending approval must appear as pending to the orchestrator and UI, never as a successful tool result.

### Task and agent run states

| Entity | States |
| --- | --- |
| Task | queued, running, awaiting_approval, blocked, paused, completed, failed, cancelled |
| Agent run | queued, running, waiting_for_tool, awaiting_approval, completed, failed, cancelled |
| Proposal | pending, approved, rejected, expired, stale, applied, failed |
| Document processing | queued, processing, needs_review, ready, failed, superseded |
| College choice | suggested, shortlisted, applying, archived |
| Application | preparing, ready_for_review, ready_to_submit, submitted, decision_received, closed |

Store events and transition reasons. A suggestion is not a saved shortlist entry; a prepared form is not a submitted application. Submitted status needs a submission receipt or explicit student confirmation, with the evidence type recorded.

The orchestrator owns dependency resolution and queue dispatch. Store task state in the database; use Redis or another configured queue/cache backend for execution and locks. Do not depend on worker process memory or an active chat stream.

### Delegation and concurrency

Construct a handoff server-side with task/run IDs, the authorized student scope, goal, selected profile revision, evidence references, output schema, allowed tools, and budget. The model cannot choose a different student scope.

Specialists return structured findings, evidence references, proposed changes, blockers, and usage. They do not receive the full parent conversation automatically. Workers independently enforce tool permissions.

Initial limits: up to three independent research jobs per student and one active writer per browser session. Make global concurrency and provider limits configurable. Begin with one active Mentor turn per conversation to avoid conflicting plans. Different student conversations may run concurrently within global limits.

Use dependency-aware queue jobs; do not assume exposing sub-agents as SDK tools creates parallel execution. Long-running or independently retriable work should use explicit orchestrator jobs. Short, bounded sub-agent calls may use SDK delegation.

Before persistence, compare the task's relevant input revisions with current revisions. If the budget changed during college research, re-evaluate affordability before recommending or applying stale results. Cancellation stops future dispatch; report any already-executed external actions accurately.

## 7. Build phases

### Phase 0 — Audit the existing app and create a runnable baseline

**Depends on:** existing repository access.

**Build steps**

1. Inventory routes, auth, frontend layout, database, queue/cache configuration, installed SDK version, and existing features. Run available baseline checks.
2. Record available provider capabilities and configurable models. Keep credentials on the backend and out of logs.
3. Establish local database migrations, private file storage, and a working queue worker. Reuse existing infrastructure.
4. Create the build-status and decisions documents. Record which parts of this plan already exist.

**Completion gate:** the actual app runs locally; a queued job completes; baseline failures are documented; the SDK integration approach matches installed interfaces.

### Phase 1 — Domain records, permissions, and workspace shell

**Depends on:** Phase 0.

**Build steps**

1. Add the first required entities: student profile, facts/evidence, college choices, applications, tasks, and audit events. Extend existing entities instead of duplicating them.
2. Introduce revisions and explicit ownership from the beginning. Add policies for all student-owned reads and writes, including conversations.
3. Build navigation for Chat, Profile, Colleges, Applications, Documents, Writing, and Tasks. Screens can be minimal but must represent genuine records.
4. Provide normal manual profile/list editing through shared domain services. Add realistic synthetic fixtures.

**Completion gate:** a student can save and reopen a profile and list manually; two users cannot access each other's data; stale conflicting edits are detected.

### Phase 2 — Memory services and a useful Ask-mode Mentor

**Depends on:** Phase 1.

**Build steps**

1. Add trusted runtime instruction files and an explicit InstructionLoader.
2. Implement MemoryReader, MemoryService, and ContextBuilder with scoped access, provenance, revisions, and configurable budgets.
3. Build the Mentor's Ask mode with persisted conversations and streaming using the installed SDK's supported transport.
4. Add summaries with message references and selective fact loading. Provide read tools without canonical mutation tools.
5. Add revision-based context caching and context-size/usage instrumentation.

**Completion gate:** the Mentor can accurately describe saved facts, identify missing information, and maintain continuity after reload. Ask-mode attempts to mutate data are rejected server-side. Corrected facts supersede older summaries. Active tool histories remain valid.

### Phase 3 — Approval proposals and safe shared mutations

**Depends on:** Phase 2.

**Build steps**

1. Implement proposal creation, diff rendering, evidence display, expiry, decisions, and transactional internal application.
2. Add inline Approve, Edit, Reject, and batch selection controls in chat and a pending-approval inbox.
3. Connect MemoryService and college-list mutations to the proposal system for agent-originated writes.
4. Implement revision checks, receipts, idempotency, and pause/resume semantics. Keep approval state durable across refreshes.

**Completion gate:** approving a profile edit changes exactly the reviewed fields once. Rejection changes nothing. Approving six of ten proposed colleges adds exactly six without duplicates. A concurrent manual edit makes a conflicting proposal stale.

### Phase 4 — Documents and progressive onboarding

**Depends on:** Phase 3.

**Build steps**

1. Add documents, immutable file versions, hashes, extraction jobs, page/source references, and candidate extracted values. Set upload type/size limits and processing states.
2. Preserve original files in private storage. Process text PDFs and supported office formats; add OCR behind a configurable capability for scanned files. Expose failures and manual entry.
3. Build a review screen showing extracted information beside the source. Confirmation uses the same verified-fact update flow; conflicts require resolution.
4. Build resumable onboarding: situation and intake, curriculum, interests, destinations, budget/currency and aid needs, optional uploads, review, then targeted missing questions.
5. Measure completeness against the current task. Allow unknown/not available and manual entry rather than blocking every student on every field.
6. Replacements mark affected extractions, context, and dependent tasks for rechecking; they do not silently overwrite confirmed grades.

**Completion gate:** uploading a transcript leads to reviewable, sourced academic candidates; confirmed values reach the profile. A bad scan fails visibly. Replacing a transcript preserves history and flags conflicts. Onboarding resumes after reload.

### Phase 5 — Knowledge base and source-backed research

**Depends on:** Phases 2 and 4.

**Build steps**

1. Add sources, versions, passages, scoped college/program/intake requirements, and private student-document retrieval.
2. Implement authorized ingestion, passage search, exact metadata filters, source display, and freshness states. Start with colleges relevant to the founder.
3. Connect the Researcher to provider-supported search/fetch tools or another explicitly configured source adapter. Do not assume every provider supports native web tools.
4. Require research outputs to include evidence references and unresolved questions. Highlight conflicting or stale information.
5. Keep exact deadlines and costs structured, including timezone, currency, and conditions where known. Do not invent missing values.

**Completion gate:** questions about selected colleges produce supported answers with inspectable sources and cycle labels. Private document results remain isolated. Missing or conflicting requirements are shown as unresolved rather than guessed.

### Phase 6 — College discovery and the application workspace

**Depends on:** Phases 3–5.

**Build steps**

1. Implement constraint-based recommendations with transparent reasons: eligibility evidence, interests, estimated costs, and aid conditions. Mark incomplete affordability assessments.
2. Save research suggestions as task artifacts. Let students approve selected colleges into saved lists or add colleges manually.
3. Add compare/filter views and suggested → shortlisted → applying transitions.
4. Create applications with requirement checklists, writing prompts, document assignments, deadlines, blockers, and next actions.
5. Represent third-party work, such as recommendations, with an owner and status; never mark it complete because the student requested it.
6. Until browser assistance exists, provide portal links and manual steps with explicit student-reported completion.

**Completion gate:** the founder can build a defensible shortlist, approve it, and open an application showing real requirements and next steps. Chat and manual screens show identical state.

### Phase 7 — Agent mode and durable task orchestration

**Depends on:** Phases 3 and 6.

**Build steps**

1. Add Ask/Agent selection and the initial approval-required policy. Enforce tool permissions on the backend for every run.
2. Implement the orchestrator with saved task dependencies, dispatch, events, budgets, blocked states, retries, cancellation, and recovery.
3. Connect Mentor planning to validated task schemas. Laravel validates and schedules the plan rather than executing arbitrary model instructions.
4. Build the live task panel: goal, subtasks, agent, status, results, sources, changes, approvals, and controls. Poll persisted events initially; reuse supported streaming/broadcasting where available.
5. Allow independent work to continue during a pending approval. Return actionable feedback to the Mentor when workers finish or fail.
6. Resume conversations and task views without requiring the original HTTP stream to remain open.

**Completion gate:** “Research affordable options and prepare a shortlist” creates visible tasks, produces sourced proposals, waits for review, and applies approved changes. Refreshing does not lose work or duplicate execution. Switching to Ask does not silently terminate an existing run.

### Phase 8 — Specialist delegation, concurrency, and writing support

**Depends on:** Phase 7.

**Build steps**

1. Add the explicit delegation envelope and structured result contracts. Integrate bounded SDK sub-agent calls and queued specialist jobs where each fits.
2. Enforce per-student/global concurrency, provider rate limits, run budgets, dependency ordering, and revision-aware result acceptance.
3. Add the Writing coach, a student-owned writing editor, prompt/word-limit tracking, immutable writing versions, and institution-specific assistance policies.
4. Add the Application agent as a planner against saved requirements and synthetic form snapshots. Keep it useful for manual preparation before the extension exists.
5. Distinguish candidate suggestions, model judgments, and deterministic validation results. Add a separate verifier agent only if measured failures justify it.

**Completion gate:** independent research jobs run within configured limits and return attributable results. A changed budget causes affected work to be re-evaluated. Writing feedback preserves source text and authorship. Child agents cannot bypass scope or approval rules.

### Phase 9 — Exports and user-controlled data lifecycle

**Depends on:** Phases 4 and 6; full task integration follows Phase 7.

**Build steps**

1. Build ExportService through the same authorization layer, callable from chat or the workspace.
2. Produce versioned JSON, CSV lists/tasks, and ZIP bundles of selected documents and writing versions. Include schema version, export timestamp, source references, and a manifest of included or omitted items.
3. Capture a consistent database revision and immutable document versions so the archive is not a mixture of updates made mid-export.
4. Use queued export jobs, private temporary output, expiring authorized downloads, and cleanup. Exclude credentials, provider keys, browser tokens/cookies, and other users' data.
5. Implement correction and deletion workflows across original storage, passages/indexes, caches, and export artifacts. Define bounded retention for operational logs and backups.

**Completion gate:** an export reconciles with the selected student snapshot and file versions; another user cannot download it; expired links fail; deletion prevents retrieval from active derived stores.

### Phase 10 — Browser bridge and local extension

**Depends on:** Phases 3, 7, and 8. Can be postponed while manual application workflows remain useful.

**Build steps**

1. Implement a BrowserSession abstraction, snapshot schema, command queue, approvals, result receipts, and a simulated transport for deterministic local fixtures. A simulation must be labeled and cannot complete real application tasks.
2. Define command contracts such as `observe_page`, `fill_field`, `select_option`, and `click_supported_control`. Use packaged handlers and validated JSON, not model-generated JavaScript execution.
3. Build a separately bundled Manifest V3 extension with `manifest.json`, a React side panel, `background.ts`, `content.ts`, and versioned shared command types.
4. Add an explicit account-linking flow issuing scoped, revocable extension authorization. Bind sessions to student, device, and permitted tab/origin. Do not send college passwords or browser cookies to Laravel.
5. Use content scripts for DOM observations/actions, Chrome messaging internally, and authenticated HTTPS for backend communication. Begin with pending-command fetching while assistance is active; do not require a continuously alive background worker.
6. Persist session checkpoints server-side and necessary reconnect state in extension storage. Treat disconnection as waiting for browser, not successful completion.
7. Capture filtered snapshots with page revision/hash and temporary field IDs. Bind each command to session, tab, origin, snapshot, proposal revision, and idempotency key. On navigation or changed fields, observe again and revalidate.
8. Keep approval-required tools on the top-level Mentor or our explicit approval workflow. The Application agent returns proposals, not hidden approval-requiring child tool calls.
9. Execute approved commands once, read back the resulting values, and acknowledge observed results. Reconcile lost acknowledgements before retrying. Freeze actions with uncertain external effects for inspection.
10. Begin with local fixture forms, then one real supported portal with the founder. Add site adapters for custom widgets, frames, and validation. Do not claim all portals work.
11. Handle login, verification codes, CAPTCHA, payment, declarations, and final submission through explicit student handoff/approval as appropriate. Initial browser release leaves final submission to the student. Keep uploads manual until a supported, tested mechanism exists.
12. Test by loading the unpacked extension locally. Store publication and public distribution are a later decision.

**Suggested bridge endpoints**

| Endpoint | Purpose |
| --- | --- |
| `POST /api/browser/sessions` | Create an authorized browser session |
| `POST /api/browser/sessions/{session}/snapshots` | Submit a filtered observation |
| `GET /api/browser/sessions/{session}/commands` | Claim/fetch eligible commands with a lease |
| `POST /api/browser/commands/{command}/result` | Acknowledge an observed outcome |
| `POST /api/approvals/{proposal}/decision` | Apply the shared proposal decision flow |

Validate account-linking protocol, CORS, permissions, command leases, and local-development connectivity against actual browser behavior. The application agent never receives extension credentials.

MCP is optional. The initial bridge uses regular Laravel tools and an authenticated API. If interoperability later warrants MCP, expose the same validated services rather than introducing a second browser execution path.

**Completion gate:** a locally loaded extension fills a supported form after approval, verifies values, survives reconnects, rejects stale/wrong-tab commands, and does not repeat completed actions. The same approvals and task records appear in the web app and side panel.

### Phase 11 — Evaluate, harden, and complete the founder's journey

**Depends on:** Phases 0–9; include Phase 10 when browser assistance is enabled.

**Build steps**

1. Instrument each run with input revisions, instruction/model/tool versions, evidence references, tool outcomes, duration, usage/cost where available, and student corrections. Redact secrets and avoid unnecessary private-content logging.
2. Maintain deterministic regression fixtures for authorization, memory, stale approvals, recovery, and browser actions. Add a small replayable evaluation set for research and writing quality.
3. Measure source-supported answers, extraction corrections, wrong-field fills, completed tasks, false success reports, manual intervention, latency, and cost per completed workflow.
4. Turn actual failures into cases; compare prompt/tool/model changes before release. Feedback is reviewed and validated, not silently treated as model training or canonical memory.
5. Configure backups/restoration, queue monitoring, provider failure behavior, budget cutoffs, source expiry, and private-file retention. Do not replay side-effecting tools automatically during model failover.
6. Walk through the founder's real profile, shortlist, requirements, materials, reviews, and application preparation. Use a few invited testers only after this journey works.

**Completion gate:** the founder can get a real application ready, explain where important facts came from, inspect every agent change, recover interrupted work, and export their data. Browser execution, when enabled, is independently verified.

## 8. Minimum end-to-end regression scenarios

| Scenario | Required behavior |
| --- | --- |
| Ask-mode request says to change GPA | Explain or propose switching mode; no canonical write |
| Two students use the same document title | Retrieval, caches, exports, and conversations remain isolated |
| Transcript contains an ambiguous grade | Candidate remains unresolved; no invented verified grade |
| New transcript conflicts with confirmed facts | Preserve both sources and request resolution |
| Student manually changes budget during research | Affected work is re-evaluated before use |
| Student approves six of ten colleges | Exactly six distinct entries are saved |
| Approval is clicked twice or request is retried | One resulting internal action and one authoritative receipt |
| Model suggests writing directly to a database | Unauthorized tool/path is unavailable and rejected |
| A retrieved page tells the agent to ignore instructions | Treat the text as untrusted source content |
| Queue worker crashes and chat reloads | Persisted state resumes without duplicate effects |
| Source deadlines conflict or lack a cycle | Show the uncertainty; avoid falsely declaring readiness |
| Specialist hits its budget or provider fails | Visible partial result/blocker; no invented completion |
| Browser navigates after an approval | Reject stale commands and obtain a new observation |
| Browser action succeeds but acknowledgement is lost | Reconcile page state before any retry |
| Student cancels a run | Stop future work and report already-completed actions |
| An export runs while documents change | Archive uses its recorded snapshot and immutable versions |

## 9. Delivery milestones and first coding-agent prompt

| Milestone | Phases | Student outcome |
| --- | --- | --- |
| Trusted foundation | 0–3 | Editable profile, useful Ask chat, durable approvals |
| First useful application workflow | 4–6 | Reviewed documents, sourced shortlist, actionable application workspace |
| Working agent operator | 7–8 | Visible delegated work, bounded concurrency, writing and preparation support |
| Portable student data | 9 | Inspectable exports and data control |
| Local browser assistant | 10 | Approved, verified assistance on supported forms |
| Founder-ready release | 11 | A complete real journey with measured reliability |

Copy this into the coding agent with this file attached:

> Build AdmitMe incrementally using this phased plan. First inspect the existing Laravel 13 + Inertia/React app, repository instructions, installed `laravel/ai` version, and current features. Begin with Phase 0 and record findings in `docs/admitme/build-status.md`; preserve working code and existing conventions. Then implement dependency-ready phases in order, completing each verification gate before relying on it. Use shared domain services for chat and manual actions, server-enforced Ask/Agent permissions, versioned student memory, and durable approval proposals. Keep browser automation as a later phase and make manual handoffs honest. Report implemented behavior, meaningful checks, limitations, and the next phase. Do not invent SDK APIs or report simulated actions as real completion.

## 10. Official implementation references

Documentation reviewed on 31 October 2026. Check the installed package versions and current browser behavior before coding against a specific API.

- [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk): agents, context, tools, sub-agents, approvals, streaming, queueing, caching, and testing.
- [Laravel queues](https://laravel.com/docs/13.x/queues): background jobs, dependencies, retries, locks, and workers.
- [Laravel MCP](https://laravel.com/docs/13.x/mcp): optional tool interoperability.
- [Chrome content scripts](https://developer.chrome.com/docs/extensions/develop/concepts/content-scripts): observing and modifying permitted pages.
- [Chrome messaging](https://developer.chrome.com/docs/extensions/develop/concepts/messaging): communication inside the extension.
- [Chrome network requests](https://developer.chrome.com/docs/extensions/develop/concepts/network-requests): extension-to-backend requests and host permissions.
- [Chrome activeTab](https://developer.chrome.com/docs/extensions/develop/concepts/activeTab): temporary user-initiated page access.
- [Chrome service worker lifecycle](https://developer.chrome.com/docs/extensions/develop/concepts/service-workers/lifecycle): shutdown, persistence, and local extension loading.
- [MCP architecture](https://modelcontextprotocol.io/docs/learn/architecture): protocol boundaries and optional integration.
- [Yale admissions AI policy](https://admissions.yale.edu/ai-policy) and [Cornell application assistance policy](https://admissions.cornell.edu/policies/receiving-help-with-your-application): examples for institution-specific writing assistance. Recheck each target institution's own policy and cycle.
