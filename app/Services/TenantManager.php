<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Spatie\Permission\PermissionRegistrar;

class TenantManager
{
    protected ?Company $currentCompany = null;

    protected bool $isImpersonating = false;

    protected ?User $originalSuperAdmin = null;

    /**
     * When enforced (set for every web request by ResolveTenant), tenant-owned
     * models refuse to return rows if no tenant is resolved, instead of
     * silently returning every company's data.
     */
    protected bool $enforced = false;

    /**
     * Set the active tenant company.
     */
    public function setTenant(?Company $company): void
    {
        $this->currentCompany = $company;
        $this->syncPermissionTeam();
    }

    /**
     * Roles and permissions are per company (Spatie "teams" with team_id = company_id).
     */
    protected function syncPermissionTeam(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->currentCompany?->id);
    }

    /**
     * Get the currently active tenant company.
     */
    public function getTenant(): ?Company
    {
        return $this->currentCompany;
    }

    /**
     * Alias for getTenant().
     */
    public function getCompany(): ?Company
    {
        return $this->currentCompany;
    }

    /**
     * Check if a tenant is currently resolved.
     */
    public function hasTenant(): bool
    {
        return $this->currentCompany !== null;
    }

    /**
     * Get current tenant ID.
     */
    public function getTenantId(): ?int
    {
        return $this->currentCompany?->id;
    }

    /**
     * Turn strict (fail-closed) tenant scoping on or off.
     */
    public function enforce(bool $enforced = true): void
    {
        $this->enforced = $enforced;
    }

    public function isEnforced(): bool
    {
        return $this->enforced;
    }

    /**
     * Run a callback in the context of the given company (queued jobs, console
     * commands, schedulers), restoring the previous context afterwards.
     */
    public function runAs(Company $company, callable $callback): mixed
    {
        $previous = $this->currentCompany;
        $previousEnforced = $this->enforced;

        $this->currentCompany = $company;
        $this->enforced = true;
        $this->syncPermissionTeam();

        try {
            return $callback($company);
        } finally {
            $this->currentCompany = $previous;
            $this->enforced = $previousEnforced;
            $this->syncPermissionTeam();
        }
    }

    /**
     * Forget the resolved tenant (used between requests / in tests).
     */
    public function forget(): void
    {
        $this->currentCompany = null;
        $this->enforced = false;
        $this->syncPermissionTeam();
    }

    /**
     * Start impersonating a company as Super Admin.
     */
    public function startImpersonation(Company $company, User $superAdmin): void
    {
        Session::put('impersonate_company_id', $company->id);
        Session::put('impersonate_original_admin_id', $superAdmin->id);

        $this->currentCompany = $company;
        $this->isImpersonating = true;
        $this->originalSuperAdmin = $superAdmin;
    }

    /**
     * Stop impersonating and return to Super Admin view.
     */
    public function stopImpersonation(): ?int
    {
        $originalAdminId = Session::get('impersonate_original_admin_id');

        Session::forget('impersonate_company_id');
        Session::forget('impersonate_original_admin_id');

        $this->isImpersonating = false;
        $this->originalSuperAdmin = null;

        return $originalAdminId;
    }

    /**
     * Check if current session is an active impersonation.
     */
    public function isImpersonating(): bool
    {
        return Session::has('impersonate_company_id');
    }

    /**
     * Get impersonation data for Inertia frontend.
     */
    public function getImpersonationData(): ?array
    {
        if (! $this->isImpersonating()) {
            return null;
        }

        $adminId = Session::get('impersonate_original_admin_id');
        $admin = $adminId ? User::find($adminId) : null;

        return [
            'is_impersonating' => true,
            'original_admin' => $admin ? [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ] : null,
            'company_name' => $this->currentCompany?->name,
        ];
    }
}
