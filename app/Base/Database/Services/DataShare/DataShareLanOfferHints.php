<?php

namespace App\Base\Database\Services\DataShare;

final class DataShareLanOfferHints
{
    /** @param list<string> $endpoints @return array<string, array{address: string, tls_public_key: string}> */
    public function forEndpoints(array $endpoints): array
    {
        if (! config('data_share.offers.lan_connection_hints', false)) {
            return [];
        }

        $hostname = gethostname();
        $address = is_string($hostname) ? gethostbyname($hostname) : '';

        if (! $this->isPrivateIpv4($address)) {
            return [];
        }

        $hints = [];

        foreach ($endpoints as $endpoint) {
            $host = parse_url($endpoint, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                continue;
            }

            $certificatePath = base_path('certs/'.$host.'.pem');
            $pin = $this->publicKeyPin($certificatePath);

            if ($pin !== null) {
                $hints[$endpoint] = ['address' => $address, 'tls_public_key' => $pin];
            }
        }

        return $hints;
    }

    private function publicKeyPin(string $certificatePath): ?string
    {
        $pem = @file_get_contents($certificatePath);

        if (! is_string($pem) || $pem === '') {
            return null;
        }

        $certificate = @openssl_x509_read($pem);
        $publicKey = $certificate !== false ? @openssl_pkey_get_public($certificate) : false;
        $details = $publicKey !== false ? @openssl_pkey_get_details($publicKey) : false;
        $publicKeyPem = is_array($details) ? ($details['key'] ?? null) : null;

        if (! is_string($publicKeyPem)) {
            return null;
        }

        $encoded = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $publicKeyPem);
        $der = is_string($encoded) ? base64_decode($encoded, true) : false;

        return is_string($der) ? 'sha256//'.base64_encode(hash('sha256', $der, true)) : null;
    }

    private function isPrivateIpv4(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $value = ip2long($address);

        return is_int($value) && (
            ($value & 0xFF000000) === 0x0A000000
            || ($value & 0xFFF00000) === 0xAC100000
            || ($value & 0xFFFF0000) === 0xC0A80000
        );
    }
}
