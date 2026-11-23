<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Department;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Every error is {"detail": ...}, the shape the frontend reads (DetailErrors). */
class ErrorFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Throwaway routes, only for this test.
        Route::prefix('api/_test')->group(function () {
            Route::get('/abort', fn () => abort(404, 'Room not found'));
            Route::get('/forbidden', fn () => abort(403));
            Route::post('/validate', fn (Request $r) => $r->validate([
                'name' => 'required|string', 'items.*.capacity' => 'integer|min:1',
            ]));
            Route::get('/clash', fn () => throw new ApiException(409, ['message' => 'Clash', 'conflicts' => [['id' => 1]]]));
            Route::get('/crash', fn () => throw new \LogicException('secret internals'));
            Route::get('/model/{id}', fn (int $id) => Department::findOrFail($id));
        });
    }

    public function test_abort_message_becomes_detail(): void
    {
        $this->getJson('/api/_test/abort')->assertStatus(404)->assertExactJson(['detail' => 'Room not found']);
    }

    public function test_bare_403_uses_the_shared_message(): void
    {
        $this->getJson('/api/_test/forbidden')->assertStatus(403)->assertExactJson(['detail' => Roles::DENIED]);
    }

    public function test_validation_errors_are_a_list_like_fastapi(): void
    {
        $response = $this->postJson('/api/_test/validate', ['items' => [['capacity' => 0]]])->assertStatus(422);

        $detail = $response->json('detail');
        $this->assertIsList($detail);
        $this->assertSame(['body', 'name'], $detail[0]['loc']);
        $this->assertSame(['body', 'items', 0, 'capacity'], $detail[1]['loc']);
        $this->assertArrayHasKey('msg', $detail[0]);
        $this->assertSame(['detail'], array_keys($response->json()));
    }

    public function test_object_detail_for_the_swap_clash_shape(): void
    {
        $this->getJson('/api/_test/clash')->assertStatus(409)
            ->assertExactJson(['detail' => ['message' => 'Clash', 'conflicts' => [['id' => 1]]]]);
    }

    public function test_crash_hides_internals(): void
    {
        $this->getJson('/api/_test/crash')->assertStatus(500)->assertExactJson(['detail' => 'Internal Server Error']);
    }

    public function test_crash_details_need_debug_and_a_local_environment(): void
    {
        config(['app.debug' => true]);
        $this->getJson('/api/_test/crash')->assertStatus(500)->assertJsonStructure(['detail', 'debug']);

        // APP_DEBUG=true left on in production still shows nothing.
        $this->app['env'] = 'production';
        $this->getJson('/api/_test/crash')->assertStatus(500)->assertExactJson(['detail' => 'Internal Server Error']);
    }

    public function test_unknown_url_wrong_method_and_missing_row(): void
    {
        $this->getJson('/api/no-such-thing')->assertStatus(404)->assertExactJson(['detail' => 'Not Found']);
        $this->deleteJson('/api/health')->assertStatus(405)->assertExactJson(['detail' => 'Method Not Allowed']);
        $this->getJson('/api/_test/model/999')->assertStatus(404)->assertExactJson(['detail' => 'Not Found']);
    }
}
