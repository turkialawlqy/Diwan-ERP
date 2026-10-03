<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuperAdminDashboardController extends Controller
{
    /**
     * Display Super Admin multi-tenant control center.
     */
    public function index(Request $request): Response
    {
        $companiesQuery = Company::with(['plan', 'branches'])
            ->withCount('users');

        if ($search = $request->query('search')) {
            $companiesQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('subdomain', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $companiesQuery->where('status', $status);
        }

        $companies = $companiesQuery->latest()->paginate(15)->withQueryString();

        $stats = [
            'total_companies' => Company::count(),
            'active_companies' => Company::where('status', 'active')->count(),
            'trial_companies' => Company::where('status', 'trial')->count(),
            'total_users' => User::where('is_super_admin', false)->count(),
        ];

        $plans = SubscriptionPlan::where('is_active', true)->get();

        return Inertia::render('SuperAdmin/Dashboard', [
            'companies' => $companies,
            'stats' => $stats,
            'plans' => $plans,
            'filters' => $request->only(['search', 'status']),
        ]);
    }
}
