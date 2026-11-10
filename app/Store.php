<?php

declare(strict_types=1);

namespace Mailcrab;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database;
use Crab\Http\HttpException;
use Psr\Clock\ClockInterface;

final readonly class Store
{
    public function __construct(
        public Database $db,
        public ClockInterface $clock,
    ) {}

    public function now(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    public function after(int $seconds): string
    {
        return $this->clock
            ->now()
            ->modify("{$seconds} seconds")
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    // Every workspace mutation takes this lock first, including worker claims and public endpoints.
    // That serializes consent/suppression, audience generation and queue claims within a workspace.
    public function lock(Connection $db, int $workspace): array
    {
        return (
            $db->table('workspaces')->where('id', $workspace)->lockForUpdate()->first() ?? throw new HttpException(
                404,
                'Workspace not found.',
            )
        );
    }

    public function row(Connection $db, string $table, int $workspace, int $id): array
    {
        return (
            $db->table($table)->where('workspace_id', $workspace)->where('id', $id)->first() ?? throw new HttpException(
                404,
                'Not found in this workspace.',
            )
        );
    }

    public static function text(array $input, string $key, int $max = 120, bool $required = true): string
    {
        $value = $input[$key] ?? '';
        if (
            !is_string($value)
            || !mb_check_encoding($value, 'UTF-8')
            || str_contains($value, "\0")
            || strlen($value) > $max
            || $required && trim($value) === ''
        ) {
            throw new HttpException(422, "Invalid {$key}; maximum {$max} bytes.");
        }
        return trim($value);
    }

    public static function email(mixed $value): string
    {
        if (
            !is_string($value)
            || strlen($value) > 254
            || !filter_var($value, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $value)
        ) {
            throw new HttpException(422, 'A valid email address is required.');
        }
        return strtolower(trim($value));
    }

    public static function timestamp(mixed $value, string $field = 'consent_at'): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d(?::\d\d)?(?:Z|[+-]\d\d:\d\d)$/D', $value)) {
            throw new HttpException(422, "{$field} must be ISO 8601 with a timezone.");
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new HttpException(422, "Invalid {$field}.");
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
            throw new HttpException(422, "Invalid {$field}.");
        }
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function id(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new HttpException(422, "Invalid {$field}.");
        }
        return (int) $value;
    }

    public static function key(): string
    {
        $key = (string) config('mailcrab.key');
        if (strlen($key) < 32) {
            throw new HttpException(503, 'Set a random APP_KEY of at least 32 bytes.');
        }
        return $key;
    }
}
