<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generates a large synthetic dataset for one company with bulk inserts and
 * measures the hot queries, to validate indexes before going live:
 *
 *   php artisan erp:load-test {company} --invoices=1000000
 *
 * Never run against a production company.
 */
class LoadTest extends Command
{
    protected $signature = 'erp:load-test {company : Company id} {--invoices=100000} {--customers=5000} {--chunk=2000} {--benchmark-only}';

    protected $description = 'Bulk-generate invoices / journal lines for a company and benchmark hot queries';

    public function handle(TenantManager $tenantManager): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $company = Company::findOrFail($this->argument('company'));

        if (! $this->option('benchmark-only')) {
            $this->generate($company);
        }

        $tenantManager->runAs($company, fn () => $this->benchmark($company));

        return self::SUCCESS;
    }

    protected function generate(Company $company): void
    {
        $chunk = (int) $this->option('chunk');
        $now = now()->toDateTimeString();

        $this->info('Customers...');
        $start = (int) DB::table('customers')->max('id');
        for ($i = 0; $i < (int) $this->option('customers'); $i += $chunk) {
            $rows = [];
            for ($j = $i; $j < min($i + $chunk, (int) $this->option('customers')); $j++) {
                $rows[] = ['company_id' => $company->id, 'code' => 'LT-'.($start + $j), 'name' => 'عميل اختبار '.($start + $j), 'customer_type' => 'commercial', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('customers')->insert($rows);
        }
        $customerIds = DB::table('customers')->where('company_id', $company->id)->pluck('id')->all();

        $accounts = DB::table('accounts')->where('company_id', $company->id)->where('is_parent', false)->pluck('id')->all();

        $total = (int) $this->option('invoices');
        $bar = $this->output->createProgressBar($total);
        $base = (int) DB::table('invoices')->max('id');

        for ($i = 0; $i < $total; $i += $chunk) {
            DB::transaction(function () use ($i, $chunk, $total, $company, $customerIds, $accounts, $now, $base) {
                $invoices = [];
                $count = min($chunk, $total - $i);
                for ($j = 0; $j < $count; $j++) {
                    $n = $base + $i + $j + 1;
                    $amount = random_int(100, 50000);
                    $date = now()->subDays(random_int(0, 1095))->toDateString();
                    $invoices[] = [
                        'company_id' => $company->id,
                        'customer_id' => $customerIds[array_rand($customerIds)],
                        'invoice_number' => 'LT-'.$n,
                        'invoice_date' => $date,
                        'subtotal' => $amount,
                        'tax_amount' => $amount * 0.15,
                        'total' => $amount * 1.15,
                        'paid_amount' => 0,
                        'remaining_amount' => $amount * 1.15,
                        'payment_status' => 'unpaid',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('invoices')->insert($invoices);

                if ($accounts) {
                    $entries = [];
                    foreach ($invoices as $inv) {
                        $entries[] = ['company_id' => $company->id, 'entry_number' => 'LTJ-'.$inv['invoice_number'], 'date' => $inv['invoice_date'], 'description' => 'load test', 'total_debit' => $inv['total'], 'total_credit' => $inv['total'], 'status' => 'posted', 'created_at' => $now, 'updated_at' => $now];
                    }
                    DB::table('journal_entries')->insert($entries);
                    $ids = DB::table('journal_entries')->where('company_id', $company->id)->orderByDesc('id')->limit(count($entries))->pluck('id')->reverse()->values();

                    $lines = [];
                    foreach ($ids as $k => $entryId) {
                        $amount = $entries[$k]['total_debit'];
                        $lines[] = ['company_id' => $company->id, 'journal_entry_id' => $entryId, 'account_id' => $accounts[array_rand($accounts)], 'debit' => $amount, 'credit' => 0, 'created_at' => $now, 'updated_at' => $now];
                        $lines[] = ['company_id' => $company->id, 'journal_entry_id' => $entryId, 'account_id' => $accounts[array_rand($accounts)], 'debit' => 0, 'credit' => $amount, 'created_at' => $now, 'updated_at' => $now];
                    }
                    foreach (array_chunk($lines, 1000) as $part) {
                        DB::table('journal_entry_lines')->insert($part);
                    }
                }
            });
            $bar->advance(min($chunk, $total - $i));
        }

        $bar->finish();
        $this->newLine();
    }

    protected function benchmark(Company $company): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateTimeString();

        $cases = [
            'Invoices page 1 (latest)' => fn () => Invoice::orderByDesc('invoice_date')->orderByDesc('id')->paginate(20),
            'Invoices search by number' => fn () => Invoice::where('invoice_number', 'like', 'LT-5%')->limit(20)->get(),
            'Month sales sum' => fn () => Invoice::whereBetween('invoice_date', [$from, $to])->sum('total'),
            'Customer statement (one customer)' => fn () => Invoice::where('customer_id', DB::table('customers')->where('company_id', $company->id)->value('id'))->orderBy('invoice_date')->paginate(50),
            'Trial balance (SQL aggregate)' => fn () => DB::table('journal_entry_lines')
                ->where('company_id', $company->id)
                ->select('account_id', DB::raw('SUM(debit) as d'), DB::raw('SUM(credit) as c'))
                ->groupBy('account_id')->get(),
        ];

        $rows = [];
        foreach ($cases as $name => $case) {
            $startedAt = microtime(true);
            $case();
            $rows[] = [$name, number_format((microtime(true) - $startedAt) * 1000, 1).' ms'];
        }

        $this->table(['Query', 'Time'], $rows);
        $this->line('Invoices: '.DB::table('invoices')->where('company_id', $company->id)->count()
            .' | Journal lines: '.DB::table('journal_entry_lines')->where('company_id', $company->id)->count());
    }
}
