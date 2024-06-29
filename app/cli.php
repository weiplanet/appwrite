<?php

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/controllers/general.php';

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Func;
use Appwrite\Platform\Appwrite;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Cache;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DSN\DSN;
use Utopia\Logger\Log;
use Utopia\Platform\Service;
use Utopia\Pools\Group;
use Utopia\Queue\Connection;
use Utopia\Registry\Registry;
use Utopia\System\System;

Authorization::disable();

CLI::setResource('register', fn () => $register);

CLI::setResource('cache', function ($pools) {
    $list = Config::getParam('pools-cache', []);
    $adapters = [];

    foreach ($list as $value) {
        $adapters[] = $pools
            ->get($value)
            ->pop()
            ->getResource()
        ;
    }

    return new Cache(new Sharding($adapters));
}, ['pools']);

CLI::setResource('pools', function (Registry $register) {
    return $register->get('pools');
}, ['register']);

CLI::setResource('dbForConsole', function ($pools, $cache) {
    $sleep = 3;
    $maxAttempts = 5;
    $attempts = 0;
    $ready = false;

    do {
        $attempts++;
        try {
            // Prepare database connection
            $dbAdapter = $pools
                ->get('console')
                ->pop()
                ->getResource();

            $dbForConsole = new Database($dbAdapter, $cache);

            $dbForConsole
                ->setNamespace('_console')
                ->setMetadata('host', \gethostname())
                ->setMetadata('project', 'console');

            // Ensure tables exist
            $collections = Config::getParam('collections', [])['console'];
            $last = \array_key_last($collections);

            if (!($dbForConsole->exists($dbForConsole->getDatabase(), $last))) { /** TODO cache ready variable using registry */
                throw new Exception('Tables not ready yet.');
            }

            $ready = true;
        } catch (\Throwable $err) {
            Console::warning($err->getMessage());
            $pools->get('console')->reclaim();
            sleep($sleep);
        }
    } while ($attempts < $maxAttempts && !$ready);

    if (!$ready) {
        throw new Exception("Console is not ready yet. Please try again later.");
    }

    return $dbForConsole;
}, ['pools', 'cache']);

CLI::setResource('getProjectDB', function (Group $pools, Database $dbForConsole, $cache) {
    $databases = []; // TODO: @Meldiron This should probably be responsibility of utopia-php/pools

    return function (Document $project) use ($pools, $dbForConsole, $cache, &$databases) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $dbForConsole;
        }

        try {
            $dsn = new DSN($project->getAttribute('database'));
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $project->getAttribute('database'));
        }

        if (isset($databases[$dsn->getHost()])) {
            $database = $databases[$dsn->getHost()];

            if ($dsn->getHost() === DATABASE_SHARED_TABLES) {
                $database
                    ->setSharedTables(true)
                    ->setTenant($project->getInternalId())
                    ->setNamespace($dsn->getParam('namespace'));
            } else {
                $database
                    ->setSharedTables(false)
                    ->setTenant(null)
                    ->setNamespace('_' . $project->getInternalId());
            }

            return $database;
        }

        $dbAdapter = $pools
            ->get($dsn->getHost())
            ->pop()
            ->getResource();

        $database = new Database($dbAdapter, $cache);

        $databases[$dsn->getHost()] = $database;

        if ($dsn->getHost() === DATABASE_SHARED_TABLES) {
            $database
                ->setSharedTables(true)
                ->setTenant($project->getInternalId())
                ->setNamespace($dsn->getParam('namespace'));
        } else {
            $database
                ->setSharedTables(false)
                ->setTenant(null)
                ->setNamespace('_' . $project->getInternalId());
        }

        $database
            ->setMetadata('host', \gethostname())
            ->setMetadata('project', $project->getId());

        return $database;
    };
}, ['pools', 'dbForConsole', 'cache']);

CLI::setResource('queue', function (Group $pools) {
    return $pools->get('queue')->pop()->getResource();
}, ['pools']);
CLI::setResource('queueForFunctions', function (Connection $queue) {
    return new Func($queue);
}, ['queue']);
CLI::setResource('queueForDeletes', function (Connection $queue) {
    return new Delete($queue);
}, ['queue']);
CLI::setResource('queueForCertificates', function (Connection $queue) {
    return new Certificate($queue);
}, ['queue']);
CLI::setResource('logError', function (Registry $register) {
    return function (Throwable $error, string $namespace, string $action) use ($register) {
        $logger = $register->get('logger');

        if ($logger) {
            $version = System::getEnv('_APP_VERSION', 'UNKNOWN');

            $log = new Log();
            $log->setNamespace($namespace);
            $log->setServer(\gethostname());
            $log->setVersion($version);
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($error->getMessage());

            $log->addTag('code', $error->getCode());
            $log->addTag('verboseType', get_class($error));

            $log->addExtra('file', $error->getFile());
            $log->addExtra('line', $error->getLine());
            $log->addExtra('trace', $error->getTraceAsString());
            $log->addExtra('detailedTrace', $error->getTrace());

            $log->setAction($action);

            $isProduction = System::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            $responseCode = $logger->addLog($log);
            Console::info('Usage stats log pushed with status code: ' . $responseCode);
        }

        Console::warning("Failed: {$error->getMessage()}");
        Console::warning($error->getTraceAsString());
    };
}, ['register']);

$platform = new Appwrite();
$platform->init(Service::TYPE_TASK);

$cli = $platform->getCli();

$cli
    ->error()
    ->inject('error')
    ->action(function (Throwable $error) {
        Console::error($error->getMessage());
    });

$cli->run();
