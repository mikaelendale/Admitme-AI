<?php

namespace App\Support;

use App\Models\OnboardingProfile;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class DashboardHubInsights
{
    /**
     * @return array{
     *     college_list_schools_count: int,
     *     upcoming_deadlines_60d: int,
     *     urgent_deadlines_14d: int,
     *     mentor_conversations_count: int,
     *     list_tiers: array{reach: int, match: int, safety: int}
     * }
     */
    public static function hubStats(User $user): array
    {
        $schools = $user->collegeListSchools()
            ->active()
            ->get(['ea_deadline', 'ed_deadline', 'rd_deadline', 'tier']);

        $today = Carbon::today();
        $horizon60 = Carbon::today()->addDays(60);
        $horizon14 = Carbon::today()->addDays(14);

        $deadlines60 = 0;
        $deadlines14 = 0;
        $tierCounts = ['reach' => 0, 'match' => 0, 'safety' => 0];

        foreach ($schools as $school) {
            if (isset($tierCounts[$school->tier])) {
                $tierCounts[$school->tier]++;
            }
            foreach (['ea_deadline', 'ed_deadline', 'rd_deadline'] as $field) {
                $d = $school->{$field};
                if (! $d instanceof CarbonInterface) {
                    continue;
                }
                if ($d->lt($today)) {
                    continue;
                }
                if ($d->betweenIncluded($today, $horizon60)) {
                    $deadlines60++;
                }
                if ($d->betweenIncluded($today, $horizon14)) {
                    $deadlines14++;
                }
            }
        }

        $conversationsCount = (int) ConversationOwnership::whereUser(DB::table('agent_conversations'), $user->id)
            ->count();

        return [
            'college_list_schools_count' => $schools->count(),
            'upcoming_deadlines_60d' => $deadlines60,
            'urgent_deadlines_14d' => $deadlines14,
            'mentor_conversations_count' => $conversationsCount,
            'list_tiers' => $tierCounts,
        ];
    }

    /**
     * Mirrors {@see HandleInertiaRequests::profileCompletionShare} for dashboard-only nudges.
     *
     * @return array<string, mixed>|null
     */
    public static function profileCompletionSnapshot(User $user): ?array
    {
        if (! $user->onboarded) {
            return null;
        }

        $row = OnboardingProfile::query()->where('user_id', $user->id)->first();
        if ($row === null) {
            return null;
        }

        $score = (int) $row->profile_completeness_score;
        $state = is_array($row->profile_completion_state) ? $row->profile_completion_state : [];
        $completed = isset($state['completed_steps']) && is_array($state['completed_steps'])
            ? $state['completed_steps']
            : [];
        $needsAttention = $score < 40 || count($completed) < 5;

        return [
            'completeness_score' => $score,
            'needs_attention' => $needsAttention,
            'completed_steps' => $completed,
        ];
    }

    /**
     * @param  array{
     *     college_list_schools_count: int,
     *     upcoming_deadlines_60d: int,
     *     urgent_deadlines_14d: int,
     *     mentor_conversations_count: int,
     *     list_tiers: array{reach: int, match: int, safety: int}
     * }  $hubStats
     * @return list<array{id: string, severity: 'info'|'warning'|'urgent', title: string, body: string, href: string, cta: string}>
     */
    public static function nudges(User $user, array $hubStats): array
    {
        $nudges = [];
        $platformAccess = PlatformAccess::sharePayload($user);
        $profileCompletion = self::profileCompletionSnapshot($user);

        if ($platformAccess !== null) {
            $hasAccess = (bool) ($platformAccess['has_access'] ?? false);
            $enforcement = (bool) ($platformAccess['enforcement_enabled'] ?? true);

            if ($enforcement && ! $hasAccess) {
                $nudges[] = [
                    'id' => 'renew_access',
                    'severity' => 'urgent',
                    'title' => 'Activate your access',
                    'body' => 'Subscribe or apply a pass to unlock the full mentor, lists, and workspace.',
                    'href' => route('billing.edit', ['access_required' => 1]),
                    'cta' => 'View billing',
                ];
            } elseif ($hasAccess && ($platformAccess['source'] ?? '') === 'pass') {
                $days = $platformAccess['days_remaining'];
                if (is_int($days) && $days > 0 && $days <= 7) {
                    $nudges[] = [
                        'id' => 'pass_expiring',
                        'severity' => 'warning',
                        'title' => $days === 1 ? '1 day left on your pass' : "{$days} days left on your pass",
                        'body' => 'Renew or upgrade so you keep uninterrupted access.',
                        'href' => route('billing.edit'),
                        'cta' => 'Manage plan',
                    ];
                }
            }
        }

        $hasAccessEffective = $platformAccess === null
            || (bool) ($platformAccess['has_access'] ?? false)
            || ! (bool) ($platformAccess['enforcement_enabled'] ?? true);

        $n = $hubStats['college_list_schools_count'];
        $urgentDeadlines = $hubStats['urgent_deadlines_14d'];

        if ($n === 0 && $hasAccessEffective) {
            $nudges[] = [
                'id' => 'empty_college_list',
                'severity' => 'info',
                'title' => 'Build your college list',
                'body' => 'Add reach, match, and safety schools so deadlines and fit stay organized.',
                'href' => route('college-lists'),
                'cta' => 'Open college lists',
            ];
        } elseif ($urgentDeadlines > 0) {
            $nudges[] = [
                'id' => 'deadlines_soon',
                'severity' => 'warning',
                'title' => $urgentDeadlines === 1
                    ? '1 application deadline in the next 2 weeks'
                    : "{$urgentDeadlines} application deadlines in the next 2 weeks",
                'body' => 'Review dates on your list and confirm portal requirements.',
                'href' => route('college-lists'),
                'cta' => 'Review deadlines',
            ];
        }

        $tiers = $hubStats['list_tiers'];
        if ($n > 0 && ($tiers['safety'] ?? 0) === 0) {
            $nudges[] = [
                'id' => 'no_safeties',
                'severity' => 'warning',
                'title' => 'No safety schools yet',
                'body' => 'Most students need at least one realistic admit — ask your mentor to suggest safeties.',
                'href' => route('college-lists'),
                'cta' => 'View list balance',
            ];
        }

        if ($profileCompletion !== null) {
            $score = (int) ($profileCompletion['completeness_score'] ?? 0);
            $needs = (bool) ($profileCompletion['needs_attention'] ?? false);
            if ($needs || $score < 40) {
                $nudges[] = [
                    'id' => 'profile_incomplete',
                    'severity' => $score < 25 ? 'warning' : 'info',
                    'title' => 'Strengthen your profile',
                    'body' => "You're at {$score}/100 completeness — a fuller profile improves list and essay advice.",
                    'href' => route('profile-overview'),
                    'cta' => 'Complete profile',
                ];
            }
        } elseif ($user->onboarded) {
            $nudges[] = [
                'id' => 'profile_missing',
                'severity' => 'info',
                'title' => 'Finish profile intake',
                'body' => 'Add scores, activities, and goals so recommendations match you.',
                'href' => route('profile-overview'),
                'cta' => 'Open profile',
            ];
        }

        if ($hasAccessEffective && $hubStats['mentor_conversations_count'] === 0 && count($nudges) < 4) {
            $nudges[] = [
                'id' => 'first_chat',
                'severity' => 'info',
                'title' => 'Meet your mentor',
                'body' => 'Ask anything in chat — gap analysis, essay angles, or list strategy.',
                'href' => route('dashboard'),
                'cta' => 'Scroll to chat',
            ];
        }

        usort($nudges, function (array $a, array $b): int {
            $order = ['urgent' => 0, 'warning' => 1, 'info' => 2];

            return ($order[$a['severity']] ?? 3) <=> ($order[$b['severity']] ?? 3);
        });

        $seen = [];
        $out = [];
        foreach ($nudges as $item) {
            $id = $item['id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $item;
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }
}
