<?php

declare(strict_types=1);

namespace Mailcrab;

final readonly class Renderer
{
    public function __construct(
        private Store $store,
    ) {}

    public static function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // Deliberately small: plain text plus [label](https://example.com), never raw HTML.
    public function body(string $body, ?\Closure $link = null): string
    {
        $html = '';
        $offset = 0;
        $position = 0;
        preg_match_all(
            '/\[([^\]\r\n]{1,200})\]\((https?:\/\/[^\s<>"\x00-\x1f]{1,2000})\)/u',
            $body,
            $matches,
            PREG_OFFSET_CAPTURE,
        );
        foreach ($matches[0] as $i => [$whole, $at]) {
            $url = $matches[2][$i][0];
            if (
                !filter_var($url, FILTER_VALIDATE_URL)
                || !parse_url($url, PHP_URL_HOST)
                || parse_url($url, PHP_URL_USER) !== null
            ) {
                continue;
            }
            $html .= nl2br(self::escape(substr($body, $offset, $at - $offset)), false);
            $target = $link ? $link($url, $position++) : $url;
            $html .=
                '<a rel="noopener noreferrer" href="'
                . self::escape($target)
                . '">'
                . self::escape($matches[1][$i][0])
                . '</a>';
            $offset = $at + strlen($whole);
        }
        return $html . nl2br(self::escape(substr($body, $offset)), false);
    }

    public function render(array $delivery, bool $tracking = true): array
    {
        $base = rtrim((string) config('app.url'), '/');
        $marketing = in_array($delivery['kind'], ['campaign', 'welcome', 'test'], true);
        $html = $this->body(
            $delivery['body'],
            $tracking && $marketing
                ? function (string $url, int $position) use ($delivery, $base): string {
                    $token = hash_hmac('sha256', $delivery['token'] . ':' . $position, Store::key());
                    $this->store
                        ->db
                        ->table('links')
                        ->insertOrIgnore([
                            'delivery_id' => $delivery['id'],
                            'token' => $token,
                            'url' => $url,
                            'position' => $position,
                        ]);
                    return $base . '/c/' . $token;
                } : null,
        );
        $unsubscribe = $base . '/u/' . $delivery['token'];
        $footer = $marketing
            ? '<hr><p>'
            . nl2br(self::escape($delivery['postal_address']), false)
            . '</p><p><a href="'
            . self::escape($unsubscribe)
            . '">Unsubscribe</a></p>'
            : '';
        return [
            'html' =>
                '<!doctype html><html><head><meta charset="utf-8"></head><body><main><h1>'
                    . self::escape($delivery['subject'])
                    . '</h1><p>'
                    . $html
                    . '</p>'
                    . $footer
                    . '</main></body></html>',
            'text' =>
                $delivery['body']
                    . ($marketing ? "\n\n" . $delivery['postal_address'] . "\nUnsubscribe: " . $unsubscribe : ''),
            'headers' => $marketing
                ? [
                    'List-Unsubscribe' => '<' . $unsubscribe . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ] : [],
        ];
    }
}
