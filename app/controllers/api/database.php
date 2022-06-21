<?php

use Utopia\App;
use Appwrite\Event\Delete;
use Appwrite\Extend\Exception;
use Utopia\Audit\Audit;
use Utopia\Validator\Boolean;
use Utopia\Validator\FloatValidator;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\QueryValidator;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\UID;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Locale\Locale;
use Appwrite\Auth\Auth;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\IP;
use Appwrite\Network\Validator\URL;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries as QueriesValidator;
use Appwrite\Utopia\Database\Validator\OrderAttributes;
use Appwrite\Utopia\Response;
use Appwrite\Detector\Detector;
use Appwrite\Event\Audit as EventAudit;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Event;
use Appwrite\Stats\Stats;
use MaxMind\Db\Reader;

/**
 * Create attribute of varying type
 *
 *
 * @return Document Newly created attribute document
 */
function createAttribute(string $collectionId, Document $attribute, Response $response, Database $dbForProject, EventDatabase $database, EventAudit $audits, Event $events, Stats $usage): Document
{
    $key = $attribute->getAttribute('key');
    $type = $attribute->getAttribute('type', '');
    $size = $attribute->getAttribute('size', 0);
    $required = $attribute->getAttribute('required', true);
    $signed = $attribute->getAttribute('signed', true); // integers are signed by default
    $array = $attribute->getAttribute('array', false);
    $format = $attribute->getAttribute('format', '');
    $formatOptions = $attribute->getAttribute('formatOptions', []);
    $filters = $attribute->getAttribute('filters', []); // filters are hidden from the endpoint
    $default = $attribute->getAttribute('default');

    $collection = $dbForProject->getDocument('collections', $collectionId);

    if ($collection->isEmpty()) {
        throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
    }

    if (!empty($format)) {
        if (!Structure::hasFormat($format, $type)) {
            throw new Exception("Format {$format} not available for {$type} attributes.", 400, Exception::ATTRIBUTE_FORMAT_UNSUPPORTED);
        }
    }

    // Must throw here since dbForProject->createAttribute is performed by db worker
    if ($required && $default) {
        throw new Exception('Cannot set default value for required attribute', 400, Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED);
    }

    if ($array && $default) {
        throw new Exception('Cannot set default value for array attributes', 400, Exception::ATTRIBUTE_DEFAULT_UNSUPPORTED);
    }

    try {
        $attribute = new Document([
            '$id' => $collectionId . '_' . $key,
            'key' => $key,
            'collectionId' => $collectionId,
            'type' => $type,
            'status' => 'processing', // processing, available, failed, deleting, stuck
            'size' => $size,
            'required' => $required,
            'signed' => $signed,
            'default' => $default,
            'array' => $array,
            'format' => $format,
            'formatOptions' => $formatOptions,
            'filters' => $filters,
        ]);

        $dbForProject->checkAttribute($collection, $attribute);
        $attribute = $dbForProject->createDocument('attributes', $attribute);
    } catch (DuplicateException $exception) {
        throw new Exception('Attribute already exists', 409, Exception::ATTRIBUTE_ALREADY_EXISTS);
    } catch (LimitException $exception) {
        throw new Exception('Attribute limit exceeded', 400, Exception::ATTRIBUTE_LIMIT_EXCEEDED);
    }

    $dbForProject->deleteCachedDocument('collections', $collectionId);
    $dbForProject->deleteCachedCollection('collection_' . $collection->getInternalId());

    $usage->setParam('database.collections.update', 1);

    $database
        ->setType(DATABASE_TYPE_CREATE_ATTRIBUTE)
        ->setCollection($collection)
        ->setDocument($attribute)
    ;

    $events
        ->setContext($collection)
        ->setParam('collectionId', $collection->getId())
        ->setParam('attributeId', $attribute->getId())
    ;

    $audits
        ->setResource('collection/' . $collectionId)
        ->setPayload($attribute->getArrayCopy())
    ;

    $response->setStatusCode(Response::STATUS_CODE_CREATED);

    return $attribute;
}

