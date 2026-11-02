<?php

namespace Tests\Feature\AdmitMe;

use App\Ai\Context\ChatMode;
use App\Ai\Context\MentorToolPolicy;
use App\Domain\Applications\CollegeListService;
use App\Domain\Exports\ExportService;
use App\Domain\Knowledge\KnowledgeSearch;
use App\Domain\Shared\ChangeSource;
use App\Domain\Students\StudentProfileService;
use App\Domain\Writing\WritingService;
use App\Jobs\BuildExportJob;
use App\Models\AdmissionRequirement;
use App\Models\AiContextSnapshot;
use App\Models\AuditEvent;
use App\Models\CollegeListSchool;
use App\Models\DataExport;
use App\Models\Document;
use App\Models\DocumentPage;
use App\Models\DocumentVersion;
use App\Models\Institution;
use App\Models\KnowledgePassage;
use App\Models\KnowledgeSource;
use App\Models\OnboardingProfile;
use App\Models\ResearchAnswer;
use App\Models\StudentFact;
use App\Models\User;
use App\Models\WritingPiece;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\KnowledgeFixtures;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 9 gate: an export reconciles with the snapshot and file versions selected; another user cannot
 * download it; expired links fail; deletion prevents retrieval from active derived stores.
 */
class DataLifecycleTest extends TestCase
{
    use KnowledgeFixtures;
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->student = User::factory()->create();
        OnboardingProfile::factory()->for($this->student)->create();
        OnboardingProfile::query()->where('user_id', $this->student->id)->update(['budget_amount' => 25000, 'budget_currency' => 'USD']);
    }

    private function document(User $owner, string $content = 'Transcript: Mathematics A*, Economics A.'): Document
    {
        $document = Document::query()->create(['user_id' => $owner->id, 'kind' => 'transcript', 'title' => 'School transcript']);
        $path = "documents/{$owner->id}/".Str::random(8).'.txt';
        Storage::disk('local')->put($path, $content);
        $version = DocumentVersion::query()->create([
            'document_id' => $document->id, 'user_id' => $owner->id, 'version' => DocumentVersion::query()->where('document_id', $document->id)->count() + 1, 'disk' => 'local', 'path' => $path,
            'original_name' => 'transcript.txt', 'mime_type' => 'text/plain', 'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content), 'status' => 'ready',
        ]);
        DocumentPage::query()->create(['document_version_id' => $version->id, 'user_id' => $owner->id, 'page_number' => 1, 'text' => $content]);
        $document->update(['current_version_id' => $version->id]);

        return $document->refresh();
    }

    private function zip(DataExport $export): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->archive_path)) === true);

        return $zip;
    }

    public function test_an_export_matches_the_snapshot_taken_when_it_was_requested(): void
    {
        Queue::fake();
        CollegeListSchool::factory()->for($this->student)->count(2)->create();
        $school = CollegeListSchool::factory()->for($this->student)->create(['school_name' => 'Synthetic Lakeside University']);
        app(CollegeListService::class)->update($school, ['choice_status' => 'applying'], null, ChangeSource::studentManual($this->student, 'test'));
        $document = $this->document($this->student);
        $piece = app(WritingService::class)->createPiece($this->student, ['title' => 'Personal statement']);
        app(WritingService::class)->saveVersion($piece, $this->student, 'Version one, in my words.', 0);
        $revision = OnboardingProfile::query()->where('user_id', $this->student->id)->value('revision');

        $export = app(ExportService::class)->request($this->student, ['sections' => ['profile', 'college_list', 'applications', 'tasks', 'writing', 'documents'], 'document_ids' => [$document->id], 'writing_piece_ids' => [$piece->id]], 'workspace');
        Queue::assertPushed(BuildExportJob::class);

        // Edits made while the export waits to be built must not leak into it...
        app(StudentProfileService::class)->update($this->student, ['budget_amount' => 30000], null, ChangeSource::studentManual($this->student, 'test'));
        app(WritingService::class)->saveVersion($piece, $this->student, 'Version two, changed later.', 1);
        $this->document($this->student, 'A different file');

        (new BuildExportJob($export->id))->handle(app(ExportService::class));
        $export->refresh();
        $this->assertSame('ready', $export->status);

        $zip = $this->zip($export);
        $data = json_decode($zip->getFromName('data.json'), true);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);

        $this->assertSame($revision, $data['profile']['revision']);
        $this->assertSame(25000, collect($data['profile']['sections']['preferences']['fields'])->firstWhere('key', 'budget_amount')['value']);
        $this->assertSame('Version one, in my words.', $zip->getFromName('writing/personal-statement-v1.txt'));
        $this->assertFalse($zip->getFromName('writing/personal-statement-v2.txt'));
        $this->assertSame(hash('sha256', 'Transcript: Mathematics A*, Economics A.'), hash('sha256', $zip->getFromName('documents/school-transcript-v1.txt')));
        $this->assertSame((string) $document->current_version_id, $manifest['snapshot']['document_versions'][$document->id]);
        $this->assertCount(4, array_filter(explode("\n", trim($zip->getFromName('college-list.csv')))), 'Header plus three colleges.');
        $this->assertNotFalse($zip->getFromName('application-checklists.csv'));
        $this->assertContains('Passwords, two-factor secrets and login sessions', $manifest['never_included']);
        $this->assertStringNotContainsString('password', strtolower($zip->getFromName('data.json')));
        $this->assertFalse(Storage::disk('local')->exists("{$export->directory}/snapshot.json"), 'The working snapshot is removed once packaged.');
    }

    public function test_only_the_owner_downloads_and_only_before_the_link_expires(): void
    {
        $export = app(ExportService::class)->request($this->student, ['sections' => ['profile']], 'workspace');
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $signed = URL::temporarySignedRoute('exports.download', $export->expires_at, ['export' => $export->id]);

        $this->actingAs(User::factory()->create())->get($signed)->assertNotFound();
        $this->actingAs($this->student)->get(route('exports.download', $export))->assertForbidden(); // unsigned
        $this->actingAs($this->student)->get($signed)->assertOk()->assertDownload();
        $this->assertSame(1, $export->fresh()->download_count);

        $this->travel(25)->hours();
        $this->actingAs($this->student)->get($signed)->assertForbidden(); // the signature expired with it

        $this->artisan('admitme:prune-data')->assertSuccessful();
        $this->assertSame('expired', $export->fresh()->status);
        $this->assertFalse(Storage::disk('local')->exists($export->archive_path));
        $fresh = URL::temporarySignedRoute('exports.download', now()->addHour(), ['export' => $export->id]);
        $this->actingAs($this->student)->get($fresh)->assertStatus(410);
    }

    public function test_deleting_a_document_removes_it_from_every_derived_store(): void
    {
        $document = $this->document($this->student);
        $version = DocumentVersion::query()->where('document_id', $document->id)->sole();
        StudentFact::query()->create(['user_id' => $this->student->id, 'field_key' => 'gpa_value', 'value' => '3.9', 'status' => 'candidate', 'review_state' => StudentFact::REVIEW_PENDING, 'source_type' => 'document', 'source_ref' => ['document_id' => $document->id, 'quote' => 'A*'], 'actor_type' => 'agent', 'profile_revision' => 1]);
        $confirmed = StudentFact::query()->create(['user_id' => $this->student->id, 'field_key' => 'curriculum', 'value' => 'A-Levels', 'status' => 'confirmed', 'review_state' => StudentFact::REVIEW_ACCEPTED, 'source_type' => 'document', 'source_ref' => ['document_id' => $document->id, 'quote' => 'A-Levels'], 'actor_type' => 'student', 'profile_revision' => 2]);
        $export = app(ExportService::class)->request($this->student, ['sections' => ['documents'], 'document_ids' => [$document->id]], 'workspace');
        $this->assertSame('ready', $export->fresh()->status);

        $this->actingAs(User::factory()->create())->delete(route('documents.destroy', $document))->assertNotFound();
        $this->actingAs($this->student)->delete(route('documents.destroy', $document))->assertRedirect(route('documents.index'));

        $this->assertFalse(Storage::disk('local')->exists($version->path), 'The original file is gone…');
        $this->assertSame(0, DocumentPage::query()->count(), '…its searchable text is gone…');
        $this->assertCount(0, app(KnowledgeSearch::class)->privateDocumentPages($this->student, 'Mathematics transcript'));
        $this->assertSame(0, StudentFact::query()->where('status', 'candidate')->count(), '…values it only proposed are gone…');
        $this->assertSame(['document_deleted' => true], $confirmed->fresh()->source_ref, '…a confirmed value stays, unlinked…');
        $this->assertSame('deleted', $export->fresh()->status, '…and the export that contained it is deleted.');
        $this->assertFalse(Storage::disk('local')->exists($export->fresh()->directory.'/admitme-export.zip'));
        $this->assertTrue(AuditEvent::query()->where('action', 'document.deleted')->exists());
    }

    public function test_deleting_a_private_page_removes_its_passages_requirements_and_answers_quoting_it(): void
    {
        $institution = Institution::query()->create(['name' => 'Synthetic Lakeside University', 'slug' => 'synthetic-lakeside-university']);
        [$private, $version] = $this->sourceFor($institution, 'https://lakeside-guide.example/a', KnowledgeSource::TYPE_STUDENT_PROVIDED, $this->student, 'The guide says the deadline is January 15.');
        KnowledgePassage::query()->create(['knowledge_source_version_id' => $version->id, 'institution_id' => $institution->id, 'owner_user_id' => $this->student->id, 'position' => 0, 'text' => 'The guide says the deadline is January 15.']);
        $this->requirementFrom($version, ['requirement_type' => 'deadline', 'label' => 'Regular Decision deadline', 'deadline_date' => '2027-01-15', 'quote' => 'deadline is January 15']);
        $answer = ResearchAnswer::query()->create(['user_id' => $this->student->id, 'institution_id' => $institution->id, 'question' => 'When?', 'answer' => 'January 15 [R1]', 'claims' => [], 'unresolved' => [], 'evidence' => ['R1' => ['url' => 'https://lakeside-guide.example/a', 'quote' => 'deadline is January 15']]]);
        [$shared] = $this->sourceFor($institution, 'https://synthetic-lakeside.edu/apply');

        $this->actingAs($this->student)->delete(route('knowledge-sources.destroy', $shared))->assertNotFound(); // shared pages are not the student's
        $this->actingAs(User::factory()->create())->delete(route('knowledge-sources.destroy', $private))->assertNotFound();
        $this->actingAs($this->student)->delete(route('knowledge-sources.destroy', $private))->assertRedirect();

        $this->assertNull(KnowledgeSource::query()->find($private->id));
        $this->assertSame(0, KnowledgePassage::query()->where('owner_user_id', $this->student->id)->count());
        $this->assertSame(0, AdmissionRequirement::query()->where('owner_user_id', $this->student->id)->count());
        $this->assertNull($answer->fresh(), 'An answer quoting the deleted page is deleted too.');
        $this->assertNotNull(KnowledgeSource::query()->find($shared->id));
    }

    public function test_deleting_writing_removes_versions_feedback_and_exports_with_it(): void
    {
        $piece = app(WritingService::class)->createPiece($this->student, ['title' => 'Essay']);
        app(WritingService::class)->saveVersion($piece, $this->student, 'My essay.', 0);
        $export = app(ExportService::class)->request($this->student, ['sections' => ['writing'], 'writing_piece_ids' => [$piece->id]], 'workspace');

        $this->actingAs($this->student)->delete(route('writing.destroy', $piece))->assertRedirect(route('writing.index'));

        $this->assertNull(WritingPiece::query()->find($piece->id));
        $this->assertSame(0, DB::table('writing_versions')->count());
        $this->assertSame('deleted', $export->fresh()->status);
    }

    public function test_other_throttled_activity_does_not_block_requesting_an_export(): void
    {
        // Found end-to-end: plain "throttle:N,1" routes share one counter per user, so downloads blocked exports.
        foreach (range(1, 10) as $ignored) {
            RateLimiter::hit(sha1((string) $this->student->getAuthIdentifier()), 60);
        }

        $this->actingAs($this->student)->post(route('exports.store'), ['sections' => ['profile']])->assertRedirect();
        $this->assertSame(1, DataExport::query()->count());
    }

    public function test_exports_never_include_another_students_items(): void
    {
        $theirs = $this->document(User::factory()->create(), 'Someone else');

        $export = app(ExportService::class)->request($this->student, ['sections' => ['documents'], 'document_ids' => [$theirs->id]], 'workspace');
        $export->refresh();

        $this->assertSame('Not found among your documents.', $export->manifest['omitted'][0]['reason']);
        $zip = $this->zip($export);
        $this->assertSame(['data.json', 'manifest.json', 'README.txt'], array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1)));
    }

    public function test_deleting_the_account_removes_files_conversations_and_exports(): void
    {
        $document = $this->document($this->student);
        $path = DocumentVersion::query()->where('document_id', $document->id)->value('path');
        $export = app(ExportService::class)->request($this->student, ['sections' => ['profile']], 'workspace');
        $conversation = (string) Str::uuid();
        DB::table('agent_conversations')->insert(['id' => $conversation, 'participant_type' => $this->student->getMorphClass(), 'participant_id' => $this->student->id, 'title' => 'Mine', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_conversation_messages')->insert(['id' => (string) Str::uuid(), 'conversation_id' => $conversation, 'role' => 'user', 'content' => 'private', 'attachments' => '[]', 'steps' => '[]', 'usage' => '[]', 'meta' => '[]', 'agent' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->student)->delete(route('settings.privacy.destroy-account'), ['password' => 'password'])->assertRedirect('/');

        $this->assertNull(User::query()->find($this->student->id));
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertFalse(Storage::disk('local')->exists($export->fresh()?->directory ?? "exports/{$this->student->id}"));
        $this->assertSame(0, DB::table('agent_conversations')->count());
        $this->assertSame(0, DB::table('agent_conversation_messages')->count());
        $this->assertSame(0, Document::query()->count());
    }

    public function test_exports_from_chat_are_agent_mode_only_and_old_operational_records_are_pruned(): void
    {
        $names = fn (ChatMode $mode) => array_map(fn ($tool) => class_basename($tool), app(MentorToolPolicy::class)->toolsFor($this->student, $mode));
        $this->assertContains('RequestExportTool', $names(ChatMode::Agent));
        $this->assertNotContains('RequestExportTool', $names(ChatMode::Ask));

        $old = AiContextSnapshot::query()->create(['user_id' => $this->student->id, 'conversation_id' => (string) Str::uuid(), 'agent' => 'mentor', 'mode' => 'ask', 'profile_revision' => 1, 'instruction_version' => 'x', 'estimated_tokens' => [], 'tools' => []]);
        $old->forceFill(['created_at' => now()->subDays(120)])->save();

        $this->artisan('admitme:prune-data')->assertSuccessful();
        $this->assertNull($old->fresh());
    }
}
