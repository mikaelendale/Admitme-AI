<?php

namespace App\Domain\Browser;

use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\ExtensionToken;
use App\Models\MentorTurn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Mentor browser control (contract: browser-extension/src/shared/agent.ts). The student gives the mentor one tab
 * from the extension's side panel. While the panel is open it polls `next` (also its heartbeat); the mentor's
 * Browser tools queue one action at a time and wait for the panel to perform it and report back.
 *
 * The panel enforces the rails in the page (passwords never read or typed, file inputs never set, CAPTCHAs left
 * to the student, the student's Allow before a consequential click). The server hands out one action at a time,
 * never re-runs one (page actions are not idempotent), refuses password and file fields it knows of, and strips
 * what must never be stored from what the panel reports.
 */
final class BrowserAgentService
{
    /** A handed-out action not reported within this long is failed, never re-run (a click may wait 3 min for Allow). */
    public const LEASE_SECONDS = 240;

    /** The mentor has control while the panel was heard from this recently (it polls about every 0.7s). */
    public const CONTROL_SECONDS = 20;

    /** Largest result accepted from the panel (bytes of JSON). */
    public const MAX_RESULT_BYTES = 400 * 1024;

    /** Element ids the content script writes on the page ("e12"). */
    public const ELEMENT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/';

    /** @var list<string> */
    public const KEYS = ['Enter', 'Tab', 'Escape', 'ArrowDown', 'ArrowUp', 'Space', 'Backspace'];

    /** @var list<string> */
    public const KINDS = ['text', 'email', 'tel', 'number', 'url', 'date', 'textarea', 'select', 'checkbox', 'radio', 'combobox', 'button', 'link', 'password', 'file', 'other'];

    /** The panel's poll writes its heartbeat at most this often, unless something else changed. */
    private const HEARTBEAT_SECONDS = 5;

    /** While a tool waits: how often it checks for the student's Stop and for the panel going away. */
    private const CHECK_EVERY_MS = 1000;

    /** A stop this recent is still news to the mentor (about one reply's length). */
    private const STOPPED_RECENTLY_MINUTES = 15;

    // PAGE_LIMITS in agent.ts: what is kept of a page the panel reports.
    private const MAX_ELEMENTS = 300;

    private const LABEL_CHARS = 160;

    private const VALUE_CHARS = 500;

    private const MAX_OPTIONS = 60;

    private const TEXT_CHARS = 6000;

    private const MAX_HEADINGS = 20;

    private const MAX_ALERTS = 20;

    /**
     * The panel's "Let the mentor use this browser": control of this tab. Any other open agent session of the
     * student ends (control is one tab at a time) and its queued actions are cancelled.
     */
    public function start(ExtensionToken $token, int $tabId, ?string $url): BrowserSession
    {
        $url = self::webUrl($url);

        return DB::transaction(function () use ($token, $tabId, $url) {
            $this->stop((int) $token->user_id, 'Browser control moved to another tab.');

            return BrowserSession::query()->create([
                'user_id' => $token->user_id,
                'extension_token_id' => $token->id,
                'transport' => 'live',
                'mode' => BrowserSession::MODE_AGENT,
                'origin' => $url !== null ? self::origin($url) : '',
                'tab_id' => $tabId,
                'status' => 'active',
                'last_seen_at' => now(),
                'current_url' => $url,
            ]);
        });
    }

    /**
     * End the student's browser control (every open agent session); queued and running actions are cancelled.
     *
     * @return int The sessions closed.
     */
    public function stop(User|int $student, string $reason = 'The student stopped browser control.'): int
    {
        $ids = BrowserSession::query()
            ->where('user_id', $student instanceof User ? $student->getKey() : $student)
            ->where('mode', BrowserSession::MODE_AGENT)
            ->where('status', '!=', 'closed')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        BrowserSession::query()->whereKey($ids)->update(['status' => 'closed']);
        $this->cancelOpenCommands($ids->all(), $reason);

        return $ids->count();
    }

    /**
     * Cancel the queued and running actions of these sessions (the session was closed).
     *
     * @param  list<string>  $sessionIds
     */
    public function cancelOpenCommands(array $sessionIds, string $reason): void
    {
        BrowserCommand::query()
            ->whereIn('browser_session_id', $sessionIds)
            ->whereIn('status', ['pending', 'leased'])
            ->update(['status' => 'cancelled', 'status_reason' => $reason, 'lease_expires_at' => null]);
    }

    /**
     * The panel's poll and heartbeat: hands out the oldest queued action, one at a time.
     *
     * @return array{status: 'ok'|'forbidden'|'closed'|'wrong_tab', message?: string, command?: array{id: string, type: string, payload: array<string, mixed>|object}|null, session?: BrowserSession}
     */
    public function next(BrowserSession $session, ExtensionToken $token, int $tabId, ?string $url): array
    {
        if (! $this->ownedBy($session, $token)) {
            return ['status' => 'forbidden', 'message' => 'This session belongs to another device.'];
        }

        if ($session->status === 'closed') {
            return ['status' => 'closed', 'message' => 'Browser control was stopped.'];
        }

        if ($tabId !== (int) $session->tab_id) {
            return ['status' => 'wrong_tab', 'message' => 'The mentor works in another tab. Switch back to it, or give the mentor this tab instead.'];
        }

        $this->heartbeat($session, $url);

        $command = DB::transaction(function () use ($session) {
            $open = BrowserCommand::query()
                ->where('browser_session_id', $session->getKey())
                ->whereIn('status', ['pending', 'leased'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($open->where('status', 'leased') as $leased) {
                // One at a time; an action the browser never reported is failed, never handed out again.
                if ($leased->lease_expires_at !== null && $leased->lease_expires_at->isFuture()) {
                    return null;
                }

                $leased->update(['status' => 'failed', 'status_reason' => 'The browser did not report back.', 'lease_expires_at' => null]);
            }

            // A tool waits at most a few minutes and cancels what it gave up on; an older queued action has nobody
            // waiting for it (the worker stopped), so it must not run in the student's tab now.
            $stale = $open->where('status', 'pending')->filter(fn (BrowserCommand $c) => $c->created_at?->lt(now()->subSeconds(self::LEASE_SECONDS)));

            if ($stale->isNotEmpty()) {
                BrowserCommand::query()->whereKey($stale->modelKeys())->update(['status' => 'cancelled', 'status_reason' => 'Not taken in time; nobody was waiting for it any more.']);
            }

            /** @var BrowserCommand|null $next */
            $next = $open->where('status', 'pending')->reject(fn (BrowserCommand $c) => $stale->contains($c))->first();

            if ($next === null) {
                return null;
            }

            $next->update(['status' => 'leased', 'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS), 'attempts' => $next->attempts + 1]);

            return ['id' => (string) $next->id, 'type' => $next->type, 'payload' => $next->payload === [] ? (object) [] : $next->payload];
        });

        return ['status' => 'ok', 'command' => $command, 'session' => $session];
    }

    /**
     * What the panel did with an action. Recorded once; a result for an action the mentor stopped waiting for is
     * still kept (it may have happened in the browser).
     *
     * @param  array{outcome: string, message?: string|null, data?: array<string, mixed>|null}  $result
     *
     * @throws ValidationException When the result is too large.
     */
    public function report(BrowserCommand $command, ExtensionToken $token, array $result): BrowserCommand
    {
        $size = strlen((string) json_encode(['message' => $result['message'] ?? null, 'data' => $result['data'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        if ($size > self::MAX_RESULT_BYTES) {
            throw ValidationException::withMessages(['data' => 'This result is too large (over 400 KB). Read the page without its text, or report the step as failed.']);
        }

        return DB::transaction(function () use ($command, $token, $result) {
            /** @var BrowserCommand $locked */
            $locked = BrowserCommand::query()->lockForUpdate()->findOrFail($command->getKey());
            $session = $locked->session;

            abort_unless($locked->isAgent() && $session !== null && $this->ownedBy($session, $token), 404);

            // Already reported, or never handed out: nothing to record.
            if (in_array($locked->status, BrowserCommand::AGENT_FINISHED, true) || (int) $locked->attempts === 0) {
                return $locked;
            }

            $outcome = (string) $result['outcome'];
            $message = filled($result['message'] ?? null) ? Str::limit(self::oneLine((string) $result['message']), 500, '…') : null;
            $data = $outcome === 'declined' ? [] : $this->cleanData((array) ($result['data'] ?? []), $session);

            [$status, $reason] = match ($outcome) {
                'done' => ['completed', null],
                'failed' => ['failed', $message ?? 'The browser could not do this.'],
                'declined' => ['declined', $message ?? 'The student declined.'],
                default => throw new InvalidArgumentException("Unknown outcome [{$outcome}]."),
            };

            $locked->update([
                'status' => $status,
                'status_reason' => $reason,
                'result' => ['outcome' => $outcome, 'message' => $message, 'data' => $data, 'at' => now()->toIso8601String()],
                'lease_expires_at' => null,
                'completed_at' => $status === 'completed' ? now() : null,
            ]);

            // A report is also a sign of life (an action can outlast the control window), and says where the tab is now.
            if ($session->status !== 'closed') {
                $changes = ['last_seen_at' => now()];

                if (isset($data['page'])) {
                    $url = self::webUrl($data['page']['url']);
                    $changes += [
                        'current_url' => $url,
                        'current_title' => $data['page']['title'] !== '' ? Str::limit($data['page']['title'], 300, '') : null,
                        'origin' => $url !== null ? self::origin($url) : $session->origin,
                    ];
                }

                $session->forceFill($changes)->save();
            }

            return $locked;
        });
    }

    /**
     * The tab the student gave the mentor, while its side panel is open (heard from in the last CONTROL_SECONDS).
     */
    public function controlFor(User $student): ?BrowserSession
    {
        return BrowserSession::query()
            ->where('user_id', $student->getKey())
            ->where('mode', BrowserSession::MODE_AGENT)
            ->where('status', '!=', 'closed')
            ->where('last_seen_at', '>=', now()->subSeconds(self::CONTROL_SECONDS))
            ->latest('last_seen_at')
            ->first();
    }

    /**
     * Do one action in the student's controlled tab: queue it and wait (polling) until the panel reports it, the
     * student stops it, or the time is up (then it is cancelled — the side panel must stay open while the mentor
     * works). Password and file fields the mentor knows of from the last page read are refused here as well.
     *
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, status?: string, error?: string, message?: string|null, data?: array<string, mixed>, refused?: array<string, string>, handed_out?: bool}
     */
    public function run(User $student, string $type, array $payload, int $timeoutSeconds): array
    {
        if (! in_array($type, BrowserCommand::AGENT_TYPES, true)) {
            throw new InvalidArgumentException("Unknown browser action [{$type}].");
        }

        $session = $this->controlFor($student);

        if ($session === null) {
            return ['ok' => false, 'error' => $this->stoppedRecently($student) ? 'control_stopped' : 'no_control'];
        }

        $refused = $this->refuseSecretFields($session, $type, $payload);

        if ($refused !== [] && ($type === 'agent_key' || ($payload['fields'] ?? []) === [])) {
            return ['ok' => false, 'error' => 'refused', 'refused' => $refused, 'message' => 'Nothing was done: password and file fields are left to the student.'];
        }

        $command = BrowserCommand::query()->create([
            'browser_session_id' => $session->getKey(),
            'user_id' => $student->getKey(),
            'type' => $type,
            'payload' => $payload,
            // Not bound to an observed snapshot or one origin: the panel acts on the tab as it is.
            'snapshot_id' => '',
            'tab_id' => $session->tab_id,
            'origin' => (string) $session->origin,
            'idempotency_key' => 'agent:'.Str::ulid(),
            'status' => 'pending',
        ]);

        $outcome = $this->await($student, $session, $command, max(1, $timeoutSeconds));

        return $refused === [] ? $outcome : [...$outcome, 'refused' => $refused];
    }

    /**
     * The page the panel last reported in this session (ids, kinds, labels), if any.
     *
     * @return array<string, mixed>|null
     */
    public function lastPage(BrowserSession $session): ?array
    {
        $recent = BrowserCommand::query()
            ->where('browser_session_id', $session->getKey())
            ->whereIn('type', ['agent_open', 'agent_read', 'agent_click', 'agent_key'])
            ->whereIn('status', ['completed', 'failed'])
            ->whereNotNull('result')
            ->orderByDesc('id')
            ->limit(3)
            ->get(['id', 'result']);

        foreach ($recent as $command) {
            $page = $command->result['data']['page'] ?? null;

            if (is_array($page)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * An address the mentor may open: a full http(s) URL with a host and no username or password in it.
     */
    public static function openableUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * A web page's address as kept (http/https only, without any username or password), or null.
     */
    public static function webUrl(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            $url = (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^@/?\#]*@#i', '$1', $url);
        }

        return mb_substr($url, 0, 2048);
    }

    /**
     * Where the tab is, for the mentor's context: host and path only (no query, which may hold tokens), shortened.
     */
    public static function describeUrl(?string $url): ?string
    {
        $parts = $url !== null ? parse_url($url) : false;

        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $where = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').(($parts['path'] ?? '/') === '/' ? '' : $parts['path']);

        return Str::limit(self::oneLine($where), 120, '…');
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return mb_substr(strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : ''), 0, 255);
    }

    private function ownedBy(BrowserSession $session, ExtensionToken $token): bool
    {
        return $session->isAgent()
            && (int) $session->user_id === (int) $token->user_id
            && (int) $session->extension_token_id === (int) $token->id;
    }

    /**
     * The panel is open (the mentor has control while it is heard from) and where its tab is.
     */
    private function heartbeat(BrowserSession $session, ?string $url): void
    {
        $changes = $session->status !== 'active' ? ['status' => 'active'] : [];

        if ($url !== null) {
            $current = self::webUrl($url);

            if ($current !== $session->current_url) {
                // The tab moved (the student navigated): the title read before belongs to the old page.
                $changes += ['current_url' => $current, 'current_title' => null, 'origin' => $current !== null ? self::origin($current) : $session->origin];
            }
        }

        if ($changes !== [] || $session->last_seen_at === null || $session->last_seen_at->lt(now()->subSeconds(self::HEARTBEAT_SECONDS))) {
            $session->forceFill([...$changes, 'last_seen_at' => now()])->save();
        }
    }

    /**
     * Fields the mentor must never type in, as far as the last page read shows: removed from a fill (and a key
     * press on one is refused). The panel refuses them too.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string> Element id => why it was refused.
     */
    private function refuseSecretFields(BrowserSession $session, string $type, array &$payload): array
    {
        if (! in_array($type, ['agent_fill', 'agent_key'], true)) {
            return [];
        }

        $kinds = collect($this->lastPage($session)['elements'] ?? [])
            ->filter(fn ($element) => is_array($element) && in_array($element['kind'] ?? null, ['password', 'file'], true))
            ->mapWithKeys(fn (array $element) => [(string) $element['id'] => $element['kind']]);

        if ($kinds->isEmpty()) {
            return [];
        }

        $why = fn (string $kind) => $kind === 'password'
            ? 'a password field: the student types passwords themselves'
            : 'a file upload: the student chooses files themselves';

        if ($type === 'agent_key') {
            $id = $payload['id'] ?? null;

            return is_string($id) && $kinds->has($id) ? [$id => $why($kinds[$id])] : [];
        }

        $refused = [];
        $fields = [];

        foreach ((array) ($payload['fields'] ?? []) as $field) {
            if ($kinds->has($field['id'])) {
                $refused[$field['id']] = $why($kinds[$field['id']]);
            } else {
                $fields[] = $field;
            }
        }

        $payload['fields'] = $fields;

        return $refused;
    }

    /**
     * Wait for the panel's report on a queued action.
     *
     * @return array{ok: bool, status?: string, error?: string, message?: string|null, data?: array<string, mixed>, handed_out?: bool}
     */
    private function await(User $student, BrowserSession $session, BrowserCommand $command, int $timeoutSeconds): array
    {
        $deadline = now()->addSeconds($timeoutSeconds);
        $interval = max(1, (int) config('admitme.browser.agent_poll_ms', 250));
        $checkedAt = null;

        while (true) {
            $status = BrowserCommand::query()->whereKey($command->getKey())->value('status');

            if (! in_array($status, ['pending', 'leased'], true)) {
                return $this->outcome($command->refresh());
            }

            if ($checkedAt === null || $checkedAt->lte(now()->subMilliseconds(self::CHECK_EVERY_MS))) {
                $checkedAt = now();

                // The student pressed Stop on the reply: stop at once rather than when the browser answers.
                if ($this->replyStopped($student)) {
                    return $this->giveUp($command, 'stopped', 'The student stopped the reply, so this was not done.');
                }

                // The side panel went away before taking the action (closed without Stop).
                if ($status === 'pending' && ! $this->panelOpen($session)) {
                    return $this->giveUp($command, 'no_control', 'The AdmitMe side panel stopped answering, so this was not done.');
                }
            }

            if (now()->gte($deadline)) {
                return $this->giveUp($command, 'timeout', "The browser did not answer within {$timeoutSeconds} seconds, so this was stopped.");
            }

            Sleep::usleep($interval * 1000);
        }
    }

    /**
     * Stop waiting: cancel the action unless the browser reported it in the meantime.
     *
     * @return array{ok: bool, status?: string, error?: string, message?: string|null, data?: array<string, mixed>, handed_out?: bool}
     */
    private function giveUp(BrowserCommand $command, string $error, string $message): array
    {
        $handedOut = BrowserCommand::query()->whereKey($command->getKey())->value('status') === 'leased';

        $cancelled = BrowserCommand::query()
            ->whereKey($command->getKey())
            ->whereIn('status', ['pending', 'leased'])
            ->update(['status' => 'cancelled', 'status_reason' => $message, 'lease_expires_at' => null]);

        if ($cancelled === 0) {
            return $this->outcome($command->refresh());
        }

        return ['ok' => false, 'error' => $error, 'message' => $message, 'handed_out' => $handedOut];
    }

    /**
     * @return array{ok: bool, status: string, error?: string, message?: string|null, data?: array<string, mixed>}
     */
    private function outcome(BrowserCommand $command): array
    {
        $data = (array) ($command->result['data'] ?? []);

        return match ($command->status) {
            'completed' => ['ok' => true, 'status' => 'completed', 'data' => $data],
            'failed' => ['ok' => false, 'status' => 'failed', 'error' => 'failed', 'message' => $command->status_reason, 'data' => $data],
            'declined' => ['ok' => false, 'status' => 'declined', 'error' => 'declined', 'message' => $command->status_reason],
            default => ['ok' => false, 'status' => (string) $command->status, 'error' => 'cancelled', 'message' => $command->status_reason ?: 'Browser control was stopped.'],
        };
    }

    /**
     * The student ended control themselves a short while ago (Stop in the panel or the web app, or unlinking): the
     * mentor should not carry on, nor ask them straight away to turn it back on.
     */
    private function stoppedRecently(User $student): bool
    {
        $latest = BrowserSession::query()
            ->where('user_id', $student->getKey())
            ->where('mode', BrowserSession::MODE_AGENT)
            ->latest('updated_at')
            ->first(['status', 'updated_at']);

        return $latest?->status === 'closed' && $latest->updated_at?->gte(now()->subMinutes(self::STOPPED_RECENTLY_MINUTES)) === true;
    }

    private function replyStopped(User $student): bool
    {
        return MentorTurn::query()
            ->where('user_id', $student->getKey())
            ->where('status', MentorTurn::RUNNING)
            ->whereNotNull('cancel_requested_at')
            ->exists();
    }

    private function panelOpen(BrowserSession $session): bool
    {
        return BrowserSession::query()
            ->whereKey($session->getKey())
            ->where('status', '!=', 'closed')
            ->where('last_seen_at', '>=', now()->subSeconds(self::CONTROL_SECONDS))
            ->exists();
    }

    /**
     * Keep what the contract allows of a report, within its limits. Never a password's value, never a chosen
     * file's name (only whether one is chosen).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cleanData(array $data, BrowserSession $session): array
    {
        $clean = [];

        if (is_array($data['page'] ?? null)) {
            $clean['page'] = $this->cleanPage($data['page']);
        }

        if (is_array($data['fields'] ?? null)) {
            $secret = collect($this->lastPage($session)['elements'] ?? [])
                ->filter(fn ($element) => is_array($element) && in_array($element['kind'] ?? null, ['password', 'file'], true))
                ->pluck('id')
                ->all();

            $clean['fields'] = collect($data['fields'])
                ->filter(fn ($field) => is_array($field))
                ->take(100)
                ->map(function (array $field) use ($secret) {
                    $id = self::text($field['id'] ?? '', 32);
                    $result = ['id' => $id, 'ok' => (bool) ($field['ok'] ?? false)];

                    if (isset($field['value']) && is_scalar($field['value']) && ! in_array($id, $secret, true)) {
                        $result['value'] = self::text($field['value'], self::VALUE_CHARS);
                    }

                    if (isset($field['error']) && is_scalar($field['error']) && (string) $field['error'] !== '') {
                        $result['error'] = self::text($field['error'], 300);
                    }

                    return $result;
                })
                ->values()
                ->all();
        }

        if (is_array($data['alerts'] ?? null)) {
            $clean['alerts'] = self::texts($data['alerts'], self::MAX_ALERTS, 300);
        }

        if (isset($data['clicked']) && is_scalar($data['clicked'])) {
            $clean['clicked'] = self::text($data['clicked'], self::LABEL_CHARS);
        }

        if (isset($data['navigated'])) {
            $clean['navigated'] = (bool) $data['navigated'];
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function cleanPage(array $page): array
    {
        $raw = array_values(array_filter((array) ($page['elements'] ?? []), 'is_array'));
        $elements = [];

        foreach (array_slice($raw, 0, self::MAX_ELEMENTS) as $element) {
            $id = is_scalar($element['id'] ?? null) ? (string) $element['id'] : '';

            if (preg_match(self::ELEMENT_ID_PATTERN, $id) !== 1) {
                continue;
            }

            $kind = in_array($element['kind'] ?? null, self::KINDS, true) ? $element['kind'] : 'other';
            $clean = ['id' => $id, 'kind' => $kind, 'label' => self::text($element['label'] ?? '', self::LABEL_CHARS)];

            if ($kind === 'file') {
                // Whether a file is chosen, never its name or path.
                if (array_key_exists('value', $element)) {
                    $clean['value'] = self::fileState($element['value']);
                }
            } elseif ($kind !== 'password' && isset($element['value']) && is_scalar($element['value'])) {
                $clean['value'] = self::text($element['value'], self::VALUE_CHARS);
            }

            foreach (['checked', 'required', 'disabled', 'consequential'] as $flag) {
                if (isset($element[$flag])) {
                    $clean[$flag] = (bool) $element[$flag];
                }
            }

            if ($kind !== 'password' && is_array($element['options'] ?? null)) {
                $clean['options'] = self::texts($element['options'], self::MAX_OPTIONS, self::LABEL_CHARS);
            }

            foreach (['href' => 2048, 'group' => self::LABEL_CHARS, 'invalid' => 300] as $key => $limit) {
                if (isset($element[$key]) && is_scalar($element[$key]) && (string) $element[$key] !== '') {
                    $clean[$key] = self::text($element[$key], $limit);
                }
            }

            $elements[] = $clean;
        }

        $clean = [
            'url' => self::text($page['url'] ?? '', 2048),
            'title' => self::text($page['title'] ?? '', 300),
            'login_required' => (bool) ($page['login_required'] ?? false),
            'captcha' => (bool) ($page['captcha'] ?? false),
            'headings' => self::texts($page['headings'] ?? [], self::MAX_HEADINGS, 300),
            'alerts' => self::texts($page['alerts'] ?? [], self::MAX_ALERTS, 300),
            'elements' => $elements,
            'truncated' => (bool) ($page['truncated'] ?? false) || count($raw) > self::MAX_ELEMENTS,
            'unreadable_frames' => max(0, (int) (is_numeric($page['unreadable_frames'] ?? null) ? $page['unreadable_frames'] : 0)),
        ];

        if (isset($page['text']) && is_scalar($page['text'])) {
            $clean['text'] = mb_substr(str_replace("\0", '', (string) $page['text']), 0, self::TEXT_CHARS);
        }

        return $clean;
    }

    private static function fileState(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'file chosen' : '';
        }

        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' || preg_match('/^(no\b|none\b|false$|0$)/i', $value) === 1 ? '' : 'file chosen';
    }

    private static function text(mixed $value, int $limit): string
    {
        return is_scalar($value) ? mb_substr(str_replace("\0", '', trim((string) $value)), 0, $limit) : '';
    }

    /**
     * @return list<string>
     */
    private static function texts(mixed $values, int $max, int $limit): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_slice(array_map(fn ($value) => self::text($value, $limit), array_filter($values, 'is_scalar')), 0, $max));
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
