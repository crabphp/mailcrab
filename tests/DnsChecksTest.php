<?php

declare(strict_types=1);

namespace Tests;

use Mailcrab\Delivery\DnsChecks;
use Mailcrab\Delivery\DnsResolver;
use PHPUnit\Framework\TestCase;

final class DnsChecksTest extends TestCase
{
    private function check(array $spf, array $dmarc): array
    {
        $resolver = new class($spf, $dmarc) extends DnsResolver {
            public function __construct(
                private array $spf,
                private array $dmarc,
            ) {}

            public function records(string $host, string $type): array
            {
                if ($type !== 'TXT') {
                    return [];
                }
                return str_starts_with($host, '_dmarc.') ? $this->dmarc : $this->spf;
            }
        };
        return new DnsChecks($resolver)->check('news.example.com', 'bounce.news.example.com', 'us-east-1', [
            'DkimAttributes' => ['Tokens' => ['one', 'two', 'three']],
        ]);
    }

    public function testDuplicateAndUnsafeSpfOrDmarcPoliciesCannotPass(): void
    {
        $records = $this->check(['v=spf1 include:amazonses.com +all'], ['v=DMARC1; p=none; p=reject;']);
        self::assertSame('conflict', $records[4]['status']);
        self::assertSame('conflict', $records[5]['status']);
        $records = $this->check(['v=spf1 include:amazonses.com ~all', 'v=spf1 -all'], [
            'v=DMARC1; p=none;',
            'v=DMARC1; p=reject;',
        ]);
        self::assertSame('conflict', $records[4]['status']);
        self::assertSame('conflict', $records[5]['status']);
    }

    public function testAcceptedPolicyValuesArePreservedRatherThanDowngraded(): void
    {
        $records = $this->check(['v=spf1 include:amazonses.com -all'], [
            'v=DMARC1; p=quarantine; rua=mailto:reports@example.com;',
        ]);
        self::assertSame('verified', $records[4]['status']);
        self::assertSame('verified', $records[5]['status']);
        self::assertSame('v=DMARC1; p=quarantine; rua=mailto:reports@example.com;', $records[5]['target']);
    }

    public function testLookupFailureIsNotReportedAsMissing(): void
    {
        $resolver = new class extends DnsResolver {
            public function records(string $host, string $type): array
            {
                throw new \RuntimeException('Timeout');
            }
        };
        $records = new DnsChecks($resolver)->check('news.example.com', 'bounce.news.example.com', 'us-east-1', [
            'DkimAttributes' => ['Tokens' => ['one', 'two', 'three']],
        ]);
        self::assertCount(6, $records);
        self::assertSame(['lookup_failed'], array_values(array_unique(array_column($records, 'status'))));
    }
}
