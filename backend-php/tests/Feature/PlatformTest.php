<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Section;
use App\Models\Teacher;
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

    public function test_preflight_answer_is_cached_for_ten_minutes(): void
    {
        $this->call('OPTIONS', '/api/health', server: [
            'HTTP_ORIGIN' => 'http://localhost:5500',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ])->assertHeader('Access-Control-Max-Age', '600');
    }

    public function test_origin_patterns_are_space_separated_and_may_contain_commas(): void
    {
        $value = '#^https://deploy-preview-\d{1,3}--numl\.netlify\.app$#  #^https://x\.example$#';
        $_ENV['CORS_ORIGIN_PATTERNS'] = $_SERVER['CORS_ORIGIN_PATTERNS'] = $value;
        try {
            $cors = require config_path('cors.php');
        } finally {
            unset($_ENV['CORS_ORIGIN_PATTERNS'], $_SERVER['CORS_ORIGIN_PATTERNS']);
        }

        $this->assertSame([
            '#^https://deploy-preview-\d{1,3}--numl\.netlify\.app$#',
            '#^https://x\.example$#',
        ], $cors['allowed_origins_patterns']);

        config(['cors.allowed_origins_patterns' => $cors['allowed_origins_patterns']]);
        $this->getJson('/api/health', ['Origin' => 'https://deploy-preview-42--numl.netlify.app'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://deploy-preview-42--numl.netlify.app');
    }

    public function test_openapi_locks_only_endpoints_that_need_a_token(): void
    {
        Route::get('/api/_test/private', fn () => ['ok' => true])->middleware('jwt');
        Route::get('/api/_test/admin-only', fn () => ['ok' => true])->middleware('role:admin');

        $paths = $this->getJson('/openapi.json')->assertOk()->json('paths');

        $this->assertSame([], $paths['/health']['get']['security'], '/health must not ask for a token');
        $this->assertArrayNotHasKey('security', $paths['/_test/private']['get']);      // inherits the global lock
        $this->assertArrayNotHasKey('security', $paths['/_test/admin-only']['get']);
    }

    public function test_paging_breaks_ties_by_id(): void
    {
        // Every section has 40 students: without the id tiebreaker the database
        // may return them in any order, page by page.
        Route::get('/api/_test/sections', function (Request $request) {
            $query = Section::query()->orderBy('student_count');
            [$rows] = Paging::get($query, $request, defaultLimit: 2);

            return $rows->pluck('id');
        });
        $ids = Section::factory()->count(4)->create(['student_count' => 40])->pluck('id')->sort()->values()->all();

        $this->assertSame(array_slice($ids, 0, 2), $this->getJson('/api/_test/sections')->json());
        $this->assertSame(array_slice($ids, 2, 2), $this->getJson('/api/_test/sections?skip=2')->json());
    }

    public function test_paging_adds_the_id_as_the_last_sort_key(): void
    {
        // The test above passes on SQLite even without the tiebreaker (it happens to
        // return rows in id order), so check the query Paging builds instead. The
        // builder's order list, not the SQL text: quoting differs per database.
        $query = Section::query()->orderBy('student_count');
        Paging::get($query, Request::create('/x'));

        $this->assertSame([
            ['column' => 'student_count', 'direction' => 'asc'],
            ['column' => 'sections.id', 'direction' => 'asc'],
        ], $query->getQuery()->orders);
    }

    public function test_factories_never_run_out_of_names(): void
    {
        // fake()->unique() keeps its list for the whole test run: numerify('#')
        // gave out after 10 sections and lastName() after a few hundred teachers.
        $dept = Department::factory()->create();
        Section::factory()->count(30)->create(['department_id' => $dept->id]);
        Teacher::factory()->count(600)->make();

        $this->assertSame(30, Section::where('department_id', $dept->id)->distinct()->count('name'));
    }
}
