<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\RequirementExtractorAgent;
use App\Ai\Agents\ResearcherAgent;
use App\Ai\Tools\ReadCollegeRequirementsTool;
use App\Ai\Tools\SearchMyDocumentsTool;
use App\Domain\Knowledge\HostResolver;
use App\Domain\Knowledge\InstitutionResolver;
use App\Domain\Knowledge\KnowledgeSearch;
use App\Models\AdmissionRequirement;
use App\Models\CollegeListSchool;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentVersion;
use App\Models\KnowledgePassage;
use App\Models\KnowledgeSource;
use App\Models\ResearchAnswer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

/**
 * Phase 5 gate: questions about a selected college get supported answers with inspectable sources and
 * cycle labels; private documents stay isolated; missing or conflicting requirements are unresolved.
 */
class KnowledgeResearchTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_URL = 'https://admissions.synthetic-lakeside.edu/apply';

    private User $student;

    private CollegeListSchool $school;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests never touch real DNS: every host "resolves" to a public documentation address...
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        $this->student = User::factory()->create();
        $this->school = CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Lakeside University', 'school_slug' => 'synthetic-lakeside-university']);
        app(InstitutionResolver::class)->forSchool($this->school)->update(['website_domain' => 'synthetic-lakeside.edu']);
    }

    private function page(string $deadline = 'January 1, 2027'): string
    {
        return <<<HTML
<html><head><title>Apply | Synthetic Lakeside University</title><script>ignore()</script></head><body>
<nav>Menu</nav>
<h1>First-year admission, Fall 2027 entry</h1>
<p>Regular Decision applications are due {$deadline} at 11:59 p.m. Eastern Time.</p>
<p>The application fee is USD 75. Fee waivers are available for students with financial need.</p>
<p>We are test-optional for the Fall 2027 entry cycle.</p>
</body></html>
HTML;
    }

    private function fakeRequirements(string $date = '2027-01-01', string $quoteDate = 'January 1, 2027'): void
    {
        RequirementExtractorAgent::fake([['requirements' => [
            ['requirement_type' => 'deadline', 'label' => 'Regular Decision deadline', 'value_text' => null, 'deadline_date' => $date, 'deadline_time' => '23:59', 'timezone' => 'America/New_York', 'amount' => null, 'currency' => null, 'conditions' => null, 'applicant_scope' => 'all', 'cycle_label' => 'Fall 2027 entry', 'program' => null, 'quote' => "Regular Decision applications are due {$quoteDate}"],
            ['requirement_type' => 'fee', 'label' => 'Application fee', 'value_text' => null, 'deadline_date' => null, 'deadline_time' => null, 'timezone' => null, 'amount' => 75, 'currency' => 'usd', 'conditions' => 'Waivers for financial need', 'applicant_scope' => 'all', 'cycle_label' => null, 'program' => null, 'quote' => 'The application fee is USD 75.'],
            ['requirement_type' => 'test_policy', 'label' => 'Testing policy', 'value_text' => 'Test-optional', 'deadline_date' => null, 'deadline_time' => null, 'timezone' => 'Eastern', 'amount' => null, 'currency' => null, 'conditions' => null, 'applicant_scope' => 'all', 'cycle_label' => 'Fall 2027 entry', 'program' => null, 'quote' => 'We are test-optional for the Fall 2027 entry cycle.'],
            // Not on the page — must be dropped rather than stored as a fact...
            ['requirement_type' => 'interview', 'label' => 'Interview', 'value_text' => 'Required', 'deadline_date' => null, 'deadline_time' => null, 'timezone' => null, 'amount' => null, 'currency' => null, 'conditions' => null, 'applicant_scope' => 'all', 'cycle_label' => null, 'program' => null, 'quote' => 'An interview is required for all applicants.'],
        ]]]);
    }

    private function addSource(string $url = self::PAGE_URL, array $extra = []): TestResponse
    {
        return $this->actingAs($this->student)->postJson(route('college-lists.sources.store', $this->school), ['url' => $url, ...$extra]);
    }

    public function test_an_official_page_becomes_sourced_structured_requirements(): void
    {
        Http::fake([self::PAGE_URL => Http::response($this->page(), 200, ['Content-Type' => 'text/html'])]);
        $this->fakeRequirements();

        $response = $this->addSource()->assertCreated();

        $source = KnowledgeSource::query()->sole();
        $this->assertSame(KnowledgeSource::TYPE_OFFICIAL, $source->source_type);
        $this->assertSame('Apply | Synthetic Lakeside University', $source->title);
        $this->assertStringNotContainsString('ignore()', $source->currentVersion->content_text, 'Scripts are not stored as page text.');
        $this->assertGreaterThan(0, KnowledgePassage::query()->count());

        $requirements = collect($response->json('requirements'))->keyBy('type');
        $this->assertCount(3, $requirements, 'The unquoted "interview" requirement is dropped.');
        $this->assertSame('2027-01-01', $requirements['deadline']['deadlineDate']);
        $this->assertSame('23:59', $requirements['deadline']['deadlineTime']);
        $this->assertSame('America/New_York', $requirements['deadline']['timezone']);
        $this->assertSame('Fall 2027 entry', $requirements['deadline']['cycle']);
        $this->assertSame('75.00', $requirements['fee']['amount']);
        $this->assertSame('USD', $requirements['fee']['currency']);
        $this->assertNull(AdmissionRequirement::query()->where('requirement_type', 'test_policy')->value('timezone'), 'An invalid timezone is left empty, not guessed.');
        $this->assertSame(self::PAGE_URL, $requirements['deadline']['source']['url']);
    }

    public function test_refetching_only_records_the_check_and_a_changed_page_replaces_old_requirements(): void
    {
        Http::fakeSequence(self::PAGE_URL)
            ->push($this->page(), 200, ['Content-Type' => 'text/html'])
            ->push($this->page(), 200, ['Content-Type' => 'text/html'])
            ->push($this->page('January 5, 2027'), 200, ['Content-Type' => 'text/html']);

        RequirementExtractorAgent::fake([
            ['requirements' => [['requirement_type' => 'deadline', 'label' => 'Regular Decision deadline', 'value_text' => null, 'deadline_date' => '2027-01-01', 'deadline_time' => null, 'timezone' => null, 'amount' => null, 'currency' => null, 'conditions' => null, 'applicant_scope' => 'all', 'cycle_label' => 'Fall 2027 entry', 'program' => null, 'quote' => 'Regular Decision applications are due January 1, 2027']]],
            ['requirements' => [['requirement_type' => 'deadline', 'label' => 'Regular Decision deadline', 'value_text' => null, 'deadline_date' => '2027-01-05', 'deadline_time' => null, 'timezone' => null, 'amount' => null, 'currency' => null, 'conditions' => null, 'applicant_scope' => 'all', 'cycle_label' => 'Fall 2027 entry', 'program' => null, 'quote' => 'Regular Decision applications are due January 5, 2027']]],
        ]);

        $this->addSource()->assertCreated();
        $this->addSource()->assertCreated();
        $this->assertSame(1, KnowledgeSource::query()->sole()->versions()->count(), 'Unchanged content is not stored again.');

        $this->addSource()->assertCreated();
        $this->assertSame(2, KnowledgeSource::query()->sole()->versions()->count());

        $active = AdmissionRequirement::query()->active()->get();
        $this->assertCount(1, $active);
        $this->assertSame('2027-01-05', $active->first()->deadline_date->toDateString());
        $this->assertSame(1, AdmissionRequirement::query()->whereNotNull('retired_at')->count());
    }

    public function test_unsafe_or_unrelated_links_are_refused(): void
    {
        Http::fake();
        RequirementExtractorAgent::fake()->preventStrayPrompts();

        foreach (['https://localhost/apply', 'https://127.0.0.1/apply', 'https://10.0.0.5/apply', 'http://admissions.synthetic-lakeside.edu/apply', 'https://user:pw@synthetic-lakeside.edu/', 'https://randomblog.example/lakeside'] as $url) {
            $this->addSource($url)->assertUnprocessable()->assertJsonValidationErrors('url');
        }

        Http::assertNothingSent();
        $this->assertSame(0, KnowledgeSource::query()->count());
    }

    public function test_research_answers_cite_real_evidence_with_cycles_and_unsupported_claims_are_unresolved(): void
    {
        Http::fake([self::PAGE_URL => Http::response($this->page(), 200, ['Content-Type' => 'text/html'])]);
        $this->fakeRequirements();
        $this->addSource()->assertCreated();

        ResearcherAgent::fake([[
            'answer' => 'The Regular Decision deadline for Fall 2027 entry is 1 January 2027 at 23:59 New York time [R1]. Interviews are required [S9].',
            'claims' => [
                ['text' => 'Regular Decision deadline is 1 January 2027, 23:59 America/New_York.', 'citations' => ['R1'], 'status' => 'supported', 'cycle_label' => 'Fall 2027 entry'],
                ['text' => 'Interviews are required.', 'citations' => ['S9'], 'status' => 'supported', 'cycle_label' => null],
            ],
            'unresolved' => ['Whether international applicants have a different deadline.'],
        ]]);

        $answer = $this->actingAs($this->student)
            ->postJson(route('college-lists.research', $this->school), ['question' => 'When is the regular decision deadline?'])
            ->assertCreated()
            ->json('answer');

        $this->assertSame('supported', $answer['claims'][0]['status']);
        $this->assertSame('Fall 2027 entry', $answer['claims'][0]['cycle_label']);
        $this->assertSame('unresolved', $answer['claims'][1]['status'], 'A claim citing evidence that does not exist is not supported.');
        $this->assertSame([], $answer['claims'][1]['citations']);
        $this->assertSame(self::PAGE_URL, $answer['evidence']['R1']['url']);
        $this->assertStringContainsString('January 1, 2027', $answer['evidence']['R1']['quote']);
        $this->assertContains('Whether international applicants have a different deadline.', $answer['unresolved']);
        $this->assertSame(1, ResearchAnswer::query()->where('user_id', $this->student->id)->count());

        ResearcherAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '[R1]') && str_contains($prompt->prompt, 'cycle: Fall 2027 entry'));
    }

    public function test_missing_conflicting_and_stale_requirements_are_shown_as_unresolved(): void
    {
        Http::fake([
            self::PAGE_URL => Http::response($this->page(), 200, ['Content-Type' => 'text/html']),
            'https://www.commonapp.org/*' => Http::response('<html><title>Common App: Synthetic Lakeside</title><p>Synthetic Lakeside University Regular Decision deadline: January 15, 2027.</p></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        RequirementExtractorAgent::fake([
            ['requirements' => [['requirement_type' => 'deadline', 'label' => 'Regular Decision deadline', 'value_text' => null, 'deadline_date' => '2027-01-01', 'deadline_time' => null, 'timezone' => null, 'amount' => null, 'currency' => null, 'conditions' => null, 'applicant_scope' => 'all', 'cycle_label' => 'Fall 2027 entry', 'program' => null, 'quote' => 'Regular Decision applications are due January 1, 2027']]],
            ['requirements' => [['requirement_type' => 'deadline', 'label' => 'Regular Decision deadline', 'value_text' => null, 'deadline_date' => '2027-01-15', 'deadline_time' => null, 'timezone' => null, 'amount' => null, 'currency' => null, 'conditions' => null, 'applicant_scope' => 'all', 'cycle_label' => null, 'program' => null, 'quote' => 'Regular Decision deadline: January 15, 2027']]],
        ]);

        $this->addSource()->assertCreated();
        $overview = $this->addSource('https://www.commonapp.org/explore/synthetic-lakeside')->assertCreated()->json();

        $this->assertSame(KnowledgeSource::TYPE_REFERENCE, KnowledgeSource::query()->where('host', 'www.commonapp.org')->value('source_type'));
        $this->assertSame(['conflict', 'conflict'], array_column($overview['requirements'], 'status'), 'Both disagreeing values are flagged; neither is picked.');

        KnowledgeSource::query()->where('host', 'www.commonapp.org')->update(['checked_at' => now()->subDays(45)]);

        $overview = $this->actingAs($this->student)->getJson(route('college-lists.knowledge', $this->school))->assertOk()->json();
        $unresolved = implode("\n", $overview['unresolved']);

        $this->assertStringContainsString('Sources disagree on Regular Decision deadline: 1 Jan 2027 per admissions.synthetic-lakeside.edu (official site) vs 15 Jan 2027 per www.commonapp.org (reference site)', $unresolved);
        $this->assertStringContainsString('Application fee: not found in the sources on file', $unresolved);
        $this->assertStringContainsString('may be out of date', $unresolved);
        $this->assertSame([false, true], collect($overview['sources'])->sortBy('url')->pluck('stale')->values()->all());
    }

    public function test_a_page_the_student_calls_official_stays_private_and_never_verifies_the_domain(): void
    {
        $url = 'https://lakeside-admissions.example/apply';
        Http::fake([$url => Http::response($this->page('January 20, 2027'), 200, ['Content-Type' => 'text/html'])]);
        $this->fakeRequirements('2027-01-20', 'January 20, 2027');

        // Without the tick, an unrelated site is refused...
        $this->addSource($url)->assertUnprocessable()->assertJsonValidationErrors('url');

        $overview = $this->addSource($url, ['official' => true])->assertCreated()->json();

        $source = KnowledgeSource::query()->sole();
        $this->assertSame(KnowledgeSource::TYPE_STUDENT_PROVIDED, $source->source_type);
        $this->assertTrue($source->claimed_official);
        $this->assertSame($this->student->id, $source->owner_user_id);
        $this->assertSame('synthetic-lakeside.edu', $source->institution->fresh()->website_domain, "A student's tick never changes the shared institution.");
        $this->assertTrue($overview['sources'][0]['private']);
        $this->assertTrue($overview['requirements'][0]['source']['claimedOfficial']);
        $this->assertSame([$this->student->id], AdmissionRequirement::query()->distinct()->pluck('owner_user_id')->all());
        $this->assertSame([$this->student->id], KnowledgePassage::query()->distinct()->pluck('owner_user_id')->all());

        // Another student with the same college sees none of it — not in the panel, search, research or the mentor tool.
        [$other, $otherSchool] = $this->anotherStudentWithLakeside();

        $theirs = $this->actingAs($other)->getJson(route('college-lists.knowledge', $otherSchool))->assertOk()->json();
        $this->assertSame([], $theirs['sources']);
        $this->assertSame([], $theirs['requirements']);
        $this->assertSame(['No sources have been added for this college yet.'], $theirs['unresolved']);

        $institution = app(InstitutionResolver::class)->findForSchool($otherSchool);
        $this->assertCount(0, app(KnowledgeSearch::class)->institutionPassages($institution, 'Regular Decision deadline fee', $other));
        $this->assertGreaterThan(0, app(KnowledgeSearch::class)->institutionPassages($institution, 'Regular Decision deadline fee', $this->student)->count());

        $tool = json_decode((string) (new ReadCollegeRequirementsTool($other))->handle(new Request(['college_name' => 'Synthetic Lakeside University'])), true);
        $this->assertSame([], $tool['requirements']);

        ResearcherAgent::fake()->preventStrayPrompts();
        $answer = $this->actingAs($other)->postJson(route('college-lists.research', $otherSchool), ['question' => 'When is the deadline?'])->assertCreated()->json('answer');
        $this->assertStringContainsString('no sources on file', $answer['answer']);
        ResearcherAgent::assertNeverPrompted();
    }

    public function test_a_private_page_that_disagrees_creates_a_conflict_only_for_its_owner(): void
    {
        $privateUrl = 'https://lakeside-guide.example/deadlines';
        Http::fake([
            self::PAGE_URL => Http::response($this->page(), 200, ['Content-Type' => 'text/html']),
            $privateUrl => Http::response('<html><title>Unofficial guide</title><p>Synthetic Lakeside Regular Decision deadline: January 15, 2027.</p></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $privateDeadline = ['requirements' => [['requirement_type' => 'deadline', 'label' => 'RD application deadline', 'value_text' => null, 'deadline_date' => '2027-01-15', 'deadline_time' => null, 'timezone' => null, 'amount' => null, 'currency' => null, 'conditions' => null, 'applicant_scope' => 'all', 'cycle_label' => null, 'program' => null, 'quote' => 'Regular Decision deadline: January 15, 2027']]];
        RequirementExtractorAgent::fake([
            ['requirements' => [['requirement_type' => 'deadline', 'label' => 'Regular Decision deadline', 'value_text' => null, 'deadline_date' => '2027-01-01', 'deadline_time' => null, 'timezone' => null, 'amount' => null, 'currency' => null, 'conditions' => null, 'applicant_scope' => 'all', 'cycle_label' => 'Fall 2027 entry', 'program' => null, 'quote' => 'Regular Decision applications are due January 1, 2027']]],
            $privateDeadline,
            $privateDeadline,
        ]);

        [$other, $otherSchool] = $this->anotherStudentWithLakeside();

        $this->addSource()->assertCreated();
        $mine = $this->addSource($privateUrl, ['official' => true])->assertCreated()->json();

        $this->assertStringContainsString('Sources disagree on Regular Decision deadline', implode("\n", $mine['unresolved']));
        $this->assertStringContainsString('marked official by you — not verified', implode("\n", $mine['unresolved']));

        // The other student sees the shared official value, with no conflict they could not inspect...
        $theirs = $this->actingAs($other)->getJson(route('college-lists.knowledge', $otherSchool))->assertOk()->json();
        $this->assertCount(1, $theirs['requirements']);
        $this->assertSame('unverified', $theirs['requirements'][0]['status']);
        $this->assertSame('official', $theirs['requirements'][0]['source']['type']);
        $this->assertStringNotContainsString('disagree', implode("\n", $theirs['unresolved']));

        // ...and adding the same page themselves gives them their own private copy, not the first student's.
        $this->actingAs($other)->postJson(route('college-lists.sources.store', $otherSchool), ['url' => $privateUrl, 'official' => true])->assertCreated();
        $this->assertSame(2, KnowledgeSource::query()->where('host', 'lakeside-guide.example')->count());
        $this->assertEqualsCanonicalizing([$this->student->id, $other->id], KnowledgeSource::query()->where('host', 'lakeside-guide.example')->pluck('owner_user_id')->all());
    }

    public function test_only_an_operator_can_verify_an_institution_domain(): void
    {
        $institution = app(InstitutionResolver::class)->findForSchool($this->school);
        $institution->update(['website_domain' => null]);

        $this->artisan('admitme:institution-domain', ['institution' => $institution->slug, 'domain' => 'https://www.Synthetic-Lakeside.edu/admissions'])
            ->expectsOutputToContain('pages on www.synthetic-lakeside.edu now count as official')
            ->assertSuccessful();
        $this->assertSame('www.synthetic-lakeside.edu', $institution->fresh()->website_domain);

        $this->artisan('admitme:institution-domain', ['institution' => $institution->slug, 'domain' => 'not a domain'])->assertFailed();
        $this->artisan('admitme:institution-domain', ['institution' => 'unknown-college', 'domain' => 'unknown.edu'])->assertFailed();
        $this->artisan('admitme:institution-domain', ['institution' => $institution->slug, '--clear' => true])->assertSuccessful();
        $this->assertNull($institution->fresh()->website_domain);
    }

    /**
     * @return array{0: User, 1: CollegeListSchool}
     */
    private function anotherStudentWithLakeside(): array
    {
        $other = User::factory()->create();

        return [$other, CollegeListSchool::factory()->for($other)->create(['school_name' => 'Synthetic Lakeside University', 'school_slug' => 'synthetic-lakeside-university'])];
    }

    public function test_with_no_sources_the_researcher_is_not_asked_to_guess(): void
    {
        ResearcherAgent::fake()->preventStrayPrompts();

        $answer = $this->actingAs($this->student)
            ->postJson(route('college-lists.research', $this->school), ['question' => 'What is the application fee?'])
            ->assertCreated()
            ->json('answer');

        $this->assertStringContainsString('no sources on file', $answer['answer']);
        $this->assertSame(['What is the application fee?'], $answer['unresolved']);
        ResearcherAgent::assertNeverPrompted();
    }

    public function test_private_document_results_stay_with_their_owner(): void
    {
        $other = User::factory()->create();

        foreach ([[$this->student, 'Mathematics grade 7 (owner)'], [$other, 'Mathematics grade 3 (someone else)']] as [$user, $text]) {
            $document = Document::query()->create(['user_id' => $user->id, 'kind' => 'transcript', 'title' => 'Transcript']);
            $version = DocumentVersion::query()->create([
                'document_id' => $document->id, 'user_id' => $user->id, 'version' => 1, 'disk' => 'local', 'path' => "documents/{$user->id}/x.pdf",
                'original_name' => 'transcript.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'status' => 'ready',
            ]);
            $document->update(['current_version_id' => $version->id]);
            DocumentPage::query()->create(['document_version_id' => $version->id, 'user_id' => $user->id, 'page_number' => 1, 'text' => $text]);
        }

        $mine = app(KnowledgeSearch::class)->privateDocumentPages($this->student, 'Transcript mathematics grade');
        $this->assertCount(1, $mine);
        $this->assertStringContainsString('(owner)', $mine->first()['page']->text);

        $theirs = json_decode((string) (new SearchMyDocumentsTool($other))->handle(new Request(['query' => 'mathematics grade'])), true);
        $this->assertCount(1, $theirs['results']);
        $this->assertStringContainsString('someone else', $theirs['results'][0]['text']);
    }

    public function test_knowledge_endpoints_are_limited_to_the_students_own_colleges(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)->getJson(route('college-lists.knowledge', $this->school))->assertNotFound();
        $this->actingAs($other)->postJson(route('college-lists.sources.store', $this->school), ['url' => self::PAGE_URL])->assertNotFound();
        $this->actingAs($other)->postJson(route('college-lists.research', $this->school), ['question' => 'What is the deadline?'])->assertNotFound();
    }
}
