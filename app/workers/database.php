<?php

use Appwrite\Event\Event;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Resque\Worker;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../init.php';

Console::title('Database V1 Worker');
Console::success(APP_NAME . ' database worker v1 has started' . "\n");

class DatabaseV1 extends Worker
{
    public function init(): void
    {
    }

    public function run(): void
    {
        Authorization::disable();

        $type = $this->args['type'];
        $project = new Document($this->args['project']);
        $collection = new Document($this->args['collection'] ?? []);
        $document = new Document($this->args['document'] ?? []);

        if ($collection->isEmpty()) {
            throw new Exception('Missing collection');
        }

        if ($document->isEmpty()) {
            throw new Exception('Missing document');
        }

        switch (strval($type)) {
            case DATABASE_TYPE_CREATE_ATTRIBUTE:
                $this->createAttribute($collection, $document, $project->getId());
                break;
            case DATABASE_TYPE_DELETE_ATTRIBUTE:
                $this->deleteAttribute($collection, $document, $project->getId());
                break;
            case DATABASE_TYPE_CREATE_INDEX:
                $this->createIndex($collection, $document, $project->getId());
                break;
            case DATABASE_TYPE_DELETE_INDEX:
                $this->deleteIndex($collection, $document, $project->getId());
                break;

            default:
                Console::error('No database operation for type: ' . $type);
                break;
        }

            Authorization::reset();
    }

    public function shutdown(): void
    {
    }

