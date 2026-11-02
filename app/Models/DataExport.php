<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One export of a student's data: a snapshot captured when requested, built into a private archive.
 */
class DataExport extends Model
{
    use HasUlids;

    public const SCHEMA_VERSION = '1.0';

    public const SECTIONS = ['profile', 'college_list', 'applications', 'tasks', 'writing', 'documents'];

    protected $fillable = ['user_id', 'status', 'selection', 'schema_version', 'snapshot', 'requested_via', 'disk', 'directory', 'archive_path', 'archive_size', 'archive_sha256', 'manifest', 'status_reason', 'download_count', 'ready_at', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'selection' => 'array',
            'snapshot' => 'array',
            'manifest' => 'array',
            'ready_at' => 'datetime',
            'expires_at' => 'datetime',
            'download_count' => 'integer',
            'archive_size' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDownloadable(): bool
    {
        return $this->status === 'ready' && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
