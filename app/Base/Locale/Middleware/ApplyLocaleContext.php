<?php
namespace App\Base\Locale\Middleware;

use App\Base\Locale\Contracts\LocaleContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

class ApplyLocaleContext
{
    public function __construct(
        private readonly LocaleContext $localeContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->localeContext->currentLanguage());
        Number::useLocale($this->localeContext->forNumber());

        return $next($request);
    }
}
