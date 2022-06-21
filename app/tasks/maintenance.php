<?php

global $cli;
global $register;

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\CLI\Console;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Document;
use Utopia\Database\Query;

function getConsoleDB(): Database
{
    global $register;

    $attempts = 0;

    do {
        try {
            $attempts++;
            $cache = new Cache(new RedisCache($register->get('cache')));
            $database = new Database(new MariaDB($register->get('db')), $cache);
            $database->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
            $database->setNamespace('_console'); // Main DB

            if (!$database->exists($database->getDefaultDatabase(), 'certificates')) {
                throw new \Exception('Console project not ready');
            }

            break; // leave loop if successful
        } catch (\Exception $e) {
            Console::warning("Database not ready. Retrying connection ({$attempts})...");
            if ($attempts >= DATABASE_RECONNECT_MAX_ATTEMPTS) {
                throw new \Exception('Failed to connect to database: ' . $e->getMessage());
            }
            sleep(DATABASE_RECONNECT_SLEEP);
        }
    } while ($attempts < DATABASE_RECONNECT_MAX_ATTEMPTS);

    return $database;
}

$cli
    ->task('maintenance')
    ->desc('Schedules maintenance tasks and publishes them to resque')
    ->action(function () {
        Console::title('Maintenance V1');
        Console::success(APP_NAME . ' maintenance process v1 has started');

        function notifyDeleteExecutionLogs(int $interval)
        {
            (new Delete())
                ->setType(DELETE_TYPE_EXECUTIONS)
                ->setTimestamp(time() - $interval)
                ->trigger();
        }

        function notifyDeleteAbuseLogs(int $interval)
        {
            (new Delete())
                ->setType(DELETE_TYPE_ABUSE)
                ->setTimestamp(time() - $interval)
                ->trigger();
        }

        function notifyDeleteAuditLogs(int $interval)
        {
            (new Delete())
                ->setType(DELETE_TYPE_AUDIT)
                ->setTimestamp(time() - $interval)
                ->trigger();
        }

        function notifyDeleteUsageStats(int $interval30m, int $interval1d)
        {
            (new Delete())
                ->setType(DELETE_TYPE_USAGE)
                ->setTimestamp1d(time() - $interval1d)
                ->setTimestamp30m(time() - $interval30m)
                ->trigger();
        }

        function notifyDeleteConnections()
        {
            (new Delete())
                ->setType(DELETE_TYPE_REALTIME)
                ->setTimestamp(time() - 60)
                ->trigger();
        }

        function renewCertificates($dbForConsole)
        {
            $time = date('d-m-Y H:i:s', time());
            $certificates = $dbForConsole->find('certificates', [
                new Query('attempts', Query::TYPE_LESSEREQUAL, [5]), // Maximum 5 attempts
                new Query('renewDate', Query::TYPE_LESSEREQUAL, [\time()]) // includes 60 days cooldown (we have 30 days to renew)
            ], 200); // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)


            if (\count($certificates) > 0) {
                Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

                $event = new Certificate();
                foreach ($certificates as $certificate) {
                    $event
                        ->setDomain(new Document([
                            'domain' => $certificate->getAttribute('domain')
                        ]))
                        ->trigger();
                }
            } else {
                Console::info("[{$time}] No certificates for renewal.");
            }
        }

        // # of days in seconds (1 day = 86400s)
        $interval = (int) App::getEnv('_APP_MAINTENANCE_INTERVAL', '86400');
        $executionLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_EXECUTION', '1209600');
        $auditLogRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_AUDIT', '1209600');
        $abuseLogsRetention = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_ABUSE', '86400');
        $usageStatsRetention30m = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_30M', '129600'); //36 hours
        $usageStatsRetention1d = (int) App::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_1D', '8640000'); // 100 days

        Console::loop(function () use ($interval, $executionLogsRetention, $abuseLogsRetention, $auditLogRetention, $usageStatsRetention30m, $usageStatsRetention1d) {
            $database = getConsoleDB();

            $time = date('d-m-Y H:i:s', time());
            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");
            notifyDeleteExecutionLogs($executionLogsRetention);
            notifyDeleteAbuseLogs($abuseLogsRetention);
            notifyDeleteAuditLogs($auditLogRetention);
            notifyDeleteUsageStats($usageStatsRetention30m, $usageStatsRetention1d);
            notifyDeleteConnections();
            renewCertificates($database);
        }, $interval);
    });
