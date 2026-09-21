<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    /**
     * Switch application language.
     */
    public function switch(Request $request): RedirectResponse
    {
        $request->validate([
            'locale' => 'required|string|in:ar,en',
        ]);

        session()->put('locale', $request->locale);

        return back();
    }
}