App::post('/v1/database/collections')
    ->desc('Create Collection')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createCollection')
    ->label('sdk.description', '/docs/references/database/create-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('collectionId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('permission', null, new WhiteList(['document', 'collection']), 'Specifies the permissions model used in this collection, which accepts either \'collection\' or \'document\'. For \'collection\' level permission, the permissions specified in read and write params are applied to all documents in the collection. For \'document\' level permissions, read and write permissions are specified in each document. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default no user is granted with any read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.')
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default no user is granted with any write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $name, ?string $permission, ?array $read, ?array $write, Response $response, Database $dbForProject, EventAudit $audits, Stats $usage, Event $events) {

        $collectionId = $collectionId == 'unique()' ? $dbForProject->getId() : $collectionId;

        try {
            $dbForProject->createDocument('collections', new Document([
                '$id' => $collectionId,
                '$read' => $read ?? [], // Collection permissions for collection documents (based on permission model)
                '$write' => $write ?? [], // Collection permissions for collection documents (based on permission model)
                'permission' => $permission, // Permissions model type (document vs collection)
                'dateCreated' => time(),
                'dateUpdated' => time(),
                'enabled' => true,
                'name' => $name,
                'search' => implode(' ', [$collectionId, $name]),
            ]));
            $collection = $dbForProject->getDocument('collections', $collectionId);

            $dbForProject->createCollection('collection_' . $collection->getInternalId());
        } catch (DuplicateException $th) {
            throw new Exception('Collection already exists', 409, Exception::COLLECTION_ALREADY_EXISTS);
        } catch (LimitException $th) {
            throw new Exception('Collection limit exceeded', 400, Exception::COLLECTION_LIMIT_EXCEEDED);
        }

        $audits
            ->setResource('collection/' . $collectionId)
            ->setPayload($collection->getArrayCopy())
        ;

        $events->setParam('collectionId', $collection->getId());
        $usage->setParam('database.collections.create', 1);

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::get('/v1/database/collections')
    ->desc('List Collections')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listCollections')
    ->label('sdk.description', '/docs/references/database/list-collections.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of collection to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this param to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the collection used as the starting point for the query, excluding the collection itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (string $search, int $limit, int $offset, string $cursor, string $cursorDirection, string $orderType, Response $response, Database $dbForProject, Stats $usage) {

        if (!empty($cursor)) {
            $cursorCollection = $dbForProject->getDocument('collections', $cursor);

            if ($cursorCollection->isEmpty()) {
                throw new Exception("Collection '{$cursor}' for the 'cursor' value not found.", 400, Exception::GENERAL_CURSOR_NOT_FOUND);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $usage->setParam('database.collections.read', 1);

        $response->dynamic(new Document([
            'collections' => $dbForProject->find('collections', $queries, $limit, $offset, [], [$orderType], $cursorCollection ?? null, $cursorDirection),
            'total' => $dbForProject->count('collections', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_COLLECTION_LIST);
    });

App::get('/v1/database/collections/:collectionId')
    ->desc('Get Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getCollection')
    ->label('sdk.description', '/docs/references/database/get-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (string $collectionId, Response $response, Database $dbForProject, Stats $usage) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $usage->setParam('database.collections.read', 1);

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::get('/v1/database/usage')
    ->desc('Get usage stats for the database')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_DATABASE)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $range, Response $response, Database $dbForProject) {

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                'database.documents.count',
                'database.collections.count',
                'database.collections.create',
                'database.collections.read',
                'database.collections.update',
                'database.collections.delete',
                'database.documents.create',
                'database.documents.read',
                'database.documents.update',
                'database.documents.delete'
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $limit, 0, ['time'], [Database::ORDER_DESC]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '30m' => 1800,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => ($stats[$metric][$last]['date'] ?? \time()) - $diff, // time of last metric minus period
                        ];
                        $backfill--;
                    }
                    // TODO@kodumbeats explore performance if query is ordered by time ASC
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'documentsCount' => $stats["database.documents.count"],
                'collectionsCount' => $stats["database.collections.count"],
                'documentsCreate' =>  $stats["database.documents.create"],
                'documentsRead' =>  $stats["database.documents.read"],
                'documentsUpdate' => $stats["database.documents.update"],
                'documentsDelete' => $stats["database.documents.delete"],
                'collectionsCreate' => $stats["database.collections.create"],
                'collectionsRead' =>  $stats["database.collections.read"],
                'collectionsUpdate' => $stats["database.collections.update"],
                'collectionsDelete' => $stats["database.collections.delete"],
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_DATABASE);
    });

App::get('/v1/database/:collectionId/usage')
    ->desc('Get usage stats for a collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getCollectionUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_COLLECTION)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $range, string $collectionId, Response $response, Database $dbForProject) {

        $collectionDocument = $dbForProject->getDocument('collections', $collectionId);
        $collection = $dbForProject->getCollection('collection_' . $collectionDocument->getInternalId());

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                "database.collections.$collectionId.documents.count",
                "database.collections.$collectionId.documents.create",
                "database.collections.$collectionId.documents.read",
                "database.collections.$collectionId.documents.update",
                "database.collections.$collectionId.documents.delete",
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $limit, 0, ['time'], [Database::ORDER_DESC]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '30m' => 1800,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => ($stats[$metric][$last]['date'] ?? \time()) - $diff, // time of last metric minus period
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'documentsCount' => $stats["database.collections.$collectionId.documents.count"],
                'documentsCreate' => $stats["database.collections.$collectionId.documents.create"],
                'documentsRead' => $stats["database.collections.$collectionId.documents.read"],
                'documentsUpdate' =>  $stats["database.collections.$collectionId.documents.update"],
                'documentsDelete' =>  $stats["database.collections.$collectionId.documents.delete"]
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_COLLECTION);
    });

App::get('/v1/database/collections/:collectionId/logs')
    ->desc('List Collection Logs')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listCollectionLogs')
    ->label('sdk.description', '/docs/references/database/get-collection-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('limit', 25, new Range(0, 100), 'Maximum number of logs to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $collectionId, int $limit, int $offset, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $collectionDocument = $dbForProject->getDocument('collections', $collectionId);
        $collection = $dbForProject->getCollection('collection_' . $collectionDocument->getInternalId());

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $audit = new Audit($dbForProject);
        $resource = 'collection/' . $collectionId;
        $logs = $audit->getLogsByResource($resource, $limit, $offset);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::put('/v1/database/collections/:collectionId')
    ->desc('Update Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'collections.[collectionId].update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'updateCollection')
    ->label('sdk.description', '/docs/references/database/update-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COLLECTION)
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('name', null, new Text(128), 'Collection name. Max length: 128 chars.')
    ->param('permission', null, new WhiteList(['document', 'collection']), 'Permissions type model to use for reading documents in this collection. You can use collection-level permission set once on the collection using the `read` and `write` params, or you can set document-level permission where each document read and write params will decide who has access to read and write to each document individually. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default inherits the existing read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default inherits the existing write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('enabled', true, new Boolean(), 'Is collection enabled?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $name, string $permission, ?array $read, ?array $write, bool $enabled, Response $response, Database $dbForProject, EventAudit $audits, Stats $usage, Event $events) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $read ??= $collection->getRead() ?? []; // By default inherit read permissions
        $write ??= $collection->getWrite() ?? []; // By default inherit write permissions
        $enabled ??= $collection->getAttribute('enabled', true);

        try {
            $collection = $dbForProject->updateDocument('collections', $collectionId, $collection
                ->setAttribute('$write', $write)
                ->setAttribute('$read', $read)
                ->setAttribute('name', $name)
                ->setAttribute('permission', $permission)
                ->setAttribute('dateUpdated', time())
                ->setAttribute('enabled', $enabled)
                ->setAttribute('search', implode(' ', [$collectionId, $name])));
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
        } catch (StructureException $exception) {
            throw new Exception('Bad structure. ' . $exception->getMessage(), 400, Exception::DOCUMENT_INVALID_STRUCTURE);
        }

        $audits
            ->setResource('collection/' . $collectionId)
            ->setPayload($collection->getArrayCopy())
        ;

        $usage->setParam('database.collections.update', 1);
        $events->setParam('collectionId', $collection->getId());

        $response->dynamic($collection, Response::MODEL_COLLECTION);
    });

