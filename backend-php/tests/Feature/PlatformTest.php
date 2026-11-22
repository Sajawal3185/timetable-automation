<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Support\Paging;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Health check, CORS, the /docs page and the shared paging helper. */
class PlatformTest extends TestCase
{
    public function test_health(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_cors_allows_the_frontend_and_exposes_the_total_count(): void
    {
        $this->getJson('/api/health', ['Origin' => 'http://localhost:5500'])
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5500')
            ->assertHeader('Access-Control-Expose-Headers', 'X-Total-Count');

        $preflight = $this->call('OPTIONS', '/api/health', server: [
            'HTTP_ORIGIN' => 'http://127.0.0.1:5500',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);
        $preflight->assertHeader('Access-Control-Allow-Origin', 'http://127.0.0.1:5500');
    }

    public function test_cors_refuses_unknown_sites(): void
    {
        $this->getJson('/api/health', ['Origin' => 'https://evil.example'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_docs_page_and_openapi_json_load_outside_local(): void
    {
        // Port of test_openapi.py. "testing" is not "local", so this also proves
        // the page isn't locked to developers' laptops.
        $this->get('/docs')->assertOk();

        $paths = $this->getJson('/openapi.json')->assertOk()->json('paths');
        $this->assertArrayHasKey('/health', $paths);   // Scramble lists paths without the /api prefix
    }

    public function test_resources_are_not_wrapped_in_data(): void
    {
        Route::get('/api/_test/one', fn () => new JsonResource(['id' => 1]));
        Route::get('/api/_test/many', fn () => JsonResource::collection([['id' => 1], ['id' => 2]]));

        $this->getJson('/api/_test/one')->assertExactJson(['id' => 1]);
        $this->getJson('/api/_test/many')->assertExactJson([['id' => 1], ['id' => 2]]);
    }

    public function test_paging_sets_total_count_and_checks_limits(): void
    {
        Route::get('/api/_test/departments', function (Request $request) {
            [$rows, $total] = Paging::get(Department::query()->orderBy('id'), $request, defaultLimit: 2);

            return Paging::response($rows->pluck('code'), $total);
        });
        Department::factory()->count(3)->create();

        $first = $this->getJson('/api/_test/departments')->assertOk()->assertHeader('X-Total-Count', '3');
        $this->assertCount(2, $first->json());
        $this->assertCount(1, $this->getJson('/api/_test/departments?skip=2&limit=5')->json());

        $this->getJson('/api/_test/departments?skip=-1')->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['query', 'skip']);
        $this->getJson('/api/_test/departments?limit=201')->assertStatus(422);
    }
}
