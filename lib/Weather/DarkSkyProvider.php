<?php
/**
 * Copyright (C) 2020 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - http://www.xibo.org.uk
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Xibo\Weather;


use GuzzleHttp\Exception\RequestException;
use Psr\Log\NullLogger;
use Xibo\Exception\GeneralException;
use Xibo\Service\LogServiceInterface;

class DarkSkyProvider
{
    /** @var \Stash\Interfaces\PoolInterface */
    private $pool;

    /** @var \GuzzleHttp\Client */
    private $client;

    /** @var \Psr\Log\NullLogger|LogServiceInterface */
    private $logger;

    private $apiUrl = 'https://api.darksky.net/forecast/';
    private $apiKey;
    private $cachePeriod = 14400;

    private $lat;
    private $long;
    private $units;
    private $lang;

    /** @var string */
    private $timezone;

    /** @var \Xibo\Weather\ForecastDay */
    private $currentDay;

    /** @var \Xibo\Weather\ForecastDay[] */
    private $forecast;

    // <editor-fold desc="Getters/Setters">

    /**
     * DarkSkyProvider constructor.
     * @param \Stash\Interfaces\PoolInterface $pool
     * @param \GuzzleHttp\Client $client
     */
    public function __construct($pool, $client)
    {
        $this->pool = $pool;
        $this->client = $client;
        $this->logger = new NullLogger();
    }

    /**
     * @param \Psr\Log\NullLogger|LogServiceInterface $logger
     * @return $this
     */
    public function enableLogging($logger)
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * @param string $url
     * @return $this
     */
    public function setUrl(string $url)
    {
        $this->apiUrl = $url;
        return $this;
    }

    /**
     * @param string $key
     * @return $this
     */
    public function setKey(string $key)
    {
        $this->apiKey = $key;
        return $this;
    }

    /**
     * @param int $cachePeriod
     * @return $this
     */
    public function setCachePeriod(int $cachePeriod)
    {
        $this->cachePeriod = $cachePeriod;
        return $this;
    }

    /**
     * @param $lat
     * @param $long
     * @return $this
     */
    public function setLocation($lat, $long)
    {
        $this->lat = $lat;
        $this->long = $long;
        return $this;
    }

    /**
     * @param $lang
     * @return $this
     */
    public function setLang($lang)
    {
        $this->lang = $lang;
        return $this;
    }

    /**
     * @param $units
     * @return $this
     */
    public function setUnits($units)
    {
        $this->units = $units;
        return $this;
    }

    // </editor-fold>

    /**
     * @return string
     */
    public function getAttribution()
    {
        return 'Powered by DarkSky';
    }

    /**
     * @return \Xibo\Weather\ForecastDay
     * @throws \Xibo\Exception\GeneralException
     */
    public function getCurrentDay()
    {
        if ($this->currentDay === null) {
            $this->get();
        }

        return $this->currentDay;
    }

    /**
     * @return \Xibo\Weather\ForecastDay[]
     * @throws \Xibo\Exception\GeneralException
     */
    public function getForecast()
    {
        if ($this->forecast === null) {
            $this->get();
        }

        return $this->forecast;
    }

    /**
     * Get TimeZone
     * @return string
     */
    public function getTimezone()
    {
        return $this->timezone;
    }

    /**
     * Get the body for our API response.
     * @throws \Xibo\Exception\GeneralException
     */
    private function get()
    {
        $url = $this->apiUrl
            . '[API_KEY]'
            . '/'
            . $this->lat
            . ','
            . $this->long
            . '?exclude=minutely,hourly'
            . '&units=' . $this->units
            . '&lang=' . $this->lang
        ;

        $cache = $this->pool->getItem('/weather/darksky/' . md5($url));
        $data = $cache->get();

        if ($cache->isMiss()) {
            $cache->lock();

            $this->logger->debug('Calling API with: ' . $url);

            $url = str_replace('[API_KEY]', $this->apiKey, $url);

            try {
                $response = $this->client->get($url);

                // Success?
                if ($response->getStatusCode() != 200) {
                    $this->logger->error('DarkSky API returned ' . $response->getStatusCode()
                        . ' status. Unable to proceed. Headers' . var_export($response->getHeaders(), true));

                    // See if we can parse the error.
                    $body = json_decode($response->getBody());

                    $error = ((isset($body->errors[0])) ? $body->errors[0]->message : 'Unknown Error');
                    $this->logger->error('DarkSky Error: ' . $error);

                    throw new GeneralException($error);
                }

                // Parse out header and body
                $data = json_decode($response->getBody(), true);

                // Cache
                $cache->set($data);
                $cache->expiresAfter($this->cachePeriod);
                $this->pool->saveDeferred($cache);

            } catch (RequestException $e) {
                $this->logger->error('Unable to reach DarkSky API: ' . $e->getMessage());
                throw new GeneralException($e->getMessage());
            }
        } else {
            $this->logger->debug('Getting Forecast from cache');
        }

        // Set the Timezone
        $this->timezone = $data['timezone'];

        // Temperature and Wind Speed Unit Mappings
        $temperatureUnit = '';
        $windSpeedUnit = '';
        $visibilityDistanceUnit = '';
        foreach ($this->unitsAvailable() as $unit) {
            if ($unit['id'] == $data['flags']['units']) {
                $temperatureUnit = $unit['tempUnit'];
                $windSpeedUnit = $unit['windUnit'];
                $visibilityDistanceUnit = $unit['visibilityUnit'];
                break;
            }
        }

        // Load this data into our objects
        $this->currentDay = new ForecastDay();
        $this->currentDay->temperatureUnit = $temperatureUnit;
        $this->currentDay->windSpeedUnit = $windSpeedUnit;
        $this->currentDay->visibilityDistanceUnit = $visibilityDistanceUnit;
        $this->processItemIntoDay($this->currentDay, $data['currently']);

        // Process each day into a forecast
        foreach ($data['daily']['data'] as $dayItem) {
            $day = new ForecastDay();
            $day->temperatureUnit = $temperatureUnit;
            $day->windSpeedUnit = $windSpeedUnit;
            $day->visibilityDistanceUnit = $visibilityDistanceUnit;
            $this->processItemIntoDay($day, $dayItem);

            $this->forecast[] = $day;
        }

        // Enhance the currently with the high/low from the first daily forecast
        $this->currentDay->temperatureHigh = $this->forecast[0]->temperatureHigh;
        $this->currentDay->temperatureMaxRound = $this->forecast[0]->temperatureMaxRound;
        $this->currentDay->temperatureLow = $this->forecast[0]->temperatureLow;
        $this->currentDay->temperatureMinRound = $this->forecast[0]->temperatureMinRound;
    }

    /**
     * Process an item into a Day
     * @param \Xibo\Weather\ForecastDay $day
     * @param array $item
     */
    private function processItemIntoDay($day, $item)
    {
        $day->time = $item['time'];
        $day->summary = $item['summary'];
        $day->icon = $item['icon'];
        $day->temperature = $item['temperature'] ?? ($item['temperatureHigh'] + $item['temperatureLow']) / 2;
        $day->temperatureRound = round($day->temperature, 0);
        $day->apparentTemperature = $item['apparentTemperature'] ?? ($item['apparentTemperatureHigh'] + $item['apparentTemperatureLow']) / 2;
        $day->apparentTemperatureRound = round($day->apparentTemperature, 0);
        $day->temperatureHigh = $item['temperatureHigh'] ?? $day->temperature;
        $day->temperatureMaxRound = round($day->temperatureHigh, 0);
        $day->temperatureLow = $item['temperatureLow'] ?? $day->temperature;
        $day->temperatureMinRound = round($day->temperatureLow, 0);
        $day->dewPoint = $item['dewPoint'];
        $day->humidity = $item['humidity'];
        $day->humidityPercent = $day->humidity * 100;
        $day->pressure = $item['pressure'];
        $day->windSpeed = $item['windSpeed'];
        $day->windBearing = $item['windBearing'];
        $day->cloudCover = $item['cloudCover'];
        $day->uvIndex = $item['uvIndex'];
        $day->visibility = $item['visibility'];
        $day->ozone = $item['ozone'];

        // Wind direction
        foreach (self::cardinalDirections() as $dir => $angles) {
            if ($day->windBearing >= $angles[0] && $day->windBearing < $angles[1]) {
                $day->windDirection = $dir;
                break;
            }
        }

        // Map icon
        $icons = self::iconMap();
        $day->wicon = $icons[$item['icon']] ?? $icons['unmapped'];
    }

    /**
     * Units supported by Forecast.IO API
     * @return array The Units Available (temperature, wind speed and visible distance)
     */
    public static function unitsAvailable()
    {
        return [
            ['id' => 'auto', 'value' => 'Automatically select based on geographic location', 'tempUnit' => '', 'windUnit' => '', 'visibilityUnit' => ''],
            ['id' => 'ca', 'value' => 'Canada', 'tempUnit' => 'C', 'windUnit' => 'KPH', 'visibilityUnit' => 'km'],
            ['id' => 'si', 'value' => 'Standard International Units', 'tempUnit' => 'C', 'windUnit' => 'MPS', 'visibilityUnit' => 'km'],
            ['id' => 'uk2', 'value' => 'United Kingdom', 'tempUnit' => 'C', 'windUnit' => 'MPH', 'visibilityUnit' => 'mi'],
            ['id' => 'us', 'value' => 'United States', 'tempUnit' => 'F', 'windUnit' => 'MPH', 'visibilityUnit' => 'mi'],
        ];
    }

    /**
     * Languages supported by Forecast.IO API
     * @return array The Supported Language
     */
    public static function supportedLanguages()
    {
        return [
            ['id' => 'ar', 'value' => __('Arabic')],
            ['id' => 'az', 'value' => __('Azerbaijani')],
            ['id' => 'be', 'value' => __('Belarusian')],
            ['id' => 'bs', 'value' => __('Bosnian')],
            ['id' => 'bg', 'value' => __('Bulgarian')],
            ['id' => 'ca', 'value' => __('Catalan')],
            ['id' => 'kw', 'value' => __('Cornish')],
            ['id' => 'zh', 'value' => __('Simplified Chinese')],
            ['id' => 'zh-tw', 'value' => __('Traditional Chinese')],
            ['id' => 'hr', 'value' => __('Croatian')],
            ['id' => 'cs', 'value' => __('Czech')],
            ['id' => 'da', 'value' => __('Danish')],
            ['id' => 'nl', 'value' => __('Dutch')],
            ['id' => 'ka', 'value' => __('Georgian')],
            ['id' => 'de', 'value' => __('German')],
            ['id' => 'el', 'value' => __('Greek')],
            ['id' => 'en', 'value' => __('English')],
            ['id' => 'et', 'value' => __('Estonian')],
            ['id' => 'fi', 'value' => __('Finnish')],
            ['id' => 'fr', 'value' => __('French')],
            ['id' => 'hu', 'value' => __('Hungarian')],
            ['id' => 'is', 'value' => __('Icelandic')],
            ['id' => 'id', 'value' => __('Indonesian')],
            ['id' => 'it', 'value' => __('Italian')],
            ['id' => 'ja', 'value' => __('Japanese')],
            ['id' => 'nb', 'value' => __('Norwegian Bokmål')],
            ['id' => 'pl', 'value' => __('Polish')],
            ['id' => 'pt', 'value' => __('Portuguese')],
            ['id' => 'ru', 'value' => __('Russian')],
            ['id' => 'sr', 'value' => __('Serbian')],
            ['id' => 'sk', 'value' => __('Slovak')],
            ['id' => 'sl', 'value' => __('Slovenian')],
            ['id' => 'es', 'value' => __('Spanish')],
            ['id' => 'sv', 'value' => __('Swedish')],
            ['id' => 'tet', 'value' => __('Tetum')],
            ['id' => 'tr', 'value' => __('Turkish')],
            ['id' => 'uk', 'value' => __('Ukrainian')],
            ['id' => 'x-pig-latin', 'value' => __('lgpay Atinlay')]
        ];
    }

    /**
     * @return array
     */
    private static function iconMap()
    {
        return [
            'unmapped' => 'wi-alien',
            'clear-day' => 'wi-day-sunny',
            'clear-night' => 'wi-night-clear',
            'rain' => 'wi-rain',
            'snow' => 'wi-snow',
            'sleet' => 'wi-hail',
            'wind' => 'wi-windy',
            'fog' => 'wi-fog',
            'cloudy' => 'wi-cloudy',
            'partly-cloudy-day' => 'wi-day-cloudy',
            'partly-cloudy-night' => 'wi-night-partly-cloudy',
        ];
    }

    /**
     * @return array
     */
    private static function cardinalDirections()
    {
        return [
            'N' => [337.5, 22.5],
            'NE' => [22.5, 67.5],
            'E' => [67.5, 112.5],
            'SE' => [112.5, 157.5],
            'S' => [157.5, 202.5],
            'SW' => [202.5, 247.5],
            'W' => [247.5, 292.5],
            'NW' => [292.5, 337.5]
        ];
    }
}