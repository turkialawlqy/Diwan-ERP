<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user_id' => 'nullable|integer',
            'event' => 'nullable|string|max:40',
            'type' => 'nullable|string|max:60',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('auditable_type', 'like', '%'.$v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 30))
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'subject_id' => $log->auditable_id,
                'user' => $log->user?->name,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
            ]);

        return Inertia::render('AuditLog/Index', [
            'logs' => $logs,
            'users' => User::where('company_id', app(TenantManager::class)->getTenantId())->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }
}
