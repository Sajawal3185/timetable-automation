<?php

namespace Tests\Feature;

use App\Support\Roles;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A typo in a route's role list ('role:admn') doesn't crash: it silently locks
 * everyone out of that endpoint. This walks every real route and fails on any
 * role name that doesn't exist.
 */
class RoleNamesTest extends TestCase
{
    public function test_every_role_middleware_names_real_roles(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'role:')) {
                    continue;
                }
                foreach (explode(',', substr($middleware, 5)) as $name) {
                    $this->assertContains(strtolower(trim($name)), Roles::ALL,
                        "Route {$route->uri()} uses unknown role '$name' in '$middleware'");
                    $checked++;
                }
            }
        }

        $this->addToAssertionCount(1);   // fine with no role routes yet (skeleton 1)
    }
}
