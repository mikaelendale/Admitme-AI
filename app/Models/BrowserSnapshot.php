<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A filtered observation of a page: visible form fields with temporary ids, and a hash of their structure.
 */
class BrowserSnapshot extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['browser_session_id', 'user_id', 'url', 'origin', 'page_hash', 'fields'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fields' => 'array'];
    }
}
