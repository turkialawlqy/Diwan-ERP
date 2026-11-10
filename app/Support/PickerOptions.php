<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The options of a long list for a dropdown that searches the rest on the server
 * (SelectCreate with `search`): its first records, plus any already chosen on the
 * form so they keep their labels.
 */
final class PickerOptions
{
    /** What the dropdown shows before the user types (SelectCreate lists 100 at most). */
    public const FIRST = 100;

    /**
     * @param  list<string>  $columns  must include the key
     * @param  array<int|string|null>  $chosen  ids already selected
     */
    public static function from(Builder $query, array $columns, array $chosen = []): Collection
    {
        $first = (clone $query)->limit(self::FIRST)->get($columns);
        $missing = array_diff(array_filter(array_map('intval', $chosen)), $first->modelKeys());

        return $missing ? $first->concat($query->whereKey($missing)->get($columns)) : $first;
    }
}
