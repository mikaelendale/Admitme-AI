<?php

namespace App\Ai\Tools;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Domain\Browser\BrowserBridge;
use App\Models\Application;
use App\Models\BrowserSession;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent mode: prepare (never perform) a fill of the application form the student has open with the AdmitMe
 * browser extension — the same service as the side panel and the workspace. The extension types the values
 * only after the student approves; the model never sees or holds extension credentials.
 */
class PrepareBrowserFillTool implements Tool
{
    public function __construct(
        protected User $user,
        protected ?string $conversationId = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'The older approve-each-value flow — use it only when the student asks to approve every value before it is typed; otherwise, with browser control on, fill the page yourself with BrowserFillTool. Prepares a fill of the application form the student has open in their browser with the AdmitMe extension (they must have started a session for that application and pressed "Observe this page" in the side panel). Only plain details from their saved profile are offered (name, email, country, curriculum…); essays, activities and choices are never filled. This does NOT type anything: the student approves the fill, then the extension types each value into the form and reads it back. This flow never submits, pays or uploads. Afterwards say the fill is waiting for their approval.';
    }

    public function handle(Request $request): Stringable|string
    {
        $application = Application::query()->where('user_id', $this->user->getKey())->find($request['application_id'] ?? null);

        if ($application === null) {
            return $this->notProposed(['That application is not one of the student\'s. Use ReadApplicationTool to find its id.']);
        }

        $session = BrowserSession::query()
            ->where('user_id', $this->user->getKey())
            ->where('application_id', $application->getKey())
            ->where('status', '!=', 'closed')
            ->latest('updated_at')
            ->first();

        if ($session === null) {
            return $this->notProposed(['There is no browser session for this application. Ask the student to open the form, then in the AdmitMe extension choose this application, press "Use this tab" and "Observe this page".']);
        }

        try {
            $proposal = app(BrowserBridge::class)->proposeFillFromPlan($this->user, $session, class_basename(AdmitMeGeneralAgent::class).'/'.class_basename(self::class), $this->conversationId);
        } catch (ValidationException $e) {
            return $this->notProposed(array_merge(...array_values($e->errors())));
        }

        return json_encode([
            'status' => 'pending_approval',
            'proposal_id' => $proposal->id,
            'title' => $proposal->title,
            'fields' => array_column($proposal->items, 'label'),
            'message' => 'Nothing has been typed. After the student approves, the extension types these into the form tab and checks each one; the student reviews and submits the form themselves.',
        ], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param  list<string>  $errors
     */
    private function notProposed(array $errors): string
    {
        return json_encode(['status' => 'not_proposed', 'errors' => $errors, 'message' => 'Nothing was prepared and nothing was typed.'], JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'application_id' => $schema->string()->description('The application whose form is open, from ReadApplicationTool.')->required(),
        ];
    }
}
