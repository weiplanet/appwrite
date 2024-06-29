<?php

namespace Executor;

use Appwrite\Extend\Exception as AppwriteException;
use Exception;
use Utopia\System\System;

class Executor
{
    public const METHOD_GET = 'GET';
    public const METHOD_POST = 'POST';
    public const METHOD_PUT = 'PUT';
    public const METHOD_PATCH = 'PATCH';
    public const METHOD_DELETE = 'DELETE';
    public const METHOD_HEAD = 'HEAD';
    public const METHOD_OPTIONS = 'OPTIONS';
    public const METHOD_CONNECT = 'CONNECT';
    public const METHOD_TRACE = 'TRACE';

    private bool $selfSigned = false;

    private string $endpoint;

    protected array $headers;

    protected int $cpus;

    protected int $memory;

    public function __construct(string $endpoint)
    {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new Exception('Unsupported endpoint');
        }

        $this->endpoint = $endpoint;
        $this->cpus = \intval(System::getEnv('_APP_FUNCTIONS_CPUS', '1'));
        $this->memory = \intval(System::getEnv('_APP_FUNCTIONS_MEMORY', '512'));
        $this->headers = [
            'content-type' => 'application/json',
            'authorization' => 'Bearer ' . System::getEnv('_APP_EXECUTOR_SECRET', ''),
            'x-opr-addressing-method' => 'anycast-efficient'
        ];
    }

    /**
     * Create runtime
     *
     * Launches a runtime container for a deployment ready for execution
     *
     * @param string $deploymentId
     * @param string $projectId
     * @param string $source
     * @param string $image
     * @param bool $remove
     * @param string $entrypoint
     * @param string $destination
     * @param array $variables
     * @param string $command
     */
    public function createRuntime(
        string $deploymentId,
        string $projectId,
        string $source,
        string $image,
        string $version,
        bool $remove = false,
        string $entrypoint = '',
        string $destination = '',
        array $variables = [],
        string $command = null,
    ) {
        $runtimeId = "$projectId-$deploymentId-build";
        $route = "/runtimes";
        $timeout = (int) System::getEnv('_APP_FUNCTIONS_BUILD_TIMEOUT', 900);
        $params = [
            'runtimeId' => $runtimeId,
            'source' => $source,
            'destination' => $destination,
            'image' => $image,
            'entrypoint' => $entrypoint,
            'variables' => $variables,
            'remove' => $remove,
            'command' => $command,
            'cpus' => $this->cpus,
            'memory' => $this->memory,
            'version' => $version,
            'timeout' => $timeout,
        ];

        $response = $this->call(self::METHOD_POST, $route, [ 'x-opr-runtime-id' => $runtimeId ], $params, true, $timeout);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            $message = \is_string($response['body']) ? $response['body'] : $response['body']['message'];
            throw new \Exception($message, $status);
        }

        return $response['body'];
    }

    /**
     * Listen to realtime logs stream of a runtime
     *
     * @param string $deploymentId
     * @param string $projectId
     * @param callable $callback
     */
    public function getLogs(
        string $deploymentId,
        string $projectId,
        callable $callback
    ) {
        $timeout = (int) System::getEnv('_APP_FUNCTIONS_BUILD_TIMEOUT', 900);

        $runtimeId = "$projectId-$deploymentId-build";
        $route = "/runtimes/{$runtimeId}/logs";
        $params = [
            'timeout' => $timeout
        ];

        $this->call(self::METHOD_GET, $route, [ 'x-opr-runtime-id' => $runtimeId ], $params, true, $timeout, $callback);
    }

    /**
     * Delete Runtime
     *
     * Deletes a runtime and cleans up any containers remaining.
     *
     * @param string $projectId
     * @param string $deploymentId
     */
    public function deleteRuntime(string $projectId, string $deploymentId)
    {
        $runtimeId = "$projectId-$deploymentId";
        $route = "/runtimes/$runtimeId";

        $response = $this->call(self::METHOD_DELETE, $route, [
            'x-opr-addressing-method' => 'broadcast'
        ], [], true, 30);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            $message = \is_string($response['body']) ? $response['body'] : $response['body']['message'];
            throw new \Exception($message, $status);
        }

        return $response['body'];
    }

    /**
     * Create an execution
     *
     * @param string $projectId
     * @param string $deploymentId
     * @param string $body
     * @param array $variables
     * @param int $timeout
     * @param string $image
     * @param string $source
     * @param string $entrypoint
     * @param string $runtimeEntrypoint
     *
     * @return array
     */
    public function createExecution(
        string $projectId,
        string $deploymentId,
        ?string $body,
        array $variables,
        int $timeout,
        string $image,
        string $source,
        string $entrypoint,
        string $version,
        string $path,
        string $method,
        array $headers,
        string $runtimeEntrypoint = null,
        int $requestTimeout = null
    ) {
        if (empty($headers['host'])) {
            $headers['host'] = System::getEnv('_APP_DOMAIN', '');
        }

        $runtimeId = "$projectId-$deploymentId";
        $route = '/runtimes/' . $runtimeId . '/execution';
        $params = [
            'runtimeId' => $runtimeId,
            'variables' => $variables,
            'body' => $body,
            'timeout' => $timeout,
            'path' => $path,
            'method' => $method,
            'headers' => $headers,
            'image' => $image,
            'source' => $source,
            'entrypoint' => $entrypoint,
            'cpus' => $this->cpus,
            'memory' => $this->memory,
            'version' => $version,
            'runtimeEntrypoint' => $runtimeEntrypoint,
        ];

        // Safety timeout. Executor has timeout, and open runtime has soft timeout.
        // This one shouldn't really happen, but prevents from unexpected networking behaviours.
        if ($requestTimeout == null) {
            $requestTimeout = $timeout + 15;
        }

        $response = $this->call(self::METHOD_POST, $route, [ 'x-opr-runtime-id' => $runtimeId ], $params, true, $requestTimeout);

        $status = $response['headers']['status-code'];
        if ($status >= 400) {
            $message = \is_string($response['body']) ? $response['body'] : $response['body']['message'];
            throw new \Exception($message, $status);
        }

        return $response['body'];
    }

    /**
     * Call
     *
     * Make an API call
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @param array $headers
     * @param bool $decode
     * @return array|string
     * @throws Exception
     */
    public function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, int $timeout = 15, callable $callback = null)
    {
        $headers            = array_merge($this->headers, $headers);
        $ch                 = curl_init($this->endpoint . $path . (($method == self::METHOD_GET && !empty($params)) ? '?' . http_build_query($params) : ''));
        $responseHeaders    = [];
        $responseStatus     = -1;
        $responseType       = '';
        $responseBody       = '';

        switch ($headers['content-type']) {
            case 'application/json':
                $query = json_encode($params);
                break;

            case 'multipart/form-data':
                $query = $this->flatten($params);
                break;

            default:
                $query = http_build_query($params);
                break;
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i . ':' . $header;
            unset($headers[$i]);
        }

        if (isset($callback)) {
            $headers[] = 'accept: text/event-stream';

            $handleEvent = function ($ch, $data) use ($callback) {
                $callback($data);
                return \strlen($data);
            };

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, $handleEvent);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        if ($method != self::METHOD_GET) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        // Allow self signed certificates
        if ($this->selfSigned) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $responseBody   = curl_exec($ch);

        if (isset($callback)) {
            curl_close($ch);
            return [];
        }

        $responseType   = $responseHeaders['content-type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        $curlErrorMessage = curl_error($ch);

        if ($decode) {
            switch (substr($responseType, 0, strpos($responseType, ';'))) {
                case 'application/json':
                    $json = json_decode($responseBody, true);

                    if ($json === null) {
                        throw new Exception('Failed to parse response: ' . $responseBody);
                    }

                    $responseBody = $json;
                    $json = null;
                    break;
            }
        }

        if ($curlError) {
            if ($curlError == CURLE_OPERATION_TIMEDOUT) {
                throw new AppwriteException(AppwriteException::FUNCTION_SYNCHRONOUS_TIMEOUT);
            }
            throw new Exception($curlErrorMessage . ' with status code ' . $responseStatus, $responseStatus);
        }

        curl_close($ch);

        $responseHeaders['status-code'] = $responseStatus;

        return [
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    /**
     * Parse Cookie String
     *
     * @param string $cookie
     * @return array
     */
    public function parseCookie(string $cookie): array
    {
        $cookies = [];

        parse_str(strtr($cookie, array('&' => '%26', '+' => '%2B', ';' => '&')), $cookies);

        return $cookies;
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * @param array $data
     * @param string $prefix
     * @return array
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                $output += $this->flatten($value, $finalKey); // @todo: handle name collision here if needed
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }
}
