<?php

namespace App\Support\EssaySuite;

use App\Ai\Tools\GetStudentAdmissionContextTool;
use App\Models\User;

/**
 * Shared compact profile envelope for essay-suite agents (mirrors tool facts).
 */
final class EssayStudentContext
{
    /**
     * @return array<string, mixed>
     */
    public static function compact(User $user): array
    {
        $tool = new GetStudentAdmissionContextTool($user);

        return $tool->buildPayload($user, 'compact');
    }
}
