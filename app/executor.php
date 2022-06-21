<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Appwrite\Runtimes\Runtimes;
use Swoole\ConnectionPool;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Swoole\Process;
use Swoole\Runtime;
use Swoole\Timer;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\Backblaze;
use Utopia\Storage\Device\DOSpaces;
use Utopia\Storage\Device\Linode;
use Utopia\Storage\Device\Wasabi;
use Utopia\Storage\Device\S3;
use Utopia\Storage\Storage;
use Utopia\Swoole\Request;
use Utopia\Swoole\Response;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;


Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL);

/** Constants */
const MAINTENANCE_INTERVAL = 3600; // 3600 seconds = 1 hour

/**
* Create a Swoole table to store runtime information
*/
$activeRuntimes = new Swoole\Table(1024);
$activeRuntimes->column('id', Swoole\Table::TYPE_STRING, 256);
$activeRuntimes->column('created', Swoole\Table::TYPE_INT, 8);
$activeRuntimes->column('updated', Swoole\Table::TYPE_INT, 8);
$activeRuntimes->column('name', Swoole\Table::TYPE_STRING, 128);
$activeRuntimes->column('status', Swoole\Table::TYPE_STRING, 128);
$activeRuntimes->column('key', Swoole\Table::TYPE_STRING, 256);
$activeRuntimes->create();

/**
 * Create orchestration pool
 */
$orchestrationPool = new ConnectionPool(function () {
    $dockerUser = App::getEnv('DOCKERHUB_PULL_USERNAME', null);
    $dockerPass = App::getEnv('DOCKERHUB_PULL_PASSWORD', null);
    $orchestration = new Orchestration(new DockerCLI($dockerUser, $dockerPass));
    return $orchestration;
}, 10);


/**
 * Create logger instance
 */
$providerName = App::getEnv('_APP_LOGGING_PROVIDER', '');
$providerConfig = App::getEnv('_APP_LOGGING_CONFIG', '');
$logger = null;

if (!empty($providerName) && !empty($providerConfig) && Logger::hasProvider($providerName)) {
    $classname = '\\Utopia\\Logger\\Adapter\\' . \ucfirst($providerName);
    $adapter = new $classname($providerConfig);
    $logger = new Logger($adapter);
}

function logError(Throwable $error, string $action, Utopia\Route $route = null)
{
    global $logger;

    if ($logger) {
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        $log = new Log();
        $log->setNamespace("executor");
        $log->setServer(\gethostname());
        $log->setVersion($version);
        $log->setType(Log::TYPE_ERROR);
        $log->setMessage($error->getMessage());

        if ($route) {
            $log->addTag('method', $route->getMethod());
            $log->addTag('url', $route->getPath());
        }

        $log->addTag('code', $error->getCode());
        $log->addTag('verboseType', get_class($error));

        $log->addExtra('file', $error->getFile());
        $log->addExtra('line', $error->getLine());
        $log->addExtra('trace', $error->getTraceAsString());
        $log->addExtra('detailedTrace', $error->getTrace());

        $log->setAction($action);

        $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
        $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

        $responseCode = $logger->addLog($log);
        Console::info('Executor log pushed with status code: ' . $responseCode);
    }

    Console::error('[Error] Type: ' . get_class($error));
    Console::error('[Error] Message: ' . $error->getMessage());
    Console::error('[Error] File: ' . $error->getFile());
    Console::error('[Error] Line: ' . $error->getLine());
}

