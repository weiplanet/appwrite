<?php

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\View;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Utopia\Database\Validator\UID;
use Utopia\Storage\Storage;

App::init(function (View $layout) {

    $layout
        ->setParam('description', 'Appwrite Console allows you to easily manage, monitor, and control your entire backend API and tools.')
        ->setParam('analytics', 'UA-26264668-5')
    ;
}, ['layout'], 'console');

App::shutdown(function (Response $response, View $layout) {

    $header = new View(__DIR__ . '/../../views/console/comps/header.phtml');
    $footer = new View(__DIR__ . '/../../views/console/comps/footer.phtml');

    $footer
        ->setParam('home', App::getEnv('_APP_HOME', ''))
        ->setParam('version', App::getEnv('_APP_VERSION', 'UNKNOWN'))
    ;

    $layout
        ->setParam('header', [$header])
        ->setParam('footer', [$footer])
    ;

    $response->html($layout->render());
}, ['response', 'layout'], 'console');

App::get('/error/:code')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->param('code', null, new \Utopia\Validator\Numeric(), 'Valid status code number', false)
    ->inject('layout')
    ->action(function (int $code, View $layout) {

        $page = new View(__DIR__ . '/../../views/error.phtml');

        $page
            ->setParam('code', $code)
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Error')
            ->setParam('body', $page);
    });

App::get('/console')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/index.phtml');

        $page
            ->setParam('home', App::getEnv('_APP_HOME', ''))
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Console')
            ->setParam('body', $page);
    });

App::get('/console/account')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/account/index.phtml');

        $cc = new View(__DIR__ . '/../../views/console/forms/credit-card.phtml');

        $page
            ->setParam('cc', $cc)
        ;

        $layout
            ->setParam('title', 'Account - ' . APP_NAME)
            ->setParam('body', $page);
    });

App::get('/console/notifications')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/v1/console/notifications/index.phtml');

        $layout
            ->setParam('title', APP_NAME . ' - Notifications')
            ->setParam('body', $page);
    });

App::get('/console/home')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/home/index.phtml');
        $page
            ->setParam('usageStatsEnabled', App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled');
        $layout
            ->setParam('title', APP_NAME . ' - Console')
            ->setParam('body', $page);
    });

App::get('/console/settings')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));

        $page = new View(__DIR__ . '/../../views/console/settings/index.phtml');

        $page->setParam('services', array_filter(Config::getParam('services'), fn($element) => $element['optional']))
            ->setParam('customDomainsEnabled', ($target->isKnown() && !$target->isTest()))
            ->setParam('customDomainsTarget', $target->get())
            ->setParam('smtpEnabled', (!empty(App::getEnv('_APP_SMTP_HOST'))))
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Settings')
            ->setParam('body', $page);
    });

App::get('/console/webhooks')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/webhooks/index.phtml');

        $page->setParam('events', Config::getParam('events', []));

        $layout
            ->setParam('title', APP_NAME . ' - Webhooks')
            ->setParam('body', $page);
    });

App::get('/console/webhooks/webhook')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->param('id', '', new UID(), 'Webhook unique ID.')
    ->inject('layout')
    ->action(function (string $id, View $layout) {

        $page = new View(__DIR__ . '/../../views/console/webhooks/webhook.phtml');

        $page
            ->setParam('events', Config::getParam('events', []))
            ->setParam('new', false)
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Webhooks')
            ->setParam('body', $page);
    });

App::get('/console/webhooks/webhook/new')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/webhooks/webhook.phtml');

        $page
            ->setParam('events', Config::getParam('events', []))
            ->setParam('new', true)
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Webhooks')
            ->setParam('body', $page);
    });

App::get('/console/keys')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $scopes = array_keys(Config::getParam('scopes'));
        $page = new View(__DIR__ . '/../../views/console/keys/index.phtml');

        $page->setParam('scopes', $scopes);

        $layout
            ->setParam('title', APP_NAME . ' - API Keys')
            ->setParam('body', $page);
    });

App::get('/console/database')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/database/index.phtml');

        $layout
            ->setParam('title', APP_NAME . ' - Database')
            ->setParam('body', $page);
    });

