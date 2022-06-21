<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Utopia\Database\Document as DatabaseDocument;

class Document extends Any
{
    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Document';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DOCUMENT;
    }

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Document ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$collection', [
                'type' => self::TYPE_STRING,
                'description' => 'Collection ID.',
                'default' => '',
                'example' => '5e5ea5c15117e',
            ])
            ->addRule('$read', [
                'type' => self::TYPE_STRING,
                'description' => 'Document read permissions.',
                'default' => '',
                'example' => 'role:all',
                'array' => true,
            ])
            ->addRule('$write', [
                'type' => self::TYPE_STRING,
                'description' => 'Document write permissions.',
                'default' => '',
                'example' => 'user:608f9da25e7e1',
                'array' => true,
            ])
        ;
    }

    public function filter(DatabaseDocument $document): DatabaseDocument
    {
        $document->removeAttribute('$internalId');

        return $document;
    }
}
