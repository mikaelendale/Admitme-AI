<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Agents\DocumentFactExtractorAgent;
use App\Ai\Context\ChatMode;
use App\Ai\Context\ContextBuilder;
use App\Domain\Documents\DocumentVersionStatus;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\FactStatus;
use App\Domain\Students\StudentProfileService;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\JobTracking;
use App\Models\OnboardingProfile;
use App\Models\StudentFact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticPdf;
use Tests\TestCase;

/**
 * Phase 4 gate: an uploaded transcript yields reviewable, sourced candidates; confirmed values reach the
 * profile; a bad scan fails visibly; replacing a transcript keeps history and flags conflicts.
 */
class DocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->student = User::factory()->create();
        OnboardingProfile::factory()->for($this->student)->create(['gpa_value' => null, 'gpa_scale' => null, 'curriculum' => null, 'sat_score' => null]);
    }

    private function transcript(string $gpa = '3.85'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('transcript.pdf', SyntheticPdf::make([
            ['Synthetic Academy - Official Transcript', 'Programme: IB Diploma Programme', "Cumulative GPA: {$gpa} (Scale: 4.0)"],
            ['Mathematics HL 7', 'Expected graduation: June 2027'],
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    private function fakeExtraction(array $candidates): void
    {
        DocumentFactExtractorAgent::fake([['candidates' => $candidates]]);
    }

    private function candidate(string $field, string $value, int $page, string $quote, bool $ambiguous = false, ?string $note = null): array
    {
        return compact('field', 'value', 'page', 'quote', 'ambiguous', 'note');
    }

    private function upload(UploadedFile $file, ?Document $replacing = null): Document
    {
        $route = $replacing ? route('documents.versions.store', $replacing) : route('documents.store');

        $this->actingAs($this->student)->post($route, ['file' => $file, 'kind' => 'transcript'])->assertRedirect();

        return $replacing?->fresh() ?? Document::query()->where('user_id', $this->student->id)->latest('created_at')->firstOrFail();
    }

    public function test_a_transcript_yields_sourced_candidates_and_confirmed_values_reach_the_profile(): void
    {
        $this->fakeExtraction([
            $this->candidate('gpa_value', '3.85', 1, 'Cumulative GPA: 3.85'),
            $this->candidate('gpa_scale', '4.0', 1, '(Scale: 4.0)'),
            $this->candidate('curriculum', 'IB Diploma', 1, 'Programme: IB Diploma Programme'),
            // Not in the document: the "quote" does not appear, so it must be discarded...
            $this->candidate('sat_score', '1520', 1, 'SAT total 1520'),
        ]);

        $document = $this->upload($this->transcript());
        $version = $document->currentVersion;

        $this->assertSame(DocumentVersionStatus::NeedsReview, $version->status);
        $this->assertSame(2, $version->page_count);
        Storage::disk('local')->assertExists($version->path);
        $this->assertSame(hash('sha256', (string) Storage::disk('local')->get($version->path)), $version->sha256);

        $candidates = StudentFact::query()->where('user_id', $this->student->id)->where('status', FactStatus::Candidate)->get()->keyBy('field_key');
        $this->assertEqualsCanonicalizing(['gpa_value', 'gpa_scale', 'curriculum'], $candidates->keys()->all(), 'An unsourced value is never kept.');
        $this->assertSame(1, $candidates['gpa_value']->source_ref['page']);
        $this->assertSame('Cumulative GPA: 3.85', $candidates['gpa_value']->source_ref['quote']);
        $this->assertNull(OnboardingProfile::query()->where('user_id', $this->student->id)->value('gpa_value'), 'Candidates do not change the profile.');

        $this->actingAs($this->student)->get(route('documents.show', $document))
            ->assertInertia(fn ($page) => $page->component('documents/show')->has('values', 3)->has('pages', 2));

        $revision = OnboardingProfile::query()->where('user_id', $this->student->id)->value('revision');

        $this->actingAs($this->student)
            ->post(route('documents.values.decide', $candidates['gpa_value']), ['action' => 'accept', 'expected_revision' => $revision])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->student)
            ->post(route('documents.values.decide', $candidates['curriculum']), ['action' => 'reject'])
            ->assertSessionHasNoErrors();

        $profile = OnboardingProfile::query()->where('user_id', $this->student->id)->firstOrFail();
        $this->assertSame('3.85', (string) $profile->gpa_value);
        $this->assertNull($profile->curriculum, 'A rejected value changes nothing.');

        $confirmed = StudentFact::query()->where('user_id', $this->student->id)->where('field_key', 'gpa_value')->current()->firstOrFail();
        $this->assertSame(FactStatus::Confirmed, $confirmed->status);
        $this->assertSame(ChangeSource::SOURCE_DOCUMENT, $confirmed->source_type);
        $this->assertSame($document->id, $confirmed->source_ref['document_id']);
        $this->assertSame(1, $confirmed->source_ref['page']);

        $this->assertSame(StudentFact::REVIEW_ACCEPTED, $candidates['gpa_value']->fresh()->review_state);
        $this->assertSame(StudentFact::REVIEW_REJECTED, $candidates['curriculum']->fresh()->review_state);
        $this->assertSame(JobTracking::STATUS_COMPLETED, JobTracking::query()->where('job_type', JobTracking::TYPE_DOCUMENT_PROCESSING)->value('status'));
    }

    public function test_an_ambiguous_grade_stays_an_unresolved_candidate_and_is_never_a_fact(): void
    {
        $this->fakeExtraction([
            $this->candidate('gpa_value', '3.85', 1, 'Cumulative GPA: 3.85', true, 'Two different GPAs appear on the transcript.'),
        ]);

        $this->upload($this->transcript());

        $candidate = StudentFact::query()->where('user_id', $this->student->id)->where('field_key', 'gpa_value')->where('source_type', ChangeSource::SOURCE_DOCUMENT)->sole();
        $this->assertSame(FactStatus::Candidate, $candidate->status);
        $this->assertTrue($candidate->source_ref['ambiguous']);
        $this->assertSame(StudentFact::REVIEW_PENDING, $candidate->review_state);
        $this->assertNull(OnboardingProfile::query()->where('user_id', $this->student->id)->value('gpa_value'));

        $context = app(ContextBuilder::class)->forMentor($this->student, ChatMode::Ask);
        $this->assertStringContainsString('1 value(s) read from the student\'s documents are waiting for their review', $context->instructions);
        $this->assertStringNotContainsString('3.85', $context->instructions, 'An unconfirmed value never reaches the mentor as a fact.');
    }

    public function test_a_scan_without_text_fails_visibly_and_offers_manual_entry(): void
    {
        DocumentFactExtractorAgent::fake()->preventStrayPrompts();

        $this->upload(UploadedFile::fake()->image('scan.png', 800, 1000));
        $scan = Document::query()->where('user_id', $this->student->id)->sole()->currentVersion;

        $this->assertSame(DocumentVersionStatus::Failed, $scan->status);
        $this->assertStringContainsString('Text recognition (OCR) is not set up', $scan->failure_reason);
        $this->assertStringContainsString('Enter them manually', $scan->failure_reason);

        $this->upload(UploadedFile::fake()->createWithContent('scanned.pdf', SyntheticPdf::make([[], []])));
        $blank = Document::query()->where('user_id', $this->student->id)->where('title', 'scanned')->firstOrFail()->currentVersion;

        $this->assertSame(DocumentVersionStatus::Failed, $blank->status);
        $this->assertStringContainsString('No readable text was found', $blank->failure_reason);
        $this->assertSame(0, StudentFact::query()->where('status', FactStatus::Candidate)->count());
        DocumentFactExtractorAgent::assertNeverPrompted();

        $this->actingAs($this->student)->get(route('documents.index'))
            ->assertInertia(fn ($page) => $page->has('documents', 2)->where('documents.0.status', 'failed')->where('documents.1.status', 'failed'));
    }

    public function test_replacing_a_transcript_keeps_history_and_flags_conflicts_without_overwriting(): void
    {
        app(StudentProfileService::class)->update($this->student, ['gpa_value' => '3.60', 'gpa_scale' => '4.0'], null, ChangeSource::studentManual($this->student, 'test'));

        DocumentFactExtractorAgent::fake([
            ['candidates' => [$this->candidate('gpa_value', '3.70', 1, 'Cumulative GPA: 3.70')]],
            ['candidates' => [$this->candidate('gpa_value', '3.90', 1, 'Cumulative GPA: 3.90')]],
        ]);

        $document = $this->upload($this->transcript('3.70'));
        $first = StudentFact::query()->where('user_id', $this->student->id)->where('field_key', 'gpa_value')->whereIn('status', [FactStatus::Candidate, FactStatus::Conflict])->sole();
        $this->assertSame(FactStatus::Conflict, $first->status, 'A value that disagrees with the confirmed one is a conflict.');
        $oldVersion = $document->currentVersion;

        $this->upload($this->transcript('3.90'), $document);

        $document->refresh();
        $this->assertCount(2, $document->versions);
        $this->assertSame(DocumentVersionStatus::Superseded, $oldVersion->fresh()->status);
        Storage::disk('local')->assertExists($oldVersion->path);
        $this->assertSame(StudentFact::REVIEW_SUPERSEDED, $first->fresh()->review_state);

        // The retired value can no longer be accepted...
        $this->actingAs($this->student)->postJson(route('documents.values.decide', $first), ['action' => 'accept'])->assertStatus(409);

        $second = StudentFact::query()->where('user_id', $this->student->id)->where('review_state', StudentFact::REVIEW_PENDING)->sole();
        $this->assertSame(FactStatus::Conflict, $second->status);
        $this->assertSame($document->current_version_id, $second->source_ref['document_version_id']);
        $this->assertSame('3.60', (string) OnboardingProfile::query()->where('user_id', $this->student->id)->value('gpa_value'), 'A confirmed grade is never silently overwritten.');
    }

    public function test_an_identical_file_is_not_stored_as_a_new_version(): void
    {
        DocumentFactExtractorAgent::fake([['candidates' => []]]);

        $document = $this->upload($this->transcript());

        $this->actingAs($this->student)
            ->from(route('documents.show', $document))
            ->post(route('documents.versions.store', $document), ['file' => $this->transcript()])
            ->assertSessionHasErrors('file');

        $this->assertSame(1, DocumentVersion::query()->where('document_id', $document->id)->count());
        $this->assertSame(DocumentVersionStatus::Ready, $document->fresh()->currentVersion->status);
    }

    public function test_another_student_cannot_see_download_replace_or_review(): void
    {
        $this->fakeExtraction([$this->candidate('gpa_value', '3.85', 1, 'Cumulative GPA: 3.85')]);
        $document = $this->upload($this->transcript());
        $candidate = StudentFact::query()->where('user_id', $this->student->id)->where('status', FactStatus::Candidate)->sole();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('documents.show', $document))->assertNotFound();
        $this->actingAs($other)->get(route('documents.versions.file', [$document, $document->current_version_id]))->assertNotFound();
        $this->actingAs($other)->post(route('documents.versions.store', $document), ['file' => $this->transcript('3.10')])->assertNotFound();
        $this->actingAs($other)->post(route('documents.values.decide', $candidate), ['action' => 'accept'])->assertNotFound();
        $this->actingAs($other)->get(route('documents.index'))->assertInertia(fn ($page) => $page->has('documents', 0));

        $this->assertSame(StudentFact::REVIEW_PENDING, $candidate->fresh()->review_state);
        $this->actingAs($this->student)->get(route('documents.versions.file', [$document, $document->current_version_id]))->assertOk();
    }
}
