<?php

declare(strict_types=1);

namespace HelloJade\Intake\Tests;

/**
 * A scripted stand-in for intake.hellojade.ai, built on `php -S`. Tests queue
 * responses; every request pops the next one and is recorded so headers and
 * bodies can be asserted. Nothing here talks to the real API.
 */
final class StubServer
{
    /** @var resource|null */
    private $proc = null;
    private string $dir;
    private int $port;

    public function __construct()
    {
        $base = getenv('HJ_STUB_BASE') ?: sys_get_temp_dir();
        $this->dir = rtrim($base, '/') . '/php-stub-' . getmypid() . '-' . bin2hex(random_bytes(3));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("cannot create {$this->dir}");
        }
        file_put_contents($this->dir . '/queue.json', '[]');
        file_put_contents($this->dir . '/requests.json', '[]');
        $this->port = self::freePort();

        $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", '-t', __DIR__ . '/stub', __DIR__ . '/stub/router.php'];
        $env = array_merge(self::env(), ['HJ_STUB_DIR' => $this->dir]);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $this->dir . '/server.log', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('could not start php -S stub');
        }
        $this->proc = $proc;

        // Wait until the listener answers.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $s = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if (is_resource($s)) {
                fclose($s);

                return;
            }
            usleep(20_000);
        }
        throw new \RuntimeException('php -S stub did not start: ' . (string) @file_get_contents($this->dir . '/server.log'));
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * @param array<string, mixed>|string $body    JSON-encoded when an array
     * @param array<string, string>       $headers
     * @param float                       $delay   seconds to sleep before answering (to trigger client timeouts)
     */
    public function enqueue(int $status, array|string $body = [], array $headers = [], float $delay = 0): self
    {
        $queue = json_decode((string) file_get_contents($this->dir . '/queue.json'), true) ?: [];
        $queue[] = ['status' => $status, 'body' => $body, 'headers' => $headers, 'delay' => $delay];
        file_put_contents($this->dir . '/queue.json', json_encode($queue));

        return $this;
    }

    /**
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        return json_decode((string) file_get_contents($this->dir . '/requests.json'), true) ?: [];
    }

    /**
     * @return array{method: string, path: string, headers: array<string, string>, body: string}
     */
    public function request(int $i): array
    {
        $all = $this->requests();
        if (!isset($all[$i])) {
            throw new \OutOfRangeException("no request #{$i} (have " . count($all) . ')');
        }

        return $all[$i];
    }

    public function stop(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if ($status['running']) {
                proc_terminate($this->proc, 15);
                $deadline = microtime(true) + 2.0;
                while (microtime(true) < $deadline && proc_get_status($this->proc)['running']) {
                    usleep(20_000);
                }
                if (proc_get_status($this->proc)['running']) {
                    proc_terminate($this->proc, 9);
                }
            }
            proc_close($this->proc);
            $this->proc = null;
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function __destruct()
    {
        $this->stop();
    }

    public static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new \RuntimeException("cannot bind: {$errstr}");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    /**
     * @return array<string, string>
     */
    private static function env(): array
    {
        $out = [
            // php -S is single-worker by default, so a scripted delay blocks every
            // later request behind it. The timeout test fires its retry while the
            // first response is still being held open, so the stub has to answer
            // both at once or the retry lands on an already-drained queue.
            'PHP_CLI_SERVER_WORKERS' => '4',
        ];
        foreach (['PATH', 'HOME', 'TMPDIR'] as $k) {
            $v = getenv($k);
            if ($v !== false) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
