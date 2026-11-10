<?php

declare(strict_types=1);

namespace Mailcrab;

use Crab\Contracts\Database\Connection;
use Crab\Http\Cookie;
use Crab\Http\HttpException;
use Crab\Http\Response;
use Psr\Http\Message\ServerRequestInterface;

final readonly class Auth
{
    public const COOKIE = 'mailcrab_session';

    public function __construct(
        private Store $store,
    ) {}

    public function createWorkspace(
        string $name,
        string $slug,
        string $email,
        #[\SensitiveParameter]
        string $password,
        string $address,
    ): array {
        $email = Store::email($email);
        Store::text(['name' => $name], 'name');
        Store::text(['address' => $address], 'address', 500);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,79}$/D', $slug) || strlen($password) < 12 || strlen($password) > 72) {
            throw new HttpException(422, 'Use a 3–80 character slug and a 12–72 byte password.');
        }
        return $this->store->db->transaction(function (Connection $db) use (
            $name,
            $slug,
            $email,
            $password,
            $address,
        ): array {
            if (
                $db->table('workspaces')->where('slug', $slug)->exists()
                || $db->table('users')->where('email', $email)->exists()
            ) {
                throw new HttpException(
                    409,
                    'Workspace or user already exists. Choose a different slug and owner email.',
                );
            }
            $now = $this->store->now();
            $workspace = $db->table('workspaces')->insert([
                'name' => $name,
                'slug' => $slug,
                'postal_address' => $address,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $user = $db->table('users')->insert([
                'email' => $email,
                'password' => password_hash($password, PASSWORD_BCRYPT),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->table('memberships')->insert(['workspace_id' => $workspace, 'user_id' => $user]);
            return ['workspace_id' => (int) $workspace, 'user_id' => (int) $user];
        });
    }

    public function login(#[\SensitiveParameter] array $input): Response
    {
        Store::key();
        $email = Store::email($input['email'] ?? null);
        $password = $input['password'] ?? null;
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new HttpException(422, 'Invalid password.');
        }
        $user = $this->store->db->table('users')->where('email', $email)->first();
        $hash = $user['password'] ?? '$2y$12$7vRfz4M4TjOoHSSvI0txbuM1ZqlmORkwFJxZ48umiiIJdC3kA0VI6';
        if (!password_verify($password, $hash) || !$user) {
            throw new HttpException(401, 'Invalid email or password.');
        }
        $token = bin2hex(random_bytes(32));
        $this->store
            ->db
            ->table('sessions')
            ->insert([
                'token_hash' => hash('sha256', $token),
                'user_id' => $user['id'],
                'expires_at' => $this->store->after(43200),
            ]);
        return Response::json([
            'user' => ['id' => (int) $user['id'], 'email' => $user['email']],
            'csrf' => $this->csrf($token),
        ])->withCookie(
            new Cookie(
                self::COOKIE,
                $token,
                43200,
                secure: str_starts_with((string) config('app.url'), 'https://'),
                sameSite: 'Strict',
            ),
        );
    }

    public function session(ServerRequestInterface $request): array
    {
        $token = $request->getCookieParams()[self::COOKIE] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new HttpException(401, 'Please log in.');
        }
        $session = $this->store
            ->db
            ->table('sessions')
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', $this->store->now())
            ->first();
        if (!$session) {
            throw new HttpException(401, 'Session expired.');
        }
        return $session + ['csrf' => $this->csrf($token)];
    }

    public function member(ServerRequestInterface $request, int $workspace): array
    {
        $session = $request->getAttribute('session');
        if (
            !is_array($session)
            || !$this->store
                ->db
                ->table('memberships')
                ->where('user_id', $session['user_id'])
                ->where('workspace_id', $workspace)
                ->exists()
        ) {
            throw new HttpException(404, 'Workspace not found.');
        }
        return $this->store->db->table('workspaces')->where('id', $workspace)->first();
    }

    private function csrf(#[\SensitiveParameter] string $token): string
    {
        return hash_hmac('sha256', $token, Store::key());
    }
}
