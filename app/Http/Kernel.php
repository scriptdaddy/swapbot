<?php namespace Swapbot\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel {

	/**
	 * The application's global HTTP middleware stack.
	 *
	 * @var array
	 */
	protected $middleware = [
		'Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode',
		'Illuminate\Cookie\Middleware\EncryptCookies',
		'Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse',
		'Illuminate\Session\Middleware\StartSession',
		'Illuminate\View\Middleware\ShareErrorsFromSession',


		// allow testing environment to ignore csrf middleware
		// 'Swapbot\Http\Middleware\TestingSafeVerifyCsrfToken',


		'Fideloper\Proxy\TrustProxies',
	];

	/**
	 * The application's route middleware.
	 *
	 * @var array
	 */
	protected $routeMiddleware = [
		'auth'       => 'Swapbot\Http\Middleware\Authenticate',
		'auth.basic' => 'Illuminate\Auth\Middleware\AuthenticateWithBasicAuth',
		'guest'      => 'Swapbot\Http\Middleware\RedirectIfAuthenticated',
		'tls'        => \Swapbot\Http\Middleware\RequireTLS::class,
	];

}
