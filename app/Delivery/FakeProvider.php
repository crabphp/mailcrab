<?php

declare(strict_types=1);

namespace Mailcrab\Delivery;

final class FakeProvider implements Provider
{
    public function idempotent(): bool
    {
        return true;
    }

    public function send(array $delivery, array $message): string
    {
        $directory = (string) config('mailcrab.outbox');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new Retryable('Cannot create fake outbox.');
        }
        $id = 'fake-' . hash('sha256', $delivery['dedupe_key']);
        $file = $directory . '/' . $id . '.json';
        // Lock file survives a process restart; complete files are atomically renamed under the lock.
        $lock = fopen($file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new Retryable('Cannot lock fake delivery.');
        }
        try {
            if (!is_file($file)) {
                $temp = tempnam($directory, 'pending-');
                if ($temp === false) {
                    throw new Retryable('Cannot create fake message.');
                }
                chmod($temp, 0600);
                try {
                    $bytes = json_encode([
                        'provider_id' => $id,
                        'delivery' => $delivery,
                        'message' => $message,
                    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
                    if (file_put_contents($temp, $bytes) !== strlen($bytes) || !rename($temp, $file)) {
                        throw new Retryable('Cannot persist fake message.');
                    }
                } finally {
                    if (is_file($temp)) {
                        unlink($temp);
                    }
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $id;
    }
}