App::delete('/v1/database/collections/:collectionId')
    ->desc('Delete Collection')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'collections.[collectionId].delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteCollection')
    ->label('sdk.description', '/docs/references/database/delete-collection.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('audits')
    ->inject('deletes')
    ->inject('usage')
    ->action(function (string $collectionId, Response $response, Database $dbForProject, Event $events, EventAudit $audits, Delete $deletes, Stats $usage) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('collections', $collectionId)) {
            throw new Exception('Failed to remove collection from DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $dbForProject->deleteCachedCollection('collection_' . $collection->getInternalId());

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($collection)
        ;

        $events
            ->setParam('collectionId', $collection->getId())
            ->setPayload($response->output($collection, Response::MODEL_COLLECTION))
        ;

        $audits
            ->setResource('collection/' . $collectionId)
            ->setPayload($collection->getArrayCopy())
        ;

        $usage->setParam('database.collections.delete', 1);

        $response->noContent();
    });

App::post('/v1/database/collections/:collectionId/attributes/string')
    ->desc('Create String Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createStringAttribute')
    ->label('sdk.description', '/docs/references/database/create-string-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_STRING)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('size', null, new Range(1, APP_DATABASE_ATTRIBUTE_STRING_MAX_LENGTH, Range::TYPE_INTEGER), 'Attribute size for text attributes, in number of characters.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $key, ?int $size, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, EventAudit $audits, Stats $usage, Event $events) {

        // Ensure attribute default is within required size
        $validator = new Text($size);
        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception($validator->getDescription(), 400, Exception::ATTRIBUTE_VALUE_INVALID);
        }

        $attribute = createAttribute($collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForProject, $database, $audits, $events, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_STRING);
    });

App::post('/v1/database/collections/:collectionId/attributes/email')
    ->desc('Create Email Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createEmailAttribute')
    ->label('sdk.description', '/docs/references/database/create-email-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_EMAIL)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Email(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, Event $audits, Stats $usage, Event $events) {

        $attribute = createAttribute($collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 254,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_EMAIL,
        ]), $response, $dbForProject, $database, $audits, $events, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_EMAIL);
    });

App::post('/v1/database/collections/:collectionId/attributes/enum')
    ->desc('Create Enum Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createEnumAttribute')
    ->label('sdk.description', '/docs/references/database/create-attribute-enum.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_ENUM)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('elements', [], new ArrayList(new Text(1024), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of elements in enumerated type. Uses length of longest element to determine size. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' elements are allowed, each 1024 characters long.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Text(0), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $key, array $elements, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, EventAudit $audits, Stats $usage, Event $events) {

        // use length of longest string as attribute size
        $size = 0;
        foreach ($elements as $element) {
            $length = \strlen($element);
            if ($length === 0) {
                throw new Exception('Each enum element must not be empty', 400, Exception::ATTRIBUTE_VALUE_INVALID);
            }
            $size = ($length > $size) ? $length : $size;
        }

        if (!is_null($default) && !in_array($default, $elements)) {
            throw new Exception('Default value not found in elements', 400, Exception::ATTRIBUTE_VALUE_INVALID);
        }

        $attribute = createAttribute($collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_ENUM,
            'formatOptions' => ['elements' => $elements],
        ]), $response, $dbForProject, $database, $audits, $events, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_ENUM);
    });

App::post('/v1/database/collections/:collectionId/attributes/ip')
    ->desc('Create IP Address Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createIpAttribute')
    ->label('sdk.description', '/docs/references/database/create-ip-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_IP)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new IP(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, EventAudit $audits, Stats $usage, Event $events) {

        $attribute = createAttribute($collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 39,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_IP,
        ]), $response, $dbForProject, $database, $audits, $events, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_IP);
    });

App::post('/v1/database/collections/:collectionId/attributes/url')
    ->desc('Create URL Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createUrlAttribute')
    ->label('sdk.description', '/docs/references/database/create-url-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_URL)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new URL(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $key, ?bool $required, ?string $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, EventAudit $audits, Stats $usage, Event $events) {

        $attribute = createAttribute($collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_STRING,
            'size' => 2000,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_URL,
        ]), $response, $dbForProject, $database, $audits, $events, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_URL);
    });

App::post('/v1/database/collections/:collectionId/attributes/integer')
    ->desc('Create Integer Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createIntegerAttribute')
    ->label('sdk.description', '/docs/references/database/create-integer-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_INTEGER)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new Integer(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new Integer(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new Integer(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $key, ?bool $required, ?int $min, ?int $max, ?int $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, EventAudit $audits, Stats $usage, Event $events) {

        // Ensure attribute default is within range
        $min = (is_null($min)) ? PHP_INT_MIN : \intval($min);
        $max = (is_null($max)) ? PHP_INT_MAX : \intval($max);

        if ($min > $max) {
            throw new Exception('Minimum value must be lesser than maximum value', 400, Exception::ATTRIBUTE_VALUE_INVALID);
        }

        $validator = new Range($min, $max, Database::VAR_INTEGER);

        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception($validator->getDescription(), 400, Exception::ATTRIBUTE_VALUE_INVALID);
        }

        $size = $max > 2147483647 ? 8 : 4; // Automatically create BigInt depending on max value

        $attribute = createAttribute($collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_INTEGER,
            'size' => $size,
            'required' => $required,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_INT_RANGE,
            'formatOptions' => [
                'min' => $min,
                'max' => $max,
            ],
        ]), $response, $dbForProject, $database, $audits, $events, $usage);

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \intval($formatOptions['min']));
            $attribute->setAttribute('max', \intval($formatOptions['max']));
        }

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_INTEGER);
    });

