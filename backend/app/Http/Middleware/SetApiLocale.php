<?php

namespace App\Http\Middleware;

use App\Services\TranslationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = app(TranslationService::class)->locale($request);
        app()->setLocale($locale);
        $request->attributes->set('locale', $locale);

        return $next($request);
    }
}
