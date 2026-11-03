<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\ModuleWidgets;
use Illuminate\Http\Request;

/** Each user hides or shows the system cards on his dashboard. */
class DashboardWidgetController extends Controller
{
    public function update(Request $request, ModuleWidgets $widgets)
    {
        $data = $request->validate(['key' => 'required|string|max:40', 'hidden' => 'required|boolean']);
        $widgets->setHidden($request->user(), $data['key'], (bool) $data['hidden']);

        return back();
    }
}
