<?php

namespace App\Services;

use App\Models\Account;
use App\Services\Accounting\AccountMap;

/**
 * @deprecated Use App\Services\Accounting\AccountMap. Kept for callers that
 * still resolve accounts by their legacy role names.
 */
class AccountingMappingService
{
    public static function getAccount(int $companyId, string $role): ?Account
    {
        $id = app(AccountMap::class)->find($role, $companyId);

        return $id ? Account::withoutGlobalScopes()->find($id) : null;
    }
}
