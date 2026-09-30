<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WorkflowHttpService
{
    /** Post data to a public HTTPS endpoint without forwarding credentials or redirects. */
    public function send(string $url, string $method, array $payload): array
    {
        $this->validateUrl($url);

        $response = Http::timeout(12)
            ->withOptions(['allow_redirects' => false])
            ->acceptJson()
            ->asJson()
            ->send(strtoupper($method), $url, ['json' => $payload]);

        if (! $response->successful()) {
            throw new RuntimeException('The webhook endpoint returned HTTP '.$response->status().'.');
        }

        $contentType = strtolower($response->header('Content-Type', ''));
        $body = str_contains($contentType, 'json')
            ? $response->json()
            : mb_substr($response->body(), 0, 5000);

        return [
            'status' => $response->status(),
            'response' => $body,
        ];
    }

    /** Validate the URL before activating an action that can make outbound requests. */
    public function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Use a public HTTPS endpoint for this webhook step.');
        }

        $this->assertPublicHost($parts['host']);
    }

    private function assertPublicHost(string $host): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        if (strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')) {
            throw new RuntimeException('Private and local webhook hosts are not allowed.');
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = array_values(array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records ?: [],
        )));

        if ($addresses === []) {
            throw new RuntimeException('JARVIS could not verify the webhook host.');
        }

        foreach ($addresses as $address) {
            $this->assertPublicIp($address);
        }
    }

    private function assertPublicIp(string $address): void
    {
        $public = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($public === false) {
            throw new RuntimeException('Private and reserved webhook hosts are not allowed.');
        }
    }
}
