<?php

namespace App\Support;

use App\Ai\Agents\AdmitMeGeneralAgent;
use App\Models\FeatureUsage;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon as IlluminateCarbon;
use Illuminate\Support\Facades\DB;

final class FeatureQuotaManager
{
    /** @var array<string, string> */
    public const FEATURE_LABELS = [
        'gap_analysis' => 'gap analyses',
        'fix_roadmap' => 'fix roadmap runs',
        'college_list' => 'college list generations',
        'essay_roast' => 'essay roasts',
        'essay_draft' => 'essay draft runs',
        'cliche_detector' => 'cliché detector runs',
        'export_college_list' => 'college list exports',
        'chat_conversation' => 'chats',
        'chat_message' => 'mentor messages',
        'chat_message_per_convo' => 'messages in this chat',
    ];

    /**
     * Internal config bucket: trial | paid.
     */
    public function resolvePlanBucket(User $user): string
    {
        if (! PlatformAccess::enforcementEnabled()) {
            return 'paid';
        }

        if ($user->subscribed('default')) {
            return 'paid';
        }

        $passActive = $this->passIsActive($user->platform_pass_ends_at);
        if ($passActive && $user->platform_pass_plan === 'sprint') {
            return 'paid';
        }

        if ($passActive && $user->platform_pass_plan === 'trial') {
            return 'trial';
        }

        return 'trial';
    }

    /**
     * User-facing: trial | sprint | pro.
     */
    public function resolveUserFacingPlan(User $user): string
    {
        if (! PlatformAccess::enforcementEnabled()) {
            return 'pro';
        }

        if ($user->subscribed('default')) {
            return 'pro';
        }

        $passActive = $this->passIsActive($user->platform_pass_ends_at);
        if ($passActive && $user->platform_pass_plan === 'sprint') {
            return 'sprint';
        }

        if ($passActive && $user->platform_pass_plan === 'trial') {
            return 'trial';
        }

        return 'trial';
    }

    public function planLabel(User $user): string
    {
        return match ($this->resolveUserFacingPlan($user)) {
            'pro' => 'Pro',
            'sprint' => 'Sprint',
            default => 'Trial',
        };
    }