App::post('/v1/database/collections/:collectionId/attributes/float')
    ->desc('Create Float Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createFloatAttribute')
    ->label('sdk.description', '/docs/references/database/create-float-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_FLOAT)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('min', null, new FloatValidator(), 'Minimum value to enforce on new documents', true)
    ->param('max', null, new FloatValidator(), 'Maximum value to enforce on new documents', true)
    ->param('default', null, new FloatValidator(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function (string $collectionId, string $key, ?bool $required, ?float $min, ?float $max, ?float $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, EventAudit $audits, Event $events, Stats $usage) {

        // Ensure attribute default is within range
        $min = (is_null($min)) ? -PHP_FLOAT_MAX : \floatval($min);
        $max = (is_null($max)) ? PHP_FLOAT_MAX : \floatval($max);

        if ($min > $max) {
            throw new Exception('Minimum value must be lesser than maximum value', 400, Exception::ATTRIBUTE_VALUE_INVALID);
        }

        // Ensure default value is a float
        if (!is_null($default)) {
            $default = \floatval($default);
        }

        $validator = new Range($min, $max, Database::VAR_FLOAT);

        if (!is_null($default) && !$validator->isValid($default)) {
            throw new Exception($validator->getDescription(), 400, Exception::ATTRIBUTE_VALUE_INVALID);
        }

        $attribute = createAttribute($collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_FLOAT,
            'required' => $required,
            'size' => 0,
            'default' => $default,
            'array' => $array,
            'format' => APP_DATABASE_ATTRIBUTE_FLOAT_RANGE,
            'formatOptions' => [
                'min' => $min,
                'max' => $max,
            ],
        ]), $response, $dbForProject, $database, $audits, $events, $usage);

        $formatOptions = $attribute->getAttribute('formatOptions', []);

        if (!empty($formatOptions)) {
            $attribute->setAttribute('min', \floatval($formatOptions['min']));
            $attribute->setAttribute('max', \floatval($formatOptions['max']));
        }

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_FLOAT);
    });

App::post('/v1/database/collections/:collectionId/attributes/boolean')
    ->desc('Create Boolean Attribute')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].attributes.[attributeId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.namespace', 'database')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'createBooleanAttribute')
    ->label('sdk.description', '/docs/references/database/create-boolean-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_BOOLEAN)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->param('required', null, new Boolean(), 'Is attribute required?')
    ->param('default', null, new Boolean(), 'Default value for attribute when not provided. Cannot be set when attribute is required.', true)
    ->param('array', false, new Boolean(), 'Is attribute an array?', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $key, ?bool $required, ?bool $default, bool $array, Response $response, Database $dbForProject, EventDatabase $database, EventAudit $audits, Stats $usage, Event $events) {

        $attribute = createAttribute($collectionId, new Document([
            'key' => $key,
            'type' => Database::VAR_BOOLEAN,
            'size' => 0,
            'required' => $required,
            'default' => $default,
            'array' => $array,
        ]), $response, $dbForProject, $database, $audits, $events, $usage);

        $response->dynamic($attribute, Response::MODEL_ATTRIBUTE_BOOLEAN);
    });

App::get('/v1/database/collections/:collectionId/attributes')
    ->desc('List Attributes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listAttributes')
    ->label('sdk.description', '/docs/references/database/list-attributes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ATTRIBUTE_LIST)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (string $collectionId, Response $response, Database $dbForProject, Stats $usage) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $attributes = $collection->getAttribute('attributes');

        $usage->setParam('database.collections.read', 1);

        $response->dynamic(new Document([
            'total' => \count($attributes),
            'attributes' => $attributes
        ]), Response::MODEL_ATTRIBUTE_LIST);
    });

App::get('/v1/database/collections/:collectionId/attributes/:key')
    ->desc('Get Attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getAttribute')
    ->label('sdk.description', '/docs/references/database/get-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', [
        Response::MODEL_ATTRIBUTE_BOOLEAN,
        Response::MODEL_ATTRIBUTE_INTEGER,
        Response::MODEL_ATTRIBUTE_FLOAT,
        Response::MODEL_ATTRIBUTE_EMAIL,
        Response::MODEL_ATTRIBUTE_ENUM,
        Response::MODEL_ATTRIBUTE_URL,
        Response::MODEL_ATTRIBUTE_IP,
        Response::MODEL_ATTRIBUTE_STRING,])// needs to be last, since its condition would dominate any other string attribute
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (string $collectionId, string $key, Response $response, Database $dbForProject, Stats $usage) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $attribute = $dbForProject->getDocument('attributes', $collectionId . '_' . $key);

        if ($attribute->isEmpty()) {
            throw new Exception('Attribute not found', 404, Exception::ATTRIBUTE_NOT_FOUND);
        }

        // Select response model based on type and format
        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');

        $model = match ($type) {
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
            Database::VAR_STRING => match ($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                default => Response::MODEL_ATTRIBUTE_STRING,
            },
            default => Response::MODEL_ATTRIBUTE,
        };

        $usage->setParam('database.collections.read', 1);

        $response->dynamic($attribute, $model);
    });

App::delete('/v1/database/collections/:collectionId/attributes/:key')
    ->desc('Delete Attribute')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'collections.[collectionId].attributes.[attributeId].delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteAttribute')
    ->label('sdk.description', '/docs/references/database/delete-attribute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Attribute Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('events')
    ->inject('audits')
    ->inject('usage')
    ->action(function (string $collectionId, string $key, Response $response, Database $dbForProject, EventDatabase $database, Event $events, EventAudit $audits, Stats $usage) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $attribute = $dbForProject->getDocument('attributes', $collectionId . '_' . $key);

        if ($attribute->isEmpty()) {
            throw new Exception('Attribute not found', 404, Exception::ATTRIBUTE_NOT_FOUND);
        }

        // Only update status if removing available attribute
        if ($attribute->getAttribute('status' === 'available')) {
            $attribute = $dbForProject->updateDocument('attributes', $attribute->getId(), $attribute->setAttribute('status', 'deleting'));
        }

        $dbForProject->deleteCachedDocument('collections', $collectionId);
        $dbForProject->deleteCachedCollection('collection_' . $collection->getInternalId());

        $database
            ->setType(DATABASE_TYPE_DELETE_ATTRIBUTE)
            ->setCollection($collection)
            ->setDocument($attribute)
        ;

        $usage->setParam('database.collections.update', 1);

        // Select response model based on type and format
        $type = $attribute->getAttribute('type');
        $format = $attribute->getAttribute('format');

        $model = match ($type) {
            Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
            Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
            Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
            Database::VAR_STRING => match ($format) {
                APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                default => Response::MODEL_ATTRIBUTE_STRING,
            },
            default => Response::MODEL_ATTRIBUTE,
        };

        $events
            ->setParam('collectionId', $collection->getId())
            ->setParam('attributeId', $attribute->getId())
            ->setContext($collection)
            ->setPayload($response->output($attribute, $model))
        ;

        $audits
            ->setResource('collection/' . $collectionId)
            ->setPayload($attribute->getArrayCopy())
        ;

        $response->noContent();
    });

