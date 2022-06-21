<?php

global $cli;

use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;

$cli
    ->task('vars')
    ->desc('List all the server environment variables')
    ->action(function () {
        $config = Config::getParam('variables', []);
        $vars = [];

        foreach ($config as $category) {
            foreach ($category['variables'] ?? [] as $var) {
                $vars[] = $var;
            }
        }

        foreach ($vars as $key => $value) {
            Console::log('- ' . $value['name'] . '=' . App::getEnv($value['name'], ''));
        }
    });
