<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Context\ChatMode;
use App\Ai\Context\MentorToolPolicy;
use App\Ai\Tools\PrepareBrowserFillTool;
use App\Domain\Browser\BrowserBridge;
use App\Domain\Browser\BrowserSessionPresenter;
use App\Domain\Exports\ExportService;
use App\Domain\Tasks\Handlers\ApplicationPlanHandler;
use App\Models\Application;
use App\Models\ApplicationFormPlan;
use App\Models\BrowserCommand;
use App\Models\BrowserSession;
use App\Models\BrowserSnapshot;
use App\Models\CollegeListSchool;
use App\Models\ExtensionToken;
use App\Models\OnboardingProfile;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 10 gate (bridge contracts): fills happen only after approval, are read back, survive reconnects,
 * reject stale or wrong-tab commands, and never repeat. A simulated, labelled transport drives the real API.
 */
class BrowserBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://127.0.0.1:8766';

    private User $student;

    private Application $application;

    /** The practice form "in the browser". */
    private array $form = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->create(['name' => 'Ada Synthetic', 'email' => 'ada@example.test']);
        OnboardingProfile::factory()->for($this->student)->create();
        $school = CollegeListSchool::factory()->for($this->student)->create();
        $this->application = Application::factory()->for($this->student)->create(['college_list_school_id' => $school->id]);
        ApplicationFormPlan::query()->create(['application_id' => $this->application->id, 'user_id' => $this->student->id, 'form_key' => ApplicationPlanHandler::FORM, 'profile_revision' => 1, 'entries' => [
            ['section' => 'Profile', 'field' => 'Legal name', 'required' => true, 'value' => 'Ada Synthetic', 'basis' => 'profile', 'note' => null],
            ['section' => 'Profile', 'field' => 'Email', 'required' => true, 'value' => 'ada@example.test', 'basis' => 'profile', 'note' => null],
            ['section' => 'Profile', 'field' => 'Country of residence', 'required' => true, 'value' => 'Nigeria', 'basis' => 'profile', 'note' => null],
            ['section' => 'Education', 'field' => 'Grades (GPA and scale)', 'required' => false, 'value' => '3.8', 'basis' => 'profile', 'note' => null],
            ['section' => 'Testing', 'field' => 'Report test scores?', 'required' => true, 'value' => 'Apply test-optional.', 'basis' => 'model_judgment', 'note' => null],
            ['section' => 'Writing', 'field' => 'Personal statement (650 words max)', 'required' => true, 'value' => 'Personal statement: version 2, 540 words', 'basis' => 'writing', 'note' => null],
            ['section' => 'College questions', 'field' => 'Application round', 'required' => true, 'value' => 'Regular Decision', 'basis' => 'checklist', 'note' => null],
        ]]);

        $this->form = [
            ['field_id' => 'f1', 'label' => 'Legal name', 'name' => 'legal_name', 'type' => 'text', 'value' => '', 'options' => [], 'required' => true],
            ['field_id' => 'f2', 'label' => 'Email', 'name' => 'email', 'type' => 'email', 'value' => '', 'options' => [], 'required' => true],
            ['field_id' => 'f3', 'label' => 'Country of residence', 'name' => 'country', 'type' => 'select', 'value' => '', 'options' => ['', 'Ghana', 'NIGERIA'], 'required' => true],
            ['field_id' => 'f4', 'label' => 'Portal password', 'name' => 'password', 'type' => 'password', 'value' => 'secret', 'options' => [], 'required' => true],
            ['field_id' => 'f5', 'label' => 'Report test scores?', 'name' => 'tests', 'type' => 'text', 'value' => '', 'options' => [], 'required' => true],
            ['field_id' => 'f6', 'label' => 'Personal statement (650 words max)', 'name' => 'essay', 'type' => 'textarea', 'value' => '', 'options' => [], 'required' => true],
            ['field_id' => 'f7', 'label' => 'Application round', 'name' => 'round', 'type' => 'text', 'value' => '', 'options' => [], 'required' => true],
            ['field_id' => 'f8', 'label' => 'Grades (GPA and scale)', 'name' => 'grades', 'type' => 'text', 'value' => '', 'options' => [], 'required' => false],
        ];
    }

    private function linkedToken(?User $user = null): string
    {
        $code = app(BrowserBridge::class)->issueLinkCode($user ?? $this->student);

        return $this->postJson(route('browser.link'), ['code' => $code, 'device_label' => 'Test Chrome'])->assertCreated()->json('token');
    }

    private function api(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function observe(string $token, string $sessionId): string
    {
        return $this->api($token)->postJson(route('browser.snapshots.store', $sessionId), ['url' => self::ORIGIN.'/apply/form.html', 'fields' => $this->form])->assertCreated()->json('snapshot.page_hash');
    }

    private function poll(string $token, string $sessionId, string $hash, int $tab = 7, string $origin = self::ORIGIN): TestResponse
    {
        return $this->api($token)->getJson(route('browser.commands.lease', [$sessionId, 'tab_id' => $tab, 'origin' => $origin, 'page_hash' => $hash]));
    }

    /** What the extension's content script does: apply a command to the page, then read the field back. */
    private function perform(array $command): string
    {
        foreach ($this->form as &$field) {
            if ($field['field_id'] === $command['field_id'] && ! ($command['reconcile'] && $field['value'] === $command['value'])) {
                $field['value'] = $command['value'];
            }
        }

        return collect($this->form)->firstWhere('field_id', $command['field_id'])['value'];
    }

    private function startSimulatedSession(string $token): string
    {
        $tokenModel = ExtensionToken::query()->findOrFail((int) explode('|', $token)[0]);

        return (string) app(BrowserBridge::class)->startSession($tokenModel, self::ORIGIN, 7, $this->application->id, 'simulated')->id;
    }

    public function test_linking_gives_a_scoped_token_that_can_be_revoked(): void
    {
        $code = app(BrowserBridge::class)->issueLinkCode($this->student);
        $token = $this->postJson(route('browser.link'), ['code' => $code])->assertCreated()->json('token');

        $this->postJson(route('browser.link'), ['code' => $code])->assertUnprocessable(); // one use
        $this->api($token)->postJson(route('browser.sessions.store'), ['origin' => self::ORIGIN, 'tab_id' => 7])->assertCreated();
        $this->assertSame([ExtensionToken::ABILITY_SESSION], ExtensionToken::query()->sole()->abilities);
        $this->assertStringNotContainsString(explode('|', $token)[1], (string) ExtensionToken::query()->sole()->token_hash, 'Only a hash is stored.');

        $expired = app(BrowserBridge::class)->issueLinkCode($this->student);
        $this->travel(11)->minutes();
        $this->postJson(route('browser.link'), ['code' => $expired])->assertUnprocessable();

        $this->actingAs($this->student)->delete(route('browser-extension.devices.revoke', ExtensionToken::query()->sole()))->assertRedirect();
        $this->api($token)->postJson(route('browser.sessions.store'), ['origin' => self::ORIGIN, 'tab_id' => 7])->assertUnauthorized();
    }

    public function test_a_fill_runs_only_after_approval_is_read_back_and_never_repeats(): void
    {
        $token = $this->linkedToken();
        $session = $this->startSimulatedSession($token);
        $hash = $this->observe($token, $session);

        $snapshotFields = collect(BrowserSession::query()->find($session)->latestSnapshot->fields)->pluck('field_id')->all();
        $this->assertNotContains('f4', $snapshotFields, 'Password fields never reach AdmitMe.');

        $proposal = $this->api($token)->postJson(route('browser.fill-proposal', $session))->assertCreated()->json('proposal');
        $this->assertSame(['f1', 'f2', 'f3'], array_column($proposal['items'], 'key'), 'Only literal profile answers are typed — not AI judgments, essay or checklist summaries, or unmarked fields.');
        $this->assertSame('NIGERIA', $proposal['items'][2]['after'], 'A select takes its own option (matched without case).');
        $this->assertSame([], $this->poll($token, $session, $hash)->assertOk()->json('commands'), 'Nothing runs before approval.');

        // Approve two of three — through the same decision flow as the web app.
        $this->api($token)->postJson(route('browser.approvals.decide', $proposal['id']), ['decision' => 'approve', 'selected_keys' => ['f1', 'f3'], 'proposal_revision' => $proposal['revision'], 'payload_hash' => $proposal['payloadHash'], 'idempotency_key' => 'once'])->assertOk();
        $this->assertSame('applied', Proposal::query()->find($proposal['id'])->status->value);

        $commands = $this->poll($token, $session, $hash)->assertOk()->json('commands');
        $this->assertSame(['fill_field', 'select_option'], array_column($commands, 'type'));

        foreach ($commands as $command) {
            $observed = $this->perform($command);
            $this->api($token)->postJson(route('browser.commands.result', $command['id']), ['outcome' => 'done', 'observed_value' => $observed])->assertOk()->assertJsonPath('command.status', 'completed');
        }

        $this->assertSame(['Ada Synthetic', '', 'NIGERIA'], array_column(array_slice($this->form, 0, 3), 'value'));
        $this->assertSame([], $this->poll($token, $session, $hash)->json('commands'), 'Completed commands are never handed out again…');
        $this->api($token)->postJson(route('browser.commands.result', $commands[0]['id']), ['outcome' => 'done', 'observed_value' => 'something else'])->assertJsonPath('command.status', 'completed'); // …nor re-recorded
        $this->assertTrue(BrowserSession::query()->find($session)->isSimulated());
    }

    public function test_wrong_tabs_and_changed_pages_are_refused(): void
    {
        $token = $this->linkedToken();
        $session = $this->startSimulatedSession($token);
        $hash = $this->observe($token, $session);
        $proposal = $this->api($token)->postJson(route('browser.fill-proposal', $session))->json('proposal');
        $this->api($token)->postJson(route('browser.approvals.decide', $proposal['id']), ['decision' => 'approve', 'selected_keys' => ['f1'], 'proposal_revision' => $proposal['revision'], 'payload_hash' => $proposal['payloadHash']])->assertOk();

        $this->poll($token, $session, $hash, tab: 99)->assertStatus(409)->assertJsonPath('status', 'wrong_tab');
        $this->poll($token, $session, $hash, origin: 'https://evil.example')->assertStatus(409);
        $this->assertSame('pending', BrowserCommand::query()->sole()->status, 'A wrong tab leases nothing.');

        // The page changes (a field was added): the approved command no longer applies.
        $this->form[] = ['field_id' => 'f10', 'label' => 'Middle name', 'name' => 'middle', 'type' => 'text', 'value' => '', 'options' => [], 'required' => false];
        $newHash = $this->observe($token, $session);
        $this->assertNotSame($hash, $newHash);
        $this->assertSame('stale', BrowserCommand::query()->sole()->status);
        $this->assertSame([], $this->poll($token, $session, $newHash)->json('commands'));

        // A proposal reviewed against the old page cannot be approved into commands for the new one.
        $old = $this->api($token)->postJson(route('browser.fill-proposal', $session))->json('proposal');
        $this->form[] = ['field_id' => 'f11', 'label' => 'Nickname', 'name' => 'nick', 'type' => 'text', 'value' => '', 'options' => [], 'required' => false];
        $this->observe($token, $session);
        $this->api($token)->postJson(route('browser.approvals.decide', $old['id']), ['decision' => 'approve', 'selected_keys' => ['f1'], 'proposal_revision' => $old['revision'], 'payload_hash' => $old['payloadHash']])->assertOk();
        $this->assertSame('stale', Proposal::query()->find($old['id'])->status->value);
        $this->assertSame(1, BrowserCommand::query()->count());
    }

    public function test_a_lost_acknowledgement_is_reconciled_and_a_mismatch_is_frozen(): void
    {
        $token = $this->linkedToken();
        $session = $this->startSimulatedSession($token);
        $hash = $this->observe($token, $session);
        $proposal = $this->api($token)->postJson(route('browser.fill-proposal', $session))->json('proposal');
        $this->api($token)->postJson(route('browser.approvals.decide', $proposal['id']), ['decision' => 'approve', 'selected_keys' => ['f1', 'f2'], 'proposal_revision' => $proposal['revision'], 'payload_hash' => $proposal['payloadHash']])->assertOk();

        $first = $this->poll($token, $session, $hash)->json('commands');
        $this->perform($first[0]); // filled, but the acknowledgement was lost (browser closed)

        $this->travel(2)->minutes();
        $again = $this->poll($token, $session, $hash)->json('commands');
        $this->assertTrue($again[0]['reconcile'], 'The extension is told to check before acting again…');
        $this->assertSame('Ada Synthetic', $this->perform($again[0]), '…and the already-filled value is simply confirmed.');
        $this->api($token)->postJson(route('browser.commands.result', $again[0]['id']), ['outcome' => 'done', 'observed_value' => 'Ada Synthetic'])->assertJsonPath('command.status', 'completed');
        $this->assertSame(2, BrowserCommand::query()->find($again[0]['id'])->attempts);

        $this->api($token)->postJson(route('browser.commands.result', $again[1]['id']), ['outcome' => 'done', 'observed_value' => 'ada@exampl'])->assertJsonPath('command.status', 'frozen');
        $this->api($token)->postJson(route('browser.commands.result', $again[1]['id']), ['outcome' => 'uncertain'])->assertJsonPath('command.status', 'frozen');
        // Silence is "waiting for the browser" (as the web app shows it), never success; the panel's next refresh is a heartbeat.
        $this->travel(3)->minutes();
        $this->assertSame('waiting_for_browser', BrowserSessionPresenter::present(BrowserSession::query()->findOrFail($session))['status']);
        $this->assertSame('active', $this->api($token)->getJson(route('browser.sessions.show', $session))->json('session.status'));
    }

    public function test_the_mentor_prepares_the_same_fill_in_agent_mode_only_and_never_types(): void
    {
        $names = fn (ChatMode $mode) => array_map(fn ($tool) => class_basename($tool), app(MentorToolPolicy::class)->toolsFor($this->student, $mode));
        $this->assertContains('PrepareBrowserFillTool', $names(ChatMode::Agent));
        $this->assertNotContains('PrepareBrowserFillTool', $names(ChatMode::Ask));

        $tool = new PrepareBrowserFillTool($this->student, 'conversation-1');
        $this->assertSame('not_proposed', json_decode((string) $tool->handle(new Request(['application_id' => $this->application->id])), true)['status'], 'No session yet: nothing is prepared.');

        $token = $this->linkedToken();
        $session = $this->startSimulatedSession($token);
        $this->observe($token, $session);
        $result = json_decode((string) $tool->handle(new Request(['application_id' => $this->application->id])), true);

        $this->assertSame('pending_approval', $result['status']);
        $this->assertSame(['Legal name', 'Email', 'Country of residence'], $result['fields']);
        $proposal = Proposal::query()->findOrFail($result['proposal_id']);
        $this->assertSame('conversation-1', $proposal->conversation_id);
        $this->assertSame($session, $proposal->context['browser_session_id']);
        $this->assertSame(0, BrowserCommand::query()->count(), 'Preparing types nothing.');

        $other = json_decode((string) (new PrepareBrowserFillTool(User::factory()->create()))->handle(new Request(['application_id' => $this->application->id])), true);
        $this->assertSame('not_proposed', $other['status'], "Another student's application is refused.");
    }

    public function test_fills_are_exported_and_old_observations_are_pruned(): void
    {
        Storage::fake('local');
        $token = $this->linkedToken();
        $session = $this->startSimulatedSession($token);
        $hash = $this->observe($token, $session);
        $proposal = $this->api($token)->postJson(route('browser.fill-proposal', $session))->json('proposal');
        $this->api($token)->postJson(route('browser.approvals.decide', $proposal['id']), ['decision' => 'approve', 'selected_keys' => ['f1'], 'proposal_revision' => $proposal['revision'], 'payload_hash' => $proposal['payloadHash']])->assertOk();
        $command = $this->poll($token, $session, $hash)->json('commands.0');
        $this->api($token)->postJson(route('browser.commands.result', $command['id']), ['outcome' => 'done', 'observed_value' => $this->perform($command)])->assertOk();

        $export = app(ExportService::class)->request($this->student, ['sections' => ['applications']], 'workspace')->fresh();
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($export->archive_path));
        $fills = collect(json_decode((string) $zip->getFromName('data.json'), true)['applications'])->firstWhere('id', $this->application->id)['browser_fills'];
        $zip->close();
        $this->assertSame([['field' => 'Legal name', 'value' => 'Ada Synthetic', 'status' => 'completed', 'read_back' => 'Ada Synthetic']], array_map(fn (array $f) => array_intersect_key($f, array_flip(['field', 'value', 'status', 'read_back'])), (array) $fills));

        $this->travel(31)->days();
        $this->artisan('admitme:prune-data')->assertSuccessful();
        $this->assertSame(0, BrowserSnapshot::query()->count(), 'What a portal form showed is not kept for long.');
        $this->assertSame('completed', BrowserCommand::query()->sole()->status, 'The record of what was typed stays.');
    }

    public function test_the_web_app_shows_the_same_session_steps_and_approvals_as_the_panel(): void
    {
        $token = $this->linkedToken();
        $session = $this->startSimulatedSession($token);
        $this->observe($token, $session);
        $proposal = $this->actingAs($this->student)->postJson(route('applications.browser-fill', $this->application))->assertCreated()->json('proposal_id');

        $this->actingAs($this->student)->get(route('applications.show', $this->application))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('applications/show')
                ->where('browser.linked', true)
                ->where('browser.session.id', $session)
                ->where('browser.session.simulated', true)
                ->where('browser.session.proposals.0.id', $proposal)
                ->where('browser.session.proposals.0.status', 'pending'));

        $this->actingAs($this->student)->get(route('browser-extension.show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/browser-extension')
                ->where('devices.0.label', 'Test Chrome')
                ->where('sessions.0.proposals.0.id', $proposal));

        // The panel lists the very same proposal for this session.
        $this->assertSame($proposal, $this->api($token)->getJson(route('browser.sessions.show', $session))->json('session.proposals.0.id'));
        $this->actingAs(User::factory()->create())->postJson(route('applications.browser-fill', $this->application))->assertNotFound();
    }

    public function test_other_students_and_devices_cannot_reach_a_session(): void
    {
        $token = $this->linkedToken();
        $session = $this->startSimulatedSession($token);
        $hash = $this->observe($token, $session);

        $otherDevice = $this->linkedToken();
        $otherStudent = $this->linkedToken(User::factory()->create());

        $this->api($otherDevice)->getJson(route('browser.sessions.show', $session))->assertNotFound();
        $this->api($otherStudent)->getJson(route('browser.sessions.show', $session))->assertNotFound();
        $this->api($otherStudent)->postJson(route('browser.snapshots.store', $session), ['url' => self::ORIGIN.'/x', 'fields' => []])->assertNotFound();
        $this->poll($otherStudent, $session, $hash)->assertNotFound();
        $this->flushHeaders()->getJson(route('browser.sessions.show', $session))->assertUnauthorized();

        // A snapshot from another site cannot be filed under this session.
        $this->api($token)->postJson(route('browser.snapshots.store', $session), ['url' => 'https://other.example/form', 'fields' => $this->form])->assertUnprocessable();
    }
}
