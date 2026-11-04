<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Ticket;
use App\Services\Saas\PlanGuard;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** The customer's no-login satisfaction survey (a signed link from the ticket). */
class SurveyController extends Controller
{
    public function show(int $company, int $ticket)
    {
        return $this->inTenant($company, function ($company) use ($ticket) {
            $ticket = Ticket::findOrFail($ticket);
            abort_if($ticket->isActive(), 404);

            return response()->view('portal.ticket-survey', [
                'company' => $company, 'ticket' => $ticket,
                'action' => URL::temporarySignedRoute('share.ticket-survey.rate', now()->addDays(config('documents.share_link_days')), ['company' => $company->id, 'ticket' => $ticket->id]),
                'done' => false,
            ]);
        });
    }

    public function rate(Request $request, int $company, int $ticket)
    {
        $data = $request->validate(['rating' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:2000']);

        return $this->inTenant($company, function ($company) use ($ticket, $data) {
            abort_unless(app(PlanGuard::class)->hasModule($company, 'support'), 404);
            $ticket = Ticket::findOrFail($ticket);
            abort_if($ticket->isActive(), 404);
            if (! $ticket->rating) { // one rating per ticket: the link cannot overwrite it later
                $ticket->update(['rating' => $data['rating'], 'rating_comment' => $data['comment'] ?? null]);
            }

            return response()->view('portal.ticket-survey', ['company' => $company, 'ticket' => $ticket, 'action' => null, 'done' => true]);
        });
    }

    protected function inTenant(int $company, callable $callback)
    {
        $company = Company::findOrFail($company);

        return app(TenantManager::class)->runAs($company, fn () => $callback($company));
    }
}