App::post('/v1/database/collections/:collectionId/indexes')
    ->desc('Create Index')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].indexes.[indexId].create')
    ->label('scope', 'collections.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createIndex')
    ->label('sdk.description', '/docs/references/database/create-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', null, new Key(), 'Index Key.')
    ->param('type', null, new WhiteList([Database::INDEX_KEY, Database::INDEX_FULLTEXT, Database::INDEX_UNIQUE, Database::INDEX_SPATIAL, Database::INDEX_ARRAY]), 'Index type.')
    ->param('attributes', null, new ArrayList(new Key(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of attributes to index. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' attributes are allowed, each 32 characters long.')
    ->param('orders', [], new ArrayList(new WhiteList(['ASC', 'DESC'], false, Database::VAR_STRING), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of index orders. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' orders are allowed.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $collectionId, string $key, string $type, array $attributes, array $orders, Response $response, Database $dbForProject, EventDatabase $database, EventAudit $audits, Stats $usage, Event $events) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $count = $dbForProject->count('indexes', [
            new Query('collectionId', Query::TYPE_EQUAL, [$collectionId])
        ], 61);

        $limit = 64 - MariaDB::getNumberOfDefaultIndexes();

        if ($count >= $limit) {
            throw new Exception('Index limit exceeded', 400, Exception::INDEX_LIMIT_EXCEEDED);
        }

        // Convert Document[] to array of attribute metadata
        $oldAttributes = \array_map(fn ($a) => $a->getArrayCopy(), $collection->getAttribute('attributes'));

        // lengths hidden by default
        $lengths = [];

        foreach ($attributes as $i => $attribute) {
            // find attribute metadata in collection document
            $attributeIndex = \array_search($attribute, array_column($oldAttributes, 'key'));

            if ($attributeIndex === false) {
                throw new Exception('Unknown attribute: ' . $attribute, 400, Exception::ATTRIBUTE_UNKNOWN);
            }

            $attributeStatus = $oldAttributes[$attributeIndex]['status'];
            $attributeType = $oldAttributes[$attributeIndex]['type'];
            $attributeSize = $oldAttributes[$attributeIndex]['size'];

            // ensure attribute is available
            if ($attributeStatus !== 'available') {
                throw new Exception('Attribute not available: ' . $oldAttributes[$attributeIndex]['key'], 400, Exception::ATTRIBUTE_NOT_AVAILABLE);
            }

            // set attribute size as index length only for strings
            $lengths[$i] = ($attributeType === Database::VAR_STRING) ? $attributeSize : null;
        }

        try {
            $index = $dbForProject->createDocument('indexes', new Document([
                '$id' => $collectionId . '_' . $key,
                'key' => $key,
                'status' => 'processing', // processing, available, failed, deleting, stuck
                'collectionId' => $collectionId,
                'type' => $type,
                'attributes' => $attributes,
                'lengths' => $lengths,
                'orders' => $orders,
            ]));
        } catch (DuplicateException $th) {
            throw new Exception('Index already exists', 409, Exception::INDEX_ALREADY_EXISTS);
        }

        $dbForProject->deleteCachedDocument('collections', $collectionId);

        $database
            ->setType(DATABASE_TYPE_CREATE_INDEX)
            ->setCollection($collection)
            ->setDocument($index)
        ;

        $usage->setParam('database.collections.update', 1);

        $events
            ->setParam('collectionId', $collection->getId())
            ->setParam('indexId', $index->getId())
            ->setContext($collection)
        ;

        $audits
            ->setResource('collection/' . $collection->getId())
            ->setPayload($index->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($index, Response::MODEL_INDEX);
    });

App::get('/v1/database/collections/:collectionId/indexes')
    ->desc('List Indexes')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listIndexes')
    ->label('sdk.description', '/docs/references/database/list-indexes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX_LIST)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (string $collectionId, Response $response, Database $dbForProject, Stats $usage) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $indexes = $collection->getAttribute('indexes');

        $usage->setParam('database.collections.read', 1);

        $response->dynamic(new Document([
            'total' => \count($indexes),
            'indexes' => $indexes,
        ]), Response::MODEL_INDEX_LIST);
    });

App::get('/v1/database/collections/:collectionId/indexes/:key')
    ->desc('Get Index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getIndex')
    ->label('sdk.description', '/docs/references/database/get-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_INDEX)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', null, new Key(), 'Index Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (string $collectionId, string $key, Response $response, Database $dbForProject, Stats $usage) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $indexes = $collection->getAttribute('indexes');

        // Search for index
        $indexIndex = array_search($key, array_column($indexes, 'key'));

        if ($indexIndex === false) {
            throw new Exception('Index not found', 404, Exception::INDEX_NOT_FOUND);
        }

        $index = new Document([\array_merge($indexes[$indexIndex], [
            'collectionId' => $collectionId,
        ])]);

        $usage->setParam('database.collections.read', 1);

        $response->dynamic($index, Response::MODEL_INDEX);
    });