App::get('/console/database/collection')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->param('id', '', new UID(), 'Collection unique ID.')
    ->inject('response')
    ->inject('layout')
    ->action(function (string $id, Response $response, View $layout) {

        $logs = new View(__DIR__ . '/../../views/console/comps/logs.phtml');

        $logs
            ->setParam('interval', App::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', 0))
            ->setParam('method', 'database.listCollectionLogs')
            ->setParam('params', [
                'collection-id' => '{{router.params.id}}',
            ])
        ;

        $page = new View(__DIR__ . '/../../views/console/database/collection.phtml');

        $page->setParam('logs', $logs);

        $layout
            ->setParam('title', APP_NAME . ' - Database Collection')
            ->setParam('body', $page)
        ;

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Expires', 0)
            ->addHeader('Pragma', 'no-cache')
        ;
    });

App::get('/console/database/document')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->param('collection', '', new UID(), 'Collection unique ID.')
    ->inject('layout')
    ->action(function (string $collection, View $layout) {

        $logs = new View(__DIR__ . '/../../views/console/comps/logs.phtml');

        $logs
            ->setParam('interval', App::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', 0))
            ->setParam('method', 'database.listDocumentLogs')
            ->setParam('params', [
                'collection-id' => '{{router.params.collection}}',
                'document-id' => '{{router.params.id}}',
            ])
        ;

        $page = new View(__DIR__ . '/../../views/console/database/document.phtml');

        $page
            ->setParam('new', false)
            ->setParam('collection', $collection)
            ->setParam('logs', $logs)
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Database Document')
            ->setParam('body', $page);
    });

App::get('/console/database/document/new')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->param('collection', '', new UID(), 'Collection unique ID.')
    ->inject('layout')
    ->action(function (string $collection, View $layout) {

        $page = new View(__DIR__ . '/../../views/console/database/document.phtml');

        $page
            ->setParam('new', true)
            ->setParam('collection', $collection)
            ->setParam('logs', new View())
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Database Document')
            ->setParam('body', $page);
    });

App::get('/console/storage')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/storage/index.phtml');

        $page
            ->setParam('home', App::getEnv('_APP_HOME', 0))
            ->setParam('fileLimit', App::getEnv('_APP_STORAGE_LIMIT', 0))
            ->setParam('fileLimitHuman', Storage::human(App::getEnv('_APP_STORAGE_LIMIT', 0)))
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Storage')
            ->setParam('body', $page);
    });

App::get('/console/storage/bucket')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->param('id', '', new UID(), 'Bucket unique ID.')
    ->inject('response')
    ->inject('layout')
    ->action(function (string $id, Response $response, View $layout) {

        $page = new View(__DIR__ . '/../../views/console/storage/bucket.phtml');
        $page
            ->setParam('home', App::getEnv('_APP_HOME', 0))
            ->setParam('fileLimit', App::getEnv('_APP_STORAGE_LIMIT', 0))
            ->setParam('fileLimitHuman', Storage::human(App::getEnv('_APP_STORAGE_LIMIT', 0)))
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Storage Buckets')
            ->setParam('body', $page)
        ;

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Expires', 0)
            ->addHeader('Pragma', 'no-cache')
        ;
    });

App::get('/console/users')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/users/index.phtml');

        $page
            ->setParam('auth', Config::getParam('auth'))
            ->setParam('providers', Config::getParam('providers'))
            ->setParam('smtpEnabled', (!empty(App::getEnv('_APP_SMTP_HOST'))))
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Users')
            ->setParam('body', $page);
    });

App::get('/console/users/user')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/users/user.phtml');

        $layout
            ->setParam('title', APP_NAME . ' - User')
            ->setParam('body', $page);
    });

App::get('/console/users/teams/team')
    ->groups(['web', 'console'])
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {

        $page = new View(__DIR__ . '/../../views/console/users/team.phtml');

        $layout
            ->setParam('title', APP_NAME . ' - Team')
            ->setParam('body', $page);
    });

App::get('/console/functions')
    ->groups(['web', 'console'])
    ->desc('Platform console project functions')
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {
        $page = new View(__DIR__ . '/../../views/console/functions/index.phtml');

        $page
            ->setParam('runtimes', Config::getParam('runtimes'))
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Functions')
            ->setParam('body', $page);
    });

App::get('/console/functions/function')
    ->groups(['web', 'console'])
    ->desc('Platform console project function')
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('layout')
    ->action(function (View $layout) {
        $page = new View(__DIR__ . '/../../views/console/functions/function.phtml');

        $page
            ->setParam('events', Config::getParam('events', []))
            ->setParam('fileLimit', App::getEnv('_APP_STORAGE_LIMIT', 0))
            ->setParam('fileLimitHuman', Storage::human(App::getEnv('_APP_STORAGE_LIMIT', 0)))
            ->setParam('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900))
            ->setParam('usageStatsEnabled', App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled');
        ;

        $layout
            ->setParam('title', APP_NAME . ' - Function')
            ->setParam('body', $page);
    });

App::get('/console/version')
    ->groups(['web', 'console'])
    ->desc('Check for new version')
    ->label('permission', 'public')
    ->label('scope', 'console')
    ->inject('response')
    ->action(function ($response) {
        try {
            $version = \json_decode(@\file_get_contents(App::getEnv('_APP_HOME', 'http://localhost') . '/v1/health/version'), true);

            if ($version && isset($version['version'])) {
                return $response->json(['version' => $version['version']]);
            } else {
                throw new Exception('Failed to check for a newer version', 500, Exception::GENERAL_SERVER_ERROR);
            }
        } catch (\Throwable $th) {
            throw new Exception('Failed to check for a newer version', 500, Exception::GENERAL_SERVER_ERROR);
        }
    });
