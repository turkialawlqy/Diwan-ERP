<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Each user orders the side menu as they like (drag and drop or arrows). Only the order is stored: what the
 * user may open is still decided by permissions, and items missing from the order keep their default place.
 */
class SidebarPreferenceController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'groups' => 'required|array|max:80',
            'groups.*' => ['string', 'max:60', 'regex:/^[A-Za-z0-9_.\-]+$/'],
            'children' => 'nullable|array|max:80',
            'children.*' => 'array|max:80',
            'children.*.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9_.\-]+$/'],
        ]);

        $request->user()->forceFill(['sidebar_order' => [
            'groups' => array_values(array_unique($data['groups'])),
            'children' => collect($data['children'] ?? [])->map(fn ($routes) => array_values(array_unique($routes)))->all(),
        ]])->save();

        return back();
    }

    public function reset(Request $request)
    {
        $request->user()->forceFill(['sidebar_order' => null])->save();

        return back();
    }
}