    /**
     * @param Document $collection
     * @param Document $attribute
     * @param string $projectId
     */
    protected function createAttribute(Document $collection, Document $attribute, string $projectId): void
    {
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($projectId);

        $events = Event::generateEvents('collections.[collectionId].attributes.[attributeId].update', [
            'collectionId' => $collection->getId(),
            'attributeId' => $attribute->getId()
        ]);
        /**
         * Fetch attribute from the database, since with Resque float values are loosing informations.
         */
        $attribute = $dbForProject->getDocument('attributes', $attribute->getId());

        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');
        $type = $attribute->getAttribute('type', '');
        $size = $attribute->getAttribute('size', 0);
        $required = $attribute->getAttribute('required', false);
        $default = $attribute->getAttribute('default', null);
        $signed = $attribute->getAttribute('signed', true);
        $array = $attribute->getAttribute('array', false);
        $format = $attribute->getAttribute('format', '');
        $formatOptions = $attribute->getAttribute('formatOptions', []);
        $filters = $attribute->getAttribute('filters', []);
        $project = $dbForConsole->getDocument('projects', $projectId);

        try {
            if (!$dbForProject->createAttribute('collection_' . $collection->getInternalId(), $key, $type, $size, $required, $default, $signed, $array, $format, $formatOptions, $filters)) {
                throw new Exception('Failed to create Attribute');
            }
            $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'available'));
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'failed'));
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $attribute,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $attribute->getArrayCopy(),
                events: $events,
                channels: $target['channels'],
                roles: $target['roles'],
                options: [
                    'projectId' => $projectId,
                    'collectionId' => $collection->getId()
                ]
            );
        }

        $dbForProject->deleteCachedDocument('collections', $collectionId);
    }

    /**
     * @param Document $collection
     * @param Document $attribute
     * @param string $projectId
     */
    protected function deleteAttribute(Document $collection, Document $attribute, string $projectId): void
    {
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($projectId);

        $events = Event::generateEvents('collections.[collectionId].attributes.[attributeId].delete', [
            'collectionId' => $collection->getId(),
            'attributeId' => $attribute->getId()
        ]);
        $collectionId = $collection->getId();
        $key = $attribute->getAttribute('key', '');
        $status = $attribute->getAttribute('status', '');
        $project = $dbForConsole->getDocument('projects', $projectId);

        // possible states at this point:
        // - available: should not land in queue; controller flips these to 'deleting'
        // - processing: hasn't finished creating
        // - deleting: was available, in deletion queue for first time
        // - failed: attribute was never created
        // - stuck: attribute was available but cannot be removed
        try {
            if ($status !== 'failed' && !$dbForProject->deleteAttribute('collection_' . $collection->getInternalId(), $key)) {
                throw new Exception('Failed to delete Attribute');
            }
            $dbForProject->deleteDocument('attributes', $attribute->getId());
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'stuck'));
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $attribute,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $attribute->getArrayCopy(),
                events: $events,
                channels: $target['channels'],
                roles: $target['roles'],
                options: [
                    'projectId' => $projectId,
                    'collectionId' => $collection->getId()
                ]
            );
        }

        // The underlying database removes/rebuilds indexes when attribute is removed
        // Update indexes table with changes
        /** @var Document[] $indexes */
        $indexes = $collection->getAttribute('indexes', []);

        foreach ($indexes as $index) {
            /** @var string[] $attributes */
            $attributes = $index->getAttribute('attributes');
            $lengths = $index->getAttribute('lengths');
            $orders = $index->getAttribute('orders');

            $found = \array_search($key, $attributes);

            if ($found !== false) {
                // If found, remove entry from attributes, lengths, and orders
                // array_values wraps array_diff to reindex array keys
                // when found attribute is removed from array
                $attributes = \array_values(\array_diff($attributes, [$attributes[$found]]));
                $lengths = \array_values(\array_diff($lengths, [$lengths[$found]]));
                $orders = \array_values(\array_diff($orders, [$orders[$found]]));

                if (empty($attributes)) {
                    $dbForProject->deleteDocument('indexes', $index->getId());
                } else {
                    $index
                        ->setAttribute('attributes', $attributes, Document::SET_TYPE_ASSIGN)
                        ->setAttribute('lengths', $lengths, Document::SET_TYPE_ASSIGN)
                        ->setAttribute('orders', $orders, Document::SET_TYPE_ASSIGN)
                    ;

                    // Check if an index exists with the same attributes and orders
                    $exists = false;
                    foreach ($indexes as $existing) {
                        if (
                            $existing->getAttribute('key') !== $index->getAttribute('key') // Ignore itself
                            && $existing->getAttribute('attributes') === $index->getAttribute('attributes')
                            && $existing->getAttribute('orders') === $index->getAttribute('orders')
                        ) {
                            $exists = true;
                            break;
                        }
                    }

                    if ($exists) { // Delete the duplicate if created, else update in db
                        $this->deleteIndex($collection, $index, $projectId);
                    } else {
                        $dbForProject->updateDocument('indexes', $index->getId(), $index);
                    }
                }
            }
        }

        $dbForProject->deleteCachedDocument('collections', $collectionId);
        $dbForProject->deleteCachedCollection('collection_' . $collection->getInternalId());
    }

    /**
     * @param Document $collection
     * @param Document $index
     * @param string $projectId
     */
    protected function createIndex(Document $collection, Document $index, string $projectId): void
    {
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($projectId);

        $events = Event::generateEvents('collections.[collectionId].indexes.[indexId].update', [
            'collectionId' => $collection->getId(),
            'indexId' => $index->getId()
        ]);
        $collectionId = $collection->getId();
        $key = $index->getAttribute('key', '');
        $type = $index->getAttribute('type', '');
        $attributes = $index->getAttribute('attributes', []);
        $lengths = $index->getAttribute('lengths', []);
        $orders = $index->getAttribute('orders', []);
        $project = $dbForConsole->getDocument('projects', $projectId);

        try {
            if (!$dbForProject->createIndex('collection_' . $collection->getInternalId(), $key, $type, $attributes, $lengths, $orders)) {
                throw new Exception('Failed to create Index');
            }
            $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'available'));
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'failed'));
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $index,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $index->getArrayCopy(),
                events: $events,
                channels: $target['channels'],
                roles: $target['roles'],
                options: [
                    'projectId' => $projectId,
                    'collectionId' => $collection->getId()
                ]
            );
        }

        $dbForProject->deleteCachedDocument('collections', $collectionId);
    }

    /**
     * @param Document $collection
     * @param Document $index
     * @param string $projectId
     */
    protected function deleteIndex(Document $collection, Document $index, string $projectId): void
    {
        $dbForConsole = $this->getConsoleDB();
        $dbForProject = $this->getProjectDB($projectId);

        $events = Event::generateEvents('collections.[collectionId].indexes.[indexId].delete', [
            'collectionId' => $collection->getId(),
            'indexId' => $index->getId()
        ]);
        $key = $index->getAttribute('key');
        $status = $index->getAttribute('status', '');
        $project = $dbForConsole->getDocument('projects', $projectId);

        try {
            if ($status !== 'failed' && !$dbForProject->deleteIndex('collection_' . $collection->getInternalId(), $key)) {
                throw new Exception('Failed to delete index');
            }
            $dbForProject->deleteDocument('indexes', $index->getId());
        } catch (\Throwable $th) {
            Console::error($th->getMessage());
            $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'stuck'));
        } finally {
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $events[0],
                payload: $index,
                project: $project
            );

            Realtime::send(
                projectId: 'console',
                payload: $index->getArrayCopy(),
                events: $events,
                channels: $target['channels'],
                roles: $target['roles'],
                options: [
                    'projectId' => $projectId,
                    'collectionId' => $collection->getId()
                ]
            );
        }

        $dbForProject->deleteCachedDocument('collections', $collection->getId());
    }
}
