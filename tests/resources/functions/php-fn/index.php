<?php

include './vendor/autoload.php';

use Appwrite\Client;
use Appwrite\Services\Storage;

// $client = new Client();

// $client
    // ->setEndpoint($_ENV['APPWRITE_ENDPOINT']) // Your API Endpoint
    // ->setProject($_ENV['APPWRITE_PROJECT']) // Your project ID
    // ->setKey($_ENV['APPWRITE_SECRET']) // Your secret API key
// ;

// $storage = new Storage($client);

// $result = $storage->getFile($_ENV['APPWRITE_FILEID']);

$output = [
    'APPWRITE_FUNCTION_ID' => $_ENV['APPWRITE_FUNCTION_ID'],
    'APPWRITE_FUNCTION_NAME' => $_ENV['APPWRITE_FUNCTION_NAME'],
    'APPWRITE_FUNCTION_TAG' => $_ENV['APPWRITE_FUNCTION_TAG'],
    'APPWRITE_FUNCTION_TRIGGER' => $_ENV['APPWRITE_FUNCTION_TRIGGER'],
    'APPWRITE_FUNCTION_RUNTIME_NAME' => $_ENV['APPWRITE_FUNCTION_RUNTIME_NAME'],
    'APPWRITE_FUNCTION_RUNTIME_VERSION' => $_ENV['APPWRITE_FUNCTION_RUNTIME_VERSION'],
    'APPWRITE_FUNCTION_EVENT' => $_ENV['APPWRITE_FUNCTION_EVENT'],
    'APPWRITE_FUNCTION_EVENT_DATA' => $_ENV['APPWRITE_FUNCTION_EVENT_DATA'],
    'APPWRITE_FUNCTION_DATA' => $_ENV['APPWRITE_FUNCTION_DATA'],
    'APPWRITE_FUNCTION_USER_ID' => $_ENV['APPWRITE_FUNCTION_USER_ID'],
    'APPWRITE_FUNCTION_JWT' => $_ENV['APPWRITE_FUNCTION_JWT'],
];

echo json_encode($output);
