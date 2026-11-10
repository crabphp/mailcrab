<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$template = file_get_contents($root . '/.env.example');
if ($template === false) {
    fwrite(STDERR, "Cannot read .env.example.\n");
    exit(1);
}
$template = str_replace("APP_KEY=\n", 'APP_KEY=' . bin2hex(random_bytes(32)) . "\n", $template);
$template = str_replace("FAKE_WEBHOOK_SECRET=\n", 'FAKE_WEBHOOK_SECRET=' . bin2hex(random_bytes(32)) . "\n", $template);
umask(0077);
$file = @fopen($root . '/.env', 'x');
if ($file === false) {
    fwrite(STDERR, "Refusing to overwrite .env, or directory is not writable.\n");
    exit(1);
}
$written = fwrite($file, $template);
fclose($file);
if ($written !== strlen($template)) {
    unlink($root . '/.env');
    fwrite(STDERR, "Could not write complete .env.\n");
    exit(1);
}
chmod($root . '/.env', 0600);
fwrite(
    STDOUT,
    "Created private .env with random secrets. Set dedicated MySQL credentials, then run php84 cua migrate.\n",
);
