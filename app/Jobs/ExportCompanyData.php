<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\DataExport;
use App\Services\TenantManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Full export of a company's data: one CSV per table (rows of this company
 * only, secrets excluded), zipped on the private disk.
 */
class ExportCompanyData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    /** Columns never exported. */
    protected const SECRET_COLUMNS = ['password', 'remember_token', 'two_factor_secret', 'private_key', 'compliance_token', 'compliance_secret',
        'production_token', 'production_secret', 'secret', 'csr', 'custom_domain_token', 'two_factor_recovery_codes', 'google_id'];

    protected const SKIP_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens',
        'migrations', 'data_exports', 'notifications', 'platform_audit_logs'];

    public function __construct(public int $exportId, public int $companyId)
    {
        $this->onQueue('exports');
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long');
        }
    }

    public function handle(TenantManager $tenantManager): void
    {
        $export = DataExport::withoutGlobalScopes()->find($this->exportId);
        $company = Company::find($this->companyId);
        if (! $export || ! $company) {
            return;
        }

        $dir = "companies/{$company->id}/exports";
        $zipPath = Storage::disk('local')->path("{$dir}/export-{$export->id}.zip");
        @mkdir(dirname($zipPath), 0750, true);

        try {
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            foreach (Schema::getTableListing() as $table) {
                $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
                if (in_array($table, self::SKIP_TABLES, true) || ! Schema::hasColumn($table, 'company_id')) {
                    continue;
                }
                $columns = array_values(array_diff(Schema::getColumnListing($table), self::SECRET_COLUMNS));
                $stream = fopen('php://temp', 'r+');
                fputcsv($stream, $columns);
                DB::table($table)->where('company_id', $company->id)->select($columns)->orderBy($columns[0])->lazy(1000)
                    ->each(fn ($row) => fputcsv($stream, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r|]/', $v) ? "'".$v : $v, (array) $row)));
                rewind($stream);
                $zip->addFromString("{$table}.csv", "\xEF\xBB\xBF".stream_get_contents($stream));
                fclose($stream);
            }

            $zip->addFromString('company.json', json_encode($company->only(['id', 'name', 'subdomain', 'tax_number', 'commercial_registration', 'created_at']), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $zip->close();

            $export->forceFill(['status' => 'ready', 'path' => "{$dir}/export-{$export->id}.zip", 'size' => filesize($zipPath)])->save();
        } catch (\Throwable $e) {
            $export->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)])->save();
            throw $e;
        }
    }
}
