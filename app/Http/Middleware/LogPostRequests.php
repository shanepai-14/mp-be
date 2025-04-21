<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogPostRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        //return $next($request);
    
        if ($request->isMethod('post')) {
            $logData = [
                'time' => now()->toDateTimeString(),
                'url' => $request->fullUrl(),
                'ip' => $request->ip(),
                'input' => $request->except(['password', 'password_confirmation']), // avoid logging sensitive data
            ];

            Log::channel('post_requests')->info('POST Request', $logData);
        }

        return $next($request);

    }
}

