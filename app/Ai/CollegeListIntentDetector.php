<?php

namespace App\Ai;

class CollegeListIntentDetector
{
    /**
     * @return 'generate'|'refine'|'add'|'remove'|null
     */
    public static function detect(string $message): ?string
    {
        $lower = mb_strtolower($message);

        $generate = [
            'build',
            'create',
            'make',
            'generate',
            'college list',
            'what schools',
            'help me apply',
        ];
        foreach ($generate as $needle) {
            if (str_contains($lower, $needle)) {
                return 'generate';
            }
        }

        $refine = [
            'change',
            'swap',
            'replace',
            'update my list',
            'different schools',
            'instead of',
        ];
        foreach ($refine as $needle) {
            if (str_contains($lower, $needle)) {
                return 'refine';
            }
        }

        if (preg_match('/\badd\b/', $lower) === 1) {
            return 'add';
        }

        $remove = [
            'remove',
            'delete',
            'drop',
            'take off',
            "don't want",
            'dont want',
        ];
        foreach ($remove as $needle) {
            if (str_contains($lower, $needle)) {
                return 'remove';
            }
        }

        if (preg_match('/\binclude\b/', $lower) === 1 || preg_match('/\bput\b/', $lower) === 1) {
            return 'add';
        }

        if (str_contains($lower, 'also apply to')) {
            return 'add';
        }

        return null;
    }
}
