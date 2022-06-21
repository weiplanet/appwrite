<?php

namespace Appwrite\Tests;

use Appwrite\Auth\Auth;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
    public function setUp(): void
    {
    }

    /**
     * Reset Roles
     */
    public function tearDown(): void
    {
        Authorization::cleanRoles();
        Authorization::setRole('role:all');
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

    public function testSessionVerify()
    {
        $secret = 'secret1';
        $hash = Auth::hash($secret);
        $tokens1 = [
            new Document([
                '$id' => 'token1',
                'expire' => time() + 60 * 60 * 24,
                'secret' => $hash,
                'provider' => Auth::SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
            ]),
            new Document([
                '$id' => 'token2',
                'expire' => time() - 60 * 60 * 24,
                'secret' => 'secret2',
                'provider' => Auth::SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
            ]),
        ];

        $tokens2 = [
            new Document([ // Correct secret and type time, wrong expire time
                '$id' => 'token1',
                'expire' => time() - 60 * 60 * 24,
                'secret' => $hash,
                'provider' => Auth::SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
            ]),
            new Document([
                '$id' => 'token2',
                'expire' => time() - 60 * 60 * 24,
                'secret' => 'secret2',
                'provider' => Auth::SESSION_PROVIDER_EMAIL,
                'providerUid' => 'test@example.com',
            ]),
        ];

        $this->assertEquals(Auth::sessionVerify($tokens1, $secret), 'token1');
        $this->assertEquals(Auth::sessionVerify($tokens1, 'false-secret'), false);
        $this->assertEquals(Auth::sessionVerify($tokens2, $secret), false);
        $this->assertEquals(Auth::sessionVerify($tokens2, 'false-secret'), false);
    }

    public function testTokenVerify()
    {
        $secret = 'secret1';
        $hash = Auth::hash($secret);
        $tokens1 = [
            new Document([
                '$id' => 'token1',
                'type' => Auth::TOKEN_TYPE_RECOVERY,
                'expire' => time() + 60 * 60 * 24,
                'secret' => $hash,
            ]),
            new Document([
                '$id' => 'token2',
                'type' => Auth::TOKEN_TYPE_RECOVERY,
                'expire' => time() - 60 * 60 * 24,
                'secret' => 'secret2',
            ]),
        ];

        $tokens2 = [
            new Document([ // Correct secret and type time, wrong expire time
                '$id' => 'token1',
                'type' => Auth::TOKEN_TYPE_RECOVERY,
                'expire' => time() - 60 * 60 * 24,
                'secret' => $hash,
            ]),
            new Document([
                '$id' => 'token2',
                'type' => Auth::TOKEN_TYPE_RECOVERY,
                'expire' => time() - 60 * 60 * 24,
                'secret' => 'secret2',
            ]),
        ];

        $tokens3 = [ // Correct secret and expire time, wrong type
            new Document([
                '$id' => 'token1',
                'type' => Auth::TOKEN_TYPE_INVITE,
                'expire' => time() + 60 * 60 * 24,
                'secret' => $hash,
            ]),
            new Document([
                '$id' => 'token2',
                'type' => Auth::TOKEN_TYPE_RECOVERY,
                'expire' => time() - 60 * 60 * 24,
                'secret' => 'secret2',
            ]),
        ];

        $this->assertEquals(Auth::tokenVerify($tokens1, Auth::TOKEN_TYPE_RECOVERY, $secret), 'token1');
        $this->assertEquals(Auth::tokenVerify($tokens1, Auth::TOKEN_TYPE_RECOVERY, 'false-secret'), false);
        $this->assertEquals(Auth::tokenVerify($tokens2, Auth::TOKEN_TYPE_RECOVERY, $secret), false);
        $this->assertEquals(Auth::tokenVerify($tokens2, Auth::TOKEN_TYPE_RECOVERY, 'false-secret'), false);
        $this->assertEquals(Auth::tokenVerify($tokens3, Auth::TOKEN_TYPE_RECOVERY, $secret), false);
        $this->assertEquals(Auth::tokenVerify($tokens3, Auth::TOKEN_TYPE_RECOVERY, 'false-secret'), false);
    }

    public function testIsPrivilegedUser()
    {
        $this->assertEquals(false, Auth::isPrivilegedUser([]));
        $this->assertEquals(false, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_GUEST]));
        $this->assertEquals(false, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_MEMBER]));
        $this->assertEquals(true, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_ADMIN]));
        $this->assertEquals(true, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_DEVELOPER]));
        $this->assertEquals(true, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_OWNER]));
        $this->assertEquals(false, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_APP]));
        $this->assertEquals(false, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_SYSTEM]));

        $this->assertEquals(false, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_APP, 'role:' . Auth::USER_ROLE_APP]));
        $this->assertEquals(false, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_APP, 'role:' . Auth::USER_ROLE_GUEST]));
        $this->assertEquals(true, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_OWNER, 'role:' . Auth::USER_ROLE_GUEST]));
        $this->assertEquals(true, Auth::isPrivilegedUser(['role:' . Auth::USER_ROLE_OWNER, 'role:' . Auth::USER_ROLE_ADMIN, 'role:' . Auth::USER_ROLE_DEVELOPER]));
    }

    public function testIsAppUser()
    {
        $this->assertEquals(false, Auth::isAppUser([]));
        $this->assertEquals(false, Auth::isAppUser(['role:' . Auth::USER_ROLE_GUEST]));
        $this->assertEquals(false, Auth::isAppUser(['role:' . Auth::USER_ROLE_MEMBER]));
        $this->assertEquals(false, Auth::isAppUser(['role:' . Auth::USER_ROLE_ADMIN]));
        $this->assertEquals(false, Auth::isAppUser(['role:' . Auth::USER_ROLE_DEVELOPER]));
        $this->assertEquals(false, Auth::isAppUser(['role:' . Auth::USER_ROLE_OWNER]));
        $this->assertEquals(true, Auth::isAppUser(['role:' . Auth::USER_ROLE_APP]));
        $this->assertEquals(false, Auth::isAppUser(['role:' . Auth::USER_ROLE_SYSTEM]));

        $this->assertEquals(true, Auth::isAppUser(['role:' . Auth::USER_ROLE_APP, 'role:' . Auth::USER_ROLE_APP]));
        $this->assertEquals(true, Auth::isAppUser(['role:' . Auth::USER_ROLE_APP, 'role:' . Auth::USER_ROLE_GUEST]));
        $this->assertEquals(false, Auth::isAppUser(['role:' . Auth::USER_ROLE_OWNER, 'role:' . Auth::USER_ROLE_GUEST]));
        $this->assertEquals(false, Auth::isAppUser(['role:' . Auth::USER_ROLE_OWNER, 'role:' . Auth::USER_ROLE_ADMIN, 'role:' . Auth::USER_ROLE_DEVELOPER]));
    }

    public function testGuestRoles()
    {
        $user = new Document([
            '$id' => ''
        ]);

        $roles = Auth::getRoles($user);
        $this->assertCount(1, $roles);
        $this->assertContains('role:guest', $roles);
    }

    public function testUserRoles()
    {
        $user  = new Document([
            '$id' => '123',
            'memberships' => [
                [
                    'teamId' => 'abc',
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    'teamId' => 'def',
                    'roles' => [
                        'guest'
                    ]
                ]
            ]
        ]);

        $roles = Auth::getRoles($user);

        $this->assertCount(7, $roles);
        $this->assertContains('role:member', $roles);
        $this->assertContains('user:123', $roles);
        $this->assertContains('team:abc', $roles);
        $this->assertContains('team:abc/administrator', $roles);
        $this->assertContains('team:abc/moderator', $roles);
        $this->assertContains('team:def', $roles);
        $this->assertContains('team:def/guest', $roles);
    }

    public function testPrivilegedUserRoles()
    {
        Authorization::setRole('role:' . Auth::USER_ROLE_OWNER);
        $user  = new Document([
            '$id' => '123',
            'memberships' => [
                [
                    'teamId' => 'abc',
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    'teamId' => 'def',
                    'roles' => [
                        'guest'
                    ]
                ]
            ]
        ]);

        $roles = Auth::getRoles($user);

        $this->assertCount(5, $roles);
        $this->assertNotContains('role:member', $roles);
        $this->assertNotContains('user:123', $roles);
        $this->assertContains('team:abc', $roles);
        $this->assertContains('team:abc/administrator', $roles);
        $this->assertContains('team:abc/moderator', $roles);
        $this->assertContains('team:def', $roles);
        $this->assertContains('team:def/guest', $roles);
    }

    public function testAppUserRoles()
    {
        Authorization::setRole('role:' . Auth::USER_ROLE_APP);
        $user  = new Document([
            '$id' => '123',
            'memberships' => [
                [
                    'teamId' => 'abc',
                    'roles' => [
                        'administrator',
                        'moderator'
                    ]
                ],
                [
                    'teamId' => 'def',
                    'roles' => [
                        'guest'
                    ]
                ]
            ]
        ]);

        $roles = Auth::getRoles($user);

        $this->assertCount(5, $roles);
        $this->assertNotContains('role:member', $roles);
        $this->assertNotContains('user:123', $roles);
        $this->assertContains('team:abc', $roles);
        $this->assertContains('team:abc/administrator', $roles);
        $this->assertContains('team:abc/moderator', $roles);
        $this->assertContains('team:def', $roles);
        $this->assertContains('team:def/guest', $roles);
    }
}