function getStorageDevice($root): Device
{
    switch (App::getEnv('_APP_STORAGE_DEVICE', Storage::DEVICE_LOCAL)) {
        case Storage::DEVICE_LOCAL:
        default:
            return new Local($root);
        case Storage::DEVICE_S3:
            $s3AccessKey = App::getEnv('_APP_STORAGE_S3_ACCESS_KEY', '');
            $s3SecretKey = App::getEnv('_APP_STORAGE_S3_SECRET', '');
            $s3Region = App::getEnv('_APP_STORAGE_S3_REGION', '');
            $s3Bucket = App::getEnv('_APP_STORAGE_S3_BUCKET', '');
            $s3Acl = 'private';
            return new S3($root, $s3AccessKey, $s3SecretKey, $s3Bucket, $s3Region, $s3Acl);
        case Storage::DEVICE_DO_SPACES:
            $doSpacesAccessKey = App::getEnv('_APP_STORAGE_DO_SPACES_ACCESS_KEY', '');
            $doSpacesSecretKey = App::getEnv('_APP_STORAGE_DO_SPACES_SECRET', '');
            $doSpacesRegion = App::getEnv('_APP_STORAGE_DO_SPACES_REGION', '');
            $doSpacesBucket = App::getEnv('_APP_STORAGE_DO_SPACES_BUCKET', '');
            $doSpacesAcl = 'private';
            return new DOSpaces($root, $doSpacesAccessKey, $doSpacesSecretKey, $doSpacesBucket, $doSpacesRegion, $doSpacesAcl);
        case Storage::DEVICE_BACKBLAZE:
            $backblazeAccessKey = App::getEnv('_APP_STORAGE_BACKBLAZE_ACCESS_KEY', '');
            $backblazeSecretKey = App::getEnv('_APP_STORAGE_BACKBLAZE_SECRET', '');
            $backblazeRegion = App::getEnv('_APP_STORAGE_BACKBLAZE_REGION', '');
            $backblazeBucket = App::getEnv('_APP_STORAGE_BACKBLAZE_BUCKET', '');
            $backblazeAcl = 'private';
            return new Backblaze($root, $backblazeAccessKey, $backblazeSecretKey, $backblazeBucket, $backblazeRegion, $backblazeAcl);
        case Storage::DEVICE_LINODE:
            $linodeAccessKey = App::getEnv('_APP_STORAGE_LINODE_ACCESS_KEY', '');
            $linodeSecretKey = App::getEnv('_APP_STORAGE_LINODE_SECRET', '');
            $linodeRegion = App::getEnv('_APP_STORAGE_LINODE_REGION', '');
            $linodeBucket = App::getEnv('_APP_STORAGE_LINODE_BUCKET', '');
            $linodeAcl = 'private';
            return new Linode($root, $linodeAccessKey, $linodeSecretKey, $linodeBucket, $linodeRegion, $linodeAcl);
        case Storage::DEVICE_WASABI:
            $wasabiAccessKey = App::getEnv('_APP_STORAGE_WASABI_ACCESS_KEY', '');
            $wasabiSecretKey = App::getEnv('_APP_STORAGE_WASABI_SECRET', '');
            $wasabiRegion = App::getEnv('_APP_STORAGE_WASABI_REGION', '');
            $wasabiBucket = App::getEnv('_APP_STORAGE_WASABI_BUCKET', '');
            $wasabiAcl = 'private';
            return new Wasabi($root, $wasabiAccessKey, $wasabiSecretKey, $wasabiBucket, $wasabiRegion, $wasabiAcl);
    }
}

