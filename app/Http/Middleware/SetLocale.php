<?php

namespace App\Http\Middleware;

use App\Support\ServerMessages;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request and set application locale.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale', config('app.locale', 'ar'));

        if (in_array($locale, ['ar', 'en'])) {
            app()->setLocale($locale);
        } else {
            app()->setLocale('ar');
        }

        $response = $next($request);

        // JSON answers (quick-create dialogs, lookups) carry Arabic messages and validation errors too.
        if (app()->getLocale() !== 'ar' && $response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data) && (isset($data['message']) || isset($data['errors']))) {
                foreach (['message', 'errors'] as $key) {
                    if (isset($data[$key])) {
                        $data[$key] = is_array($data[$key]) ? ServerMessages::translateAll($data[$key]) : ServerMessages::translate($data[$key]);
                    }
                }
                $response->setData($data);
            }
        }

        return $response;
    }
}
