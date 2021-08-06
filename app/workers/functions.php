<?php

use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Adapter\MySQL as MySQLAdapter;
use Appwrite\Database\Adapter\Redis as RedisAdapter;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Event\Event;
use Appwrite\Resque\Worker;
use Appwrite\Utopia\Response\Model\Execution;
use Cron\CronExpression;
use Swoole\Runtime;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;

require_once __DIR__.'/../workers.php';

Runtime::enableCoroutine(0);

Console::title('Functions V1 Worker');
Console::success(APP_NAME.' functions worker v1 has started');

$runtimes = Config::getParam('runtimes');

/**
 * Warmup Docker Images
 */
$warmupStart = \microtime(true);

Co\run(function() use ($runtimes) {  // Warmup: make sure images are ready to run fast 🚀

    $dockerUser = App::getEnv('DOCKERHUB_PULL_USERNAME', null);
    $dockerPass = App::getEnv('DOCKERHUB_PULL_PASSWORD', null);

    if($dockerUser) {
        $stdout = '';
        $stderr = '';

        Console::execute('docker login --username '.$dockerUser.' --password-stdin', $dockerPass, $stdout, $stderr);
        Console::log('Docker Login'. $stdout.$stderr);
    }

    foreach($runtimes as $runtime) {
        go(function() use ($runtime) {
            $stdout = '';
            $stderr = '';
        
            Console::info('Warming up '.$runtime['name'].' '.$runtime['version'].' environment...');
        
            Console::execute('docker pull '.$runtime['image'], '', $stdout, $stderr);
        
            if(!empty($stdout)) {
                Console::log($stdout);
            }
        
            if(!empty($stderr)) {
                Console::error($stderr);
            }
        });
    }
});

$warmupEnd = \microtime(true);
$warmupTime = $warmupEnd - $warmupStart;

Console::success('Finished warmup in '.$warmupTime.' seconds');

/**
 * List function servers
 */
$stdout = '';
$stderr = '';

$executionStart = \microtime(true);

$exitCode = Console::execute('docker ps --all --format "name={{.Names}}&status={{.Status}}&labels={{.Labels}}" --filter label=appwrite-type=function'
    , '', $stdout, $stderr, 30);

$executionEnd = \microtime(true);

$list = [];
$stdout = \explode("\n", $stdout);

\array_map(function($value) use (&$list) {
    $container = [];

    \parse_str($value, $container);

    if(isset($container['name'])) {
        $container = [
            'name' => $container['name'],
            'online' => (\substr($container['status'], 0, 2) === 'Up'),
            'status' => $container['status'],
            'labels' => $container['labels'],
        ];

        \array_map(function($value) use (&$container) {
            $value = \explode('=', $value);
            
            if(isset($value[0]) && isset($value[1])) {
                $container[$value[0]] = $value[1];
            }
        }, \explode(',', $container['labels']));

        $list[$container['name']] = $container;
    }
}, $stdout);

Console::info(count($list)." functions listed in " . ($executionEnd - $executionStart) . " seconds with exit code {$exitCode}");

/**
 * 1. Get event args - DONE
 * 2. Unpackage code in the isolated container - DONE
 * 3. Execute in container with timeout
 *      + messure execution time - DONE
 *      + pass env vars - DONE
 *      + pass one-time api key
 * 4. Update execution status - DONE
 * 5. Update execution stdout & stderr - DONE
 * 6. Trigger audit log - DONE
 * 7. Trigger usage log - DONE
 */

//TODO aviod scheduled execution if delay is bigger than X offest

class FunctionsV1 extends Worker
{
    public $args = [];

    public $allowed = [];

    public function init(): void
    {
    }

