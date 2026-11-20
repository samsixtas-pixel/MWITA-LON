<?php
/**
 * tools/tests/bootstrap.php
 * -------------------------------------------------------
 * Shared helpers for the identity-capture test suite.
 *
 * The tests run against a real installation: the unit tests
 * only need the database, the HTTP tests also need a running
 * web server (php -S or Apache).
 *
 * Usage:
 *   DB_SOCKET=/path/mysql.sock php tools/tests/run.php \
 *       --base-url=http://127.0.0.1:8099/loan/ \
 *       --user=testadmin --password=Admin@2026
 * -------------------------------------------------------
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

require_once __DIR__ . '/../../includes/autoload.php';

final class TestSuite
{
    /** @var array<int,array{name:string,ok:bool,detail:string}> */
    private array $results = [];
    private int $failures = 0;

    public function test(string $name, callable $callback): void
    {
        try {
            $detail = $callback();
            $this->results[] = ['name' => $name, 'ok' => true, 'detail' => (string) ($detail ?? '')];
            echo "  ✔ {$name}" . ($detail ? " — {$detail}" : '') . PHP_EOL;
        } catch (Throwable $e) {
            $this->failures++;
            $this->results[] = ['name' => $name, 'ok' => false, 'detail' => $e->getMessage()];
            echo "  ✖ {$name}\n      " . $e->getMessage() . PHP_EOL;
        }
    }

    public function skip(string $name, string $reason): void
    {
        echo "  ↷ {$name} — skipped: {$reason}" . PHP_EOL;
    }

    public function summary(): int
    {
        $total = count($this->results);
        echo PHP_EOL . str_repeat('─', 60) . PHP_EOL;
        echo sprintf(
            '%d test(s), %d passed, %d failed%s',
            $total,
            $total - $this->failures,
            $this->failures,
            PHP_EOL
        );

        return $this->failures === 0 ? 0 : 1;
    }

    public function failures(): int
    {
        return $this->failures;
    }
}

function assertTrue($condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ' — ' : '')
            . 'expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(
            ($message !== '' ? $message . ' — ' : '') . 'missing “' . $needle . '”'
        );
    }
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException(
            ($message !== '' ? $message . ' — ' : '') . 'unexpected “' . $needle . '”'
        );
    }
}

/**
 * Cookie-aware HTTP client for the functional tests.
 */
final class HttpClient
{
    private string $baseUrl;
    private string $cookieFile;
    private ?string $csrf = null;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'lms-test-') ?: '/tmp/lms-test-cookies';
    }

    public function __destruct()
    {
        @unlink($this->cookieFile);
    }

    /** Resolve app-relative, root-relative and absolute URLs. */
    private function resolve(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            $origin = (string) parse_url($this->baseUrl, PHP_URL_SCHEME) . '://'
                . parse_url($this->baseUrl, PHP_URL_HOST)
                . (parse_url($this->baseUrl, PHP_URL_PORT) ? ':' . parse_url($this->baseUrl, PHP_URL_PORT) : '');

            return $origin . $path;
        }

        return $this->baseUrl . $path;
    }

    /** @return array{status:int,headers:string,body:string,location:string} */
    public function request(string $method, string $path, array $fields = [], bool $multipart = false, ?string $json = null): array
    {
        $url = $this->resolve($path);
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $headers = [];

        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Accept: application/json';
        } elseif (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $fields : http_build_query($fields));
            if (!$multipart) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
            $headers[] = 'Accept: text/html,application/json';
        }

        if ($this->csrf !== null) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = (string) curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $body       = substr($response, $headerSize);
        $location   = '';

        if (preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $m)) {
            $location = trim($m[1]);
        }

        return ['status' => $status, 'headers' => $rawHeaders, 'body' => $body, 'location' => $location];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields, bool $multipart = false): array
    {
        return $this->request('POST', $path, $fields, $multipart);
    }

    public function postJson(string $path, array $payload): array
    {
        return $this->request('POST', $path, [], false, json_encode($payload));
    }

    /** Read the CSRF token from a page and keep it for later requests. */
    public function loadCsrf(string $path = 'views/customers/add.php'): string
    {
        $page = $this->get($path);
        $html = $page['body'];

        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m)) {
            return $this->csrf = $m[1];
        }

        if (preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m)) {
            return $this->csrf = $m[1];
        }

        throw new RuntimeException('CSRF token not found on ' . $path);
    }

    public function csrf(): string
    {
        if ($this->csrf === null) {
            $this->loadCsrf();
        }

        return (string) $this->csrf;
    }

    /** Simulate a request that forgot the CSRF token. */
    public function clearCsrf(): void
    {
        $this->csrf = null;
    }

    public function login(string $username, string $password): array
    {
        $this->get('login.php');
        $token = $this->loadCsrf('login.php');

        return $this->post('login.php', [
            'csrf_token' => $token,
            'username'   => $username,
            'password'   => $password,
        ]);
    }
}
