<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Client;
use Utopia\Database\Database;

trait DatabaseBase
{
    public function testCreateCollection(): array
    {
        /**
         * Test for SUCCESS
         */
        $movies = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'Movies',
            'read' => [],
            'write' => [],
            'permission' => 'document',
        ]);

        $this->assertEquals($movies['headers']['status-code'], 201);
        $this->assertEquals($movies['body']['name'], 'Movies');

        return ['moviesId' => $movies['body']['$id']];
    }

    /**
     * @depends testCreateCollection
     */
    public function testDisableCollection(array $data): void
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/database/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Movies',
            'enabled' => false,
            'permission' => 'document',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertFalse($response['body']['enabled']);

        if ($this->getSide() === 'client') {
            $responseCreateDocument = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()), [
                'documentId' => 'unique()',
                'data' => [
                    'title' => 'Captain America',
                ],
                'read' => ['user:' . $this->getUser()['$id']],
                'write' => ['user:' . $this->getUser()['$id']],
            ]);

            $responseListDocument = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $responseGetDocument = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents/someID', array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals($responseCreateDocument['headers']['status-code'], 404);
            $this->assertEquals($responseListDocument['headers']['status-code'], 404);
            $this->assertEquals($responseGetDocument['headers']['status-code'], 404);
        }

        $response = $this->client->call(Client::METHOD_PUT, '/database/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'Movies',
            'enabled' => true,
            'permission' => 'document',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertTrue($response['body']['enabled']);
    }


    /**
     * @depends testCreateCollection
     */
    public function testCreateAttributes(array $data): array
    {
        $title = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'title',
            'size' => 256,
            'required' => true,
        ]);

        $releaseYear = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYear',
            'required' => true,
        ]);

        $duration = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'duration',
            'required' => false,
        ]);

        $actors = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'actors',
            'size' => 256,
            'required' => false,
            'array' => true,
        ]);

        $this->assertEquals($title['headers']['status-code'], 201);
        $this->assertEquals($title['body']['key'], 'title');
        $this->assertEquals($title['body']['type'], 'string');
        $this->assertEquals($title['body']['size'], 256);
        $this->assertEquals($title['body']['required'], true);

        $this->assertEquals($releaseYear['headers']['status-code'], 201);
        $this->assertEquals($releaseYear['body']['key'], 'releaseYear');
        $this->assertEquals($releaseYear['body']['type'], 'integer');
        $this->assertEquals($releaseYear['body']['required'], true);

        $this->assertEquals($duration['headers']['status-code'], 201);
        $this->assertEquals($duration['body']['key'], 'duration');
        $this->assertEquals($duration['body']['type'], 'integer');
        $this->assertEquals($duration['body']['required'], false);

        $this->assertEquals($actors['headers']['status-code'], 201);
        $this->assertEquals($actors['body']['key'], 'actors');
        $this->assertEquals($actors['body']['type'], 'string');
        $this->assertEquals($actors['body']['size'], 256);
        $this->assertEquals($actors['body']['required'], false);
        $this->assertEquals($actors['body']['array'], true);

        // wait for database worker to create attributes
        sleep(2);

        $movies = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertIsArray($movies['body']['attributes']);
        $this->assertCount(4, $movies['body']['attributes']);
        $this->assertEquals($movies['body']['attributes'][0]['key'], $title['body']['key']);
        $this->assertEquals($movies['body']['attributes'][1]['key'], $releaseYear['body']['key']);
        $this->assertEquals($movies['body']['attributes'][2]['key'], $duration['body']['key']);
        $this->assertEquals($movies['body']['attributes'][3]['key'], $actors['body']['key']);

        return $data;
    }

    /**
     * @depends testCreateAttributes
     */
    public function testAttributeResponseModels(array $data): array
    {
        $collection = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'Response Models',
            'read' => [],
            'write' => [],
            'permission' => 'document',
        ]);

        $this->assertEquals($collection['headers']['status-code'], 201);
        $this->assertEquals($collection['body']['name'], 'Response Models');

        $collectionId = $collection['body']['$id'];

        $string = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'string',
            'size' => 16,
            'required' => false,
            'default' => 'default',
        ]);

        $email = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'required' => false,
            'default' => 'default@example.com',
        ]);

        $enum = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'elements' => ['yes', 'no', 'maybe'],
            'required' => false,
            'default' => 'maybe',
        ]);

        $ip = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/ip', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'ip',
            'required' => false,
            'default' => '192.0.2.0',
        ]);

        $url = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/url', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'url',
            'required' => false,
            'default' => 'http://example.com',
        ]);

        $integer = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'integer',
            'required' => false,
            'min' => 1,
            'max' => 5,
            'default' => 3
        ]);

        $float = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'float',
            'required' => false,
            'min' => 1.5,
            'max' => 5.5,
            'default' => 3.5
        ]);

        $boolean = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/boolean', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'boolean',
            'required' => false,
            'default' => true,
        ]);

        $this->assertEquals(201, $string['headers']['status-code']);
        $this->assertEquals('string', $string['body']['key']);
        $this->assertEquals('string', $string['body']['type']);
        $this->assertEquals(false, $string['body']['required']);
        $this->assertEquals(false, $string['body']['array']);
        $this->assertEquals(16, $string['body']['size']);
        $this->assertEquals('default', $string['body']['default']);

        $this->assertEquals(201, $email['headers']['status-code']);
        $this->assertEquals('email', $email['body']['key']);
        $this->assertEquals('string', $email['body']['type']);
        $this->assertEquals(false, $email['body']['required']);
        $this->assertEquals(false, $email['body']['array']);
        $this->assertEquals('email', $email['body']['format']);
        $this->assertEquals('default@example.com', $email['body']['default']);

        $this->assertEquals(201, $enum['headers']['status-code']);
        $this->assertEquals('enum', $enum['body']['key']);
        $this->assertEquals('string', $enum['body']['type']);
        $this->assertEquals(false, $enum['body']['required']);
        $this->assertEquals(false, $enum['body']['array']);
        $this->assertEquals('enum', $enum['body']['format']);
        $this->assertEquals('maybe', $enum['body']['default']);
        $this->assertIsArray($enum['body']['elements']);
        $this->assertEquals(['yes', 'no', 'maybe'], $enum['body']['elements']);

        $this->assertEquals(201, $ip['headers']['status-code']);
        $this->assertEquals('ip', $ip['body']['key']);
        $this->assertEquals('string', $ip['body']['type']);
        $this->assertEquals(false, $ip['body']['required']);
        $this->assertEquals(false, $ip['body']['array']);
        $this->assertEquals('ip', $ip['body']['format']);
        $this->assertEquals('192.0.2.0', $ip['body']['default']);

        $this->assertEquals(201, $url['headers']['status-code']);
        $this->assertEquals('url', $url['body']['key']);
        $this->assertEquals('string', $url['body']['type']);
        $this->assertEquals(false, $url['body']['required']);
        $this->assertEquals(false, $url['body']['array']);
        $this->assertEquals('url', $url['body']['format']);
        $this->assertEquals('http://example.com', $url['body']['default']);

        $this->assertEquals(201, $integer['headers']['status-code']);
        $this->assertEquals('integer', $integer['body']['key']);
        $this->assertEquals('integer', $integer['body']['type']);
        $this->assertEquals(false, $integer['body']['required']);
        $this->assertEquals(false, $integer['body']['array']);
        $this->assertEquals(1, $integer['body']['min']);
        $this->assertEquals(5, $integer['body']['max']);
        $this->assertEquals(3, $integer['body']['default']);

        $this->assertEquals(201, $float['headers']['status-code']);
        $this->assertEquals('float', $float['body']['key']);
        $this->assertEquals('double', $float['body']['type']);
        $this->assertEquals(false, $float['body']['required']);
        $this->assertEquals(false, $float['body']['array']);
        $this->assertEquals(1.5, $float['body']['min']);
        $this->assertEquals(5.5, $float['body']['max']);
        $this->assertEquals(3.5, $float['body']['default']);

        $this->assertEquals(201, $boolean['headers']['status-code']);
        $this->assertEquals('boolean', $boolean['body']['key']);
        $this->assertEquals('boolean', $boolean['body']['type']);
        $this->assertEquals(false, $boolean['body']['required']);
        $this->assertEquals(false, $boolean['body']['array']);
        $this->assertEquals(true, $boolean['body']['default']);

        // wait for database worker to create attributes
        sleep(30);

        $stringResponse = $this->client->call(Client::METHOD_GET, "/database/collections/{$collectionId}/attributes/{$string['body']['key']}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $emailResponse = $this->client->call(Client::METHOD_GET, "/database/collections/{$collectionId}/attributes/{$email['body']['key']}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $enumResponse = $this->client->call(Client::METHOD_GET, "/database/collections/{$collectionId}/attributes/{$enum['body']['key']}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $ipResponse = $this->client->call(Client::METHOD_GET, "/database/collections/{$collectionId}/attributes/{$ip['body']['key']}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $urlResponse = $this->client->call(Client::METHOD_GET, "/database/collections/{$collectionId}/attributes/{$url['body']['key']}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $integerResponse = $this->client->call(Client::METHOD_GET, "/database/collections/{$collectionId}/attributes/{$integer['body']['key']}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $floatResponse = $this->client->call(Client::METHOD_GET, "/database/collections/{$collectionId}/attributes/{$float['body']['key']}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $booleanResponse = $this->client->call(Client::METHOD_GET, "/database/collections/{$collectionId}/attributes/{$boolean['body']['key']}", array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $stringResponse['headers']['status-code']);
        $this->assertEquals($string['body']['key'], $stringResponse['body']['key']);
        $this->assertEquals($string['body']['type'], $stringResponse['body']['type']);
        $this->assertEquals('available', $stringResponse['body']['status']);
        $this->assertEquals($string['body']['required'], $stringResponse['body']['required']);
        $this->assertEquals($string['body']['array'], $stringResponse['body']['array']);
        $this->assertEquals(16, $stringResponse['body']['size']);
        $this->assertEquals($string['body']['default'], $stringResponse['body']['default']);

        $this->assertEquals(200, $emailResponse['headers']['status-code']);
        $this->assertEquals($email['body']['key'], $emailResponse['body']['key']);
        $this->assertEquals($email['body']['type'], $emailResponse['body']['type']);
        $this->assertEquals('available', $emailResponse['body']['status']);
        $this->assertEquals($email['body']['required'], $emailResponse['body']['required']);
        $this->assertEquals($email['body']['array'], $emailResponse['body']['array']);
        $this->assertEquals($email['body']['format'], $emailResponse['body']['format']);
        $this->assertEquals($email['body']['default'], $emailResponse['body']['default']);

        $this->assertEquals(200, $enumResponse['headers']['status-code']);
        $this->assertEquals($enum['body']['key'], $enumResponse['body']['key']);
        $this->assertEquals($enum['body']['type'], $enumResponse['body']['type']);
        $this->assertEquals('available', $enumResponse['body']['status']);
        $this->assertEquals($enum['body']['required'], $enumResponse['body']['required']);
        $this->assertEquals($enum['body']['array'], $enumResponse['body']['array']);
        $this->assertEquals($enum['body']['format'], $enumResponse['body']['format']);
        $this->assertEquals($enum['body']['default'], $enumResponse['body']['default']);
        $this->assertEquals($enum['body']['elements'], $enumResponse['body']['elements']);

        $this->assertEquals(200, $ipResponse['headers']['status-code']);
        $this->assertEquals($ip['body']['key'], $ipResponse['body']['key']);
        $this->assertEquals($ip['body']['type'], $ipResponse['body']['type']);
        $this->assertEquals('available', $ipResponse['body']['status']);
        $this->assertEquals($ip['body']['required'], $ipResponse['body']['required']);
        $this->assertEquals($ip['body']['array'], $ipResponse['body']['array']);
        $this->assertEquals($ip['body']['format'], $ipResponse['body']['format']);
        $this->assertEquals($ip['body']['default'], $ipResponse['body']['default']);

        $this->assertEquals(200, $urlResponse['headers']['status-code']);
        $this->assertEquals($url['body']['key'], $urlResponse['body']['key']);
        $this->assertEquals($url['body']['type'], $urlResponse['body']['type']);
        $this->assertEquals('available', $urlResponse['body']['status']);
        $this->assertEquals($url['body']['required'], $urlResponse['body']['required']);
        $this->assertEquals($url['body']['array'], $urlResponse['body']['array']);
        $this->assertEquals($url['body']['format'], $urlResponse['body']['format']);
        $this->assertEquals($url['body']['default'], $urlResponse['body']['default']);

        $this->assertEquals(200, $integerResponse['headers']['status-code']);
        $this->assertEquals($integer['body']['key'], $integerResponse['body']['key']);
        $this->assertEquals($integer['body']['type'], $integerResponse['body']['type']);
        $this->assertEquals('available', $integerResponse['body']['status']);
        $this->assertEquals($integer['body']['required'], $integerResponse['body']['required']);
        $this->assertEquals($integer['body']['array'], $integerResponse['body']['array']);
        $this->assertEquals($integer['body']['min'], $integerResponse['body']['min']);
        $this->assertEquals($integer['body']['max'], $integerResponse['body']['max']);
        $this->assertEquals($integer['body']['default'], $integerResponse['body']['default']);

        $this->assertEquals(200, $floatResponse['headers']['status-code']);
        $this->assertEquals($float['body']['key'], $floatResponse['body']['key']);
        $this->assertEquals($float['body']['type'], $floatResponse['body']['type']);
        $this->assertEquals('available', $floatResponse['body']['status']);
        $this->assertEquals($float['body']['required'], $floatResponse['body']['required']);
        $this->assertEquals($float['body']['array'], $floatResponse['body']['array']);
        $this->assertEquals($float['body']['min'], $floatResponse['body']['min']);
        $this->assertEquals($float['body']['max'], $floatResponse['body']['max']);
        $this->assertEquals($float['body']['default'], $floatResponse['body']['default']);

        $this->assertEquals(200, $booleanResponse['headers']['status-code']);
        $this->assertEquals($boolean['body']['key'], $booleanResponse['body']['key']);
        $this->assertEquals($boolean['body']['type'], $booleanResponse['body']['type']);
        $this->assertEquals('available', $booleanResponse['body']['status']);
        $this->assertEquals($boolean['body']['required'], $booleanResponse['body']['required']);
        $this->assertEquals($boolean['body']['array'], $booleanResponse['body']['array']);
        $this->assertEquals($boolean['body']['default'], $booleanResponse['body']['default']);

        $attributes = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId . '/attributes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $attributes['headers']['status-code']);
        $this->assertEquals(8, $attributes['body']['total']);

        $attributes = $attributes['body']['attributes'];

        $this->assertIsArray($attributes);
        $this->assertCount(8, $attributes);

        $this->assertEquals($stringResponse['body']['key'], $attributes[0]['key']);
        $this->assertEquals($stringResponse['body']['type'], $attributes[0]['type']);
        $this->assertEquals($stringResponse['body']['status'], $attributes[0]['status']);
        $this->assertEquals($stringResponse['body']['required'], $attributes[0]['required']);
        $this->assertEquals($stringResponse['body']['array'], $attributes[0]['array']);
        $this->assertEquals($stringResponse['body']['size'], $attributes[0]['size']);
        $this->assertEquals($stringResponse['body']['default'], $attributes[0]['default']);

        $this->assertEquals($emailResponse['body']['key'], $attributes[1]['key']);
        $this->assertEquals($emailResponse['body']['type'], $attributes[1]['type']);
        $this->assertEquals($emailResponse['body']['status'], $attributes[1]['status']);
        $this->assertEquals($emailResponse['body']['required'], $attributes[1]['required']);
        $this->assertEquals($emailResponse['body']['array'], $attributes[1]['array']);
        $this->assertEquals($emailResponse['body']['default'], $attributes[1]['default']);
        $this->assertEquals($emailResponse['body']['format'], $attributes[1]['format']);

        $this->assertEquals($enumResponse['body']['key'], $attributes[2]['key']);
        $this->assertEquals($enumResponse['body']['type'], $attributes[2]['type']);
        $this->assertEquals($enumResponse['body']['status'], $attributes[2]['status']);
        $this->assertEquals($enumResponse['body']['required'], $attributes[2]['required']);
        $this->assertEquals($enumResponse['body']['array'], $attributes[2]['array']);
        $this->assertEquals($enumResponse['body']['default'], $attributes[2]['default']);
        $this->assertEquals($enumResponse['body']['format'], $attributes[2]['format']);
        $this->assertEquals($enumResponse['body']['elements'], $attributes[2]['elements']);

        $this->assertEquals($ipResponse['body']['key'], $attributes[3]['key']);
        $this->assertEquals($ipResponse['body']['type'], $attributes[3]['type']);
        $this->assertEquals($ipResponse['body']['status'], $attributes[3]['status']);
        $this->assertEquals($ipResponse['body']['required'], $attributes[3]['required']);
        $this->assertEquals($ipResponse['body']['array'], $attributes[3]['array']);
        $this->assertEquals($ipResponse['body']['default'], $attributes[3]['default']);
        $this->assertEquals($ipResponse['body']['format'], $attributes[3]['format']);

        $this->assertEquals($urlResponse['body']['key'], $attributes[4]['key']);
        $this->assertEquals($urlResponse['body']['type'], $attributes[4]['type']);
        $this->assertEquals($urlResponse['body']['status'], $attributes[4]['status']);
        $this->assertEquals($urlResponse['body']['required'], $attributes[4]['required']);
        $this->assertEquals($urlResponse['body']['array'], $attributes[4]['array']);
        $this->assertEquals($urlResponse['body']['default'], $attributes[4]['default']);
        $this->assertEquals($urlResponse['body']['format'], $attributes[4]['format']);

        $this->assertEquals($integerResponse['body']['key'], $attributes[5]['key']);
        $this->assertEquals($integerResponse['body']['type'], $attributes[5]['type']);
        $this->assertEquals($integerResponse['body']['status'], $attributes[5]['status']);
        $this->assertEquals($integerResponse['body']['required'], $attributes[5]['required']);
        $this->assertEquals($integerResponse['body']['array'], $attributes[5]['array']);
        $this->assertEquals($integerResponse['body']['default'], $attributes[5]['default']);
        $this->assertEquals($integerResponse['body']['min'], $attributes[5]['min']);
        $this->assertEquals($integerResponse['body']['max'], $attributes[5]['max']);

        $this->assertEquals($floatResponse['body']['key'], $attributes[6]['key']);
        $this->assertEquals($floatResponse['body']['type'], $attributes[6]['type']);
        $this->assertEquals($floatResponse['body']['status'], $attributes[6]['status']);
        $this->assertEquals($floatResponse['body']['required'], $attributes[6]['required']);
        $this->assertEquals($floatResponse['body']['array'], $attributes[6]['array']);
        $this->assertEquals($floatResponse['body']['default'], $attributes[6]['default']);
        $this->assertEquals($floatResponse['body']['min'], $attributes[6]['min']);
        $this->assertEquals($floatResponse['body']['max'], $attributes[6]['max']);

        $this->assertEquals($booleanResponse['body']['key'], $attributes[7]['key']);
        $this->assertEquals($booleanResponse['body']['type'], $attributes[7]['type']);
        $this->assertEquals($booleanResponse['body']['status'], $attributes[7]['status']);
        $this->assertEquals($booleanResponse['body']['required'], $attributes[7]['required']);
        $this->assertEquals($booleanResponse['body']['array'], $attributes[7]['array']);
        $this->assertEquals($booleanResponse['body']['default'], $attributes[7]['default']);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]));

        $this->assertEquals(200, $collection['headers']['status-code']);

        $attributes = $collection['body']['attributes'];

        $this->assertIsArray($attributes);
        $this->assertCount(8, $attributes);

        $this->assertEquals($stringResponse['body']['key'], $attributes[0]['key']);
        $this->assertEquals($stringResponse['body']['type'], $attributes[0]['type']);
        $this->assertEquals($stringResponse['body']['status'], $attributes[0]['status']);
        $this->assertEquals($stringResponse['body']['required'], $attributes[0]['required']);
        $this->assertEquals($stringResponse['body']['array'], $attributes[0]['array']);
        $this->assertEquals($stringResponse['body']['size'], $attributes[0]['size']);
        $this->assertEquals($stringResponse['body']['default'], $attributes[0]['default']);

        $this->assertEquals($emailResponse['body']['key'], $attributes[1]['key']);
        $this->assertEquals($emailResponse['body']['type'], $attributes[1]['type']);
        $this->assertEquals($emailResponse['body']['status'], $attributes[1]['status']);
        $this->assertEquals($emailResponse['body']['required'], $attributes[1]['required']);
        $this->assertEquals($emailResponse['body']['array'], $attributes[1]['array']);
        $this->assertEquals($emailResponse['body']['default'], $attributes[1]['default']);
        $this->assertEquals($emailResponse['body']['format'], $attributes[1]['format']);

        $this->assertEquals($enumResponse['body']['key'], $attributes[2]['key']);
        $this->assertEquals($enumResponse['body']['type'], $attributes[2]['type']);
        $this->assertEquals($enumResponse['body']['status'], $attributes[2]['status']);
        $this->assertEquals($enumResponse['body']['required'], $attributes[2]['required']);
        $this->assertEquals($enumResponse['body']['array'], $attributes[2]['array']);
        $this->assertEquals($enumResponse['body']['default'], $attributes[2]['default']);
        $this->assertEquals($enumResponse['body']['format'], $attributes[2]['format']);
        $this->assertEquals($enumResponse['body']['elements'], $attributes[2]['elements']);

        $this->assertEquals($ipResponse['body']['key'], $attributes[3]['key']);
        $this->assertEquals($ipResponse['body']['type'], $attributes[3]['type']);
        $this->assertEquals($ipResponse['body']['status'], $attributes[3]['status']);
        $this->assertEquals($ipResponse['body']['required'], $attributes[3]['required']);
        $this->assertEquals($ipResponse['body']['array'], $attributes[3]['array']);
        $this->assertEquals($ipResponse['body']['default'], $attributes[3]['default']);
        $this->assertEquals($ipResponse['body']['format'], $attributes[3]['format']);

        $this->assertEquals($urlResponse['body']['key'], $attributes[4]['key']);
        $this->assertEquals($urlResponse['body']['type'], $attributes[4]['type']);
        $this->assertEquals($urlResponse['body']['status'], $attributes[4]['status']);
        $this->assertEquals($urlResponse['body']['required'], $attributes[4]['required']);
        $this->assertEquals($urlResponse['body']['array'], $attributes[4]['array']);
        $this->assertEquals($urlResponse['body']['default'], $attributes[4]['default']);
        $this->assertEquals($urlResponse['body']['format'], $attributes[4]['format']);

        $this->assertEquals($integerResponse['body']['key'], $attributes[5]['key']);
        $this->assertEquals($integerResponse['body']['type'], $attributes[5]['type']);
        $this->assertEquals($integerResponse['body']['status'], $attributes[5]['status']);
        $this->assertEquals($integerResponse['body']['required'], $attributes[5]['required']);
        $this->assertEquals($integerResponse['body']['array'], $attributes[5]['array']);
        $this->assertEquals($integerResponse['body']['default'], $attributes[5]['default']);
        $this->assertEquals($integerResponse['body']['min'], $attributes[5]['min']);
        $this->assertEquals($integerResponse['body']['max'], $attributes[5]['max']);

        $this->assertEquals($floatResponse['body']['key'], $attributes[6]['key']);
        $this->assertEquals($floatResponse['body']['type'], $attributes[6]['type']);
        $this->assertEquals($floatResponse['body']['status'], $attributes[6]['status']);
        $this->assertEquals($floatResponse['body']['required'], $attributes[6]['required']);
        $this->assertEquals($floatResponse['body']['array'], $attributes[6]['array']);
        $this->assertEquals($floatResponse['body']['default'], $attributes[6]['default']);
        $this->assertEquals($floatResponse['body']['min'], $attributes[6]['min']);
        $this->assertEquals($floatResponse['body']['max'], $attributes[6]['max']);

        $this->assertEquals($booleanResponse['body']['key'], $attributes[7]['key']);
        $this->assertEquals($booleanResponse['body']['type'], $attributes[7]['type']);
        $this->assertEquals($booleanResponse['body']['status'], $attributes[7]['status']);
        $this->assertEquals($booleanResponse['body']['required'], $attributes[7]['required']);
        $this->assertEquals($booleanResponse['body']['array'], $attributes[7]['array']);
        $this->assertEquals($booleanResponse['body']['default'], $attributes[7]['default']);

        /**
         * Test for FAILURE
         */
        $badEnum = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'elements' => ['yes', 'no', ''],
            'required' => false,
            'default' => 'maybe',
        ]);

        $this->assertEquals(400, $badEnum['headers']['status-code']);
        $this->assertEquals('Each enum element must not be empty', $badEnum['body']['message']);

        return $data;
    }

    /**
     * @depends testCreateAttributes
     */
    public function testCreateIndexes(array $data): array
    {
        $titleIndex = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'titleIndex',
            'type' => 'fulltext',
            'attributes' => ['title'],
        ]);

        $this->assertEquals(201, $titleIndex['headers']['status-code']);
        $this->assertEquals('titleIndex', $titleIndex['body']['key']);
        $this->assertEquals('fulltext', $titleIndex['body']['type']);
        $this->assertCount(1, $titleIndex['body']['attributes']);
        $this->assertEquals('title', $titleIndex['body']['attributes'][0]);

        $releaseYearIndex = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'releaseYear',
            'type' => 'key',
            'attributes' => ['releaseYear'],
        ]);

        $this->assertEquals(201, $releaseYearIndex['headers']['status-code']);
        $this->assertEquals('releaseYear', $releaseYearIndex['body']['key']);
        $this->assertEquals('key', $releaseYearIndex['body']['type']);
        $this->assertCount(1, $releaseYearIndex['body']['attributes']);
        $this->assertEquals('releaseYear', $releaseYearIndex['body']['attributes'][0]);

        // wait for database worker to create index
        sleep(2);

        $movies = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), []);

        $this->assertIsArray($movies['body']['indexes']);
        $this->assertCount(2, $movies['body']['indexes']);
        $this->assertEquals($titleIndex['body']['key'], $movies['body']['indexes'][0]['key']);
        $this->assertEquals($releaseYearIndex['body']['key'], $movies['body']['indexes'][1]['key']);
        $this->assertEquals('available', $movies['body']['indexes'][0]['status']);
        $this->assertEquals('available', $movies['body']['indexes'][1]['status']);

        return $data;
    }

    /**
     * @depends testCreateIndexes
     */
    public function testCreateDocument(array $data): array
    {
        $document1 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [
                    'Chris Evans',
                    'Samuel Jackson',
                ]
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $document2 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Spider-Man: Far From Home',
                'releaseYear' => 2019,
                'actors' => [
                    'Tom Holland',
                    'Zendaya Maree Stoermer',
                    'Samuel Jackson',
                ]
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $document3 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Spider-Man: Homecoming',
                'releaseYear' => 2017,
                'duration' => 0,
                'actors' => [
                    'Tom Holland',
                    'Zendaya Maree Stoermer',
                ],
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);


        $document4 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'releaseYear' => 2020, // Missing title, expect an 400 error
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $this->assertEquals($document1['headers']['status-code'], 201);
        $this->assertEquals($document1['body']['title'], 'Captain America');
        $this->assertEquals($document1['body']['releaseYear'], 1944);
        $this->assertIsArray($document1['body']['$read']);
        $this->assertIsArray($document1['body']['$write']);
        $this->assertCount(1, $document1['body']['$read']);
        $this->assertCount(1, $document1['body']['$write']);
        $this->assertCount(2, $document1['body']['actors']);
        $this->assertEquals($document1['body']['actors'][0], 'Chris Evans');
        $this->assertEquals($document1['body']['actors'][1], 'Samuel Jackson');

        $this->assertEquals($document2['headers']['status-code'], 201);
        $this->assertEquals($document2['body']['title'], 'Spider-Man: Far From Home');
        $this->assertEquals($document2['body']['releaseYear'], 2019);
        $this->assertEquals($document2['body']['duration'], null);
        $this->assertIsArray($document2['body']['$read']);
        $this->assertIsArray($document2['body']['$write']);
        $this->assertCount(1, $document2['body']['$read']);
        $this->assertCount(1, $document2['body']['$write']);
        $this->assertCount(3, $document2['body']['actors']);
        $this->assertEquals($document2['body']['actors'][0], 'Tom Holland');
        $this->assertEquals($document2['body']['actors'][1], 'Zendaya Maree Stoermer');
        $this->assertEquals($document2['body']['actors'][2], 'Samuel Jackson');

        $this->assertEquals($document3['headers']['status-code'], 201);
        $this->assertEquals($document3['body']['title'], 'Spider-Man: Homecoming');
        $this->assertEquals($document3['body']['releaseYear'], 2017);
        $this->assertEquals($document3['body']['duration'], 0);
        $this->assertIsArray($document3['body']['$read']);
        $this->assertIsArray($document3['body']['$write']);
        $this->assertCount(1, $document3['body']['$read']);
        $this->assertCount(1, $document3['body']['$write']);
        $this->assertCount(2, $document3['body']['actors']);
        $this->assertEquals($document3['body']['actors'][0], 'Tom Holland');
        $this->assertEquals($document3['body']['actors'][1], 'Zendaya Maree Stoermer');

        $this->assertEquals($document4['headers']['status-code'], 400);

        return $data;
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocuments(array $data): array
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][2]['releaseYear']);
        $this->assertFalse(array_key_exists('$internalId', $documents['body']['documents'][0]));
        $this->assertFalse(array_key_exists('$internalId', $documents['body']['documents'][1]));
        $this->assertFalse(array_key_exists('$internalId', $documents['body']['documents'][2]));
        $this->assertCount(3, $documents['body']['documents']);

        foreach ($documents['body']['documents'] as $document) {
            $this->assertEquals($data['moviesId'], $document['$collection']);
        }

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['DESC'],
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals(1944, $documents['body']['documents'][2]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(3, $documents['body']['documents']);

        return $documents['body']['documents'];
    }

    /**
     * @depends testListDocuments
     */
    public function testGetDocument(array $documents): void
    {
        foreach ($documents as $document) {
            $response = $this->client->call(Client::METHOD_GET, '/database/collections/' . $document['$collection'] . '/documents/' . $document['$id'], array_merge([
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals($response['headers']['status-code'], 200);
            $this->assertEquals($response['body']['$id'], $document['$id']);
            $this->assertEquals($response['body']['$collection'], $document['$collection']);
            $this->assertEquals($response['body']['title'], $document['title']);
            $this->assertEquals($response['body']['releaseYear'], $document['releaseYear']);
            $this->assertEquals($response['body']['$read'], $document['$read']);
            $this->assertEquals($response['body']['$write'], $document['$write']);
            $this->assertFalse(array_key_exists('$internalId', $response['body']));
        }
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocumentsAfterPagination(array $data): array
    {
        /**
         * Test after without order.
         */
        $base = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($base['headers']['status-code'], 200);
        $this->assertEquals('Captain America', $base['body']['documents'][0]['title']);
        $this->assertEquals('Spider-Man: Far From Home', $base['body']['documents'][1]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $base['body']['documents'][2]['title']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'cursor' => $base['body']['documents'][0]['$id']
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals($base['body']['documents'][1]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertEquals($base['body']['documents'][2]['$id'], $documents['body']['documents'][1]['$id']);
        $this->assertCount(2, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'cursor' => $base['body']['documents'][2]['$id']
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEmpty($documents['body']['documents']);

        /**
         * Test with ASC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
        ]);

        $this->assertEquals($base['headers']['status-code'], 200);
        $this->assertEquals(1944, $base['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $base['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['documents'][2]['releaseYear']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
            'cursor' => $base['body']['documents'][1]['$id']
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals($base['body']['documents'][2]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertCount(1, $documents['body']['documents']);

        /**
         * Test with DESC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['DESC'],
        ]);

        $this->assertEquals($base['headers']['status-code'], 200);
        $this->assertEquals(1944, $base['body']['documents'][2]['releaseYear']);
        $this->assertEquals(2017, $base['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['documents'][0]['releaseYear']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['DESC'],
            'cursor' => $base['body']['documents'][1]['$id']
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals($base['body']['documents'][2]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertCount(1, $documents['body']['documents']);

        /**
         * Test after with unknown document.
         */
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'cursor' => 'unknown'
        ]);

        $this->assertEquals($documents['headers']['status-code'], 400);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocumentsBeforePagination(array $data): array
    {
        /**
         * Test before without order.
         */
        $base = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($base['headers']['status-code'], 200);
        $this->assertEquals('Captain America', $base['body']['documents'][0]['title']);
        $this->assertEquals('Spider-Man: Far From Home', $base['body']['documents'][1]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $base['body']['documents'][2]['title']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'cursor' => $base['body']['documents'][2]['$id'],
            'cursorDirection' => Database::CURSOR_BEFORE
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals($base['body']['documents'][0]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertEquals($base['body']['documents'][1]['$id'], $documents['body']['documents'][1]['$id']);
        $this->assertCount(2, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'cursor' => $base['body']['documents'][0]['$id'],
            'cursorDirection' => Database::CURSOR_BEFORE
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEmpty($documents['body']['documents']);

        /**
         * Test with ASC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
        ]);

        $this->assertEquals($base['headers']['status-code'], 200);
        $this->assertEquals(1944, $base['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $base['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['documents'][2]['releaseYear']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
            'cursor' => $base['body']['documents'][1]['$id'],
            'cursorDirection' => Database::CURSOR_BEFORE
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals($base['body']['documents'][0]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertCount(1, $documents['body']['documents']);

        /**
         * Test with DESC order and after.
         */
        $base = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['DESC'],
        ]);

        $this->assertEquals($base['headers']['status-code'], 200);
        $this->assertEquals(1944, $base['body']['documents'][2]['releaseYear']);
        $this->assertEquals(2017, $base['body']['documents'][1]['releaseYear']);
        $this->assertEquals(2019, $base['body']['documents'][0]['releaseYear']);
        $this->assertCount(3, $base['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['DESC'],
            'cursor' => $base['body']['documents'][1]['$id'],
            'cursorDirection' => Database::CURSOR_BEFORE
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals($base['body']['documents'][0]['$id'], $documents['body']['documents'][0]['$id']);
        $this->assertCount(1, $documents['body']['documents']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testListDocumentsLimitAndOffset(array $data): array
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 1,
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'limit' => 2,
            'offset' => 1,
            'orderAttributes' => ['releaseYear'],
            'orderTypes' => ['ASC'],
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals(2017, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2019, $documents['body']['documents'][1]['releaseYear']);
        $this->assertCount(2, $documents['body']['documents']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testDocumentsListQueries(array $data): array
    {
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['title.search("Captain America")'],
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['$id.equal("' . $documents['body']['documents'][0]['$id'] . '")'],
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals(1944, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['title.search("Homecoming")'],
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals(2017, $documents['body']['documents'][0]['releaseYear']);
        $this->assertCount(1, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['title.search("spider")'],
        ]);

        $this->assertEquals($documents['headers']['status-code'], 200);
        $this->assertEquals(2019, $documents['body']['documents'][0]['releaseYear']);
        $this->assertEquals(2017, $documents['body']['documents'][1]['releaseYear']);
        $this->assertCount(2, $documents['body']['documents']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['releaseYear.equal(1944)'],
        ]);

        $this->assertCount(1, $documents['body']['documents']);
        $this->assertEquals('Captain America', $documents['body']['documents'][0]['title']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['releaseYear.notEqual(1944)'],
        ]);

        $this->assertCount(2, $documents['body']['documents']);
        $this->assertEquals('Spider-Man: Far From Home', $documents['body']['documents'][0]['title']);
        $this->assertEquals('Spider-Man: Homecoming', $documents['body']['documents'][1]['title']);

        /**
         * Test for Failure
         */
        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['actors.equal("Tom Holland")'],
        ]);
        $this->assertEquals(400, $documents['headers']['status-code']);
        $this->assertEquals('Index not found: actors', $documents['body']['message']);

        $conditions = [];

        for ($i = 0; $i < 101; $i++) {
            $conditions[] = $i;
        }

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'queries' => ['releaseYear.equal(' . implode(',', $conditions) . ')'],
        ]);

        $this->assertEquals(400, $documents['headers']['status-code']);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testUpdateDocument(array $data): array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Thor: Ragnaroc',
                'releaseYear' => 2017,
                'actors' => [],
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $id = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertEquals($document['body']['title'], 'Thor: Ragnaroc');
        $this->assertEquals($document['body']['releaseYear'], 2017);
        $this->assertEquals('user:' . $this->getUser()['$id'], $document['body']['$read'][0]);
        $this->assertEquals('user:' . $this->getUser()['$id'], $document['body']['$write'][0]);

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Thor: Ragnarok',
            ],
            'read' => ['role:member'],
            'write' => ['role:member'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['$id'], $id);
        $this->assertEquals($document['body']['$collection'], $data['moviesId']);
        $this->assertEquals($document['body']['title'], 'Thor: Ragnarok');
        $this->assertEquals($document['body']['releaseYear'], 2017);
        $this->assertEquals('role:member', $document['body']['$read'][0]);
        $this->assertEquals('role:member', $document['body']['$write'][0]);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $id = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['title'], 'Thor: Ragnarok');
        $this->assertEquals($document['body']['releaseYear'], 2017);

        return [];
    }

    /**
     * @depends testCreateDocument
     */
    public function testDeleteDocument(array $data): array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Thor: Ragnarok',
                'releaseYear' => 2017,
                'actors' => [],
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $id = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 201);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 200);

        $document = $this->client->call(Client::METHOD_DELETE, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 204);

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 404);

        return $data;
    }

    public function testInvalidDocumentStructure()
    {
        $collection = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'invalidDocumentStructure',
            'read' => [],
            'write' => [],
            'permission' => 'document',
        ]);

        $this->assertEquals(201, $collection['headers']['status-code']);
        $this->assertEquals('invalidDocumentStructure', $collection['body']['name']);

        $collectionId = $collection['body']['$id'];

        $email = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/email', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'email',
            'required' => false,
        ]);

        $enum = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'enum',
            'elements' => ['yes', 'no', 'maybe'],
            'required' => false,
        ]);

        $ip = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/ip', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'ip',
            'required' => false,
        ]);

        $url = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/url', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'url',
            'size' => 256,
            'required' => false,
        ]);

        $range = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'range',
            'required' => false,
            'min' => 1,
            'max' => 10,
        ]);

        // TODO@kodumbeats min and max are rounded in error message
        $floatRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'floatRange',
            'required' => false,
            'min' => 1.1,
            'max' => 1.4,
        ]);

        $probability = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/float', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'probability',
            'required' => false,
            'default' => 0,
            'min' => 0,
            'max' => 1,
        ]);

        $upperBound = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'upperBound',
            'required' => false,
            'max' => 10,
        ]);

        $lowerBound = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'lowerBound',
            'required' => false,
            'min' => 5,
        ]);

        /**
         * Test for failure
         */

        $invalidRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json', 'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'invalidRange',
            'required' => false,
            'min' => 4,
            'max' => 3,
        ]);

        $defaultArray = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json', 'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'defaultArray',
            'required' => false,
            'default' => 42,
            'array' => true,
        ]);

        $defaultRequired = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/integer', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'defaultRequired',
            'required' => true,
            'default' => 12
        ]);

        $enumDefault = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'enumDefault',
            'elements' => ['north', 'west'],
            'default' => 'south'
        ]);

        $enumDefaultStrict = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/enum', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'attributeId' => 'enumDefault',
            'elements' => ['north', 'west'],
            'default' => 'NORTH'
        ]);

        $this->assertEquals(201, $email['headers']['status-code']);
        $this->assertEquals(201, $ip['headers']['status-code']);
        $this->assertEquals(201, $url['headers']['status-code']);
        $this->assertEquals(201, $range['headers']['status-code']);
        $this->assertEquals(201, $floatRange['headers']['status-code']);
        $this->assertEquals(201, $probability['headers']['status-code']);
        $this->assertEquals(201, $upperBound['headers']['status-code']);
        $this->assertEquals(201, $lowerBound['headers']['status-code']);
        $this->assertEquals(201, $enum['headers']['status-code']);
        $this->assertEquals(400, $invalidRange['headers']['status-code']);
        $this->assertEquals(400, $defaultArray['headers']['status-code']);
        $this->assertEquals(400, $defaultRequired['headers']['status-code']);
        $this->assertEquals(400, $enumDefault['headers']['status-code']);
        $this->assertEquals(400, $enumDefaultStrict['headers']['status-code']);
        $this->assertEquals('Minimum value must be lesser than maximum value', $invalidRange['body']['message']);
        $this->assertEquals('Cannot set default value for array attributes', $defaultArray['body']['message']);

        // wait for worker to add attributes
        sleep(3);

        $collection = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ]), []);

        $this->assertCount(9, $collection['body']['attributes']);

        /**
         * Test for successful validation
         */

        $goodEmail = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'email' => 'user@example.com',
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $goodEnum = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'enum' => 'yes',
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $goodIp = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'ip' => '1.1.1.1',
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $goodUrl = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'url' => 'http://www.example.com',
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $goodRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'range' => 3,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $goodFloatRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'floatRange' => 1.4,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $goodProbability = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'probability' => 0.99999,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $notTooHigh = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'upperBound' => 8,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $notTooLow = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'lowerBound' => 8,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $this->assertEquals(201, $goodEmail['headers']['status-code']);
        $this->assertEquals(201, $goodEnum['headers']['status-code']);
        $this->assertEquals(201, $goodIp['headers']['status-code']);
        $this->assertEquals(201, $goodUrl['headers']['status-code']);
        $this->assertEquals(201, $goodRange['headers']['status-code']);
        $this->assertEquals(201, $goodFloatRange['headers']['status-code']);
        $this->assertEquals(201, $goodProbability['headers']['status-code']);
        $this->assertEquals(201, $notTooHigh['headers']['status-code']);
        $this->assertEquals(201, $notTooLow['headers']['status-code']);

        /*
         * Test that custom validators reject documents
         */

        $badEmail = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'email' => 'user@@example.com',
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $badEnum = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'enum' => 'badEnum',
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $badIp = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'ip' => '1.1.1.1.1',
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $badUrl = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'url' => 'example...com',
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $badRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'range' => 11,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $badFloatRange = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'floatRange' => 2.5,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $badProbability = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'probability' => 1.1,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $tooHigh = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'upperBound' => 11,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $tooLow = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'lowerBound' => 3,
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $this->assertEquals(400, $badEmail['headers']['status-code']);
        $this->assertEquals(400, $badEnum['headers']['status-code']);
        $this->assertEquals(400, $badIp['headers']['status-code']);
        $this->assertEquals(400, $badUrl['headers']['status-code']);
        $this->assertEquals(400, $badRange['headers']['status-code']);
        $this->assertEquals(400, $badFloatRange['headers']['status-code']);
        $this->assertEquals(400, $badProbability['headers']['status-code']);
        $this->assertEquals(400, $tooHigh['headers']['status-code']);
        $this->assertEquals(400, $tooLow['headers']['status-code']);
        $this->assertEquals('Invalid document structure: Attribute "email" has invalid format. Value must be a valid email address', $badEmail['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "enum" has invalid format. Value must be one of (yes, no, maybe)', $badEnum['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "ip" has invalid format. Value must be a valid IP address', $badIp['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "url" has invalid format. Value must be a valid URL', $badUrl['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "range" has invalid format. Value must be a valid range between 1 and 10', $badRange['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "floatRange" has invalid format. Value must be a valid range between 1 and 1', $badFloatRange['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "probability" has invalid format. Value must be a valid range between 0 and 1', $badProbability['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "upperBound" has invalid format. Value must be a valid range between -9,223,372,036,854,775,808 and 10', $tooHigh['body']['message']);
        $this->assertEquals('Invalid document structure: Attribute "lowerBound" has invalid format. Value must be a valid range between 5 and 9,223,372,036,854,775,808', $tooLow['body']['message']);
    }

    /**
     * @depends testDeleteDocument
     */
    public function testDefaultPermissions(array $data): array
    {
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [],
            ],
        ]);

        $id = $document['body']['$id'];

        $this->assertEquals($document['headers']['status-code'], 201);
        $this->assertEquals($document['body']['title'], 'Captain America');
        $this->assertEquals($document['body']['releaseYear'], 1944);
        $this->assertIsArray($document['body']['$read']);
        $this->assertIsArray($document['body']['$write']);

        if ($this->getSide() == 'client') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(1, $document['body']['$write']);
            $this->assertEquals(['user:' . $this->getUser()['$id']], $document['body']['$read']);
            $this->assertEquals(['user:' . $this->getUser()['$id']], $document['body']['$write']);
        }

        if ($this->getSide() == 'server') {
            $this->assertCount(0, $document['body']['$read']);
            $this->assertCount(0, $document['body']['$write']);
            $this->assertEquals([], $document['body']['$read']);
            $this->assertEquals([], $document['body']['$write']);
        }

        // Updated and Inherit Permissions

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Captain America 2',
                'releaseYear' => 1945,
                'actors' => [],
            ],
            'read' => ['role:all'],
        ]);

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['title'], 'Captain America 2');
        $this->assertEquals($document['body']['releaseYear'], 1945);

        if ($this->getSide() == 'client') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(1, $document['body']['$write']);
            $this->assertEquals(['role:all'], $document['body']['$read']);
            $this->assertEquals(['user:' . $this->getUser()['$id']], $document['body']['$write']);
        }

        if ($this->getSide() == 'server') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(0, $document['body']['$write']);
            $this->assertEquals(['role:all'], $document['body']['$read']);
            $this->assertEquals([], $document['body']['$write']);
        }

        $document = $this->client->call(Client::METHOD_GET, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($document['headers']['status-code'], 200);
        $this->assertEquals($document['body']['title'], 'Captain America 2');
        $this->assertEquals($document['body']['releaseYear'], 1945);

        if ($this->getSide() == 'client') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(1, $document['body']['$write']);
            $this->assertEquals(['role:all'], $document['body']['$read']);
            $this->assertEquals(['user:' . $this->getUser()['$id']], $document['body']['$write']);
        }

        if ($this->getSide() == 'server') {
            $this->assertCount(1, $document['body']['$read']);
            $this->assertCount(0, $document['body']['$write']);
            $this->assertEquals(['role:all'], $document['body']['$read']);
            $this->assertEquals([], $document['body']['$write']);
        }

        // Reset Permissions

        $document = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['moviesId'] . '/documents/' . $id, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'data' => [
                'title' => 'Captain America 3',
                'releaseYear' => 1946,
                'actors' => [],
            ],
            'read' => [],
            'write' => [],
        ]);

        if ($this->getSide() == 'client') {
            $this->assertEquals($document['headers']['status-code'], 401);
        }

        if ($this->getSide() == 'server') {
            $this->assertEquals($document['headers']['status-code'], 200);
            $this->assertEquals($document['body']['title'], 'Captain America 3');
            $this->assertEquals($document['body']['releaseYear'], 1946);
            $this->assertCount(0, $document['body']['$read']);
            $this->assertCount(0, $document['body']['$write']);
            $this->assertEquals([], $document['body']['$read']);
            $this->assertEquals([], $document['body']['$write']);
        }

        return $data;
    }


    public function testEnforceCollectionPermissions()
    {
        $user = 'user:' . $this->getUser()['$id'];
        $collection = $this->client->call(Client::METHOD_POST, '/database/collections', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'collectionId' => 'unique()',
            'name' => 'enforceCollectionPermissions',
            'permission' => 'collection',
            'read' => [$user],
            'write' => [$user]
        ]);

        $this->assertEquals($collection['headers']['status-code'], 201);
        $this->assertEquals($collection['body']['name'], 'enforceCollectionPermissions');
        $this->assertEquals($collection['body']['permission'], 'collection');

        $collectionId = $collection['body']['$id'];

        sleep(2);

        $attribute = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/attributes/string', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'attribute',
            'size' => 64,
            'required' => true,
        ]);

        $this->assertEquals(201, $attribute['headers']['status-code'], 201);
        $this->assertEquals('attribute', $attribute['body']['key']);

        // wait for db to add attribute
        sleep(2);

        $index = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'key_attribute',
            'type' => 'key',
            'attributes' => [$attribute['body']['key']],
        ]);

        $this->assertEquals(201, $index['headers']['status-code']);
        $this->assertEquals('key_attribute', $index['body']['key']);

        // wait for db to add attribute
        sleep(2);

        $document1 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'attribute' => 'one',
            ],
            'read' => [$user],
            'write' => [$user],
        ]);

        $this->assertEquals(201, $document1['headers']['status-code']);

        $document2 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'attribute' => 'one',
            ],
            'read' => [],
            'write' => [$user],
        ]);

        $this->assertEquals(201, $document2['headers']['status-code']);

        $document3 = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'attribute' => 'one',
            ],
            'read' => [],
            'write' => [],
        ]);

        $this->assertEquals(201, $document3['headers']['status-code']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(3, $documents['body']['total']);
        $this->assertCount(3, $documents['body']['documents']);

        /*
         * Test for Failure
         */

        // Remove write permission
        $collection = $this->client->call(Client::METHOD_PUT, '/database/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'enforceCollectionPermissions',
            'permission' => 'collection',
            'read' => [$user],
            'write' => []
        ]);

        $this->assertEquals(200, $collection['headers']['status-code']);

        $badDocument = $this->client->call(Client::METHOD_POST, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'attribute' => 'bad',
            ],
            'read' => [$user],
            'write' => [$user],
        ]);

        if ($this->getSide() == 'client') {
            $this->assertEquals(401, $badDocument['headers']['status-code']);
        }

        if ($this->getSide() == 'server') {
            $this->assertEquals(201, $badDocument['headers']['status-code']);
        }

        // Remove read permission
        $collection = $this->client->call(Client::METHOD_PUT, '/database/collections/' . $collectionId, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'name' => 'enforceCollectionPermissions',
            'permission' => 'collection',
            'read' => [],
            'write' => []
        ]);

        $this->assertEquals(200, $collection['headers']['status-code']);

        $documents = $this->client->call(Client::METHOD_GET, '/database/collections/' . $collectionId . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals(401, $documents['headers']['status-code']);
    }

    /**
     * @depends testDefaultPermissions
     */
    public function testUniqueIndexDuplicate(array $data): array
    {
        $uniqueIndex = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/indexes', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey']
        ]), [
            'key' => 'unique_title',
            'type' => 'unique',
            'attributes' => ['title'],
        ]);

        $this->assertEquals($uniqueIndex['headers']['status-code'], 201);

        sleep(2);

        // test for failure
        $duplicate = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [
                    'Chris Evans',
                    'Samuel Jackson',
                ]
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $this->assertEquals(409, $duplicate['headers']['status-code']);

        // Test for exception when updating document to conflict
        $document = $this->client->call(Client::METHOD_POST, '/database/collections/' . $data['moviesId'] . '/documents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Captain America 5',
                'releaseYear' => 1944,
                'actors' => [
                    'Chris Evans',
                    'Samuel Jackson',
                ]
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $this->assertEquals(201, $document['headers']['status-code']);

        // Test for exception when updating document to conflict
        $duplicate = $this->client->call(Client::METHOD_PATCH, '/database/collections/' . $data['moviesId'] . '/documents/' . $document['body']['$id'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'documentId' => 'unique()',
            'data' => [
                'title' => 'Captain America',
                'releaseYear' => 1944,
                'actors' => [
                    'Chris Evans',
                    'Samuel Jackson',
                ]
            ],
            'read' => ['user:' . $this->getUser()['$id']],
            'write' => ['user:' . $this->getUser()['$id']],
        ]);

        $this->assertEquals(409, $duplicate['headers']['status-code']);

        return $data;
    }
}
