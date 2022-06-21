<?php

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Request;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Locale\Locale;

App::get('/v1/locale')
    ->desc('Get User Locale')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/locale/get-locale.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOCALE)
    ->inject('request')
    ->inject('response')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (Request $request, Response $response, Locale $locale, Reader $geodb) {
        $eu = Config::getParam('locale-eu');
        $currencies = Config::getParam('locale-currencies');
        $output = [];
        $ip = $request->getIP();
        $time = (60 * 60 * 24 * 45); // 45 days cache

        $output['ip'] = $ip;

        $currency = null;

        $record = $geodb->get($ip);

        if ($record) {
            $output['countryCode'] = $record['country']['iso_code'];
            $output['country'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            $output['continent'] = $locale->getText('continents.' . strtolower($record['continent']['code']), $locale->getText('locale.country.unknown'));
            $output['continent'] = (isset($continents[$record['continent']['code']])) ? $continents[$record['continent']['code']] : $locale->getText('locale.country.unknown');
            $output['continentCode'] = $record['continent']['code'];
            $output['eu'] = (\in_array($record['country']['iso_code'], $eu)) ? true : false;

            foreach ($currencies as $code => $element) {
                if (isset($element['locations']) && isset($element['code']) && \in_array($record['country']['iso_code'], $element['locations'])) {
                    $currency = $element['code'];
                }
            }

            $output['currency'] = $currency;
        } else {
            $output['countryCode'] = '--';
            $output['country'] = $locale->getText('locale.country.unknown');
            $output['continent'] = $locale->getText('locale.country.unknown');
            $output['continentCode'] = '--';
            $output['eu'] = false;
            $output['currency'] = $currency;
        }

        $response
            ->addHeader('Cache-Control', 'public, max-age=' . $time)
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time) . ' GMT') // 45 days cache
        ;
        $response->dynamic(new Document($output), Response::MODEL_LOCALE);
    });

App::get('/v1/locale/countries')
    ->desc('List Countries')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountries')
    ->label('sdk.description', '/docs/references/locale/get-countries.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COUNTRY_LIST)
    ->inject('response')
    ->inject('locale')
    ->action(function (Response $response, Locale $locale) {
        $list = Config::getParam('locale-countries'); /* @var $list array */
        $output = [];

        foreach ($list as $value) {
            $output[] = new Document([
                'name' => $locale->getText('countries.' . strtolower($value)),
                'code' => $value,
            ]);
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        $response->dynamic(new Document(['countries' => $output, 'total' => \count($output)]), Response::MODEL_COUNTRY_LIST);
    });

App::get('/v1/locale/countries/eu')
    ->desc('List EU Countries')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesEU')
    ->label('sdk.description', '/docs/references/locale/get-countries-eu.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COUNTRY_LIST)
    ->inject('response')
    ->inject('locale')
    ->action(function (Response $response, Locale $locale) {
        $eu = Config::getParam('locale-eu');
        $output = [];

        foreach ($eu as $code) {
            if ($locale->getText('countries.' . strtolower($code), false) !== false) {
                $output[] = new Document([
                    'name' => $locale->getText('countries.' . strtolower($code)),
                    'code' => $code,
                ]);
            }
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        $response->dynamic(new Document(['countries' => $output, 'total' => \count($output)]), Response::MODEL_COUNTRY_LIST);
    });

App::get('/v1/locale/countries/phones')
    ->desc('List Countries Phone Codes')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesPhones')
    ->label('sdk.description', '/docs/references/locale/get-countries-phones.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PHONE_LIST)
    ->inject('response')
    ->inject('locale')
    ->action(function (Response $response, Locale $locale) {
        $list = Config::getParam('locale-phones'); /* @var $list array */
        $output = [];

        \asort($list);

        foreach ($list as $code => $name) {
            if ($locale->getText('countries.' . strtolower($code), false) !== false) {
                $output[] = new Document([
                    'code' => '+' . $list[$code],
                    'countryCode' => $code,
                    'countryName' => $locale->getText('countries.' . strtolower($code)),
                ]);
            }
        }

        $response->dynamic(new Document(['phones' => $output, 'total' => \count($output)]), Response::MODEL_PHONE_LIST);
    });

App::get('/v1/locale/continents')
    ->desc('List Continents')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getContinents')
    ->label('sdk.description', '/docs/references/locale/get-continents.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_CONTINENT_LIST)
    ->inject('response')
    ->inject('locale')
    ->action(function (Response $response, Locale $locale) {
        $list = Config::getParam('locale-continents');

        foreach ($list as $value) {
            $output[] = new Document([
                'name' => $locale->getText('continents.' . strtolower($value)),
                'code' => $value,
            ]);
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        $response->dynamic(new Document(['continents' => $output, 'total' => \count($output)]), Response::MODEL_CONTINENT_LIST);
    });

App::get('/v1/locale/currencies')
    ->desc('List Currencies')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCurrencies')
    ->label('sdk.description', '/docs/references/locale/get-currencies.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_CURRENCY_LIST)
    ->inject('response')
    ->action(function (Response $response) {
        $list = Config::getParam('locale-currencies');

        $list = array_map(fn ($node) => new Document($node), $list);

        $response->dynamic(new Document(['currencies' => $list, 'total' => \count($list)]), Response::MODEL_CURRENCY_LIST);
    });


App::get('/v1/locale/languages')
    ->desc('List Languages')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getLanguages')
    ->label('sdk.description', '/docs/references/locale/get-languages.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LANGUAGE_LIST)
    ->inject('response')
    ->action(function (Response $response) {
        $list = Config::getParam('locale-languages');

        $list = array_map(fn ($node) => new Document($node), $list);

        $response->dynamic(new Document(['languages' => $list, 'total' => \count($list)]), Response::MODEL_LANGUAGE_LIST);
    });
