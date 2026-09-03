<?php

declare(strict_types=1);

namespace HelloJade\Intake\Transport;

use HelloJade\Intake\Exception\TransportException;

/**
 * The default transport: ext-curl, certificate verification on, HTTP/2 when
 * cURL supports it. No dependencies.
 */
final class CurlTransport implements TransportInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('curl_init failed');
        }

        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        $timeoutMs = (int) max(1, round($timeout * 1000));
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $value = trim(substr($line, $pos + 1));
                    if (!array_key_exists($name, $responseHeaders)) {
                        $responseHeaders[$name] = $value;
                    }
                }

                return $len;
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if (defined('CURL_HTTP_VERSION_2TLS')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            throw new TransportException("curl error {$errno}: {$error}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return new Response($status, $responseHeaders, (string) $raw);
    }
}
