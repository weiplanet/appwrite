<?php

namespace Appwrite\Auth;

use Appwrite\Database\Document;

class Auth
{
    /**
     * User Status.
     */
    const USER_STATUS_UNACTIVATED = 0;
    const USER_STATUS_ACTIVATED = 1;
    const USER_STATUS_BLOCKED = 2;

    /**
     * User Roles.
     */
    const USER_ROLE_GUEST = 'guest';
    const USER_ROLE_MEMBER = 'member';
    const USER_ROLE_ADMIN = 'admin';
    const USER_ROLE_DEVELOPER = 'developer';
    const USER_ROLE_OWNER = 'owner';
    const USER_ROLE_APP = 'app';
    const USER_ROLE_SYSTEM = 'system';
    const USER_ROLE_ALL = '*';

    /**
     * Token Types.
     */
    const TOKEN_TYPE_LOGIN = 1;
    const TOKEN_TYPE_VERIFICATION = 2;
    const TOKEN_TYPE_RECOVERY = 3;
    const TOKEN_TYPE_INVITE = 4;

    /**
     * Token Expiration times.
     */
    const TOKEN_EXPIRATION_LOGIN_LONG = 31536000;      /* 1 year */
    const TOKEN_EXPIRATION_LOGIN_SHORT = 3600;         /* 1 hour */
    const TOKEN_EXPIRATION_RECOVERY = 3600;            /* 1 hour */
    const TOKEN_EXPIRATION_CONFIRM = 3600 * 24 * 7;    /* 7 days */

    /**
     * @var string
     */
    public static $cookieName = 'a_session';

    /**
     * User Unique ID.
     *
     * @var string
     */
    public static $unique = '';

    /**
     * User Secret Key.
     *
     * @var string
     */
    public static $secret = '';

    /**
     * Set Cookie Name.
     *
     * @param $string
     *
     * @return string
     */
    public static function setCookieName($string)
    {
        return self::$cookieName = $string;
    }

    /**
     * Encode Session.
     *
     * @param string $id
     * @param string $secret
     *
     * @return string
     */
    public static function encodeSession($id, $secret)
    {
        return \base64_encode(\json_encode([
            'id' => $id,
            'secret' => $secret,
        ]));
    }

    /**
     * Decode Session.
     *
     * @param string $session
     *
     * @return array
     *
     * @throws \Exception
     */
    public static function decodeSession($session)
    {
        $session = \json_decode(\base64_decode($session), true);
        $default = ['id' => null, 'secret' => ''];

        if (!\is_array($session)) {
            return $default;
        }

        return \array_merge($default, $session);
    }

    /**
     * Encode.
     *
     * One-way encryption
     *
     * @param $string
     *
     * @return string
     */
    public static function hash(string $string)
    {
        return \hash('sha256', $string);
    }

    /**
     * Password Hash.
     *
     * One way string hashing for user passwords
     *
     * @param $string
     *
     * @return bool|string|null
     */
    public static function passwordHash($string)
    {
        return \password_hash($string, PASSWORD_BCRYPT, array('cost' => 8));
    }

    /**
     * Password verify.
     *
     * @param $plain
     * @param $hash
     *
     * @return bool
     */
    public static function passwordVerify($plain, $hash)
    {
        return \password_verify($plain, $hash);
    }

    /**
     * Password Generator.
     *
     * Generate random password string
     *
     * @param int $length
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function passwordGenerator(int $length = 20):string
    {
        return \bin2hex(\random_bytes($length));
    }

    /**
     * Token Generator.
     *
     * Generate random password string
     *
     * @param int $length
     *
     * @return string
     *
     * @throws \Exception
     */
    public static function tokenGenerator(int $length = 128):string
    {
        return \bin2hex(\random_bytes($length));
    }

    /**
     * Verify token and check that its not expired.
     *
     * @param array  $tokens
     * @param int    $type
     * @param string $secret
     *
     * @return bool|string
     */
    public static function tokenVerify(array $tokens, int $type, string $secret)
    {
        foreach ($tokens as $token) { /** @var Document $token */
            if ($token->isSet('type') &&
                $token->isSet('secret') &&
                $token->isSet('expire') &&
                $token->getAttribute('type') == $type &&
                $token->getAttribute('secret') === self::hash($secret) &&
                $token->getAttribute('expire') >= \time()) {
                return (string)$token->getId();
            }
        }

        return false;
    }

    /**
     * Is Previligged User?
     * 
     * @param array $roles
     * 
     * @return bool
     */
    public static function isPreviliggedUser(array $roles): bool
    {
        if(
            array_key_exists('role:'.self::USER_ROLE_OWNER, $roles) ||
            array_key_exists('role:'.self::USER_ROLE_DEVELOPER, $roles) ||
            array_key_exists('role:'.self::USER_ROLE_ADMIN, $roles)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Is App User?
     * 
     * @param array $roles
     * 
     * @return bool
     */
    public static function isAppUser(array $roles): bool
    {
        if(array_key_exists('role:'.self::USER_ROLE_APP, $roles)) {
            return true;
        }

        return false;
    }
}
