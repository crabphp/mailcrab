<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

class SnsCertificate
{
    public function fetch(string $url): string
    {
        $curl = curl_init($url);
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if ((strlen($body) + strlen($chunk)) > 32768) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($curl) === false || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new SnsCertificateUnavailable('Could not fetch SNS certificate.');
            }
            return $body;
        } finally {
            curl_close($curl);
        }
    }
}
