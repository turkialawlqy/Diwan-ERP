<?php

namespace App\Services\Documents;

use App\Services\TenantManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Gap-resistant, race-safe document numbering backed by the
 * document_sequences table (one row per company / type / year, locked with
 * SELECT ... FOR UPDATE while the next number is taken).
 */
class DocumentNumberService
{
    /** Numbers checked per query while skipping used ones (well under SQLite's 999 bound values). */
    protected const SKIP_BLOCK = 500;

    public function __construct(protected TenantManager $tenantManager, protected NumberingRules $rules) {}

    /**
     * Reserve and return the next number. Call inside the transaction that
     * creates the document so a rollback also releases the number.
     */
    public function next(string $type, ?CarbonInterface $date = null, ?int $companyId = null): string
    {
        $companyId ??= $this->companyId();
        $definition = $this->definition($type);
        $rule = $this->rules->resolve($type, $companyId, $date);
        $scope = $this->scope($rule, $date);
        $prefix = $this->prefix($rule, $date);

        return DB::transaction(function () use ($type, $definition, $rule, $scope, $prefix, $companyId) {
            $row = $this->lockedRow($companyId, $type, $scope, $prefix, $definition, $rule);
            $number = (int) $row->next_number;

            // Guard against numbers already used (e.g. entered manually, imported or
            // left by an older numbering scheme): skip forward to the first free one.
            if ($this->exists($definition, $companyId, $this->format($prefix, $number, $rule['pad']))) {
                $number = $this->firstFree($definition, $companyId, $prefix, (int) $rule['pad'], $number + 1);
            }

            DB::table('document_sequences')->where('id', $row->id)->update([
                'next_number' => $number + 1,
                'updated_at' => now(),
            ]);

            return $this->format($prefix, $number, $rule['pad']);
        });
    }

    /**
     * Preview the next number without reserving it (for "create" forms): the number next()
     * would give now, so a number already used is never offered.
     */
    public function peek(string $type, ?CarbonInterface $date = null, ?int $companyId = null): string
    {
        $companyId ??= $this->companyId();
        $definition = $this->definition($type);
        $rule = $this->rules->resolve($type, $companyId, $date);
        $scope = $this->scope($rule, $date);
        $prefix = $this->prefix($rule, $date);

        $next = (int) (DB::table('document_sequences')
            ->where(['company_id' => $companyId, 'type' => $type, 'scope' => $scope])
            ->value('next_number') ?? $this->initialNumber($definition, $companyId, $prefix, $rule));

        // A number taken outside the sequence (typed as suggested, imported) is skipped, as next() skips it.
        if ($this->exists($definition, $companyId, $this->format($prefix, $next, $rule['pad']))) {
            $next = $this->firstFree($definition, $companyId, $prefix, (int) $rule['pad'], $next + 1);
        }

        return $this->format($prefix, $next, $rule['pad']);
    }

    protected function lockedRow(int $companyId, string $type, string $scope, string $prefix, array $definition, array $rule): object
    {
        $query = fn () => DB::table('document_sequences')
            ->where(['company_id' => $companyId, 'type' => $type, 'scope' => $scope])
            ->lockForUpdate()
            ->first();

        if ($row = $query()) {
            return $row;
        }

        DB::table('document_sequences')->insertOrIgnore([
            'company_id' => $companyId,
            'type' => $type,
            'scope' => $scope,
            'next_number' => $this->initialNumber($definition, $companyId, $prefix, $rule),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $query();
    }

    /**
     * First number for a new sequence: continue after the highest existing
     * number with the same prefix so upgrades never collide.
     */
    protected function initialNumber(array $definition, int $companyId, string $prefix, array $rule): int
    {
        $start = max(1, (int) $rule['start_number']);

        if (empty($definition['table'])) {
            return $start;
        }

        $max = 0;

        // Paged by id rather than OFFSET, so the scan stays linear on a large table.
        DB::table($definition['table'])
            ->where('company_id', $companyId)
            ->where($definition['column'], 'like', addcslashes($prefix, '%_').'%')
            ->select(['id', $definition['column']])
            ->chunkById(1000, function ($rows) use (&$max, $definition, $prefix) {
                foreach ($rows as $row) {
                    $rest = substr((string) $row->{$definition['column']}, strlen($prefix));
                    if ($rest !== '' && ctype_digit($rest)) {
                        $max = max($max, (int) $rest);
                    }
                }
            });

        return max($start, $max + 1);
    }

    /**
     * The first number from $number on that no document uses yet. Candidates are
     * checked a block per query, so a long run of used numbers (an import, data
     * from another system) costs a few queries instead of one per number.
     */
    protected function firstFree(array $definition, int $companyId, string $prefix, int $pad, int $number): int
    {
        for (; ; $number += self::SKIP_BLOCK) {
            $candidates = [];
            foreach (range($number, $number + self::SKIP_BLOCK - 1) as $candidate) {
                $candidates[$this->format($prefix, $candidate, $pad)] = $candidate;
            }

            $used = DB::table($definition['table'])
                ->where('company_id', $companyId)
                ->whereIn($definition['column'], array_keys($candidates))
                ->pluck($definition['column'])
                // MySQL's default collation matches numbers case-insensitively: compare the same way.
                ->map(fn ($value) => mb_strtolower((string) $value))
                ->flip();

            foreach ($candidates as $formatted => $candidate) {
                if (! $used->has(mb_strtolower($formatted))) {
                    return $candidate;
                }
            }
        }
    }

    protected function exists(array $definition, int $companyId, string $number): bool
    {
        if (empty($definition['table'])) {
            return false;
        }

        return DB::table($definition['table'])
            ->where('company_id', $companyId)
            ->where($definition['column'], $number)
            ->exists();
    }

    protected function prefix(array $rule, ?CarbonInterface $date): string
    {
        $base = $rule['with_year'] ? $rule['prefix'].($date ?? now())->format('y').'-' : $rule['prefix'];

        return $base.$this->nodeTag();
    }

    /**
     * A branch server running offline numbers on its own (document_sequences never travels), so its numbers carry the branch's
     * node: INV-26-B2-000123. Two servers can then never issue the same number, and the head office keeps the plain form.
     */
    protected function nodeTag(): string
    {
        if (config('sync-node.role') !== 'branch') {
            return '';
        }
        $node = (int) DB::table('sync_state')->where('id', 1)->value('node_id');

        return $node > 1 ? "B{$node}-" : '';
    }

    protected function scope(array $rule, ?CarbonInterface $date): string
    {
        $scope = $rule['with_year'] ? ($date ?? now())->format('Y') : 'all';

        return $this->nodeTag() === '' ? $scope : $scope.'-'.rtrim($this->nodeTag(), '-');
    }

    protected function format(string $prefix, int $number, int $pad): string
    {
        return $prefix.str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
    }

    protected function definition(string $type): array
    {
        $definition = config("documents.types.{$type}");

        if (! $definition) {
            throw new InvalidArgumentException("Unknown document type [{$type}].");
        }

        return $definition;
    }

    protected function companyId(): int
    {
        $companyId = $this->tenantManager->getTenantId();

        if (! $companyId) {
            throw new \RuntimeException('Document numbers require a resolved company.');
        }

        return $companyId;
    }
}
