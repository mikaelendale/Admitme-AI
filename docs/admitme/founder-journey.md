# Founder's journey checklist (Phase 11, step 6)

The plan's final gate is a walk-through with the founder's **real** profile and colleges. That cannot be done with synthetic data or by the coding agent. The synthetic rehearsal (all browser smokes, run back to back on the final code — see `build-status.md`) shows each step works. This checklist is for the real run. Use a production-like environment with real provider keys, the queue worker and the scheduler running.

Tick each item, and note anything wrong or confusing. Every failure becomes a test or an evaluation case ([`regression-scenarios.md`](regression-scenarios.md)).

## 1. Profile and documents

- [ ] Sign up, finish guided onboarding, and answer "don't know" where true. Reload mid-way: it resumes at the right section.
- [ ] Upload a real transcript. Each value read from it shows its page and quote. Accept, correct or reject each one, and say whether the corrections were reasonable.
- [ ] On the profile page, every fact says where it came from (you, a document, an approved proposal).

## 2. Colleges and requirements

- [ ] Ask the mentor (Ask mode) about fit and costs. Answers cite saved facts or sources, or say what is unknown.
- [ ] Run a shortlist plan (Agent mode). Approve some suggestions and reject others; exactly those are saved.
- [ ] For each college you really apply to, add its official admissions pages. Requirements appear with quotes. Disagreements and missing cycles are shown, not hidden.
- [ ] Ask an operator to verify each college's official domain (`php artisan admitme:institution-domain`) so its pages count as official.

## 3. Applications

- [ ] Move a college to "applying": one application opens with a checklist built from its requirements.
- [ ] Mark your own steps done, request a recommendation, and mark it received only when the portal shows it.
- [ ] Prepare the form plan, then check each field's basis label (saved data, check, AI judgment, suggestion).
- [ ] Write a draft in Writing and request feedback. Every comment quotes your words, and the college's policy is respected.

## 4. Browser assistance (if enabled)

- [ ] Build and load the extension (`browser-extension/README.md`), then link it from Settings.
- [ ] On the real portal, observe the form and prepare a fill. Write down which fields matched and which did not. Real portals will need site adapters: list them.
- [ ] Approve, watch it type, then check every value on the page yourself. Submit yourself.

## 5. Trust and recovery

- [ ] Open Approvals and Tasks: every agent change is listed with what it rested on.
- [ ] Stop the queue worker mid-plan, then start it again: the plan resumes without duplicates.
- [ ] Cancel a plan: nothing already done is undone, and the record says what finished.
- [ ] Export your data. Open the ZIP: the profile, applications (including `browser_fills`), documents and writing match what you see in the app.

## 6. Operator check after the run

- [ ] `php artisan admitme:metrics --days=7`: all integrity checks are 0. Note the correction rate, source-supported rate, wrong-fill rate, latency and tokens per completed goal.
- [ ] `php artisan admitme:health`: everything is OK.
- [ ] `php artisan admitme:eval all --save`: keep the file as the release baseline.

Invite a few testers only after this list passes.
