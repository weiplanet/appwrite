<?php

global $cli, $register;

use Utopia\CLI\Console;
use Appwrite\Migration\Migration;
use Utopia\App;
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Redis as RedisCache;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Text;

$cli
    ->task('migrate')
    ->param('version', APP_VERSION_STABLE, new Text(8), 'Version to migrate to.', true)
    ->action(function ($version) use ($register) {
        Authorization::disable();
        if (!array_key_exists($version, Migration::$versions)) {
            Console::error("Version {$version} not found.");
            Console::exit(1);
            return;
        }

        $app = new App('UTC');

        Console::success('Starting Data Migration to version ' . $version);

        $db = $register->get('db', true);
        $cache = $register->get('cache', true);

        $cache = new Cache(new RedisCache($cache));

        $projectDB = new Database(new MariaDB($db), $cache);
        $projectDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));

        $consoleDB = new Database(new MariaDB($db), $cache);
        $consoleDB->setDefaultDatabase(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));
        $consoleDB->setNamespace('_project_console');

        $console = $app->getResource('console');

        $limit = 30;
        $sum = 30;
        $offset = 0;
        $projects = [$console];
        $count = 0;

        try {
            $totalProjects = $consoleDB->count('projects') + 1;
        } catch (\Throwable $th) {
            $consoleDB->setNamespace('_console');
            $totalProjects = $consoleDB->count('projects') + 1;
        }

        $class = 'Appwrite\\Migration\\Version\\' . Migration::$versions[$version];
        $migration = new $class();

        while (!empty($projects)) {
            foreach ($projects as $project) {
                try {
                    $migration
                        ->setProject($project, $projectDB, $consoleDB)
                        ->execute();
                } catch (\Throwable $th) {
                    throw $th;
                    Console::error('Failed to update project ("' . $project->getId() . '") version with error: ' . $th->getMessage());
                }
            }

            $sum = \count($projects);
            $projects = $consoleDB->find('projects', limit: $limit, offset: $offset);

            $offset = $offset + $limit;
            $count = $count + $sum;

            Console::log('Migrated ' . $count . '/' . $totalProjects . ' projects...');
        }

        Swoole\Event::wait(); // Wait for Coroutines to finish
        Console::success('Data Migration Completed');
    });
