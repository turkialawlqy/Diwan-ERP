<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\ActiveBranch;
use App\Services\Reports\Insights;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class InsightsController extends Controller
{
    public function index(Request $request, Insights $insights)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = Carbon::parse($request->input('from', now()->startOfYear()->toDateString()));
        $to = Carbon::parse($request->input('to', now()->toDateString()));
        $key = 'insights:'.app(TenantManager::class)->getTenantId().':'.app(ActiveBranch::class)->scopeKey().':';

        // Aggregations over many rows: cached briefly per company and branch scope
        // (every tab follows the branches the user is viewing).
        return Inertia::render('Reports/Insights', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'cash' => Cache::remember($key.'cash:'.now()->toDateString(), now()->addMinutes(10), fn () => $insights->cashForecast()),
            'aging' => Cache::remember($key.'aging:'.now()->toDateString(), now()->addMinutes(10), fn () => $insights->aging()),
            'profitability' => Cache::remember($key.'profit:'.$from->toDateString().':'.$to->toDateString(), now()->addMinutes(10), fn () => $insights->profitability($from, $to)),
            'budget' => Cache::remember($key.'budget:'.now()->toDateString(), now()->addMinutes(10), fn () => $insights->budget()),
        ]);
    }
}
