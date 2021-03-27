<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class File extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'File ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$permissions', [
                'type' => Response::MODEL_PERMISSIONS,
                'description' => 'File permissions.',
                'default' => new \stdClass,
                'example' => new \stdClass,
                'array' => false,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'File name.',
                'default' => '',
                'example' => 'Pink.png',
            ])
            ->addRule('dateCreated', [
                'type' => self::TYPE_INTEGER,
                'description' => 'File creation date in Unix timestamp.',
                'default' => 0,
                'example' => 1592981250,
            ])
            ->addRule('signature', [
                'type' => self::TYPE_STRING,
                'description' => 'File MD5 signature.',
                'default' => '',
                'example' => '5d529fd02b544198ae075bd57c1762bb',
            ])
            ->addRule('mimeType', [
                'type' => self::TYPE_STRING,
                'description' => 'File mime type.',
                'default' => '',
                'example' => 'image/png',
            ])
            ->addRule('sizeOriginal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'File original size in bytes.',
                'default' => 0,
                'example' => 17890,
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'File';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_FILE;
    }
}