App::delete('/v1/database/collections/:collectionId/indexes/:key')
    ->desc('Delete Index')
    ->groups(['api', 'database'])
    ->label('scope', 'collections.write')
    ->label('event', 'collections.[collectionId].indexes.[indexId].delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteIndex')
    ->label('sdk.description', '/docs/references/database/delete-index.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', null, new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('key', '', new Key(), 'Index Key.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('database')
    ->inject('events')
    ->inject('audits')
    ->inject('usage')
    ->action(function (string $collectionId, string $key, Response $response, Database $dbForProject, EventDatabase $database, Event $events, EventAudit $audits, Stats $usage) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $index = $dbForProject->getDocument('indexes', $collectionId . '_' . $key);

        if (empty($index->getId())) {
            throw new Exception('Index not found', 404, Exception::INDEX_NOT_FOUND);
        }

        // Only update status if removing available index
        if ($index->getAttribute('status') === 'available') {
            $index = $dbForProject->updateDocument('indexes', $index->getId(), $index->setAttribute('status', 'deleting'));
        }

        $dbForProject->deleteCachedDocument('collections', $collectionId);

        $database
            ->setType(DATABASE_TYPE_DELETE_INDEX)
            ->setCollection($collection)
            ->setDocument($index)
        ;

        $usage->setParam('database.collections.update', 1);

        $events
            ->setParam('collectionId', $collection->getId())
            ->setParam('indexId', $index->getId())
            ->setContext($collection)
            ->setPayload($response->output($index, Response::MODEL_INDEX))
        ;

        $audits
            ->setResource('collection/' . $collection->getId())
            ->setPayload($index->getArrayCopy())
        ;

        $response->noContent();
    });

App::post('/v1/database/collections/:collectionId/documents')
    ->desc('Create Document')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].documents.[documentId].create')
    ->label('scope', 'documents.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'createDocument')
    ->label('sdk.description', '/docs/references/database/create-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('documentId', '', new CustomId(), 'Document ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('collectionId', null, new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection). Make sure to define attributes before creating documents.')
    ->param('data', [], new JSON(), 'Document data as JSON object.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->inject('mode')
    ->action(function (string $documentId, string $collectionId, string|array $data, ?array $read, ?array $write, Response $response, Database $dbForProject, Document $user, EventAudit $audits, Stats $usage, Event $events, string $mode) {

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception('Missing payload', 400, Exception::DOCUMENT_MISSING_PAYLOAD);
        }

        if (isset($data['$id'])) {
            throw new Exception('$id is not allowed for creating new documents, try update instead', 400, Exception::DOCUMENT_INVALID_STRUCTURE);
        }

        /**
         * Skip Authorization to get the collection. Needed in case of empty permissions for document level permissions.
         *
         * @var Document $collection
         */
        $collection = Authorization::skip(fn() => $dbForProject->getDocument('collections', $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
            }
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('write');
            if (!$validator->isValid($collection->getWrite())) {
                throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
            }
        }

        $data['$collection'] = $collection->getId(); // Adding this param to make API easier for developers
        $data['$id'] = $documentId == 'unique()' ? $dbForProject->getId() : $documentId;
        $data['$read'] = (is_null($read) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $read ?? []; //  By default set read permissions for user
        $data['$write'] = (is_null($write) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $write ?? []; //  By default set write permissions for user

        // Users can only add their roles to documents, API keys and Admin users can add any
        $roles = Authorization::getRoles();

        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles)) {
            foreach ($data['$read'] as $read) {
                if (!Authorization::isRole($read)) {
                    // TODO: Isn't this a 401: Unauthorized Error ?
                    throw new Exception('Read permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
                }
            }
            foreach ($data['$write'] as $write) {
                if (!Authorization::isRole($write)) {
                    throw new Exception('Write permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
                }
            }
        }

        try {
            if ($collection->getAttribute('permission') === 'collection') {
                /** @var Document $document */
                $document = Authorization::skip(fn() => $dbForProject->createDocument('collection_' . $collection->getInternalId(), new Document($data)));
            } else {
                $document = $dbForProject->createDocument('collection_' . $collection->getInternalId(), new Document($data));
            }
            $document->setAttribute('$collection', $collectionId);
        } catch (StructureException $exception) {
            throw new Exception($exception->getMessage(), 400, Exception::DOCUMENT_INVALID_STRUCTURE);
        } catch (DuplicateException $exception) {
            throw new Exception('Document already exists', 409, Exception::DOCUMENT_ALREADY_EXISTS);
        }

        $events
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext($collection)
        ;

        $usage
            ->setParam('database.documents.create', 1)
            ->setParam('collectionId', $collectionId)
        ;

        $audits
            ->setResource('document/' . $document->getId())
            ->setPayload($document->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::get('/v1/database/collections/:collectionId/documents')
    ->desc('List Documents')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listDocuments')
    ->label('sdk.description', '/docs/references/database/list-documents.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT_LIST)
    ->param('collectionId', '', new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('queries', [], new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/database#querying-documents). Maximum of 100 queries are allowed, each 128 characters long.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of documents to return in response. By default will return maximum 25 results. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the document used as the starting point for the query, excluding the document itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderAttributes', [], new ArrayList(new Text(128), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of attributes used to sort results. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' order attributes are allowed, each 128 characters long.', true)
    ->param('orderTypes', [], new ArrayList(new WhiteList(['DESC', 'ASC'], true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of order directions for sorting attribtues. Possible values are DESC for descending order, or ASC for ascending order. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' order types are allowed.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('mode')
    ->action(function (string $collectionId, array $queries, int $limit, int $offset, string $cursor, string $cursorDirection, array $orderAttributes, array $orderTypes, Response $response, Database $dbForProject, Stats $usage, string $mode) {

        /**
         * Skip Authorization to get the collection. Needed in case of empty permissions for document level permissions.
         *
         * @var Utopia\Database\Document $collection
         */
        $collection = Authorization::skip(fn() => $dbForProject->getDocument('collections', $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
            }
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('read');
            if (!$validator->isValid($collection->getRead())) {
                throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
            }
        }

        $queries = \array_map(function ($query) {
            $query = Query::parse($query);

            if (\count($query->getValues()) > 100) {
                throw new Exception("You cannot use more than 100 query values on attribute '{$query->getAttribute()}'", 400, Exception::GENERAL_QUERY_LIMIT_EXCEEDED);
            }

            return $query;
        }, $queries);

        if (!empty($orderAttributes)) {
            $validator = new OrderAttributes($collection->getAttribute('attributes', []), $collection->getAttribute('indexes', []), true);
            if (!$validator->isValid($orderAttributes)) {
                throw new Exception($validator->getDescription(), 400, Exception::GENERAL_QUERY_INVALID);
            }
        }

        if (!empty($queries)) {
            $validator = new QueriesValidator(new QueryValidator($collection->getAttribute('attributes', [])), $collection->getAttribute('indexes', []), true);
            if (!$validator->isValid($queries)) {
                throw new Exception($validator->getDescription(), 400, Exception::GENERAL_QUERY_INVALID);
            }
        }

        $cursorDocument = null;
        if (!empty($cursor)) {
            $cursorDocument = $collection->getAttribute('permission') === 'collection'
                ? Authorization::skip(fn () => $dbForProject->getDocument('collection_' . $collection->getInternalId(), $cursor))
                : $dbForProject->getDocument('collection_' . $collection->getInternalId(), $cursor);

            if ($cursorDocument->isEmpty()) {
                throw new Exception("Document '{$cursor}' for the 'cursor' value not found.", 400, Exception::GENERAL_CURSOR_NOT_FOUND);
            }
        }

        if ($collection->getAttribute('permission') === 'collection') {
            /** @var Document[] $documents */
            $documents = Authorization::skip(fn() => $dbForProject->find('collection_' . $collection->getInternalId(), $queries, $limit, $offset, $orderAttributes, $orderTypes, $cursorDocument ?? null, $cursorDirection));
            $total = Authorization::skip(fn() => $dbForProject->count('collection_' . $collection->getInternalId(), $queries, APP_LIMIT_COUNT));
        } else {
            $documents = $dbForProject->find('collection_' . $collection->getInternalId(), $queries, $limit, $offset, $orderAttributes, $orderTypes, $cursorDocument ?? null, $cursorDirection);
            $total = $dbForProject->count('collection_' . $collection->getInternalId(), $queries, APP_LIMIT_COUNT);
        }

        /**
         * Reset $collection attribute to remove prefix.
         */
        $documents = array_map(fn(Document $document) => $document->setAttribute('$collection', $collectionId), $documents);

        $usage
            ->setParam('database.documents.read', 1)
            ->setParam('collectionId', $collectionId)
        ;

        $response->dynamic(new Document([
            'total' => $total,
            'documents' => $documents,
        ]), Response::MODEL_DOCUMENT_LIST);
    });

App::get('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Get Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'getDocument')
    ->label('sdk.description', '/docs/references/database/get-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('collectionId', null, new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('mode')
    ->action(function (string $collectionId, string $documentId, Response $response, Database $dbForProject, Stats $usage, string $mode) {

        /**
         * Skip Authorization to get the collection. Needed in case of empty permissions for document level permissions.
         */
        $collection = Authorization::skip(fn() => $dbForProject->getDocument('collections', $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
            }
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('read');
            if (!$validator->isValid($collection->getRead())) {
                throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
            }
        }

        if ($collection->getAttribute('permission') === 'collection') {
            /** @var Document $document */
            $document = Authorization::skip(fn() => $dbForProject->getDocument('collection_' . $collection->getInternalId(), $documentId));
        } else {
            $document = $dbForProject->getDocument('collection_' . $collection->getInternalId(), $documentId);
        }

        /**
         * Reset $collection attribute to remove prefix.
         */
        $document->setAttribute('$collection', $collectionId);

        if ($document->isEmpty()) {
            throw new Exception('No document found', 404, Exception::DOCUMENT_NOT_FOUND);
        }

        $usage
            ->setParam('database.documents.read', 1)
            ->setParam('collectionId', $collectionId)
        ;

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::get('/v1/database/collections/:collectionId/documents/:documentId/logs')
    ->desc('List Document Logs')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'listDocumentLogs')
    ->label('sdk.description', '/docs/references/database/get-document-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('collectionId', '', new UID(), 'Collection ID.')
    ->param('documentId', null, new UID(), 'Document ID.')
    ->param('limit', 25, new Range(0, 100), 'Maximum number of logs to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $collectionId, string $documentId, int $limit, int $offset, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $collection = $dbForProject->getDocument('collections', $collectionId);

        if ($collection->isEmpty()) {
            throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
        }

        $document = $dbForProject->getDocument('collection_' . $collection->getInternalId(), $documentId);

        if ($document->isEmpty()) {
            throw new Exception('No document found', 404, Exception::DOCUMENT_NOT_FOUND);
        }

        $audit = new Audit($dbForProject);
        $resource = 'document/' . $document->getId();
        $logs = $audit->getLogsByResource($resource, $limit, $offset);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }
        $response->dynamic(new Document([
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::patch('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Update Document')
    ->groups(['api', 'database'])
    ->label('event', 'collections.[collectionId].documents.[documentId].update')
    ->label('scope', 'documents.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'updateDocument')
    ->label('sdk.description', '/docs/references/database/update-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOCUMENT)
    ->param('collectionId', null, new UID(), 'Collection ID.')
    ->param('documentId', null, new UID(), 'Document ID.')
    ->param('data', [], new JSON(), 'Document data as JSON object. Include only attribute and value pairs to be updated.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default inherits the existing read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default inherits the existing write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->inject('mode')
    ->action(function (string $collectionId, string $documentId, string|array $data, ?array $read, ?array $write, Response $response, Database $dbForProject, EventAudit $audits, Stats $usage, Event $events, string $mode) {

        /**
         * Skip Authorization to get the collection. Needed in case of empty permissions for document level permissions.
         */
        $collection = Authorization::skip(fn() => $dbForProject->getDocument('collections', $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
            }
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('write');
            if (!$validator->isValid($collection->getWrite())) {
                throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
            }

            $document = Authorization::skip(fn() => $dbForProject->getDocument('collection_' . $collection->getInternalId(), $documentId));
        } else {
            $document = $dbForProject->getDocument('collection_' . $collection->getInternalId(), $documentId);
        }


        if ($document->isEmpty()) {
            throw new Exception('Document not found', 404, Exception::DOCUMENT_NOT_FOUND);
        }

        $data = (\is_string($data)) ? \json_decode($data, true) : $data; // Cast to JSON array

        if (empty($data)) {
            throw new Exception('Missing payload', 400, Exception::DOCUMENT_MISSING_PAYLOAD);
        }

        if (!\is_array($data)) {
            throw new Exception('Data param should be a valid JSON object', 400, Exception::DOCUMENT_INVALID_STRUCTURE);
        }

        $data = \array_merge($document->getArrayCopy(), $data);

        $data['$collection'] = $collection->getId(); // Make sure user don't switch collectionID
        $data['$id'] = $document->getId(); // Make sure user don't switch document unique ID
        $data['$read'] = (is_null($read)) ? ($document->getRead() ?? []) : $read; // By default inherit read permissions
        $data['$write'] = (is_null($write)) ? ($document->getWrite() ?? []) : $write; // By default inherit write permissions

        // Users can only add their roles to documents, API keys and Admin users can add any
        $roles = Authorization::getRoles();

        if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles)) {
            if (!is_null($read)) {
                foreach ($data['$read'] as $read) {
                    if (!Authorization::isRole($read)) {
                        throw new Exception('Read permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
                    }
                }
            }
            if (!is_null($write)) {
                foreach ($data['$write'] as $write) {
                    if (!Authorization::isRole($write)) {
                        throw new Exception('Write permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
                    }
                }
            }
        }

        try {
            if ($collection->getAttribute('permission') === 'collection') {
                /** @var Document $document */
                $document = Authorization::skip(fn() => $dbForProject->updateDocument('collection_' . $collection->getInternalId(), $document->getId(), new Document($data)));
            } else {
                $document = $dbForProject->updateDocument('collection_' . $collection->getInternalId(), $document->getId(), new Document($data));
            }
            /**
             * Reset $collection attribute to remove prefix.
             */
            $document->setAttribute('$collection', $collectionId);
        } catch (AuthorizationException $exception) {
            throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
        } catch (DuplicateException $exception) {
            throw new Exception('Document already exists', 409, Exception::DOCUMENT_ALREADY_EXISTS);
        } catch (StructureException $exception) {
            throw new Exception($exception->getMessage(), 400, Exception::DOCUMENT_INVALID_STRUCTURE);
        }

        $events
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext($collection)
        ;

        $usage
            ->setParam('database.documents.update', 1)
            ->setParam('collectionId', $collectionId)
        ;

        $audits
            ->setResource('document/' . $document->getId())
            ->setPayload($document->getArrayCopy())
        ;

        $response->dynamic($document, Response::MODEL_DOCUMENT);
    });

App::delete('/v1/database/collections/:collectionId/documents/:documentId')
    ->desc('Delete Document')
    ->groups(['api', 'database'])
    ->label('scope', 'documents.write')
    ->label('event', 'collections.[collectionId].documents.[documentId].delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'database')
    ->label('sdk.method', 'deleteDocument')
    ->label('sdk.description', '/docs/references/database/delete-document.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('collectionId', null, new UID(), 'Collection ID. You can create a new collection using the Database service [server integration](https://appwrite.io/docs/server/database#createCollection).')
    ->param('documentId', null, new UID(), 'Document ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('audits')
    ->inject('deletes')
    ->inject('usage')
    ->inject('mode')
    ->action(function (string $collectionId, string $documentId, Response $response, Database $dbForProject, Event $events, EventAudit $audits, Delete $deletes, Stats $usage, string $mode) {

        /**
         * Skip Authorization to get the collection. Needed in case of empty permissions for document level permissions.
         */
        $collection = Authorization::skip(fn() => $dbForProject->getDocument('collections', $collectionId));

        if ($collection->isEmpty() || !$collection->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception('Collection not found', 404, Exception::COLLECTION_NOT_FOUND);
            }
        }

        // Check collection permissions when enforced
        if ($collection->getAttribute('permission') === 'collection') {
            $validator = new Authorization('write');
            if (!$validator->isValid($collection->getWrite())) {
                throw new Exception('Unauthorized permissions', 401, Exception::USER_UNAUTHORIZED);
            }
        }

        if ($collection->getAttribute('permission') === 'collection') {
            /** @var Document $document */
            $document = Authorization::skip(fn() => $dbForProject->getDocument('collection_' . $collection->getInternalId(), $documentId));
        } else {
            $document = $dbForProject->getDocument('collection_' . $collection->getInternalId(), $documentId);
        }

        if ($document->isEmpty()) {
            throw new Exception('No document found', 404, Exception::DOCUMENT_NOT_FOUND);
        }

        if ($collection->getAttribute('permission') === 'collection') {
            Authorization::skip(fn() => $dbForProject->deleteDocument('collection_' . $collection->getInternalId(), $documentId));
        } else {
            $dbForProject->deleteDocument('collection_' . $collection->getInternalId(), $documentId);
        }

        $dbForProject->deleteCachedDocument('collection_' . $collection->getInternalId(), $documentId);

        /**
         * Reset $collection attribute to remove prefix.
         */
        $document->setAttribute('$collection', $collectionId);

        $deletes
            ->setType(DELETE_TYPE_AUDIT)
            ->setDocument($document)
        ;

        $usage
            ->setParam('database.documents.delete', 1)
            ->setParam('collectionId', $collectionId)
        ;

        $events
            ->setParam('collectionId', $collection->getId())
            ->setParam('documentId', $document->getId())
            ->setContext($collection)
            ->setPayload($response->output($document, Response::MODEL_DOCUMENT))
        ;

        $audits
            ->setResource('document/' . $document->getId())
            ->setPayload($document->getArrayCopy())
        ;

        $response->noContent();
    });
