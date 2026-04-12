<?php

namespace App\Models;

use Database\Factories\CollegeListRunEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollegeListRunEvent extends Model
{
    /** @use HasFactory<CollegeListRunEventFactory> */
    use HasFactory, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_MATCH = 'match';

    public const SEVERITY_DEBATE = 'debate';

    public const SEVERITY_DISCARDED = 'discarded';

    protected $fillable = [
        'college_list_run_id',
        'phase',
        'severity',
        'title',
        'body',
        'school_slug',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CollegeListRun::class, 'college_list_run_id');
    }
}
