<?php

namespace Appwrite\Tests;

use Appwrite\Auth\Auth;
use Appwrite\Database\Document;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testCookieName()
    {
        $name = 'cookie-name';

        $this->assertEquals(Auth::setCookieName($name), $name);
        $this->assertEquals(Auth::$cookieName, $name);
    }

    public function testEncodeDecodeSession()
    {
        $id = 'id';
        $secret = 'secret';
        $session = 'eyJpZCI6ImlkIiwic2VjcmV0Ijoic2VjcmV0In0=';

        $this->assertEquals(Auth::encodeSession($id, $secret), $session);
        $this->assertEquals(Auth::decodeSession($session), ['id' => $id, 'secret' => $secret]);
    }
    
    public function testHash()
    {
        $secret = 'secret';
        $this->assertEquals(Auth::hash($secret), '2bb80d537b1da3e38bd30361aa855686bde0eacd7162fef6a25fe97bf527a25b');
    }
    
    public function testPassword()
    {
        $secret = 'secret';
        $static = '$2y$08$PDbMtV18J1KOBI9tIYabBuyUwBrtXPGhLxCy9pWP6xkldVOKLrLKy';
        $dynamic = Auth::passwordHash($secret);
        
        $this->assertEquals(Auth::passwordVerify($secret, $dynamic), true);
        $this->assertEquals(Auth::passwordVerify($secret, $static), true);
    }
    
    public function testPasswordGenerator()
    {
        $this->assertEquals(\mb_strlen(Auth::passwordGenerator()), 40);
        $this->assertEquals(\mb_strlen(Auth::passwordGenerator(5)), 10);
    }
    
    public function testTokenGenerator()
    {
        $this->assertEquals(\mb_strlen(Auth::tokenGenerator()), 256);
        $this->assertEquals(\mb_strlen(Auth::tokenGenerator(5)), 10);
    }
    
    public function testTokenVerify()
    {
        $secret = 'secret1';
        $hash = Auth::hash($secret);
        $tokens1 = [
            new Document([
                '$id' => 'token1',
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'expire' => time() + 60 * 60 * 24,
                'secret' => $hash,
            ]),
            new Document([
                '$id' => 'token2',
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'expire' => time() - 60 * 60 * 24,
                'secret' => 'secret2',
            ]),
        ];

        $tokens2 = [
            new Document([ // Correct secret and type time, wrong expire time
                '$id' => 'token1',
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'expire' => time() - 60 * 60 * 24,
                'secret' => $hash,
            ]),
            new Document([
                '$id' => 'token2',
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'expire' => time() - 60 * 60 * 24,
                'secret' => 'secret2',
            ]),
        ];

        $tokens3 = [ // Correct secret and expire time, wrong type
            new Document([
                '$id' => 'token1',
                'type' => Auth::TOKEN_TYPE_RECOVERY,
                'expire' => time() + 60 * 60 * 24,
                'secret' => $hash,
            ]),
            new Document([
                '$id' => 'token2',
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'expire' => time() - 60 * 60 * 24,
                'secret' => 'secret2',
            ]),
        ];

        $this->assertEquals(Auth::tokenVerify($tokens1, Auth::TOKEN_TYPE_LOGIN, $secret), 'token1');
        $this->assertEquals(Auth::tokenVerify($tokens1, Auth::TOKEN_TYPE_LOGIN, 'false-secret'), false);
        $this->assertEquals(Auth::tokenVerify($tokens2, Auth::TOKEN_TYPE_LOGIN, $secret), false);
        $this->assertEquals(Auth::tokenVerify($tokens2, Auth::TOKEN_TYPE_LOGIN, 'false-secret'), false);
        $this->assertEquals(Auth::tokenVerify($tokens3, Auth::TOKEN_TYPE_LOGIN, $secret), false);
        $this->assertEquals(Auth::tokenVerify($tokens3, Auth::TOKEN_TYPE_LOGIN, 'false-secret'), false);
    }

    public function testIsPreviliggedUser()
    {
        $this->assertEquals(false, Auth::isPreviliggedUser([]));
        $this->assertEquals(false, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_GUEST => true]));
        $this->assertEquals(false, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_MEMBER => true]));
        $this->assertEquals(true, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_ADMIN => true]));
        $this->assertEquals(true, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_DEVELOPER => true]));
        $this->assertEquals(true, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_OWNER => true]));
        $this->assertEquals(false, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_APP => true]));
        $this->assertEquals(false, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_SYSTEM => true]));

        $this->assertEquals(false, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_APP => true, 'role:'.Auth::USER_ROLE_APP => true]));
        $this->assertEquals(false, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_APP => true, 'role:'.Auth::USER_ROLE_GUEST => true]));
        $this->assertEquals(true, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_OWNER => true, 'role:'.Auth::USER_ROLE_GUEST => true]));
        $this->assertEquals(true, Auth::isPreviliggedUser(['role:'.Auth::USER_ROLE_OWNER => true, 'role:'.Auth::USER_ROLE_ADMIN => true, 'role:'.Auth::USER_ROLE_DEVELOPER => true]));
    }
    
    public function testIsAppUser()
    {
        $this->assertEquals(false, Auth::isAppUser([]));
        $this->assertEquals(false, Auth::isAppUser(['role:'.Auth::USER_ROLE_GUEST => true]));
        $this->assertEquals(false, Auth::isAppUser(['role:'.Auth::USER_ROLE_MEMBER => true]));
        $this->assertEquals(false, Auth::isAppUser(['role:'.Auth::USER_ROLE_ADMIN => true]));
        $this->assertEquals(false, Auth::isAppUser(['role:'.Auth::USER_ROLE_DEVELOPER => true]));
        $this->assertEquals(false, Auth::isAppUser(['role:'.Auth::USER_ROLE_OWNER => true]));
        $this->assertEquals(true, Auth::isAppUser(['role:'.Auth::USER_ROLE_APP => true]));
        $this->assertEquals(false, Auth::isAppUser(['role:'.Auth::USER_ROLE_SYSTEM => true]));

        $this->assertEquals(true, Auth::isAppUser(['role:'.Auth::USER_ROLE_APP => true, 'role:'.Auth::USER_ROLE_APP => true]));
        $this->assertEquals(true, Auth::isAppUser(['role:'.Auth::USER_ROLE_APP => true, 'role:'.Auth::USER_ROLE_GUEST => true]));
        $this->assertEquals(false, Auth::isAppUser(['role:'.Auth::USER_ROLE_OWNER => true, 'role:'.Auth::USER_ROLE_GUEST => true]));
        $this->assertEquals(false, Auth::isAppUser(['role:'.Auth::USER_ROLE_OWNER => true, 'role:'.Auth::USER_ROLE_ADMIN => true, 'role:'.Auth::USER_ROLE_DEVELOPER => true]));
    }
}
