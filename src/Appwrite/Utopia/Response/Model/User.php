<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class User extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'User ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'User name.',
                'default' => '',
                'example' => 'John Doe',
            ])
            ->addRule('registration', [
                'type' => self::TYPE_INTEGER,
                'description' => 'User registration date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('status', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'User status. Pass `true` for enabled and `false` for disabled.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('passwordUpdate', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Unix timestamp of the most recent password update',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('email', [
                'type' => self::TYPE_STRING,
                'description' => 'User email address.',
                'default' => '',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('emailVerification', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Email verification status.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('prefs', [
                'type' => Response::MODEL_PREFERENCES,
                'description' => 'User preferences as a key-value object',
                'default' => new \stdClass(),
                'example' => ['theme' => 'pink', 'timezone' => 'UTC'],
            ])
        ;
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function filter(Document $document): Document
    {
        $prefs = $document->getAttribute('prefs');
        if ($prefs instanceof Document) {
            $prefs = $prefs->getArrayCopy();
        }

        if (is_array($prefs) && empty($prefs)) {
            $document->setAttribute('prefs', new \stdClass());
        }
        return $document;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'User';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USER;
    }
}