App::post('/v1/runtimes')
    ->desc("Create a new runtime server")
    ->param('runtimeId', '', new Text(64), 'Unique runtime ID.')
    ->param('source', '', new Text(0), 'Path to source files.')
    ->param('destination', '', new Text(0), 'Destination folder to store build files into.', true)
    ->param('vars', [], new Assoc(), 'Environment Variables required for the build.')
    ->param('commands', [], new ArrayList(new Text(1024), 100), 'Commands required to build the container. Maximum of 100 commands are allowed, each 1024 characters long.')
    ->param('runtime', '', new Text(128), 'Runtime for the cloud function.')
    ->param('baseImage', '', new Text(128), 'Base image name of the runtime.')
    ->param('entrypoint', '', new Text(256), 'Entrypoint of the code file.', true)
    ->param('remove', false, new Boolean(), 'Remove a runtime after execution.')
    ->param('workdir', '', new Text(256), 'Working directory.', true)
    ->inject('orchestrationPool')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, string $source, string $destination, array $vars, array $commands, string $runtime, string $baseImage, string $entrypoint, bool $remove, string $workdir, $orchestrationPool, $activeRuntimes, Response $response) {
        if ($activeRuntimes->exists($runtimeId)) {
            if ($activeRuntimes->get($runtimeId)['status'] == 'pending') {
                throw new \Exception('A runtime with the same ID is already being created. Attempt a execution soon.', 500);
            }

            throw new Exception('Runtime already exists.', 409);
        }

        $container = [];
        $containerId = '';
        $stdout = '';
        $stderr = '';
        $startTime = \time();
        $endTime = 0;
        $orchestration = $orchestrationPool->get();

        $secret = \bin2hex(\random_bytes(16));

        if (!$remove) {
            $activeRuntimes->set($runtimeId, [
                'id' => $containerId,
                'name' => $runtimeId,
                'created' => $startTime,
                'updated' => $endTime,
                'status' => 'pending',
                'key' => $secret,
            ]);
        }

        try {
            Console::info('Building container : ' . $runtimeId);

            /**
             * Temporary file paths in the executor
             */
            $tmpSource = "/tmp/$runtimeId/src/code.tar.gz";
            $tmpBuild = "/tmp/$runtimeId/builds/code.tar.gz";

            /**
             * Copy code files from source to a temporary location on the executor
             */
            $sourceDevice = getStorageDevice("/");
            $localDevice = new Local();
            $buffer = $sourceDevice->read($source);
            if (!$localDevice->write($tmpSource, $buffer)) {
                throw new Exception('Failed to copy source code to temporary directory', 500);
            };

            /**
             * Create the mount folder
             */
            if (!\file_exists(\dirname($tmpBuild))) {
                if (!@\mkdir(\dirname($tmpBuild), 0755, true)) {
                    throw new Exception("Failed to create temporary directory", 500);
                }
            }

            /**
             * Create container
             */
            $vars = \array_merge($vars, [
                'INTERNAL_RUNTIME_KEY' => $secret,
                'INTERNAL_RUNTIME_ENTRYPOINT' => $entrypoint,
            ]);
            $vars = array_map(fn ($v) => strval($v), $vars);
            $orchestration
                ->setCpus((int) App::getEnv('_APP_FUNCTIONS_CPUS', 0))
                ->setMemory((int) App::getEnv('_APP_FUNCTIONS_MEMORY', 0))
                ->setSwap((int) App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', 0));

            /** Keep the container alive if we have commands to be executed */
            $entrypoint = !empty($commands) ? [
                'tail',
                '-f',
                '/dev/null'
            ] : [];

            $containerId = $orchestration->run(
                image: $baseImage,
                name: $runtimeId,
                hostname: $runtimeId,
                vars: $vars,
                command: $entrypoint,
                labels: [
                    'openruntimes-id' => $runtimeId,
                    'openruntimes-type' => 'runtime',
                    'openruntimes-created' => strval($startTime),
                    'openruntimes-runtime' => $runtime,
                ],
                workdir: $workdir,
                volumes: [
                    \dirname($tmpSource) . ':/tmp:rw',
                    \dirname($tmpBuild) . ':/usr/code:rw'
                ]
            );

            if (empty($containerId)) {
                throw new Exception('Failed to create build container', 500);
            }

            $orchestration->networkConnect($runtimeId, App::getEnv('OPEN_RUNTIMES_NETWORK', 'appwrite_runtimes'));

            /**
             * Execute any commands if they were provided
             */
            if (!empty($commands)) {
                $status = $orchestration->execute(
                    name: $runtimeId,
                    command: $commands,
                    stdout: $stdout,
                    stderr: $stderr,
                    timeout: App::getEnv('_APP_FUNCTIONS_BUILD_TIMEOUT', 900)
                );

                if (!$status) {
                    throw new Exception('Failed to build dependenices ' . $stderr, 500);
                }
            }

            /**
             * Move built code to expected build directory
             */
            if (!empty($destination)) {
                // Check if the build was successful by checking if file exists
                if (!\file_exists($tmpBuild)) {
                    throw new Exception('Something went wrong during the build process', 500);
                }

                $destinationDevice = getStorageDevice($destination);
                $outputPath = $destinationDevice->getPath(\uniqid() . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));

                $buffer = $localDevice->read($tmpBuild);
                if (!$destinationDevice->write($outputPath, $buffer, $localDevice->getFileMimeType($tmpBuild))) {
                    throw new Exception('Failed to move built code to storage', 500);
                };

                $container['outputPath'] = $outputPath;
            }

            if (empty($stdout)) {
                $stdout = 'Build Successful!';
            }

            $endTime = \time();
            $container = array_merge($container, [
                'status' => 'ready',
                'response' => \mb_strcut($stdout, 0, 1000000), // Limit to 1MB
                'stderr' => \mb_strcut($stderr, 0, 1000000), // Limit to 1MB
                'startTime' => $startTime,
                'endTime' => $endTime,
                'duration' => $endTime - $startTime,
            ]);

            if (!$remove) {
                $activeRuntimes->set($runtimeId, [
                    'id' => $containerId,
                    'name' => $runtimeId,
                    'created' => $startTime,
                    'updated' => $endTime,
                    'status' => 'Up ' . \round($endTime - $startTime, 2) . 's',
                    'key' => $secret,
                ]);
            }

            Console::success('Build Stage completed in ' . ($endTime - $startTime) . ' seconds');
        } catch (Throwable $th) {
            Console::error('Build failed: ' . $th->getMessage() . $stdout);

            throw new Exception($th->getMessage() . $stdout, 500);
        } finally {
            // Container cleanup
            if ($remove) {
                if (!empty($containerId)) {
                    // If container properly created
                    $orchestration->remove($containerId, true);
                    $activeRuntimes->del($runtimeId);
                } else {
                    // If whole creation failed, but container might have been initialized
                    try {
                        // Try to remove with contaier name instead of ID
                        $orchestration->remove($runtimeId, true);
                        $activeRuntimes->del($runtimeId);
                    } catch (Throwable $th) {
                        // If fails, means initialization also failed.
                        // Contianer is not there, no need to remove
                    }
                }
            }

            // Release orchestration back to pool, we are done with it
            $orchestrationPool->put($orchestration);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json($container);
    });


