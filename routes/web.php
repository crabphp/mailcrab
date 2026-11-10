<?php

declare(strict_types=1);

use Crab\Http\RateLimit;
use Crab\Route;
use Mailcrab\Http\Api;
use Mailcrab\Http\Authenticated;
use Mailcrab\Http\DeliveryApi;
use Mailcrab\Http\PublicPages;

Route::get('/', [PublicPages::class, 'dashboard']);
Route::get('/ready', [PublicPages::class, 'ready']);
Route::get('/subscribe/{slug}', [PublicPages::class, 'subscribe']);
Route::post('/subscribe/{slug}', [PublicPages::class, 'signup'])->middleware(
    new RateLimit(10, name: 'signup'),
    new RateLimit(120, by: static fn(): string => 'global', name: 'signup-global'),
);
Route::get('/confirm/{token}', [PublicPages::class, 'confirmation']);
Route::post('/confirm/{token}', [PublicPages::class, 'confirm'])->middleware(new RateLimit(30, name: 'confirm'));
Route::get('/u/{token}', [PublicPages::class, 'unsubscribe']);
Route::post('/u/{token}', [PublicPages::class, 'unsubscribePost']);
Route::get('/c/{token}', [PublicPages::class, 'click'])->middleware(new RateLimit(120, name: 'click'));
Route::post('/webhooks/fake', [PublicPages::class, 'feedback'])->middleware(new RateLimit(300, name: 'feedback'));

Route::post('/webhooks/ses/{workspace}', [DeliveryApi::class, 'webhook'])->middleware(
    new RateLimit(600, name: 'ses-feedback'),
);

Route::prefix('/api/v1')->group(static function (): void {
    Route::post('/auth/login', [Api::class, 'login'])->middleware(new RateLimit(10, name: 'login'));
    Route::middleware(Authenticated::class)->group(static function (): void {
        Route::get('/auth/me', [Api::class, 'me']);
        Route::post('/auth/logout', [Api::class, 'logout']);
        Route::prefix('/workspaces/{workspace}')->group(static function (): void {
            Route::get('/delivery', [DeliveryApi::class, 'show']);
            Route::post('/delivery/connection/check', [DeliveryApi::class, 'connection'])->middleware(
                new RateLimit(5, name: 'ses-connect'),
            );
            Route::put('/delivery/preferences', [DeliveryApi::class, 'preferences']);
            Route::post('/delivery/domains', [DeliveryApi::class, 'addDomain']);
            Route::post('/delivery/domains/{id}/connect', [DeliveryApi::class, 'connectDomain'])->middleware(
                new RateLimit(5, name: 'ses-domain-connect'),
            );
            Route::post('/delivery/domains/{id}/check', [DeliveryApi::class, 'refreshDomain'])->middleware(
                new RateLimit(10, name: 'ses-dns-check'),
            );
            Route::post('/delivery/domains/{id}/disconnect', [DeliveryApi::class, 'disconnectDomain']);
            Route::post('/delivery/feedback/connect', [DeliveryApi::class, 'connectFeedback'])->middleware(
                new RateLimit(5, name: 'ses-feedback-connect'),
            );
            Route::post('/delivery/feedback/confirm', [DeliveryApi::class, 'confirmFeedback'])->middleware(
                new RateLimit(5, name: 'ses-feedback-confirm'),
            );
            Route::post('/delivery/feedback/check', [DeliveryApi::class, 'checkFeedback'])->middleware(
                new RateLimit(5, name: 'ses-feedback-check'),
            );
            Route::get('/subscribers', [Api::class, 'subscribers']);
            Route::post('/subscribers', [Api::class, 'addSubscriber']);
            Route::post('/subscribers/import', [Api::class, 'import'])->middleware(new RateLimit(10, name: 'import'));
            Route::get('/subscribers/{id}/consent', [Api::class, 'consent']);
            Route::put('/subscribers/{id}/tags', [Api::class, 'tagSubscriber']);
            Route::post('/subscribers/{id}/suppress', [Api::class, 'suppress']);
            Route::get('/tags', [Api::class, 'tags']);
            Route::get('/senders', [Api::class, 'senders']);
            Route::post('/senders', [Api::class, 'addSender'])->middleware(new RateLimit(5, name: 'sender-add'));
            Route::put('/senders/{id}', [Api::class, 'updateSender']);
            Route::post('/senders/{id}/revoke', [Api::class, 'revokeSender']);
            Route::post('/senders/{id}/verify', [Api::class, 'verifySender'])->middleware(
                new RateLimit(5, name: 'sender-verify'),
            );
            Route::get('/campaigns', [Api::class, 'campaigns']);
            Route::post('/campaigns', [Api::class, 'addCampaign']);
            Route::put('/campaigns/{id}', [Api::class, 'updateCampaign']);
            Route::post('/campaigns/{id}/schedule', [Api::class, 'schedule']);
            Route::post('/campaigns/{id}/cancel', [Api::class, 'cancel']);
            Route::post('/campaigns/{id}/test', [Api::class, 'test'])->middleware(new RateLimit(5, name: 'test-send'));
            Route::get('/campaigns/{id}/preview', [Api::class, 'preview']);
            Route::get('/welcome', [Api::class, 'getWelcome']);
            Route::put('/welcome', [Api::class, 'saveWelcome']);
            Route::get('/outbox', [Api::class, 'outbox']);
            Route::get('/report', [Api::class, 'report']);
        });
    });
});
