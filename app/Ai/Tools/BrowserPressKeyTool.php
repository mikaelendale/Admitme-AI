<?php

namespace App\Ai\Tools;

use App\Domain\Browser\BrowserAgentService;
use App\Domain\Browser\PageFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode: press a key in the page open in the student's tab (on an element, or the focused one). Synthetic key
 * events only: Enter never submits a form by itself.
 */
class BrowserPressKeyTool extends BrowserAgentTool
{
    public const TIMEOUT_SECONDS = 20;

    public function description(): Stringable|string
    {
        return 'Press a key in the page open in the student\'s browser tab, on an element (element_id) or on the focused one: '
            .'ArrowDown / ArrowUp then Enter to pick from a typeahead list, Escape to close a popup, Tab to move on, Space to toggle, Backspace to delete a character. '
            .'Enter never submits a form by itself: click the form\'s button instead. Returns the page afterwards.';
    }

    public function handle(Request $request): Stringable|string
    {
        $key = is_string($request['key'] ?? null) ? $request['key'] : '';

        if (! in_array($key, BrowserAgentService::KEYS, true)) {
            return 'No key pressed: key must be one of '.implode(', ', BrowserAgentService::KEYS).'.';
        }

        $raw = $request['element_id'] ?? null;
        $id = self::validElementId($raw);

        if ($raw !== null && $raw !== '' && $id === null) {
            return 'No key pressed: element_id must be a control id from the latest page read (like e12), or null for the focused element.';
        }

        $run = $this->browser()->run($this->student, 'agent_key', $id !== null ? ['key' => $key, 'id' => $id] : ['key' => $key], self::TIMEOUT_SECONDS);

        if (! $run['ok']) {
            return $this->notDone($run, 'pressed');
        }

        $heading = "Pressed {$key}".($id !== null ? " on {$id}" : '').'.';
        $page = $run['data']['page'] ?? null;

        return is_array($page) ? PageFormatter::forModel($page, $heading) : $heading.' Use BrowserReadTool to see the page now.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->enum(BrowserAgentService::KEYS)->description('The key to press.')->required(),
            'element_id' => $schema->string()->description('The control to press it on, from the latest page read (e.g. "e12"), or null for the focused element.')->nullable()->required(),
        ];
    }
}
