<?php

namespace Tests\Feature\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Two requests racing cannot be staged inside one test, so a fix that makes them wait for each other is checked by
 * what it reads under lock. SQLite ignores row locks and drops the clause from its SQL: here it is kept as a comment.
 */
trait ObservesRowLocks
{
    /**
     * Every query $work ran, in order, each as ['table' => …, 'locked' => bool].
     *
     * @return list<array{table:?string,locked:bool,sql:string}>
     */
    protected function queriesOf(callable $work): array
    {
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
            {
                protected function compileLock(Builder $query, $value)
                {
                    return '/* for update */';
                }
            });
        }

        $queries = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries) {
            preg_match('/^\s*(?:select .*? from|insert(?: or ignore| ignore)? into|update|delete from)\s+[`"]?(\w+)[`"]?/is', $event->sql, $match);
            $queries[] = ['table' => $match[1] ?? null, 'locked' => (bool) preg_match('/for update/i', $event->sql), 'sql' => $event->sql];
        });

        try {
            $work();
        } finally {
            Event::forget(QueryExecuted::class);
            $connection->useDefaultQueryGrammar();
        }

        return $queries;
    }

    /** @return list<string> the tables $work read under a row lock, in the order it took them */
    protected function tablesLockedBy(callable $work): array
    {
        return array_values(array_unique(array_column(array_filter($this->queriesOf($work), fn ($q) => $q['locked']), 'table')));
    }
}
