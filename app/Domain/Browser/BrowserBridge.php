<?php

namespace App\Domain\Browser;

use App\Domain\Approvals\ProposalService;
use App\Domain\Tasks\Handlers\ApplicationPlanHandler;
use App\Models\Application;
use App\Models\ApplicationFormPlan;
use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\BrowserSnapshot;
use App\Models\ExtensionLinkCode;
use App\Models\ExtensionToken;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Linking, sessions and observations for the browser extension. The extension never sends college
 * passwords or cookies; snapshots keep only visible, non-sensitive form fields with temporary ids.
 */
final class BrowserBridge
{
    private const LINK_CODE_MINUTES = 10;

    private const TOKEN_DAYS = 30;

    private const MAX_FIELDS = 200;

    /** Field types never sent to AdmitMe. */
    private const SENSITIVE_TYPES = ['password', 'hidden', 'file', 'credit-card'];

    public function issueLinkCode(User $student): string
    {
        $code = Str::upper(Str::random(4).'-'.Str::random(4));
        ExtensionLinkCode::query()->create(['user_id' => $student->getKey(), 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(self::LINK_CODE_MINUTES)]);

        return $code;
    }

    /**
     * Exchange a one-time code for a scoped, revocable token (returned once; only its hash is stored).
     *
     * @return array{token: string, token_id: int, student: string, expires_at: string}
     *
     * @throws ValidationException
     */
    public function link(string $code, string $deviceLabel): array
    {
        return DB::transaction(function () use ($code, $deviceLabel) {
            $link = ExtensionLinkCode::query()->where('code_hash', hash('sha256', Str::upper(trim($code))))->lockForUpdate()->first();

            if ($link === null || $link->used_at !== null || $link->expires_at->isPast()) {
                throw ValidationException::withMessages(['code' => 'That code is not valid any more. Create a new one in AdmitMe (Settings → Browser extension).']);
            }

            $link->update(['used_at' => now()]);
            $secret = Str::random(48);

            $token = ExtensionToken::query()->create([
                'user_id' => $link->user_id,
                'device_label' => Str::limit(trim($deviceLabel) ?: 'Browser', 120, ''),
                'token_hash' => hash('sha256', $secret),
                'abilities' => [ExtensionToken::ABILITY_SESSION],
                'expires_at' => now()->addDays(self::TOKEN_DAYS),
            ]);

            return ['token' => $token->id.'|'.$secret, 'token_id' => $token->id, 'student' => (string) User::query()->whereKey($link->user_id)->value('name'), 'expires_at' => $token->expires_at->toIso8601String()];
        });
    }

