<?php

namespace App\Http\Controllers;

use App\Services\Documents\DocumentNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

abstract class Controller
{
    /**
     * Page size from the request, clamped so a client can never request an
     * unbounded page (e.g. ?per_page=1000000) and exhaust memory.
     */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        return max(1, min($max, (int) $request->input('per_page', $default)));
    }

    /**
     * True for API-style calls (axios quick-create modals) that expect JSON.
     * Inertia form posts are XHR too but must get a redirect, so they are
     * excluded by their X-Inertia header.
     */
    protected function wantsJsonResponse(Request $request): bool
    {
        return ! $request->header('X-Inertia') && ($request->wantsJson() || $request->ajax());
    }

    /**
     * Reserve the next number for a document type (see config/documents.php).
     * Call inside the transaction that creates the document.
     */
    protected function nextNumber(string $type, $date = null): string
    {
        return app(DocumentNumberService::class)
            ->next($type, $date ? Carbon::parse($date) : null);
    }

    /**
     * Preview the next number without reserving it.
     */
    protected function peekNumber(string $type, $date = null): string
    {
        return app(DocumentNumberService::class)
            ->peek($type, $date ? Carbon::parse($date) : null);
    }
}
