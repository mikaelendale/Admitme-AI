<?php

namespace Tests\Unit;

use App\Domain\Knowledge\RequirementConflicts;
use PHPUnit\Framework\TestCase;

/**
 * Differently worded labels for the same requirement must be recognised as the same one, so a conflict
 * between sources is never missed because of wording.
 */
class RequirementLabelTest extends TestCase
{
    public function test_common_deadline_and_fee_labels_are_compared_by_meaning(): void
    {
        $label = fn (string $type, string $text) => RequirementConflicts::canonicalLabel($type, $text);

        $this->assertSame($label('deadline', 'Regular Decision deadline'), $label('deadline', 'RD application deadline'));
        $this->assertSame($label('deadline', 'Regular Decision deadline'), $label('deadline', 'Regular decision: application due'));
        $this->assertSame('deadline:ed2', $label('deadline', 'Early Decision II deadline'));
        $this->assertSame('deadline:ed', $label('deadline', 'Early Decision deadline'));
        $this->assertSame('deadline:ea', $label('deadline', 'Early Action deadline'));
        $this->assertNotSame($label('deadline', 'Early Action deadline'), $label('deadline', 'Regular Decision deadline'));
        $this->assertSame($label('fee', 'Application fee'), $label('fee', 'Undergraduate application fee (non-refundable)'));
        $this->assertNotSame($label('fee', 'Application fee'), $label('fee', 'Application fee waiver'), 'A waiver is not another statement of the fee.');
    }
}
