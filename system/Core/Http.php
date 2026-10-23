<?php

declare(strict_types=1);

namespace Omni\Core;

/** An HTTP request with PHP's streams (no cURL needed), redirects not followed. The status comes from the stream's metadata. */
final class Http
{
    /**
     * @param array<int, string> $headers
     * @return array{status: int, body: string, error: string}
     */
    public static function request(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $headers[] = 'User-Agent: Omni';
        $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0]]);
        $error = '';
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = preg_replace('/^.*?\):\s*/', '', $message) ?? $message;
            return true;
        });
        try {
            $stream = fopen($url, 'rb', false, $context);
            $response = $stream !== false ? stream_get_contents($stream, 262144) : false;
            $lines = $stream !== false ? (array)(stream_get_meta_data($stream)['wrapper_data'] ?? []) : [];
            if ($stream !== false) {
                fclose($stream);
            }
        } finally {
            restore_error_handler();
        }
        $status = 0;
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$line, $m)) {
                $status = (int)$m[1];
            }
        }
        return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => $status === 0 ? ($error ?: 'no answer') : ''];
    }
}
