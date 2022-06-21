<?php

global $cli;

use Appwrite\Event\Certificate;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Validator\Hostname;

$cli
    ->task('ssl')
    ->desc('Validate server certificates')
    ->param('domain', App::getEnv('_APP_DOMAIN', ''), new Hostname(), 'Domain to generate certificate for. If empty, main domain will be used.', true)
    ->action(function ($domain) {
        Console::success('Scheduling a job to issue a TLS certificate for domain: ' . $domain);

        (new Certificate())
            ->setDomain(new Document([
                'domain' => $domain
            ]))
            ->setSkipRenewCheck(true)
            ->trigger();
    });
