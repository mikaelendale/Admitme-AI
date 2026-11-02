<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Knowledge rows are shared (owner_user_id null) or private to the student who supplied the page.
 * Every read filters by viewer in the query itself, before any search or ranking (D-016).
 */
trait HasKnowledgeVisibility
{
    /**
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('owner_user_id'))->orWhere($this->qualifyColumn('owner_user_id'), $viewer->getKey()));
    }

    public function isPrivate(): bool
    {
        return $this->owner_user_id !== null;
    }
}
