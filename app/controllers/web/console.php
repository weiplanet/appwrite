<?php

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\App;

App::init()
    ->groups(['web'])
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $response
            ->addHeader('X-Frame-Options', 'SAMEORIGIN') // Avoid console and homepage from showing in iframes
            ->addHeader('X-XSS-Protection', '1; mode=block; report=/v1/xss?url=' . \urlencode($request->getURI()))
            ->addHeader('X-UA-Compatible', 'IE=Edge') // Deny IE browsers from going into quirks mode
        ;
    });

App::get('/console/*')
    ->alias('/')
    ->alias('auth/*')
    ->alias('/invite')
    ->alias('/login')
    ->alias('/mfa')
    ->alias('/card/*')
    ->alias('/recover')
    ->alias('/register/*')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $fallback = file_get_contents(__DIR__ . '/../../../console/index.html');

        // Card SSR
        if (\str_starts_with($request->getURI(), '/card')) {
            $urlCunks = \explode('/', $request->getURI());
            $userId = $urlCunks[\count($urlCunks) - 1] ?? '';

            $domain = $request->getProtocol() . '://' . $request->getHostname();

            if (!empty($userId)) {
                $ogImageUrl = $domain . '/v1/cards/cloud-og?userId=' . $userId;
            } else {
                $ogImageUrl = $domain . '/v1/cards/cloud-og?mock=normal';
            }

            $ogTags = [
                '<title>Appwrite Cloud Card</title>',
                '<meta name="description" content="Appwrite Cloud is now LIVE! Share your Cloud card for a chance to win an exclusive Cloud hoodie!">',
                '<meta property="og:url" content="' . $domain . $request->getURI() . '">',
                '<meta name="og:image:type" content="image/png">',
                '<meta name="og:image:width" content="1008">',
                '<meta name="og:image:height" content="1008">',
                '<meta property="og:type" content="website">',
                '<meta property="og:title" content="Appwrite Cloud Card">',
                '<meta property="og:description" content="Appwrite Cloud is now LIVE! Share your Cloud card for a chance to win an exclusive Cloud hoodie!">',
                '<meta property="og:image" content="' . $ogImageUrl . '">',
                '<meta name="twitter:card" content="summary_large_image">',
                '<meta property="twitter:domain" content="' . $request->getHostname() . '">',
                '<meta property="twitter:url" content="' . $domain . $request->getURI() . '">',
                '<meta name="twitter:title" content="Appwrite Cloud Card">',
                '<meta name="twitter:image:type" content="image/png">',
                '<meta name="twitter:image:width" content="1008">',
                '<meta name="twitter:image:height" content="1008">',
                '<meta name="twitter:description" content="Appwrite Cloud is now LIVE! Share your Cloud card for a chance to win an exclusive Cloud hoodie!">',
                '<meta name="twitter:image" content="' . $ogImageUrl . '">',
            ];

            $fallback = \str_replace('<!-- {{CLOUD_OG}} -->', \implode('', $ogTags), $fallback);
        }

        $response->html($fallback);
    });