    private function passIsActive(mixed $ends): bool
    {
        if (! $ends instanceof DateTimeInterface) {
            return false;
        }

        return IlluminateCarbon::parse($ends)->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    private function planConfig(User $user): array
    {
        $bucket = $this->resolvePlanBucket($user);

        return (array) config("feature_limits.plans.{$bucket}", []);
    }

    public function limit(User $user, string $feature): ?int
    {
        $cfg = $this->planConfig($user);
        $features = isset($cfg['features']) && is_array($cfg['features']) ? $cfg['features'] : [];

        if (! array_key_exists($feature, $features)) {
            return null;
        }

        $v = $features[$feature];

        return is_int($v) ? $v : null;
    }

    public function windowDays(User $user): ?int
    {
        $cfg = $this->planConfig($user);
        $w = $cfg['window'] ?? null;

        return is_int($w) ? $w : null;
    }

    public function windowStart(User $user): ?IlluminateCarbon
    {
        $days = $this->windowDays($user);
        if ($days === null) {
            return null;
        }

        return IlluminateCarbon::now()->subDays($days);
    }

    public function used(User $user, string $feature, ?string $scopeId = null): int
    {
        return match ($feature) {
            'chat_conversation' => $this->countChatConversations($user),
            'chat_message' => $this->countChatUserMessagesInWindow($user),
            'chat_message_per_convo' => $scopeId !== null
                ? $this->countChatUserMessagesInConversation($user, $scopeId)
                : 0,
            default => $this->countFeatureUsageEvents($user, $feature),
        };
    }

    public function remaining(User $user, string $feature, ?string $scopeId = null): ?int
    {
        $limit = $this->limit($user, $feature);
        if ($limit === null) {
            return null;
        }

        $used = $this->used($user, $feature, $scopeId);

        return max(0, $limit - $used);
    }

    public function canUse(User $user, string $feature, ?string $scopeId = null): bool
    {
        $limit = $this->limit($user, $feature);
        if ($limit === null) {
            return true;
        }

        if ($limit <= 0) {
            return false;
        }

        return $this->used($user, $feature, $scopeId) < $limit;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(User $user, string $feature, ?array $metadata = null): void
    {
        FeatureUsage::query()->create([
            'user_id' => $user->id,
            'feature' => $feature,
            'metadata' => $metadata,
        ]);
    }

    public function hasBinaryFeature(User $user, string $key): bool
    {
        $cfg = $this->planConfig($user);
        $binary = isset($cfg['binary']) && is_array($cfg['binary']) ? $cfg['binary'] : [];

        return isset($binary[$key]) && $binary[$key] === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function featureStatus(User $user, string $feature, ?string $scopeId = null): array
    {
        $limit = $this->limit($user, $feature);
        $used = $this->used($user, $feature, $scopeId);
        $remaining = $this->remaining($user, $feature, $scopeId);
        $threshold = (float) config('feature_limits.warning_threshold', 0.8);
        $warning = $limit !== null && $limit > 0 && ($used / $limit) >= $threshold;

        return [
            'feature' => $feature,
            'label' => self::FEATURE_LABELS[$feature] ?? $feature,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'warning' => $warning,
            'resets_at' => $this->nextResetAt($user, $feature),
        ];
    }

    public function nextResetAt(User $user, string $feature): ?string
    {
        if ($this->windowDays($user) === null) {
            return null;
        }

        $oldest = match ($feature) {
            'chat_message' => $this->oldestChatUserMessageTime($user),
            default => $this->oldestFeatureUsageTime($user, $feature),
        };

        if ($oldest === null) {
            return null;
        }

        $days = (int) $this->windowDays($user);

        return $oldest->copy()->addDays($days)->toIso8601String();
    }

    private function oldestChatUserMessageTime(User $user): ?IlluminateCarbon
    {
        $start = $this->windowStart($user);
        if ($start === null) {
            return null;
        }

        $row = DB::table('agent_conversation_messages')
            ->where('user_id', $user->id)
            ->where('agent', AdmitMeGeneralAgent::class)
            ->where('role', 'user')
            ->where('created_at', '>=', $start)
            ->orderBy('created_at')
            ->first(['created_at']);

        if ($row === null || ! isset($row->created_at)) {
            return null;
        }

        return IlluminateCarbon::parse($row->created_at);
    }

    private function oldestFeatureUsageTime(User $user, string $feature): ?IlluminateCarbon
    {
        $start = $this->windowStart($user);
        if ($start === null) {
            return null;
        }

        $row = FeatureUsage::query()
            ->where('user_id', $user->id)
            ->where('feature', $feature)
            ->where('created_at', '>=', $start)
            ->orderBy('created_at')
            ->first(['created_at']);

        if ($row === null || $row->created_at === null) {
            return null;
        }

        return IlluminateCarbon::parse($row->created_at);
    }

    private function countChatConversations(User $user): int
    {
        return (int) DB::table('agent_conversations')
            ->where('user_id', $user->id)
            ->count();
    }

    private function countChatUserMessagesInWindow(User $user): int
    {
        $start = $this->windowStart($user);
        if ($start === null) {
            return 0;
        }

        return (int) DB::table('agent_conversation_messages')
            ->where('user_id', $user->id)
            ->where('agent', AdmitMeGeneralAgent::class)
            ->where('role', 'user')
            ->where('created_at', '>=', $start)
            ->count();
    }

    private function countChatUserMessagesInConversation(User $user, string $conversationId): int
    {
        return (int) DB::table('agent_conversation_messages')
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversationId)
            ->where('agent', AdmitMeGeneralAgent::class)
            ->where('role', 'user')
            ->count();
    }

    private function countFeatureUsageEvents(User $user, string $feature): int
    {
        $start = $this->windowStart($user);
        $q = FeatureUsage::query()
            ->where('user_id', $user->id)
            ->where('feature', $feature);

        if ($start !== null) {
            $q->where('created_at', '>=', $start);
        }

        return (int) $q->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function usageSummaryForInertia(User $user): array
    {
        $keys = [
            'gap_analysis',
            'fix_roadmap',
            'college_list',
            'essay_roast',
            'essay_draft',
            'cliche_detector',
            'export_college_list',
        ];

        $features = [];
        foreach ($keys as $key) {
            $limit = $this->limit($user, $key);
            $used = $this->used($user, $key);
            $remaining = $this->remaining($user, $key);
            $threshold = (float) config('feature_limits.warning_threshold', 0.8);
            $warning = $limit !== null && $limit > 0 && ($used / $limit) >= $threshold;

            $features[$key] = [
                'limit' => $limit,
                'used' => $used,
                'remaining' => $remaining,
                'warning' => $warning,
                'resets_at' => $this->nextResetAt($user, $key),
                'label' => self::FEATURE_LABELS[$key] ?? $key,
            ];
        }

        $bucket = $this->resolvePlanBucket($user);
        $chat = $bucket === 'trial'
            ? $this->chatTrialSummary($user)
            : $this->chatPaidSummary($user);

        $cfg = $this->planConfig($user);
        $binary = isset($cfg['binary']) && is_array($cfg['binary']) ? $cfg['binary'] : [];

        $windowDays = $this->windowDays($user);

        return [
            'plan' => $this->resolveUserFacingPlan($user),
            'plan_label' => $this->planLabel($user),
            'plan_bucket' => $bucket,
            'window_days' => $windowDays,
            'resets_at' => $windowDays !== null ? $this->globalResetHint($user) : null,
            'features' => $features,
            'binary' => $binary,
            'chat' => $chat,
        ];
    }

    private function globalResetHint(User $user): ?string
    {
        // Earliest next reset across tracked rolling features (informational).
        $times = [];
        foreach (['gap_analysis', 'chat_message'] as $f) {
            $t = $this->nextResetAt($user, $f);
            if ($t !== null) {
                $times[] = $t;
            }
        }
        if ($times === []) {
            return null;
        }

        sort($times);

        return $times[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function chatTrialSummary(User $user): array
    {
        $convLimit = $this->limit($user, 'chat_conversation');
        $convUsed = $this->used($user, 'chat_conversation');
        $perLimit = $this->limit($user, 'chat_message_per_convo');

        return [
            'mode' => 'trial',
            'conversations_limit' => $convLimit,
            'conversations_used' => $convUsed,
            'conversations_remaining' => $this->remaining($user, 'chat_conversation'),
            'messages_per_convo_limit' => $perLimit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chatPaidSummary(User $user): array
    {
        $limit = $this->limit($user, 'chat_message');
        $used = $this->used($user, 'chat_message');
        $remaining = $this->remaining($user, 'chat_message');
        $threshold = (float) config('feature_limits.warning_threshold', 0.8);
        $warning = $limit !== null && $limit > 0 && ($used / $limit) >= $threshold;

        return [
            'mode' => 'paid',
            'messages_limit' => $limit,
            'messages_used' => $used,
            'messages_remaining' => $remaining,
            'warning' => $warning,
            'resets_at' => $this->nextResetAt($user, 'chat_message'),
        ];
    }
}