    public function run(): void
    {
        global $register;

        $db = $register->get('db');
        $cache = $register->get('cache');

        $projectId = $this->args['projectId'] ?? '';
        $functionId = $this->args['functionId'] ?? '';
        $webhooks = $this->args['webhooks'] ?? [];
        $executionId = $this->args['executionId'] ?? '';
        $trigger = $this->args['trigger'] ?? '';
        $event = $this->args['event'] ?? '';
        $scheduleOriginal = $this->args['scheduleOriginal'] ?? '';
        $eventData = (!empty($this->args['eventData'])) ? json_encode($this->args['eventData']) : '';
        $data = $this->args['data'] ?? '';
        $userId = $this->args['userId'] ?? '';
        $jwt = $this->args['jwt'] ?? '';

        $database = new Database();
        $database->setAdapter(new RedisAdapter(new MySQLAdapter($db, $cache), $cache));
        $database->setNamespace('app_'.$projectId);
        $database->setMocks(Config::getParam('collections', []));

        switch ($trigger) {
            case 'event':
                $limit = 30;
                $sum = 30;
                $offset = 0;
                $functions = []; /** @var Document[] $functions */

                while ($sum >= $limit) {

                    Authorization::disable();

                    $functions = $database->getCollection([
                        'limit' => $limit,
                        'offset' => $offset,
                        'orderField' => 'name',
                        'orderType' => 'ASC',
                        'orderCast' => 'string',
                        'filters' => [
                            '$collection='.Database::SYSTEM_COLLECTION_FUNCTIONS,
                        ],
                    ]);

                    Authorization::reset();

                    $sum = \count($functions);
                    $offset = $offset + $limit;

                    Console::log('Fetched '.$sum.' functions...');

                    foreach($functions as $function) {
                        $events =  $function->getAttribute('events', []);
                        $tag =  $function->getAttribute('tag', []);

                        Console::success('Itterating function: '.$function->getAttribute('name'));

                        if(!\in_array($event, $events) || empty($tag)) {
                            continue;
                        }

                        Console::success('Triggered function: '.$event);

                        $this->execute('event', $projectId, '', $database, $function, $event, $eventData, $data, $webhooks, $userId, $jwt);
                    }
                }
                break;

            case 'schedule':
                /*
                 * 1. Get Original Task
                 * 2. Check for updates
                 *  If has updates skip task and don't reschedule
                 *  If status not equal to play skip task
                 * 3. Check next run date, update task and add new job at the given date
                 * 4. Execute task (set optional timeout)
                 * 5. Update task response to log
                 *      On success reset error count
                 *      On failure add error count
                 *      If error count bigger than allowed change status to pause
                 */

                // Reschedule
                Authorization::disable();
                $function = $database->getDocument($functionId);
                Authorization::reset();

                if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                    throw new Exception('Function not found ('.$functionId.')');
                }

                if($scheduleOriginal && $scheduleOriginal !== $function->getAttribute('schedule')) { // Schedule has changed from previous run, ignore this run.
                    return;
                }

                $cron = new CronExpression($function->getAttribute('schedule'));
                $next = (int) $cron->getNextRunDate()->format('U');

                $function
                    ->setAttribute('scheduleNext', $next)
                    ->setAttribute('schedulePrevious', \time())
                ;

                Authorization::disable();

                $function = $database->updateDocument(array_merge($function->getArrayCopy(), [
                    'scheduleNext' => $next,
                ]));

                Authorization::reset();

                ResqueScheduler::enqueueAt($next, 'v1-functions', 'FunctionsV1', [
                    'projectId' => $projectId,
                    'webhooks' => $webhooks,
                    'functionId' => $function->getId(),
                    'executionId' => null,
                    'trigger' => 'schedule',
                    'scheduleOriginal' => $function->getAttribute('schedule', ''),
                ]);  // Async task rescheduale

                $this->execute($trigger, $projectId, $executionId, $database, $function, /*$event*/'', /*$eventData*/'', $data, $webhooks, $userId, $jwt);
                break;

            case 'http':
                Authorization::disable();
                $function = $database->getDocument($functionId);
                Authorization::reset();

                if (empty($function->getId()) || Database::SYSTEM_COLLECTION_FUNCTIONS != $function->getCollection()) {
                    throw new Exception('Function not found ('.$functionId.')');
                }

                $this->execute($trigger, $projectId, $executionId, $database, $function, /*$event*/'', /*$eventData*/'', $data, $webhooks, $userId, $jwt);
                break;
            
            default:
                # code...
                break;
        }
    }

    /**
     * Execute function tag
     * 
     * @param string $trigger
     * @param string $projectId
     * @param string $executionId
     * @param Database $database
     * @param Database $function
     * @param string $event
     * @param string $eventData
     * @param string $data
     * @param array $webhooks
     * @param string $userId
     * @param string $jwt
     * 
     * @return void
     */
    public function execute(string $trigger, string $projectId, string $executionId, Database $database, Document $function, string $event = '', string $eventData = '', string $data = '', array $webhooks = [], string $userId = '', string $jwt = ''): void
    {
        global $list;

        $runtimes = Config::getParam('runtimes');

        Authorization::disable();
        $tag = $database->getDocument($function->getAttribute('tag', ''));
        Authorization::reset();

        if($tag->getAttribute('functionId') !== $function->getId()) {
            throw new Exception('Tag not found', 404);
        }

        Authorization::disable();

        $execution = (!empty($executionId)) ? $database->getDocument($executionId) : $database->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_EXECUTIONS,
            '$permissions' => [
                'read' => [],
                'write' => [],
            ],
            'dateCreated' => time(),
            'functionId' => $function->getId(),
            'trigger' => $trigger, // http / schedule / event
            'status' => 'processing', // waiting / processing / completed / failed
            'exitCode' => 0,
            'stdout' => '',
            'stderr' => '',
            'time' => 0,
        ]);

        if(false === $execution || ($execution instanceof Document && $execution->isEmpty())) {
            throw new Exception('Failed to create or read execution');
        }
        
        Authorization::reset();

        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')]))
            ? $runtimes[$function->getAttribute('runtime', '')]
            : null;

        if(\is_null($runtime)) {
            throw new Exception('Runtime "'.$function->getAttribute('runtime', '').'" is not supported');
        }

        $vars = \array_merge($function->getAttribute('vars', []), [
            'APPWRITE_FUNCTION_ID' => $function->getId(),
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name', ''),
            'APPWRITE_FUNCTION_TAG' => $tag->getId(),
            'APPWRITE_FUNCTION_TRIGGER' => $trigger,
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'],
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'],
            'APPWRITE_FUNCTION_EVENT' => $event,
            'APPWRITE_FUNCTION_EVENT_DATA' => $eventData,
            'APPWRITE_FUNCTION_DATA' => $data,
            'APPWRITE_FUNCTION_USER_ID' => $userId,
            'APPWRITE_FUNCTION_JWT' => $jwt,
            'APPWRITE_FUNCTION_PROJECT_ID' => $projectId,
        ]);

        \array_walk($vars, function (&$value, $key) {
            $key = $this->filterEnvKey($key);
            $value = \escapeshellarg((empty($value)) ? '' : $value);
            $value = "--env {$key}={$value}";
        });

        $tagPath = $tag->getAttribute('path', '');
        $tagPathTarget = '/tmp/project-'.$projectId.'/'.$tag->getId().'/code.tar.gz';
        $tagPathTargetDir = \pathinfo($tagPathTarget, PATHINFO_DIRNAME);
        $container = 'appwrite-function-'.$tag->getId();
        $command = \escapeshellcmd($tag->getAttribute('command', ''));

        if(!\is_readable($tagPath)) {
            throw new Exception('Code is not readable: '.$tag->getAttribute('path', ''));
        }

        if (!\file_exists($tagPathTargetDir)) {
            if (!\mkdir($tagPathTargetDir, 0755, true)) {
                throw new Exception('Can\'t create directory '.$tagPathTargetDir);
            }
        }
        
        if (!\file_exists($tagPathTarget)) {
            if(!\copy($tagPath, $tagPathTarget)) {
                throw new Exception('Can\'t create temporary code file '.$tagPathTarget);
            }
        }

        if(isset($list[$container]) && !$list[$container]['online']) { // Remove conatiner if not online
            $stdout = '';
            $stderr = '';
            
            if(Console::execute("docker rm {$container}", '', $stdout, $stderr, 30) !== 0) {
                throw new Exception('Failed to remove offline container: '.$stderr);
            }

            unset($list[$container]);
        }

        /**
         * Limit CPU Usage - DONE
         * Limit Memory Usage - DONE
         * Limit Network Usage
         * Limit Storage Usage (//--storage-opt size=120m \)
         * Make sure no access to redis, mariadb, influxdb or other system services
         * Make sure no access to NFS server / storage volumes
         * Access Appwrite REST from internal network for improved performance
         */
        if(!isset($list[$container])) { // Create contianer if not ready
            $stdout = '';
            $stderr = '';
    
            $executionStart = \microtime(true);
            $executionTime = \time();
            $cpus = App::getEnv('_APP_FUNCTIONS_CPUS', '');
            $memory = App::getEnv('_APP_FUNCTIONS_MEMORY', '');
            $swap = App::getEnv('_APP_FUNCTIONS_MEMORY_SWAP', '');
            $exitCode = Console::execute("docker run ".
                " -d".
                " --entrypoint=\"\"".
                (empty($cpus) ? "" : (" --cpus=".$cpus)).
                (empty($memory) ? "" : (" --memory=".$memory."m")).
                (empty($swap) ? "" : (" --memory-swap=".$swap."m")).
                " --name={$container}".
                " --label appwrite-type=function".
                " --label appwrite-created={$executionTime}".
                " --volume {$tagPathTargetDir}:/tmp:rw".
                " --workdir /usr/local/src".
                " ".\implode(" ", $vars).
                " {$runtime['image']}".
                " tail -f /dev/null"
            , '', $stdout, $stderr, 30);

            if($exitCode !== 0) {
                throw new Exception('Failed to create function environment: '.$stderr);
            }

            $exitCodeUntar = Console::execute("docker exec ".
                $container.
                " sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz'"
                , '', $stdout, $stderr, 60);

            if($exitCodeUntar !== 0) {
                throw new Exception('Failed to extract tar: '.$stderr);
            }

            $executionEnd = \microtime(true);

            $list[$container] = [
                'name' => $container,
                'online' => true,
                'status' => 'Up',
                'labels' => [
                    'appwrite-type' => 'function',
                    'appwrite-created' => $executionTime,
                ],
            ];

            Console::info("Function created in " . ($executionEnd - $executionStart) . " seconds with exit code {$exitCode}");
        }
        else {
            Console::info('Container is ready to run');
        }
        
        $stdout = '';
        $stderr = '';

        $executionStart = \microtime(true);
        
        $exitCode = Console::execute("docker exec ".\implode(" ", $vars)." {$container} {$command}"
            , '', $stdout, $stderr, $function->getAttribute('timeout', (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)));

        $executionEnd = \microtime(true);
        $executionTime = ($executionEnd - $executionStart);
        $functionStatus = ($exitCode === 0) ? 'completed' : 'failed';

        Console::info("Function executed in " . ($executionEnd - $executionStart) . " seconds with exit code {$exitCode}");

        Authorization::disable();
        
        $execution = $database->updateDocument(array_merge($execution->getArrayCopy(), [
            'tagId' => $tag->getId(),
            'status' => $functionStatus,
            'exitCode' => $exitCode,
            'stdout' => \mb_substr($stdout, -4000), // log last 4000 chars output
            'stderr' => \mb_substr($stderr, -4000), // log last 4000 chars output
            'time' => $executionTime,
        ]));
        
        Authorization::reset();

        if (false === $function) {
            throw new Exception('Failed saving execution to DB', 500);
        }

        $executionModel = new Execution();
        $executionUpdate = new Event('v1-webhooks', 'WebhooksV1');

        $executionUpdate
            ->setParam('projectId', $projectId)
            ->setParam('userId', $userId)
            ->setParam('webhooks', $webhooks)
            ->setParam('event', 'functions.executions.update')
            ->setParam('eventData', $execution->getArrayCopy(array_keys($executionModel->getRules())));

        $executionUpdate->trigger();

        $usage = new Event('v1-usage', 'UsageV1');

        $usage
            ->setParam('projectId', $projectId)
            ->setParam('functionId', $function->getId())
            ->setParam('functionExecution', 1)
            ->setParam('functionStatus', $functionStatus)
            ->setParam('functionExecutionTime', $executionTime * 1000) // ms
            ->setParam('networkRequestSize', 0)
            ->setParam('networkResponseSize', 0)
        ;
        
        if(App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $usage->trigger();
        }

        $this->cleanup();
    }

    /**
     * Cleanup any hanging containers above the allowed max containers.
     * 
     * @return void
     */
    public function cleanup(): void
    {
        global $list;

        Console::success(count($list).' running containers counted');

        $max = (int) App::getEnv('_APP_FUNCTIONS_CONTAINERS');

        if(\count($list) > $max) {
            Console::info('Starting containers cleanup');

            \uasort($list, function ($item1, $item2) {
                return (int)($item1['appwrite-created'] ?? 0) <=> (int)($item2['appwrite-created'] ?? 0);
            });

            while(\count($list) > $max) {
                $first = \array_shift($list);
                $stdout = '';
                $stderr = '';

                if(Console::execute("docker rm -f {$first['name']}", '', $stdout, $stderr, 30) !== 0) {
                    Console::error('Failed to remove container: '.$stderr);
                }
                else {
                    Console::info('Removed container: '.$first['name']);
                }
            }
        }
    }

    /**
     * Filter ENV vars
     * 
     * @param string $string
     * 
     * @return string
     */
    public function filterEnvKey(string $string): string
    {
        if(empty($this->allowed)) {
            $this->allowed = array_fill_keys(\str_split('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_'), true);
        }

        $string     = \str_split($string);
        $output     = '';

        foreach ($string as $char) {
            if(\array_key_exists($char, $this->allowed)) {
                $output .= $char;
            }
        }

        return $output;
    }

    public function shutdown(): void
    {
    }
}