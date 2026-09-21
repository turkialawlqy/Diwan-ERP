<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Session;

class TenantManager
{
    protected ?Company $currentCompany = null;
    protected bool $isImpersonating = false;
    protected ?User $originalSuperAdmin = null;

    /**
     * Set the active tenant company.
     */
    public function setTenant(?Company $company): void
    {
        $this->currentCompany = $company;
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
        if (!$this->isImpersonating()) {
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
