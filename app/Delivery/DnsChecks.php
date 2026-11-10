<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

final readonly class DnsChecks
{
    public function __construct(
        private DnsResolver $resolver,
    ) {}

    public function check(string $domain, string $mailFrom, string $region, array $identity): array
    {
        $records = [];
        $tokens = $identity['DkimAttributes']['Tokens'] ?? [];
        if (count($tokens) !== 3) {
            throw new \Crab\Http\HttpException(
                422,
                'This identity has no three Easy DKIM tokens. Ask the operator to review Easy DKIM setup in SES before continuing.',
            );
        }
        foreach ($tokens as $token) {
            if (!is_string($token) || !preg_match('/^[a-z0-9]{1,128}$/D', $token)) {
                throw new \RuntimeException('Invalid SES DKIM token.');
            }
            $records[] = $this->record(
                'DKIM',
                'CNAME',
                $token . '._domainkey.' . $domain,
                $token . '.dkim.amazonses.com',
            );
        }
        $records[] = $this->record('MAIL FROM', 'MX', $mailFrom, '10 feedback-smtp.' . $region . '.amazonses.com');
        $records[] = $this->record(
            'SPF',
            'TXT',
            $mailFrom,
            'v=spf1 include:amazonses.com ~all',
            function (array $values): bool {
                $spf = array_values(array_filter($values, static fn($s): bool => str_starts_with(
                    strtolower($s),
                    'v=spf1 ',
                )));
                return (
                    count($spf) === 1
                    && preg_match('/(?:^|\s)include:amazonses\.com(?:\s|$)/i', $spf[0]) === 1
                    && preg_match('/\s[-~]all\s*$/i', $spf[0]) === 1
                    && preg_match_all('/(?:^|\s)[+?~-]?all(?:\s|$)/i', $spf[0]) === 1
                );
            },
        );
        $records[] = $this->record(
            'DMARC',
            'TXT',
            '_dmarc.' . $domain,
            'v=DMARC1; p=none;',
            function (array $values): bool {
                $dmarc = array_values(array_filter($values, static fn($s): bool => str_starts_with(
                    strtoupper($s),
                    'V=DMARC1;',
                )));
                if (count($dmarc) !== 1) {
                    return false;
                }
                $tags = [];
                foreach (explode(';', $dmarc[0]) as $part) {
                    if (trim($part) === '') {
                        continue;
                    }
                    $pair = explode('=', trim($part), 2);
                    if (count($pair) !== 2 || array_key_exists(strtolower($pair[0]), $tags)) {
                        return false;
                    }
                    $tags[strtolower($pair[0])] = trim($pair[1]);
                }
                return in_array(strtolower($tags['p'] ?? ''), ['none', 'quarantine', 'reject'], true);
            },
        );
        return $records;
    }

    private function record(string $purpose, string $type, string $name, string $value, ?\Closure $match = null): array
    {
        try {
            $found = $this->resolver->records($name, $type);
            $valid = $match ? $match($found) : in_array(strtolower($value), array_map('strtolower', $found), true);
            $status = $valid ? 'verified' : ($found === [] ? 'pending' : 'conflict');
        } catch (\Throwable) {
            $found = [];
            $status = 'lookup_failed';
        }
        // Never suggest replacing an accepted existing policy with the example default.
        if ($status === 'verified' && in_array($purpose, ['SPF', 'DMARC'], true)) {
            $prefix = $purpose === 'SPF' ? 'v=spf1 ' : 'v=dmarc1;';
            $value = array_values(array_filter($found, static fn($s): bool => str_starts_with(
                strtolower($s),
                $prefix,
            )))[0];
        }
        $priority = $type === 'MX' ? 10 : null;
        $target = $type === 'MX' ? substr($value, 3) : $value;
        return compact('purpose', 'type', 'name', 'value', 'status', 'found', 'priority', 'target');
    }
}
