<?php

namespace Tests\Unit;

use App\Services\GapFix\GapFixAnalysisPayloadValidator;
use PHPUnit\Framework\TestCase;

class GapFixAnalysisPayloadValidatorTest extends TestCase
{
    public function test_full_match_when_all_schools_present(): void
    {
        $v = new GapFixAnalysisPayloadValidator;
        $ids = ['01hxyz', '01habc'];
        $payload = [
            'rejection_risks' => [
                ['college_list_school_id' => '01hxyz', 'reasons' => ['a', 'b', 'c']],
                ['college_list_school_id' => '01habc', 'reasons' => ['d', 'e', 'f']],
            ],
            'gaps' => [
                [
                    'id' => 'g1',
                    'severity' => 'critical',
                    'title' => 'T',
                    'steps' => ['s1', 's2', 's3'],
                ],
            ],
            'disclaimer' => null,
        ];

        $r = $v->validate($payload, $ids);
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['partial']);
        $this->assertSame([], $r['missing_school_ids']);
        $risks = $r['payload']['rejection_risks'];
        $this->assertSame('moderate', $risks[0]['reasons'][0]['severity']);
        $this->assertSame('a', $risks[0]['reasons'][0]['text']);
    }

    public function test_full_match_accepts_structured_reasons(): void
    {
        $v = new GapFixAnalysisPayloadValidator;
        $ids = ['01hxyz'];
        $payload = [
            'rejection_risks' => [
                [
                    'college_list_school_id' => '01hxyz',
                    'reasons' => [
                        ['text' => 'x', 'severity' => 'critical'],
                        ['text' => 'y', 'severity' => 'moderate'],
                        ['text' => 'z', 'severity' => 'minor'],
                    ],
                ],
            ],
            'gaps' => [
                [
                    'id' => 'g1',
                    'severity' => 'critical',
                    'title' => 'T',
                    'steps' => ['s1', 's2', 's3'],
                ],
            ],
        ];

        $r = $v->validate($payload, $ids);
        $this->assertTrue($r['ok']);
        $this->assertSame('critical', $r['payload']['rejection_risks'][0]['reasons'][0]['severity']);
    }

    public function test_partial_when_one_school_missing(): void
    {
        $v = new GapFixAnalysisPayloadValidator;
        $ids = ['01hxyz', '01habc'];
        $payload = [
            'rejection_risks' => [
                ['college_list_school_id' => '01hxyz', 'reasons' => ['a', 'b', 'c']],
            ],
            'gaps' => [
                [
                    'id' => 'g1',
                    'severity' => 'moderate',
                    'title' => 'T',
                    'steps' => ['s1', 's2', 's3', 's4'],
                ],
            ],
        ];

        $r = $v->validate($payload, $ids);
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['partial']);
        $this->assertSame(['01habc'], $r['missing_school_ids']);
    }

    public function test_fails_on_duplicate_school_rows(): void
    {
        $v = new GapFixAnalysisPayloadValidator;
        $ids = ['01hxyz'];
        $payload = [
            'rejection_risks' => [
                ['college_list_school_id' => '01hxyz', 'reasons' => ['a', 'b', 'c']],
                ['college_list_school_id' => '01hxyz', 'reasons' => ['d', 'e', 'f']],
            ],
            'gaps' => [
                [
                    'id' => 'g1',
                    'severity' => 'minor',
                    'title' => 'T',
                    'steps' => ['s1', 's2', 's3'],
                ],
            ],
        ];

        $r = $v->validate($payload, $ids);
        $this->assertFalse($r['ok']);
    }
}
