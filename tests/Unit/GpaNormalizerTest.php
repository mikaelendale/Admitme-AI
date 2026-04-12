<?php

namespace Tests\Unit;

use App\Services\Profile\GpaNormalizer;
use PHPUnit\Framework\TestCase;

class GpaNormalizerTest extends TestCase
{
    public function test_scales_to_four_point(): void
    {
        $n = new GpaNormalizer;
        $out = $n->toUsFourPointScale(3.5, 4.0, 'US 4.0');

        $this->assertSame(3.5, $out['gpa_normalized_4']);
    }
}
