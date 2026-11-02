<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-time code shown in the web app and typed into the extension to link it (stored hashed).
 */
class ExtensionLinkCode extends Model
{
    protected $fillable = ['user_id', 'code_hash', 'expires_at', 'used_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
