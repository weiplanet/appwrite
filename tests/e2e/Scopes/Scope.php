<?php

namespace Tests\E2E\Scopes;

use Tests\E2E\Client;
use PHPUnit\Framework\TestCase;

abstract class Scope extends TestCase
{
    /**
     * @var Client
     */
    protected $client = null;

    /**
     * @var string
     */
    protected $endpoint = 'http://localhost/v1';

    protected function setUp(): void
    {
        $this->client = new Client();

        $this->client
            ->setEndpoint($this->endpoint)
        ;
    }

    protected function tearDown(): void
    {
        $this->client = null;
    }

    protected function getLastEmail():array
    {
        sleep(3);
        $emails = json_decode(file_get_contents('http://maildev/email'), true);

        if($emails && is_array($emails)) {
            return end($emails);
        }

        return [];
    }

    /**
     * @return array
     */
    abstract public function getHeaders():array;

    /**
     * @return array
     */
    abstract public function getProject():array;

    /**
     * @var array
     */
    protected static $root = [];

    /**
     * @return array
     */
    public function getRoot(): array
    {
        if((self::$root)) {
            return self::$root;
        }

        $email = uniqid().'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        $root = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $root['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $session = $this->client->parseCookie($session['headers']['set-cookie'])['a_session_console'];

        self::$root = [
            '$uid' => $root['body']['$uid'],
            'name' => $root['body']['name'],
            'email' => $root['body']['email'],
            'session' => $session,
        ];

        return self::$root;
    }

    /**
     * @var array
     */
    protected static $user = [];

    /**
     * @return array
     */
    public function getUser(): array
    {
        if(isset(self::$user[$this->getProject()['$uid']])) {
            return self::$user[$this->getProject()['$uid']];
        }

        $email = uniqid().'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        $user = $this->client->call(Client::METHOD_POST, '/account', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $this->assertEquals(201, $user['headers']['status-code']);

        $session = $this->client->call(Client::METHOD_POST, '/account/sessions', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], [
            'email' => $email,
            'password' => $password,
        ]);

        $session = $this->client->parseCookie($session['headers']['set-cookie'])['a_session_'.$this->getProject()['$uid']];

        self::$user[$this->getProject()['$uid']] = [
            '$uid' => $user['body']['$uid'],
            'name' => $user['body']['name'],
            'email' => $user['body']['email'],
            'session' => $session,
        ];

        return self::$user[$this->getProject()['$uid']];
    }
}