<?php

use Utopia\Config\Config;
use Utopia\CLI\Console;
use Appwrite\Spec\Swagger2;
use Appwrite\SDK\SDK;
use Appwrite\SDK\Language\CLI;
use Appwrite\SDK\Language\PHP;
use Appwrite\SDK\Language\Web;
use Appwrite\SDK\Language\Node;
use Appwrite\SDK\Language\Python;
use Appwrite\SDK\Language\Ruby;
use Appwrite\SDK\Language\Dart;
use Appwrite\SDK\Language\Deno;
use Appwrite\SDK\Language\DotNet;
use Appwrite\SDK\Language\Flutter;
use Appwrite\SDK\Language\Go;
use Appwrite\SDK\Language\Kotlin;
use Appwrite\SDK\Language\Android;
use Appwrite\SDK\Language\Swift;
use Appwrite\SDK\Language\SwiftClient;

$cli
    ->task('sdks')
    ->action(function () {
        $platforms = Config::getParam('platforms');
        $selected = \strtolower(Console::confirm('Choose SDK ("*" for all):'));
        $version = Console::confirm('Choose an Appwrite version');
        $git = (Console::confirm('Should we use git push? (yes/no)') == 'yes');
        $production = ($git) ? (Console::confirm('Type "Appwrite" to push code to production git repos') == 'Appwrite') : false;
        $message = ($git) ? Console::confirm('Please enter your commit message:') : '';

        if (!in_array($version, ['0.6.x', '0.7.x', '0.8.x', '0.9.x', '0.10.x', '0.11.x', '0.12.x', '0.13.x', '0.14.x', 'latest'])) {
            throw new Exception('Unknown version given');
        }

        foreach ($platforms as $key => $platform) {
            foreach ($platform['languages'] as $language) {
                if ($selected !== $language['key'] && $selected !== '*') {
                    continue;
                }

                if (!$language['enabled']) {
                    Console::warning($language['name'] . ' for ' . $platform['name'] . ' is disabled');
                    continue;
                }

                Console::info('Fetching API Spec for ' . $language['name'] . ' for ' . $platform['name'] . ' (version: ' . $version . ')');

                $spec = file_get_contents(__DIR__ . '/../config/specs/swagger2-' . $version . '-' . $language['family'] . '.json');

                $cover = 'https://appwrite.io/images/github.png';
                $result = \realpath(__DIR__ . '/..') . '/sdks/' . $key . '-' . $language['key'];
                $resultExamples = \realpath(__DIR__ . '/../..') . '/docs/examples/' . $version . '/' . $key . '-' . $language['key'];
                $target = \realpath(__DIR__ . '/..') . '/sdks/git/' . $language['key'] . '/';
                $readme = \realpath(__DIR__ . '/../../docs/sdks/' . $language['key'] . '/README.md');
                $readme = ($readme) ? \file_get_contents($readme) : '';
                $gettingStarted = \realpath(__DIR__ . '/../../docs/sdks/' . $language['key'] . '/GETTING_STARTED.md');
                $gettingStarted = ($gettingStarted) ? \file_get_contents($gettingStarted) : '';
                $examples = \realpath(__DIR__ . '/../../docs/sdks/' . $language['key'] . '/EXAMPLES.md');
                $examples = ($examples) ? \file_get_contents($examples) : '';
                $changelog = \realpath(__DIR__ . '/../../docs/sdks/' . $language['key'] . '/CHANGELOG.md');
                $changelog = ($changelog) ? \file_get_contents($changelog) : '# Change Log';
                $warning = '**This SDK is compatible with Appwrite server version ' . $version . '. For older versions, please check [previous releases](' . $language['url'] . '/releases).**';
                $license = 'BSD-3-Clause';
                $licenseContent = 'Copyright (c) ' . date('Y') . ' Appwrite (https://appwrite.io) and individual contributors.
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

    3. Neither the name Appwrite nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.';

                switch ($language['key']) {
                    case 'web':
                        $config = new Web();
                        $config->setNPMPackage('appwrite');
                        $config->setBowerPackage('appwrite');
                        break;
                    case 'cli':
                        $config = new CLI();
                        $config->setNPMPackage('appwrite-cli');
                        $config->setExecutableName('appwrite');
                        $config->setLogo(json_encode("
    _                            _ _           ___   __   _____ 
   /_\  _ __  _ ____      ___ __(_) |_ ___    / __\ / /   \_   \
  //_\\\| '_ \| '_ \ \ /\ / / '__| | __/ _ \  / /   / /     / /\/
 /  _  \ |_) | |_) \ V  V /| |  | | ||  __/ / /___/ /___/\/ /_  
 \_/ \_/ .__/| .__/ \_/\_/ |_|  |_|\__\___| \____/\____/\____/  
       |_|   |_|                                                

"));
                        $config->setLogoUnescaped("
     _                            _ _           ___   __   _____ 
    /_\  _ __  _ ____      ___ __(_) |_ ___    / __\ / /   \_   \
   //_\\\| '_ \| '_ \ \ /\ / / '__| | __/ _ \  / /   / /     / /\/
  /  _  \ |_) | |_) \ V  V /| |  | | ||  __/ / /___/ /___/\/ /_  
  \_/ \_/ .__/| .__/ \_/\_/ |_|  |_|\__\___| \____/\____/\____/  
        |_|   |_|                                                ");
                        break;
                    case 'php':
                        $config = new PHP();
                        $config->setComposerVendor('appwrite');
                        $config->setComposerPackage('appwrite');
                        break;
                    case 'nodejs':
                        $config = new Node();
                        $config->setNPMPackage('node-appwrite');
                        $config->setBowerPackage('appwrite');
                        $warning = $warning . "\n\n > This is the Node.js SDK for integrating with Appwrite from your Node.js server-side code.
                            If you're looking to integrate from the browser, you should check [appwrite/sdk-for-web](https://github.com/appwrite/sdk-for-web)";
                        break;
                    case 'deno':
                        $config = new Deno();
                        break;
                    case 'python':
                        $config = new Python();
                        $config->setPipPackage('appwrite');
                        $license = 'BSD License'; // license edited due to classifiers in pypi
                        break;
                    case 'ruby':
                        $config = new Ruby();
                        $config->setGemPackage('appwrite');
                        break;
                    case 'flutter':
                        $config = new Flutter();
                        $config->setPackageName('appwrite');
                        break;
                    case 'flutter-dev':
                        $config = new Flutter();
                        $config->setPackageName('appwrite_dev');
                        break;
                    case 'dart':
                        $config = new Dart();
                        $config->setPackageName('dart_appwrite');
                        $warning = $warning . "\n\n > This is the Dart SDK for integrating with Appwrite from your Dart server-side code. If you're looking for the Flutter SDK you should check [appwrite/sdk-for-flutter](https://github.com/appwrite/sdk-for-flutter)";
                        break;
                    case 'go':
                        $config = new Go();
                        break;
                    case 'swift':
                        $config = new Swift();
                        $warning = $warning . "\n\n > This is the Swift SDK for integrating with Appwrite from your Swift server-side code. If you're looking for the Apple SDK you should check [appwrite/sdk-for-apple](https://github.com/appwrite/sdk-for-apple)";
                        break;
                    case 'apple':
                        $config = new SwiftClient();
                        break;
                    case 'dotnet':
                        $cover = '';
                        $config = new DotNet();
                        break;
                    case 'android':
                        $config = new Android();
                        break;
                    case 'kotlin':
                        $config = new Kotlin();
                        $warning = $warning . "\n\n > This is the Kotlin SDK for integrating with Appwrite from your Kotlin server-side code. If you're looking for the Android SDK you should check [appwrite/sdk-for-android](https://github.com/appwrite/sdk-for-android)";
                        break;
                    default:
                        throw new Exception('Language "' . $language['key'] . '" not supported');
                        break;
                }

                Console::info("Generating {$language['name']} SDK...");

                $sdk = new SDK($config, new Swagger2($spec));

                $sdk
                    ->setName($language['name'])
                    ->setNamespace('io appwrite')
                    ->setDescription("Appwrite is an open-source backend as a service server that abstract and simplify complex and repetitive development tasks behind a very simple to use REST API. Appwrite aims to help you develop your apps faster and in a more secure way. Use the {$language['name']} SDK to integrate your app with the Appwrite server to easily start interacting with all of Appwrite backend APIs and tools. For full API documentation and tutorials go to [https://appwrite.io/docs](https://appwrite.io/docs)")
                    ->setShortDescription('Appwrite is an open-source self-hosted backend server that abstract and simplify complex and repetitive development tasks behind a very simple REST API')
                    ->setLicense($license)
                    ->setLicenseContent($licenseContent)
                    ->setVersion($language['version'])
                    ->setGitURL($language['url'])
                    ->setGitRepo($language['gitUrl'])
                    ->setGitRepoName($language['gitRepoName'])
                    ->setGitUserName($language['gitUserName'])
                    ->setLogo($cover)
                    ->setURL('https://appwrite.io')
                    ->setShareText('Appwrite is a backend as a service for building web or mobile apps')
                    ->setShareURL('http://appwrite.io')
                    ->setShareTags('JS,javascript,reactjs,angular,ios,android,serverless')
                    ->setShareVia('appwrite')
                    ->setWarning($warning)
                    ->setReadme($readme)
                    ->setGettingStarted($gettingStarted)
                    ->setChangelog($changelog)
                    ->setExamples($examples)
                    ->setTwitter(APP_SOCIAL_TWITTER_HANDLE)
                    ->setDiscord(APP_SOCIAL_DISCORD_CHANNEL, APP_SOCIAL_DISCORD)
                    ->setDefaultHeaders([
                        'X-Appwrite-Response-Format' => '0.14.0',
                    ]);

                try {
                    $sdk->generate($result);
                } catch (Exception $exception) {
                    Console::error($exception->getMessage());
                } catch (Throwable $exception) {
                    Console::error($exception->getMessage());
                }

                $gitUrl = $language['gitUrl'];
                $gitBranch = $language['gitBranch'];


                if (!$production) {
                    $gitUrl = 'git@github.com:aw-tests/' . $language['gitRepoName'] . '.git';
                }

                if ($git && !empty($gitUrl)) {
                    \exec('rm -rf ' . $target . ' && \
                        mkdir -p ' . $target . ' && \
                        cd ' . $target . ' && \
                        git init --initial-branch=' . $gitBranch . ' && \
                        git remote add origin ' . $gitUrl . ' && \
                        git fetch && \
                        git pull ' . $gitUrl . ' && \
                        rm -rf ' . $target . '/* && \
                        cp -r ' . $result . '/* ' . $target . '/ && \
                        git add . && \
                        git commit -m "' . $message . '" && \
                        git push -u origin ' . $gitBranch . '
                    ');

                    Console::success("Pushed {$language['name']} SDK to {$gitUrl}");

                    \exec('rm -rf ' . $target);
                    Console::success("Remove temp directory '{$target}' for {$language['name']} SDK");
                }

                $docDirectories = $language['docDirectories'] ?? [''];

                if ($version === 'latest') {
                    continue;
                }

                foreach ($docDirectories as $languageTitle => $path) {
                    $languagePath = strtolower($languageTitle !== 0 ? '/' . $languageTitle : '');
                    \exec(
                        'mkdir -p ' . $resultExamples . $languagePath . ' && \
                        cp -r ' . $result . '/docs/examples' . $languagePath . ' ' . $resultExamples
                    );
                    Console::success("Copied code examples for {$language['name']} SDK to: {$resultExamples}");
                }
            }
        }

        Console::exit();
    });
