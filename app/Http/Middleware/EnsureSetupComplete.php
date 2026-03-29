<?php

namespace App\Http\Middleware;

use App\Models\AppSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSetupComplete
{
    /**
     * Redirect to the setup wizard if initial setup has not been completed.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! AppSetting::isTrue('setup_completed')) {
            return redirect()->route('setup.wizard');
        }

        return $next($request);
    }
}
