<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Client;

trait TeamsBase
{
    public function testCreateTeam():array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'name' => 'Arsenal'
        ]);

        $this->assertEquals(201, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$uid']);
        $this->assertEquals('Arsenal', $response1['body']['name']);
        $this->assertGreaterThan(-1, $response1['body']['sum']);
        $this->assertIsInt($response1['body']['sum']);
        $this->assertIsInt($response1['body']['dateCreated']);

        $teamUid = $response1['body']['$uid'];

        $response2 = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'name' => 'Manchester United'
        ]);

        $this->assertEquals(201, $response2['headers']['status-code']);
        $this->assertNotEmpty($response2['body']['$uid']);
        $this->assertEquals('Manchester United', $response2['body']['name']);
        $this->assertGreaterThan(-1, $response2['body']['sum']);
        $this->assertIsInt($response2['body']['sum']);
        $this->assertIsInt($response2['body']['dateCreated']);

        $response3 = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'name' => 'Newcastle'
        ]);

        $this->assertEquals(201, $response3['headers']['status-code']);
        $this->assertNotEmpty($response3['body']['$uid']);
        $this->assertEquals('Newcastle', $response3['body']['name']);
        $this->assertGreaterThan(-1, $response3['body']['sum']);
        $this->assertIsInt($response3['body']['sum']);
        $this->assertIsInt($response3['body']['dateCreated']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return ['teamUid' => $teamUid];
    }

    /**
     * @depends testCreateTeam
     */
    public function testGetTeam($data):array
    {
        $uid = (isset($data['teamUid'])) ? $data['teamUid'] : '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$uid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertEquals('Arsenal', $response['body']['name']);
        $this->assertGreaterThan(-1, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertIsInt($response['body']['dateCreated']);

        /**
         * Test for FAILURE
         */

         return [];
    }

    /**
     * @depends testCreateTeam
     */
    public function testListTeams($data):array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertCount(3, $response['body']['teams']);

        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'limit' => 2,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertCount(2, $response['body']['teams']);
        
        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'offset' => 1,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertCount(2, $response['body']['teams']);
        
        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'search' => 'Manchester',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertCount(1, $response['body']['teams']);
        $this->assertEquals('Manchester United', $response['body']['teams'][0]['name']);
        
        $response = $this->client->call(Client::METHOD_GET, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'search' => 'United',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertGreaterThan(0, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertCount(1, $response['body']['teams']);
        $this->assertEquals('Manchester United', $response['body']['teams'][0]['name']);

        /**
         * Test for FAILURE
         */

         return [];
    }

    public function testUpdateTeam():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'name' => 'Demo'
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertEquals('Demo', $response['body']['name']);
        $this->assertGreaterThan(-1, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertIsInt($response['body']['dateCreated']);

        $response = $this->client->call(Client::METHOD_PUT, '/teams/'.$response['body']['$uid'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'name' => 'Demo New'
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertEquals('Demo New', $response['body']['name']);
        $this->assertGreaterThan(-1, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertIsInt($response['body']['dateCreated']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/teams/'.$response['body']['$uid'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }

    public function testDeleteTeam():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'name' => 'Demo'
        ]);

        $teamUid = $response['body']['$uid'];

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertEquals('Demo', $response['body']['name']);
        $this->assertGreaterThan(-1, $response['body']['sum']);
        $this->assertIsInt($response['body']['sum']);
        $this->assertIsInt($response['body']['dateCreated']);

        $response = $this->client->call(Client::METHOD_DELETE, '/teams/'.$teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals(204, $response['headers']['status-code']);
        $this->assertEmpty($response['body']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/teams/'.$teamUid, array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals(404, $response['headers']['status-code']);

        return [];
    }

    /**
     * @depends testCreateTeam
     */
    public function testCreateTeamMembership($data):array
    {
        $uid = (isset($data['teamUid'])) ? $data['teamUid'] : '';
        $email = uniqid().'friend@localhost.test';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/teams/'.$uid.'/memberships', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()), [
            'email' => $email,
            'name' => 'Friend User',
            'roles' => ['admin', 'editor'],
            'url' => 'http://localhost:5000/join-us#title'
        ]);

        if ($response['headers']['status-code'] !== 201) {var_dump($response);}

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$uid']);
        $this->assertNotEmpty($response['body']['userId']);
        $this->assertNotEmpty($response['body']['teamId']);
        $this->assertCount(2, $response['body']['roles']);
        $this->assertIsInt($response['body']['joined']);
        $this->assertEquals(false, $response['body']['confirm']);

        /**
         * Test for FAILURE
         */
        // $response = $this->client->call(Client::METHOD_POST, '/teams/'.$uid.'/memberships', array_merge([
        //     'content-type' => 'application/json',
        //     'x-appwrite-project' => $this->getProject()['$uid'],
        // ], $this->getHeaders()));

        // $this->assertEquals(404, $response['headers']['status-code']);

        return [];
    }
}