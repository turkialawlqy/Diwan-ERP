<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Permissions\PermissionRegistry;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'erp:sync-permissions {--reset-roles : Reset default roles of every company to their default permissions}';

    protected $description = 'Sync permissions from config/erp_permissions.php and ensure every company has its default roles';

    public function handle(PermissionRegistry $registry): int
    {
        $registry->syncPermissions();
        $this->info('Permissions synced: '.$registry->names()->count());

        $count = 0;
        Company::query()->each(function (Company $company) use ($registry, &$count) {
            $registry->seedCompanyRoles($company, (bool) $this->option('reset-roles'));
            $count++;
        });

        $this->info("Default roles ensured for {$count} companies.");

        return self::SUCCESS;
    }
}