App::get('/v1/runtimes')
    ->desc("List currently active runtimes")
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function ($activeRuntimes, Response $response) {
        $runtimes = [];

        foreach ($activeRuntimes as $runtime) {
            $runtimes[] = $runtime;
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($runtimes);
    });

App::get('/v1/runtimes/:runtimeId')
    ->desc("Get a runtime by its ID")
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function ($runtimeId, $activeRuntimes, Response $response) {

        if (!$activeRuntimes->exists($runtimeId)) {
            throw new Exception('Runtime not found', 404);
        }

        $runtime = $activeRuntimes->get($runtimeId);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($runtime);
    });

App::delete('/v1/runtimes/:runtimeId')
    ->desc('Delete a runtime')
    ->param('runtimeId', '', new Text(64), 'Runtime unique ID.', false)
    ->inject('orchestrationPool')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(function (string $runtimeId, $orchestrationPool, $activeRuntimes, Response $response) {

        if (!$activeRuntimes->exists($runtimeId)) {
            throw new Exception('Runtime not found', 404);
        }

        Console::info('Deleting runtime: ' . $runtimeId);

        try {
            $orchestration = $orchestrationPool->get();
            $orchestration->remove($runtimeId, true);
            $activeRuntimes->del($runtimeId);
            Console::success('Removed runtime container: ' . $runtimeId);
        } finally {
            $orchestrationPool->put($orchestration);
        }

        // Remove all the build containers with that same  ID
        // TODO:: Delete build containers
        // foreach ($buildIds as $buildId) {
        //     try {
        //         Console::info('Deleting build container : ' . $buildId);
        //         $status = $orchestration->remove('build-' . $buildId, true);
        //     } catch (Throwable $th) {
        //         Console::error($th->getMessage());
        //     }
        // }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });


App::post('/v1/execution')
    ->desc('Create an execution')
    ->param('runtimeId', '', new Text(64), 'The runtimeID to execute.')
    ->param('vars', [], new Assoc(), 'Environment variables required for the build.')
    ->param('data', '', new Text(8192), 'Data to be forwarded to the function, this is user specified.', true)
    ->param('timeout', 15, new Range(1, (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Function maximum execution time in seconds.')
    ->inject('activeRuntimes')
    ->inject('response')
    ->action(
        function (string $runtimeId, array $vars, string $data, $timeout, $activeRuntimes, Response $response) {
            if (!$activeRuntimes->exists($runtimeId)) {
                throw new Exception('Runtime not found. Please create the runtime.', 404);
            }

            for ($i = 0; $i < 5; $i++) {
                if ($activeRuntimes->get($runtimeId)['status'] === 'pending') {
                    Console::info('Waiting for runtime to be ready...');
                    sleep(1);
                } else {
                    break;
                }

                if ($i === 4) {
                    throw new Exception('Runtime failed to launch in allocated time.', 500);
                }
            }

            $runtime = $activeRuntimes->get($runtimeId);
            $secret = $runtime['key'];
            if (empty($secret)) {
                throw new Exception('Runtime secret not found. Please re-create the runtime.', 500);
            }

            Console::info('Executing Runtime: ' . $runtimeId);

            $execution = [];
            $executionStart = \microtime(true);
            $stdout = '';
            $stderr = '';
            $statusCode = 0;
            $errNo = -1;
            $executorResponse = '';

            $timeout ??= (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900);

            $ch = \curl_init();
            $body = \json_encode([
                'env' => $vars,
                'payload' => $data,
                'timeout' => $timeout
            ]);
            \curl_setopt($ch, CURLOPT_URL, "http://" . $runtimeId . ":3000/");
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . \strlen($body),
                'x-internal-challenge: ' . $secret,
                'host: null'
            ]);

            $executorResponse = \curl_exec($ch);

            $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $error = \curl_error($ch);

            $errNo = \curl_errno($ch);

            \curl_close($ch);

            switch (true) {
                /** No Error. */
                case $errNo === 0:
                    break;
                /** Runtime not ready for requests yet. 111 is the swoole error code for Connection Refused - see https://openswoole.com/docs/swoole-error-code */
                case $errNo === 111:
                    throw new Exception('An internal curl error has occurred within the executor! Error Msg: ' . $error, 406);
                /** Any other CURL error */
                default:
                    throw new Exception('An internal curl error has occurred within the executor! Error Msg: ' . $error, 500);
            }

            switch (true) {
                case $statusCode >= 500:
                    $stderr = $executorResponse ?? 'Internal Runtime error.';
                    break;
                case $statusCode >= 100:
                    $stdout = $executorResponse;
                    break;
                default:
                    $stderr = $executorResponse ?? 'Execution failed.';
                    break;
            }

            $executionEnd = \microtime(true);
            $executionTime = ($executionEnd - $executionStart);
            $functionStatus = ($statusCode >= 500) ? 'failed' : 'completed';

            Console::success('Function executed in ' . $executionTime . ' seconds, status: ' . $functionStatus);

            $execution = [
                'status' => $functionStatus,
                'statusCode' => $statusCode,
                'response' => \mb_strcut($stdout, 0, 1000000), // Limit to 1MB
                'stderr' => \mb_strcut($stderr, 0, 1000000), // Limit to 1MB
                'time' => $executionTime,
            ];

            /** Update swoole table */
            $runtime['updated'] = \time();
            $activeRuntimes->set($runtimeId, $runtime);

            $response
                ->setStatusCode(Response::STATUS_CODE_OK)
                ->json($execution);
        }
    );

App::setMode(App::MODE_TYPE_PRODUCTION); // Define Mode

$http = new Server("0.0.0.0", 80);

/** Set Resources */
App::setResource('orchestrationPool', fn() => $orchestrationPool);
App::setResource('activeRuntimes', fn() => $activeRuntimes);

/** Set callbacks */
App::error(function ($utopia, $error, $request, $response) {
    $route = $utopia->match($request);
    logError($error, "httpError", $route);

    switch ($error->getCode()) {
        case 400: // Error allowed publicly
        case 401: // Error allowed publicly
        case 402: // Error allowed publicly
        case 403: // Error allowed publicly
        case 404: // Error allowed publicly
        case 406: // Error allowed publicly
        case 409: // Error allowed publicly
        case 412: // Error allowed publicly
        case 425: // Error allowed publicly
        case 429: // Error allowed publicly
        case 501: // Error allowed publicly
        case 503: // Error allowed publicly
            $code = $error->getCode();
            break;
        default:
            $code = 500; // All other errors get the generic 500 server error status code
    }

    $output = [
        'message' => $error->getMessage(),
        'code' => $error->getCode(),
        'file' => $error->getFile(),
        'line' => $error->getLine(),
        'trace' => $error->getTrace(),
        'version' => App::getEnv('_APP_VERSION', 'UNKNOWN')
    ];

    $response
        ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->addHeader('Expires', '0')
        ->addHeader('Pragma', 'no-cache')
        ->setStatusCode($code);

    $response->json($output);
}, ['utopia', 'error', 'request', 'response']);

App::init(function ($request, $response) {
     $secretKey = $request->getHeader('x-appwrite-executor-key', '');
    if (empty($secretKey)) {
        throw new Exception('Missing executor key', 401);
    }

    if ($secretKey !== App::getEnv('_APP_EXECUTOR_SECRET', '')) {
        throw new Exception('Missing executor key', 401);
    }
}, ['request', 'response']);


$http->on('start', function ($http) {
    global $orchestrationPool;
    global $activeRuntimes;

    /**
     * Warmup: make sure images are ready to run fast 🚀
     */
    $runtimes = new Runtimes('v1');
    $allowList = empty(App::getEnv('_APP_FUNCTIONS_RUNTIMES')) ? [] : \explode(',', App::getEnv('_APP_FUNCTIONS_RUNTIMES'));
    $runtimes = $runtimes->getAll(true, $allowList);
    foreach ($runtimes as $runtime) {
        go(function () use ($runtime, $orchestrationPool) {
            try {
                $orchestration = $orchestrationPool->get();
                Console::info('Warming up ' . $runtime['name'] . ' ' . $runtime['version'] . ' environment...');
                $response = $orchestration->pull($runtime['image']);
                if ($response) {
                    Console::success("Successfully Warmed up {$runtime['name']} {$runtime['version']}!");
                } else {
                    Console::warning("Failed to Warmup {$runtime['name']} {$runtime['version']}!");
                }
            } catch (\Throwable $th) {
            } finally {
                $orchestrationPool->put($orchestration);
            }
        });
    }

    /**
     * Remove residual runtimes
     */
    Console::info('Removing orphan runtimes...');
    try {
        $orchestration = $orchestrationPool->get();
        $orphans = $orchestration->list(['label' => 'openruntimes-type=runtime']);
    } finally {
        $orchestrationPool->put($orchestration);
    }

    foreach ($orphans as $runtime) {
        go(function () use ($runtime, $orchestrationPool) {
            try {
                $orchestration = $orchestrationPool->get();
                $orchestration->remove($runtime->getName(), true);
                Console::success("Successfully removed {$runtime->getName()}");
            } catch (\Throwable $th) {
                Console::error('Orphan runtime deletion failed: ' . $th->getMessage());
            } finally {
                $orchestrationPool->put($orchestration);
            }
        });
    }

    /**
     * Register handlers for shutdown
     */
    @Process::signal(SIGINT, function () use ($http) {
        $http->shutdown();
    });

    @Process::signal(SIGQUIT, function () use ($http) {
        $http->shutdown();
    });

    @Process::signal(SIGKILL, function () use ($http) {
        $http->shutdown();
    });

    @Process::signal(SIGTERM, function () use ($http) {
        $http->shutdown();
    });

    /**
     * Run a maintenance worker every MAINTENANCE_INTERVAL seconds to remove inactive runtimes
     */
    Timer::tick(MAINTENANCE_INTERVAL * 1000, function () use ($orchestrationPool, $activeRuntimes) {
        Console::warning("Running maintenance task ...");
        foreach ($activeRuntimes as $runtime) {
            $inactiveThreshold = \time() - App::getEnv('_APP_FUNCTIONS_INACTIVE_THRESHOLD', 60);
            if ($runtime['updated'] < $inactiveThreshold) {
                go(function () use ($runtime, $orchestrationPool, $activeRuntimes) {
                    try {
                        $orchestration = $orchestrationPool->get();
                        $orchestration->remove($runtime['name'], true);
                        $activeRuntimes->del($runtime['name']);
                        Console::success("Successfully removed {$runtime['name']}");
                    } catch (\Throwable $th) {
                        Console::error('Inactive Runtime deletion failed: ' . $th->getMessage());
                    } finally {
                        $orchestrationPool->put($orchestration);
                    }
                });
            }
        }
    });
});


$http->on('beforeShutdown', function () {
    global $orchestrationPool;
    Console::info('Cleaning up containers before shutdown...');

    $orchestration = $orchestrationPool->get();
    $functionsToRemove = $orchestration->list(['label' => 'openruntimes-type=runtime']);
    $orchestrationPool->put($orchestration);

    foreach ($functionsToRemove as $container) {
        go(function () use ($orchestrationPool, $container) {
            try {
                $orchestration = $orchestrationPool->get();
                $orchestration->remove($container->getId(), true);
                Console::info('Removed container ' . $container->getName());
            } catch (\Throwable $th) {
                Console::error('Failed to remove container: ' . $container->getName());
            } finally {
                $orchestrationPool->put($orchestration);
            }
        });
    }
});


$http->on('request', function (SwooleRequest $swooleRequest, SwooleResponse $swooleResponse) {
    $request = new Request($swooleRequest);
    $response = new Response($swooleResponse);
    $app = new App('UTC');

    try {
        $app->run($request, $response);
    } catch (\Throwable $th) {
        logError($th, "serverError");
        $swooleResponse->setStatusCode(500);
        $output = [
            'message' => 'Error: ' . $th->getMessage(),
            'code' => 500,
            'file' => $th->getFile(),
            'line' => $th->getLine(),
            'trace' => $th->getTrace()
        ];
        $swooleResponse->end(\json_encode($output));
    }
});

$http->start();
