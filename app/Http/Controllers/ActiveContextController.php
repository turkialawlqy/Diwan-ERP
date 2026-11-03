<?php

namespace App\Http\Controllers;

use App\Services\ActiveBranch;
use App\Services\ActiveFiscalYear;
use App\Services\Saas\PlanGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Working branch and fiscal year: chosen in the sidebar's «سياق العمل» card
 * (update), or one at a time (branch, fiscalYear).
 */
class ActiveContextController extends Controller
{
    /**
     * Applies the branch and fiscal year sent (either may be left out) together:
     * nothing changes unless every value sent is allowed.
     */
    public function update(Request $request, ActiveBranch $branches, ActiveFiscalYear $years): RedirectResponse
    {
        $request->validate([
            'branch_id' => ['nullable', 'regex:/^(all|\d+)$/'],
            'fiscal_year_id' => ['nullable', 'integer'],
        ]);
        $branch = $request->input('branch_id');
        $branch = $branch === null || $branch === ActiveBranch::ALL ? $branch : (int) $branch;
        $year = $request->filled('fiscal_year_id') ? (int) $request->input('fiscal_year_id') : null;

        if ($branch !== null && ! $branches->allows($branch)) {
            return back()->withErrors(['context' => 'لا تملك صلاحية العمل على هذا الفرع.']);
        }
        if ($year !== null && ! $years->allows($year)) {
            return back()->withErrors(['context' => 'السنة المالية غير موجودة.']);
        }
        if ($branch === null && $year === null) {
            return back();
        }

        if ($year !== null) {
            $years->switchTo($year);
        }
        if ($branch !== null) {
            $branches->switchTo($branch);
        }

        $chosen = $branches->branches()->firstWhere('id', $branches->id());
        $summary = array_filter([
            $branches->isAll() ? 'كل الفروع' : ($chosen?->name_ar ?: $chosen?->name_en),
            ($current = $years->current()) ? 'السنة المالية '.$current->name : null,
        ]);

        return ($branch !== null ? $this->backToSafePage($request) : back())
            ->with('success', 'تم تغيير سياق العمل: '.implode(' • ', $summary));
    }

    public function branch(Request $request, ActiveBranch $active): RedirectResponse
    {
        $request->validate(['branch_id' => ['required']]);
        $value = $request->input('branch_id');

        if (! $active->switchTo($value === ActiveBranch::ALL ? ActiveBranch::ALL : (int) $value)) {
            return back()->with('error', 'لا تملك صلاحية العمل على هذا الفرع.');
        }

        return $this->backToSafePage($request);
    }

    public function fiscalYear(Request $request, ActiveFiscalYear $active): RedirectResponse
    {
        $request->validate(['fiscal_year_id' => ['required', 'integer']]);

        if (! $active->switchTo((int) $request->input('fiscal_year_id'))) {
            return back()->with('error', 'السنة المالية غير موجودة.');
        }

        return back();
    }

    /**
     * Back to the page the user was on. A record page (a number in its path)
     * may belong to the other branch, so those go to the dashboard.
     */
    protected function backToSafePage(Request $request): RedirectResponse
    {
        $previous = parse_url((string) url()->previous(), PHP_URL_PATH) ?: '/';
        if (preg_match('#/\d+(/|$)#', $previous)) {
            return redirect()->route('dashboard');
        }

        // The page belongs to an activity the new branch does not run: go to the dashboard instead of a 403.
        try {
            $name = app('router')->getRoutes()->match(Request::create($previous))->getName();
        } catch (\Throwable) {
            $name = null;
        }
        $guard = app(PlanGuard::class);
        if ($name && ($module = $guard->moduleForRoute($name)) && ! $guard->branchAllows($module)) {
            return redirect()->route('dashboard');
        }

        return back();
    }
}
