<?php

namespace Tests\Feature;

use App\Services\Security;
use Illuminate\Container\Container;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Settings read from the environment, the way Render sets them: config files
 * loaded with real environment variables, and a fresh app booted for real.
 */
class ConfigLoadingTest extends TestCase
{
    /** @var array<string, array{0: mixed, 1: mixed}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => [$server, $env]) {
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
        }
        parent::tearDown();
    }

    // --- DOCS_ENABLED --------------------------------------------------------------

    #[DataProvider('docsSwitch')]
    public function test_docs_switch_fails_closed(string $value, bool $enabled): void
    {
        $this->setEnv(['DOCS_ENABLED' => $value]);

        $this->assertSame($enabled, $this->loadConfig('timetable')['docs_enabled']);
    }

    public static function docsSwitch(): array
    {
        return [
            'true' => ['true', true], 'one' => ['1', true], 'yes' => ['yes', true], 'on' => ['on', true],
            'false' => ['false', false], 'zero' => ['0', false], 'off' => ['off', false], 'no' => ['no', false],
            'typo' => ['ture', false], 'empty' => ['', false],
        ];
    }

    // --- DB_SSL_CA: only read when a MySQL connection is opened ----------------------

    public function test_loading_the_config_never_reads_the_ca_file(): void
    {
        // The Docker build (package:discover) loads config before Render mounts ca.pem.
        $this->setEnv(['DB_CONNECTION' => 'mysql', 'DB_SSL_CA' => '/etc/secrets/not-mounted-yet.pem',
            'DB_URL' => 'mysql://u:p@db.example:3306/timetable_php?ssl-mode=VERIFY_CA']);

        $mysql = $this->loadConfig('database')['connections']['mysql'];

        $this->assertSame('/etc/secrets/not-mounted-yet.pem', $mysql['ssl_ca']);
        $this->assertSame([], $mysql['options']);
    }

    public function test_opening_a_mysql_connection_with_a_missing_ca_fails_loudly(): void
    {
        $this->probeConnection(['ssl_ca' => '/etc/secrets/not-mounted-yet.pem']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DB_SSL_CA file not found');
        DB::connection('tls_probe');
    }

    public function test_opening_a_mysql_connection_applies_the_tls_options(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('needs pdo_mysql for the option constants');
        }
        $ca = tempnam(sys_get_temp_dir(), 'ca');
        $this->probeConnection(['ssl_ca' => $ca]);

        try {
            // Laravel connects on the first query, so this doesn't need a server.
            $options = DB::connection('tls_probe')->getConfig('options');
            $this->assertContains($ca, $options);
            $this->assertContains(true, $options);   // verify the server certificate
        } finally {
            DB::purge('tls_probe');
            unlink($ca);
        }
    }

    // --- JWT_SECRET_KEY checked at boot, over HTTP --------------------------------

    public function test_a_short_jwt_secret_turns_every_request_into_a_500(): void
    {
        // Tests run in the console, where the boot check is skipped (so artisan
        // works); this boots a second app the way Apache does and sends a request.
        $this->setEnv(['JWT_SECRET_KEY' => 'too-short', 'APP_DEBUG' => 'true']);
        $app = require $this->app->basePath('bootstrap/app.php');
        (fn () => $this->isRunningInConsole = false)->call($app);

        try {
            $response = $app->make(Kernel::class)->handle(Request::create('/api/health'));
        } finally {
            // The second app made itself the global one; hand control back.
            Container::setInstance($this->app);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($this->app);
        }

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('Internal Server Error', $body['detail']);
        // APP_ENV=testing + APP_DEBUG show the cause; production shows only the line above.
        $this->assertSame(Security::secretProblem('too-short'), $body['debug']['message']);
    }

    // --- helpers -------------------------------------------------------------------

    /** Set real environment variables (what Render's Environment tab does), undone in tearDown. */
    private function setEnv(array $vars): void
    {
        foreach ($vars as $key => $value) {
            $this->savedEnv[$key] ??= [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
            $_SERVER[$key] = $_ENV[$key] = $value;
        }
    }

    /** The array a config file returns with the current environment. */
    private function loadConfig(string $name): array
    {
        return require config_path("$name.php");
    }

    /** A MySQL connection named tls_probe, like the real one but never used for queries. */
    private function probeConnection(array $overrides): void
    {
        config(['database.connections.tls_probe' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'probe', 'username' => 'probe',
            'password' => '', 'url' => null, 'options' => [], ...$overrides,
        ]]);
    }
}
