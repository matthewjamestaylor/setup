<?php

declare(strict_types=1);

namespace Legends;

/**
 * Cloudflare Turnstile server-side verification.
 * Fails closed on network errors when enabled.
 */
final class Turnstile
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private array $cfg)
    {
    }

    public function enabled(): bool
    {
        return (bool) ($this->cfg['enabled'] ?? false)
            && ($this->cfg['secret'] ?? '') !== '';
    }

    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (!$this->enabled()) {
            return true; // disabled (e.g. local dev)
        }
        if (!$token) {
            return false;
        }

        $payload = http_build_query(array_filter([
            'secret'   => (string) $this->cfg['secret'],
            'response' => $token,
            'remoteip' => $remoteIp,
        ]));

        $body = $this->post(self::VERIFY_URL, $payload);
        if ($body === null) {
            return false; // fail closed
        }
        $json = json_decode($body, true);
        return is_array($json) && ($json['success'] ?? false) === true;
    }

    private function post(string $url, string $payload): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $res = curl_exec($ch);
            $ok = $res !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
            curl_close($ch);
            return $ok ? (string) $res : null;
        }

        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : (string) $res;
    }
}
