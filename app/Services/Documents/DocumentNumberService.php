<?php

namespace App\Services\Documents;

use App\Models\CompanySetting;
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

    public function __construct(protected TenantManager $tenantManager) {}

    /**
     * Reserve and return the next number. Call inside the transaction that
     * creates the document so a rollback also releases the number.
     */
    public function next(string $type, ?CarbonInterface $date = null, ?int $companyId = null): string
    {
        $companyId ??= $this->companyId();
        $definition = $this->definition($type);
        $scope = $this->scope($definition, $date);
        $prefix = $this->prefix($type, $definition, $companyId, $date);

        return DB::transaction(function () use ($type, $definition, $scope, $prefix, $companyId) {
            $row = $this->lockedRow($companyId, $type, $scope, $prefix, $definition);
            $number = (int) $row->next_number;

            // Guard against numbers already used (e.g. entered manually, imported or
            // left by an older numbering scheme): skip forward to the first free one.
            if ($this->exists($definition, $companyId, $this->format($prefix, $number, $definition['pad']))) {
                $number = $this->firstFree($definition, $companyId, $prefix, $number + 1);
            }

            DB::table('document_sequences')->where('id', $row->id)->update([
                'next_number' => $number + 1,
                'updated_at' => now(),
            ]);

            return $this->format($prefix, $number, $definition['pad']);
        });
    }

    /**
     * Preview the next number without reserving it (for "create" forms).
     */
    public function peek(string $type, ?CarbonInterface $date = null, ?int $companyId = null): string
    {
        $companyId ??= $this->companyId();
        $definition = $this->definition($type);
        $scope = $this->scope($definition, $date);
        $prefix = $this->prefix($type, $definition, $companyId, $date);

        $next = DB::table('document_sequences')
            ->where(['company_id' => $companyId, 'type' => $type, 'scope' => $scope])
            ->value('next_number') ?? $this->initialNumber($definition, $companyId, $prefix);

        return $this->format($prefix, (int) $next, $definition['pad']);
    }

    protected function lockedRow(int $companyId, string $type, string $scope, string $prefix, array $definition): object
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
            'next_number' => $this->initialNumber($definition, $companyId, $prefix),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $query();
    }

    /**
     * First number for a new sequence: continue after the highest existing
     * number with the same prefix so upgrades never collide.
     */
    protected function initialNumber(array $definition, int $companyId, string $prefix): int
    {
        if (empty($definition['table'])) {
            return 1;
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

        return $max + 1;
    }

    /**
     * The first number from $number on that no document uses yet. Candidates are
     * checked a block per query, so a long run of used numbers (an import, data
     * from another system) costs a few queries instead of one per number.
     */
    protected function firstFree(array $definition, int $companyId, string $prefix, int $number): int
    {
        for (; ; $number += self::SKIP_BLOCK) {
            $candidates = [];
            foreach (range($number, $number + self::SKIP_BLOCK - 1) as $candidate) {
                $candidates[$this->format($prefix, $candidate, $definition['pad'])] = $candidate;
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

    protected function prefix(string $type, array $definition, int $companyId, ?CarbonInterface $date): string
    {
        $prefix = CompanySetting::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('key', "{$type}_prefix")
            ->value('value') ?: $definition['prefix'];

        return $definition['yearly'] ? $prefix.($date ?? now())->format('y').'-' : $prefix;
    }

    protected function scope(array $definition, ?CarbonInterface $date): string
    {
        return $definition['yearly'] ? ($date ?? now())->format('Y') : 'all';
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
