<?php

namespace App\Http\Controllers;

use App\Domain\Writing\WritingCoach;
use App\Domain\Writing\WritingService;
use App\Models\Application;
use App\Models\ApplicationItem;
use App\Models\CollegeListSchool;
use App\Models\WritingFeedback;
use App\Models\WritingPiece;
use App\Models\WritingVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The student's writing workspace: their own text in immutable versions, with policy-bound coach feedback.
 */
class WritingController extends Controller
{
    public function index(Request $request): Response
    {
        $pieces = WritingPiece::query()
            ->where('user_id', $request->user()->getKey())
            ->with('collegeListSchool:id,school_name', 'currentVersion:id,number,word_count,created_at')
            ->latest('updated_at')
            ->get()
            ->map(fn (WritingPiece $piece) => [
                'id' => (string) $piece->id,
                'title' => $piece->title,
                'kind' => $piece->kind,
                'college' => $piece->collegeListSchool?->school_name,
                'wordLimit' => $piece->word_limit,
                'words' => $piece->currentVersion?->word_count,
                'version' => $piece->currentVersion?->number,
                'policy' => $piece->assistance_policy->label(),
                'updatedAt' => $piece->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $colleges = CollegeListSchool::query()
            ->where('user_id', $request->user()->getKey())
            ->whereIn('choice_status', ['shortlisted', 'applying'])
            ->orderBy('school_name')
            ->get(['id', 'school_name'])
            ->map(fn (CollegeListSchool $s) => ['id' => (string) $s->id, 'name' => $s->school_name])
            ->all();

        return Inertia::render('writing/index', ['pieces' => $pieces, 'colleges' => $colleges]);
    }

    public function store(Request $request, WritingService $writing): RedirectResponse
    {
        $school = $request->filled('college_list_school_id')
            ? CollegeListSchool::query()->where('user_id', $request->user()->getKey())->findOrFail($request->string('college_list_school_id')->toString())
            : null;

        $piece = $writing->createPiece($request->user(), $request->only(['title', 'prompt_text', 'word_limit', 'kind']), $school);

        return redirect()->route('writing.show', $piece);
    }

    public function fromEssayStep(Request $request, Application $application, ApplicationItem $item, WritingService $writing): RedirectResponse
    {
        Gate::authorize('update', $application);
        abort_unless((string) $item->application_id === (string) $application->getKey() && $item->kind === 'essay', 404);

        return redirect()->route('writing.show', $writing->forEssayStep($item));
    }

    public function show(Request $request, WritingPiece $piece): Response
    {
        $this->owned($request, $piece);
        $piece->load('collegeListSchool:id,school_name', 'currentVersion');

        return Inertia::render('writing/show', [
            'piece' => [
                'id' => (string) $piece->id,
                'title' => $piece->title,
                'kind' => $piece->kind,
                'college' => $piece->collegeListSchool?->school_name,
                'collegeId' => $piece->college_list_school_id,
                'prompt' => $piece->prompt_text,
                'wordLimit' => $piece->word_limit,
                'policy' => ['value' => $piece->assistance_policy->value, 'label' => $piece->assistance_policy->label(), 'description' => $piece->assistance_policy->description(), 'allowsFeedback' => $piece->assistance_policy->allowsFeedback()],
                'current' => $piece->currentVersion !== null ? $this->version($piece->currentVersion) : null,
            ],
            'versions' => $piece->versions()->get()->map(fn (WritingVersion $v) => $this->version($v))->values()->all(),
            'feedback' => $piece->feedback()->with('version:id,number')->limit(5)->get()->map(fn (WritingFeedback $f) => [
                'id' => (string) $f->id,
                'versionNumber' => $f->version?->number,
                'policy' => $f->assistance_policy,
                'summary' => $f->summary,
                'comments' => $f->comments,
                'droppedComments' => $f->dropped_comments,
                'createdAt' => $f->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function saveVersion(Request $request, WritingPiece $piece, WritingService $writing): RedirectResponse
    {
        $this->owned($request, $piece);
        $validated = $request->validate(['body' => ['required', 'string', 'max:50000'], 'based_on' => ['nullable', 'integer', 'min:0']]);

        $writing->saveVersion($piece, $request->user(), $validated['body'], isset($validated['based_on']) ? (int) $validated['based_on'] : null);

        return back()->with('status', 'version-saved');
    }

    public function feedback(Request $request, WritingPiece $piece, WritingCoach $coach): RedirectResponse
    {
        $this->owned($request, $piece);
        $validated = $request->validate(['version_id' => ['required', 'string', 'size:26']]);
        $version = WritingVersion::query()->where('writing_piece_id', $piece->getKey())->findOrFail($validated['version_id']);

        $coach->feedback($piece, $version, $request->user());

        return back()->with('status', 'feedback-ready');
    }

    /**
     * @return array<string, mixed>
     */
    private function version(WritingVersion $version): array
    {
        return [
            'id' => (string) $version->id,
            'number' => $version->number,
            'body' => $version->body,
            'words' => $version->word_count,
            'author' => $version->author,
            'createdAt' => $version->created_at?->toIso8601String(),
        ];
    }

    private function owned(Request $request, WritingPiece $piece): void
    {
        abort_unless((int) $piece->user_id === (int) $request->user()->getKey(), 404);
    }
}
