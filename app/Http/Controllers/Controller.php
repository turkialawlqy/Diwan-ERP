<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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

    //
}
