<?php

namespace RiseTechApps\Monitoring\Features\Device;
use GuzzleHttp\Client;

class Device
{
    public function __construct()
    {
        $class = (new \hisorange\BrowserDetect\Parser())
            ->parse($_GET['agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Missing');
    }

    public static function info(): array
    {
        try {
            $class = (new \hisorange\BrowserDetect\Parser())
                ->parse($_GET['agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Missing');
            return [
                'device' => static::getTypeDevice($class),
                'browser' => static::getTypeBrowser($class),
                'browser_name' => static::getTypeBrowserName($class),
                'platformName' => static::getPlatformName($class),
                'geo_ip' => static::getGeoIP($class)
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function getTypeDevice(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        if ($class->isDesktop()) {
            return 'Desktop';
        } else if ($class->isMobile()) {
            return 'Mobile' . self::getMobileDevice($class);
        } else if ($class->isTablet()) {
            return 'Tablet';
        } else if ($class->isBot()) {
            return 'Bot';
        }
        return 'Unknown';
    }

    private static function getMobileDevice(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        if ($class->isAndroid()) {
            return ' - Android';
        } else if ($class->isMac()) {
            return ' - Mac';
        } else if ($class->isLinux()) {
            return ' - linux';
        } else if ($class->isWindows()) {
            return ' - Windows';
        }

        return '';
    }

    private static function getTypeBrowser(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        if ($class->isChrome()) {
            return 'Chrome';
        } else if ($class->isSafari()) {
            return 'Safari';
        } else if ($class->isOpera()) {
            return 'Opera';
        } else if ($class->isFirefox()) {
            return 'Firefox';
        } else if ($class->isIE()) {
            return 'IE';
        } else if ($class->isEdge()) {
            return 'Edge';
        } else if ($class->isInApp()) {
            return 'webView';
        } else if ($class->isAndroid()) {
            return $class->browserFamily();
        }
        return 'Unknown';
    }

    private static function getTypeBrowserName(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        return $class->browserName();
    }

    private static function getPlatformName(\hisorange\BrowserDetect\Contracts\ResultInterface $class): string
    {
        return $class->platformName();
    }

    private static function getGeoIP(\hisorange\BrowserDetect\Contracts\ResultInterface $class)
    {
        try {
            $ip = request()->ip();

            $client = new Client();

            $response = $client->get("http://ip-api.com/json/${ip}");

            if ($response->getStatusCode() == 200) {
                return json_decode($response->getBody(), true);
            }

            return null;
        } catch (\Exception $exception) {
            return null;
        }
    }
}
