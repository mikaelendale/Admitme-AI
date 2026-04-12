<?php

namespace Tests\Unit;

use App\Services\Ai\AiContextService;
use PHPUnit\Framework\TestCase;

class AiContextServiceTest extends TestCase
{
    public function test_count_scalar_leaves_excludes_meta(): void
    {
        $s = new AiContextService;
        $n = $s->countScalarLeaves([
            'a' => 1,
            'b' => 'x',
            '_meta' => ['last_updated' => '2026-01-01', 'x' => 2],
        ]);

        $this->assertSame(2, $n);
    }

    public function test_merge_and_cap_trims_when_over_limit(): void
    {
        $s = new AiContextService;
        $incoming = [];
        for ($i = 0; $i < 120; $i++) {
            $incoming["k{$i}"] = $i;
        }
        $merged = $s->mergeAndCap([], $incoming, 100);
        $this->assertLessThanOrEqual(100, $s->countScalarLeaves($merged));
    }
}
