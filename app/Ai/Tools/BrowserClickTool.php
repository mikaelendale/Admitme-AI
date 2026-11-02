<?php

namespace App\Ai\Tools;

use App\Domain\Browser\PageFormatter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode: click a control on the page open in the student's tab. A consequential control (submit, pay, sign,
 * certify, send, delete…) is clicked only after the student presses Allow in the side panel; the panel waits up to
 * three minutes for them, so this tool waits a little longer.
 */
class BrowserClickTool extends BrowserAgentTool
{
    public const TIMEOUT_SECONDS = 200;

    public function description(): Stringable|string
    {
        return 'Click a control on the page open in the student\'s browser tab by its id: a button (Next, Save, Continue, Add), link, tab, checkbox, radio or option. '
            .'Returns the page afterwards (use its new ids). Controls marked "needs the student\'s Allow" (submit, pay, sign, certify, send, delete…): '
            .'click one only after the student said yes in the chat; the side panel then asks them to press Allow (it waits up to 3 minutes). If they decline, nothing is clicked.';
    }

    public function handle(Request $request): Stringable|string
    {
        $id = self::validElementId($request['element_id'] ?? null);

        if ($id === null) {
            return 'Nothing clicked: element_id must be a control id from the latest page read, like e12.';
        }

        $run = $this->browser()->run($this->student, 'agent_click', ['id' => $id], self::TIMEOUT_SECONDS);

        if (! $run['ok']) {
            return $this->notDone($run, 'clicked');
        }

        $data = $run['data'] ?? [];
        $clicked = is_string($data['clicked'] ?? null) && $data['clicked'] !== ''
            ? '"'.Str::limit(trim((string) preg_replace('/\s+/u', ' ', $data['clicked'])), 80, '…').'" ('.$id.')'
            : $id;
        $heading = "Clicked {$clicked}.".(! empty($data['navigated']) ? ' The page changed: use the ids below.' : '');

        return is_array($data['page'] ?? null)
            ? PageFormatter::forModel($data['page'], $heading)
            : $heading.' Use BrowserReadTool to see the page now.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'element_id' => $schema->string()->description('The control\'s id from the latest page read, e.g. "e7".')->required(),
        ];
    }
}
