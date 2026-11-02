<?php

namespace App\Ai\Tools;

use App\Domain\Browser\BrowserAgentService;
use App\Domain\Browser\PageFormatter;
use App\Models\User;
use Laravel\Ai\Contracts\Tool;

/**
 * Agent mode only: the mentor works in the browser tab the student gave it from the AdmitMe extension's side panel
 * (see BrowserAgentService). Each tool queues one action, waits for the panel to report it, and returns plain text
 * for the model. The panel never reads or types passwords, never sets file inputs, leaves CAPTCHAs to the student
 * and asks the student to Allow a consequential click; everything read from a page is untrusted.
 */
abstract class BrowserAgentTool implements Tool
{
    public const NO_CONTROL = 'Not done — no browser control: the student has not given you a browser tab, or the AdmitMe side panel is closed. Ask them to open the AdmitMe extension\'s side panel in Chrome and press "Let the mentor use this browser", and to keep the panel open while you work. (Not linked yet? AdmitMe → Settings → Browser extension.)';

    public const CONTROL_STOPPED = 'Not done — the student stopped browser control (Stop in the side panel or in AdmitMe). Do not carry on in the browser: tell them briefly what is done and what is left, and ask whether to continue. To continue, they press "Let the mentor use this browser" in the side panel again.';

    public function __construct(protected User $student) {}

    protected function browser(): BrowserAgentService
    {
        return app(BrowserAgentService::class);
    }

    /**
     * Why an action did not happen, for the model.
     *
     * @param  array<string, mixed>  $run  BrowserAgentService::run()'s result.
     */
    protected function notDone(array $run, string $action): string
    {
        $message = is_string($run['message'] ?? null) && $run['message'] !== '' ? $run['message'] : null;

        $text = match ($run['error'] ?? null) {
            'no_control' => self::NO_CONTROL,
            'control_stopped' => self::CONTROL_STOPPED,
            'declined' => "The student declined in the side panel (pressed Deny or did not answer within 3 minutes), so nothing was {$action}. Ask them in the chat what they want to do; do not try it again unless they ask.",
            'stopped' => 'The student stopped the reply. Nothing more was done in the browser; stop here.',
            'cancelled' => 'Browser control was stopped (Stop in the side panel, or control moved to another tab), so this was not done. Do not retry; tell the student what is done and ask whether to continue.',
            'timeout' => ($message ?? 'The browser did not answer in time, so this was stopped.')
                .' The AdmitMe side panel must stay open while you work: ask the student to check it.'
                .(! empty($run['handed_out']) ? ' The panel had already started, so it may have happened: read the page before trying again.' : ''),
            'refused' => ($message ?? 'Refused.').self::refusedLines($run['refused'] ?? []),
            default => 'The browser could not do this'.($message !== null ? ': '.$message : '.'),
        };

        $page = $run['data']['page'] ?? null;

        return is_array($page) ? $text."\n\n".PageFormatter::forModel($page) : $text;
    }

    /**
     * @param  mixed  $refused  Element id => why.
     */
    protected static function refusedLines(mixed $refused): string
    {
        if (! is_array($refused) || $refused === []) {
            return '';
        }

        return "\n".implode("\n", array_map(fn (string $id, string $why) => "✗ {$id} — refused: {$why}", array_keys($refused), $refused));
    }

    protected static function validElementId(mixed $id): ?string
    {
        return is_string($id) && preg_match(BrowserAgentService::ELEMENT_ID_PATTERN, trim($id)) === 1 ? trim($id) : null;
    }
}