    public function resolveToken(?string $bearer): ?ExtensionToken
    {
        if (! is_string($bearer) || ! str_contains($bearer, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $bearer, 2);
        $token = ExtensionToken::query()->find((int) $id);

        if ($token === null || ! hash_equals($token->token_hash, hash('sha256', $secret)) || ! $token->isUsable()) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $token;
    }

    /**
     * @throws ValidationException
     */
    public function startSession(ExtensionToken $token, string $origin, int $tabId, ?string $applicationId, string $transport = 'live'): BrowserSession
    {
        $origin = $this->normalizeOrigin($origin);

        if ($applicationId !== null && ! Application::query()->where('user_id', $token->user_id)->whereKey($applicationId)->exists()) {
            throw ValidationException::withMessages(['application_id' => 'That application is not one of yours.']);
        }

        return BrowserSession::query()->create([
            'user_id' => $token->user_id,
            'extension_token_id' => $token->id,
            'application_id' => $applicationId,
            'transport' => $transport,
            'origin' => $origin,
            'tab_id' => $tabId,
            'status' => 'active',
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Record a filtered observation. Commands planned against a different page structure become stale.
     *
     * @param  list<mixed>  $fields
     *
     * @throws ValidationException
     */
    public function recordSnapshot(BrowserSession $session, string $url, array $fields): BrowserSnapshot
    {
        $origin = $this->normalizeOrigin($url);

        if ($origin !== $session->origin) {
            throw ValidationException::withMessages(['url' => 'This session is for '.$session->origin.'; observe a page there or start a new session.']);
        }

        $clean = [];

        foreach (array_slice($fields, 0, self::MAX_FIELDS) as $field) {
            if (! is_array($field) || ! is_string($field['field_id'] ?? null) || preg_match('/^f\d{1,4}$/', $field['field_id']) !== 1) {
                continue;
            }

            $type = Str::lower((string) ($field['type'] ?? 'text'));

            if (in_array($type, self::SENSITIVE_TYPES, true)) {
                continue;
            }

            $clean[] = [
                'field_id' => $field['field_id'],
                'label' => Str::limit(trim((string) ($field['label'] ?? '')), 200, ''),
                'name' => Str::limit((string) ($field['name'] ?? ''), 120, ''),
                'type' => $type,
                'value' => Str::limit((string) ($field['value'] ?? ''), 2000, ''),
                'options' => array_values(array_map(fn ($o) => Str::limit((string) $o, 200, ''), array_slice((array) ($field['options'] ?? []), 0, 100))),
                'required' => (bool) ($field['required'] ?? false),
            ];
        }

        // The page's structure (not its values) identifies it: same fields, same hash.
        $hash = hash('sha256', json_encode(array_map(fn (array $f) => [$f['field_id'], $f['label'], $f['name'], $f['type'], $f['options']], $clean)));

        return DB::transaction(function () use ($session, $url, $origin, $clean, $hash) {
            $snapshot = BrowserSnapshot::query()->create(['browser_session_id' => $session->getKey(), 'user_id' => $session->user_id, 'url' => Str::limit($url, 2048, ''), 'origin' => $origin, 'page_hash' => $hash, 'fields' => $clean]);
            $session->forceFill(['latest_snapshot_id' => $snapshot->getKey(), 'status' => 'active', 'last_seen_at' => now()])->save();

            // Commands planned on another page structure must not run here.
            BrowserCommand::query()
                ->where('browser_session_id', $session->getKey())
                ->whereIn('status', ['pending', 'leased'])
                ->get()
                ->filter(fn (BrowserCommand $c) => ($c->payload['page_hash'] ?? null) !== $hash)
                ->each(fn (BrowserCommand $c) => $c->update(['status' => 'stale', 'status_reason' => 'The page changed after this was approved. Observe again and approve a new fill.']));

            return $snapshot;
        });
    }

    /**
     * Prepare (not perform) a fill: values from the student's saved data, matched to the observed fields,
     * as an approval proposal bound to this session, snapshot, tab and origin.
     *
     * @throws ValidationException
     */
    public function proposeFillFromPlan(User $student, BrowserSession $session, string $actor = 'browser-extension', ?string $conversationId = null): Proposal
    {
        $snapshot = $session->latestSnapshot;

        if ($snapshot === null || $session->application_id === null) {
            throw ValidationException::withMessages(['session' => 'Observe the form page first (from the extension), with an application selected.']);
        }

        $plan = ApplicationFormPlan::query()->where('application_id', $session->application_id)->latest('created_at')->first();

        if ($plan === null) {
            throw ValidationException::withMessages(['session' => 'Prepare the form plan for this application first.']);
        }

        // Only literal answers copied from the profile are typed (a name, a country). Summaries of essays,
        // activities or checklist status, checks, AI judgments and suggestions stay with the student.
        $fillable = $this->fillableLabels((string) $plan->form_key);
        $values = collect($plan->entries)
            ->filter(fn (array $e) => $e['basis'] === 'profile' && $e['value'] !== null && in_array($this->labelKey($e['field']), $fillable, true))
            ->mapWithKeys(fn (array $e) => [$this->labelKey($e['field']) => $e]);

        $items = [];

        foreach ($snapshot->fields as $field) {
            $entry = $values->get($this->labelKey($field['label'])) ?? $values->get($this->labelKey($field['name']));

            if ($entry === null || in_array($field['type'], ['checkbox', 'radio', 'submit', 'button'], true)) {
                continue;
            }

            $value = (string) $entry['value'];

            // A select can only take one of its own options (matched without case); otherwise it is left alone.
            if ($field['type'] === 'select') {
                $value = collect($field['options'])->first(fn (string $option) => Str::lower(trim($option)) === Str::lower(trim($value)));

                if ($value === null) {
                    continue;
                }
            }

            if ($field['value'] === $value) {
                continue;
            }

            $items[] = ['key' => $field['field_id'], 'label' => $field['label'] ?: $field['name'], 'before' => $field['value'] !== '' ? $field['value'] : null, 'after' => $value, 'reason' => 'From your saved profile ('.$entry['field'].').'];
        }

        if ($items === []) {
            throw ValidationException::withMessages(['session' => 'Nothing on this page matches your saved details, or it is already filled in.']);
        }

        return app(ProposalService::class)->proposeBrowserFill($student, 'Fill '.count($items).' field(s) on '.parse_url($snapshot->url, PHP_URL_HOST), $items, [
            'browser_session_id' => (string) $session->getKey(),
            'snapshot_id' => (string) $snapshot->getKey(),
            'page_hash' => $snapshot->page_hash,
            'tab_id' => $session->tab_id,
            'origin' => $session->origin,
            'transport' => $session->transport,
        ], $actor, $conversationId);
    }

    /**
     * Normalised labels of the form fields that hold a literal answer ("fill": true in the form definition).
     *
     * @return list<string>
     */
    private function fillableLabels(string $formKey): array
    {
        if ($formKey !== ApplicationPlanHandler::FORM) {
            return [];
        }

        return collect(ApplicationPlanHandler::formDefinition()['sections'])
            ->flatMap(fn (array $section) => $section['fields'])
            ->filter(fn (array $field) => ($field['fill'] ?? false) === true)
            ->map(fn (array $field) => $this->labelKey($field['label']))
            ->values()
            ->all();
    }

    private function labelKey(string $label): string
    {
        return Str::of($label)->lower()->replaceMatches('/\(.*?\)|\*/', '')->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }

    /**
     * @throws ValidationException
     */
    private function normalizeOrigin(string $url): string
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw ValidationException::withMessages(['origin' => 'Only web pages (http or https) can be assisted.']);
        }

        return $scheme.'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
