<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The docs page lives where FastAPI's did (/docs, /openapi.json),
        // not at Scramble's default /docs/api; see boot().
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        // Like Python's settings: no JWT secret, no service. Checked when serving
        // requests only, so artisan commands (key:generate, migrate) still run.
        if (! $this->app->runningInConsole() && blank(config('timetable.jwt.secret'))) {
            throw new RuntimeException('JWT_SECRET_KEY is not set');
        }

        // FastAPI sends the object or list itself; Laravel would wrap it in {"data": ...}.
        JsonResource::withoutWrapping();

        $this->docsPage();
    }

    /**
     * Scramble's /docs page replaces FastAPI's: admins use it for rare tasks
     * (create accounts, rooms). Public like FastAPI's; every endpoint on it
     * still needs a token, entered with the "Authorize" button.
     */
    private function docsPage(): void
    {
        Gate::define('viewApiDocs', fn ($user = null) => true);

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::http('bearer', 'JWT'));
        });

        Scramble::registerUiRoute('docs');
        Scramble::registerJsonSpecificationRoute('openapi.json');
    }
}
