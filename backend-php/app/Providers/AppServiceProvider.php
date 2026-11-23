<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\RequireRoles;
use App\Services\Security;
use App\Support\Identifiers;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The docs page lives where FastAPI's did (/docs, /openapi.json),
        // not at Scramble's default /docs/api; see docsPage().
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        // Like Python's settings: no usable JWT secret, no service. Same rule as
        // Security::secret() (at least 32 characters), so a short key fails here
        // at startup instead of turning every request into a 401. Checked when
        // serving requests only, so artisan commands (key:generate, migrate) still run.
        if (! $this->app->runningInConsole() && $problem = Security::secretProblem(config('timetable.jwt.secret'))) {
            throw new RuntimeException($problem);
        }

        // FastAPI sends the object or list itself; Laravel would wrap it in {"data": ...}.
        JsonResource::withoutWrapping();

        // 'throttle:auth' on login and activate (not in the Python backend; added
        // in review). 10 tries a minute per account per address stops password
        // guessing; 100 a minute per address stops trying many accounts. The
        // second is high on purpose: a whole campus can share one address.
        // Over the limit: 429 {"detail": "Too Many Attempts."} plus Retry-After.
        RateLimiter::for('auth', function (Request $request) {
            $identifier = $request->input('identifier');   // not validated yet: may be anything
            $account = is_string($identifier) ? Identifiers::normalize($identifier) : '';

            return [
                Limit::perMinute(10)->by('id:'.$account.'|'.$request->ip()),
                Limit::perMinute(100)->by('ip:'.$request->ip()),
            ];
        });

        if (config('timetable.docs_enabled')) {
            $this->docsPage();
        }
    }

    /**
     * Scramble's /docs page replaces FastAPI's: admins use it for rare tasks
     * (create accounts, rooms). Public like FastAPI's (DOCS_ENABLED=false hides it).
     * Endpoints behind 'jwt' or 'role:' show a lock and need a token, entered
     * with the "Authorize" button; public ones (login, activate, health) don't.
     */
    private function docsPage(): void
    {
        Gate::define('viewApiDocs', fn ($user = null) => true);

        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(SecurityScheme::http('bearer', 'JWT'));
            })
            ->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo) {
                if (! self::needsToken($routeInfo->route->gatherMiddleware())) {
                    $operation->security = [];   // overrides the document-wide lock
                }
            });

        Scramble::registerUiRoute('docs');
        Scramble::registerJsonSpecificationRoute('openapi.json');
    }

    /** @param  array<int, string>  $middleware */
    private static function needsToken(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if ($m === 'jwt' || str_starts_with($m, 'role:')
                || $m === AuthenticateJwt::class || str_starts_with($m, RequireRoles::class)) {
                return true;
            }
        }

        return false;
    }
}
