<?php

namespace App\Http\Middleware;

use Auth;
use Closure;
use Illuminate\Http\Request;
use App\Constant\UserRoleConstant;
use App\Exceptions\User\AccessDeniedException;

class IsAdministratorChecker
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        
        if (!$user || !$user->userRole) {
            throw new AccessDeniedException();
        }

        $authUserRole = $user->userRole->getType();

        if ($authUserRole !== UserRoleConstant::ADMINISTRATOR &&
            $authUserRole !== UserRoleConstant::SUPER_ADMINISTRATOR) {
            throw new AccessDeniedException();
        }

        return $next($request);
    }
}
