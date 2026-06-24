<?php
declare(strict_types=1);

namespace ElektronFaucet;

/**
 * Minimal JSON-RPC 1.0 client for Bitcoin-Core-compatible nodes (elektron-net).
 */
final class RpcClient
{
    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $pass,
        private ?string $wallet = null,
        private int $timeout = 30,
    ) {}

    public function call(string $method, array $params = []): mixed
    {
        $url = sprintf('http://%s:%d/', $this->host, $this->port);
        if ($this->wallet !== null && $this->wallet !== '') {
            $url .= 'wallet/' . rawurlencode($this->wallet);
        }
        $body = json_encode([
            'jsonrpc' => '1.0',
            'id'      => 'faucet',
            'method'  => $method,
            'params'  => $params,
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
        ]);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("RPC connection error ($errno): $errstr");
        }
        if ($resp === false || $resp === '') {
            throw new \RuntimeException("RPC empty response (HTTP $httpCode)");
        }
        try {
            $data = json_decode((string)$resp, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("RPC invalid JSON (HTTP $httpCode): " . substr((string)$resp, 0, 200));
        }
        if (!is_array($data)) {
            throw new \RuntimeException('RPC response not an object');
        }
        if (!empty($data['error'])) {
            $msg = is_array($data['error']) ? ($data['error']['message'] ?? 'unknown') : (string)$data['error'];
            $code = is_array($data['error']) ? (int)($data['error']['code'] ?? 0) : 0;
            throw new RpcException((string)$msg, $code);
        }
        return $data['result'] ?? null;
    }
}

class RpcException extends \RuntimeException {}
