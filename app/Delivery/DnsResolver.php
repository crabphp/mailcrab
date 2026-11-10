<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

class DnsResolver
{
    public function records(string $host, string $type): array
    {
        $kind = match ($type) {
            'CNAME' => DNS_CNAME,
            'TXT' => DNS_TXT,
            'MX' => DNS_MX,
            default => throw new \InvalidArgumentException('Unsupported DNS type.'),
        };
        $records = dns_get_record($host, $kind);
        if ($records === false) {
            throw new \RuntimeException('DNS lookup failed.');
        }
        return array_map(static fn(array $record): string => match ($type) {
            'CNAME' => strtolower(rtrim($record['target'], '.')),
            'MX' => $record['pri'] . ' ' . strtolower(rtrim($record['target'], '.')),
            'TXT' => $record['txt'] ?? implode('', $record['entries'] ?? []),
        }, $records);
    }
}
