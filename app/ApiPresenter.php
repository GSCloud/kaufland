<?php
/**
 * GSC Tesseract
 * php version 7.4.0
 *
 * @category Framework
 * @package  Tesseract
 * @author   Fred Brooker <git@gscloud.cz>
 * @license  MIT https://gscloud.cz/LICENSE
 * @link     https://1950.mxd.cz
 */

namespace GSC;

use Cake\Cache\Cache;
use RedisClient\RedisClient;
use Symfony\Component\Lock\Factory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * API Presenter
 * 
 * @category Framework
 * @package  Tesseract
 * @author   Fred Brooker <git@gscloud.cz>
 * @license  MIT https://gscloud.cz/LICENSE
 * @link     https://1950.mxd.cz
 */

class ApiPresenter extends APresenter
{
    const ACCESS_TIME_LIMIT = 3599;
    const API_CACHE = 'tenminutes';
    const MAX_API_HITS = 1000;
    const MAX_RECORDS = 300;
    const USE_CACHE = true;

    /**
     * Main controller
     * 
     * @return self
     */
    public function process()
    {
        \setlocale(LC_ALL, 'cs_CZ.utf8');
        \error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

        $cfg = $this->getCfg();
        $d = $this->getData();
        $match = $this->getMatch();
        $view = $this->getView();

        // view properties
        $presenter = $this->getPresenter();
        $use_key = $presenter[$view]["use_key"] ?? false;
        $priv = $presenter[$view]["private"] ?? false;

        // user data, permissions and authorizations
        $api_key = $_GET["apikey"] ?? null;
        $d["user"] = $this->getCurrentUser() ?? [];
        $user_id = $d["user"]["id"] ?? null;
        $d["admin"] = $user_group = $this->getUserGroup();
        if ($user_group) {
            $d["admin_group_${user_group}"] = true;
        }

        // general API properties
        $extras = [
            "fn" => $view,
            "name" => "LAHVE REST API",
            "api_quota" => (int) self::MAX_API_HITS,
            "api_usage" => $this->accessLimiter(),
            "access_time_limit" => self::ACCESS_TIME_LIMIT,
            "cache_time_limit" => $this->getData("cache_profiles")[self::API_CACHE],
            "records_quota" => self::MAX_RECORDS,
            "private" => $priv,
            "use_key" => $use_key,
            "uuid" => $this->getUID(),
        ];

        // access validation
        if (($priv) && (!$user_id)) {
            return $this->writeJsonData(401, $extras);
        }
        if (($priv) && ($user_id) && (!$user_group)) {
            return $this->writeJsonData(401, $extras);
        }
        if (($use_key) && (!$api_key)) {
            return $this->writeJsonData(403, $extras);
        }
        if (($use_key) && ($api_key)) {
            $test = $this->checkKey($api_key);
            if (\is_null($test)) {
                return $this->writeJsonData(401, $extras);
            }
            if ($test["valid"] !== true) {
                return $this->writeJsonData(401, $extras);
            }
        }

        // process API calls
        switch ($view) {

        case "GetUser":
            $data["user"] = [
                "name" => $this->getIdentity()["name"] ?? null,
                "email" => $this->getIdentity()["email"] ?? null,
                "country" => $this->getIdentity()["country"] ?? null,
                "role" => $user_group,
                "avatar" => $this->getIdentity()["avatar"] ?? null,
                "login_type" => $this->getIdentity()["id"] ?
                    "Google OAuth 2.0" : null,
                "security_level" => $this->getIdentity()["id"] ?
                    "high" : "none",
            ];
            return $this->writeJsonData($data, $extras);
            break;

        case "GetVersion":
            $data = [
                "version" => $this->getData('VERSION'),
            ];
            return $this->writeJsonData($data, $extras);
            break;

        case "GetSalt":
            $today = date('Y-m-d');
            $data = [
                "salt" => $this->getSalt(),
                "today" => $this->getToday(),
            ];
            return $this->writeJsonData($data, $extras);
            break;

        case "GetDiscounts":
            if (!file_exists(ROOT . '/akce.data')) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $discounts = $this->getDiscounts();
            $data = [
                "records" => count($discounts),
                "discounts" => $discounts,
            ];
            return $this->writeJsonData($data, $extras);
            break;

        case "GetChangeLog":
            if (!file_exists(WWW . '/changelog.txt')) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $log = file(WWW . '/changelog.txt');
            foreach ($log as $k => $v) {
                $v = trim($v);
                if (strpos($v, '[var]')) {
                    $log[$k] = "<span class=yellow10>$v</span>";
                }
                if (strpos($v, '[fn]')) {
                    $log[$k] = "<span class=blue8>$v</span>";
                }
                if (strpos($v, '[fn,priv]')) {
                    $log[$k] = "<span class=indigo10>$v</span>";
                }
                if (strpos($v, '[API]')) {
                    $log[$k] = "<span class=green6>$v</span>";
                }
                if (strpos($v, '[TESTER]')) {
                    $log[$k] = "<span class=teal8>$v</span>";
                }
            }
            $log = implode('<br>', $log);
            $log = preg_replace('/\n=+\n/', '<hr>', $log);
            $log = preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)/', '<b>$1</b>', $log);
            $data = [
                "changelog" => $log,
            ];
            return $this->writeJsonData($data, $extras);
            break;

        default:
            // TODO: uncomment in production
            //sleep(5);
            return ErrorPresenter::getInstance()->process(404);
        }
        return $this;
    }

    /**
     * Check REST API key validity
     *
     * @param [string] $apikey API key
     * 
     * @return true
     */
    public function checkKey($apikey)
    {
        return true;
    }

    /**
     * Get beer discounts
     *
     * @return array
     */
    public function getDiscounts()
    {
        $discounts = [];
        $file = ROOT . "/akce.data";
        if (file_exists($file)) {
            $arr = file($file);
            $c = 0;
            $count = 0;
            foreach ($arr ?? [] as $s) {
                $s = trim($s);
                if (!strlen($s)) {
                    continue;
                }
                // separator
                if ($s == '---') {
                    $c = 1;
                    $el = [];
                    $el["id"] = $count;
                    continue;
                }
                // product
                if ($c == 1) {
                    $el["product"] = (int) $s;
                    $c++;
                    continue;
                }
                // title
                if ($c == 2) {
                    $el["title"] = strtolower($s);
                    $c++;
                    continue;
                }
                // market
                if ($c == 3) {
                    $el["market"] = $s;
                    $c++;
                    continue;
                }
                // price
                if ($c == 4) {
                    $el["price"] = (int) trim(str_replace('Kč', '', $s));
                    $c++;
                    continue;
                }
                // discount
                if ($c == 5) {
                    $el["discount"] = (int) trim(str_replace('%', '', $s));
                    $c++;
                    array_push($discounts, $el);
                    $count++;
                    if ($count == self::MAX_RECORDS) {
                        break;
                    }
                    continue;
                }
            }
        }        
        return $discounts;
    }

    /**
     * Get current date
     *
     * @return string current date as YYYY-MM-DD
     */
    public function getToday()
    {
        return date('Y-m-d');
    }

    /**
     * Get daily salt
     *
     * @return string salt as SHA-256 hash
     */
    public function getSalt()
    {
        return hash(
            'sha256',
            hash(
                'sha256',
                $this->getToday()
            )
            . $this->getData('daily_salt_seed') ?? 'RandomSaltSeedIsInPrivateConfig'
        );
    }

    /**
     * Redis access limiter
     *
     * @return mixed access count or null
     */
    public function accessLimiter()
    {
        $hour = date("H");
        $uid = $this->getUID();
        $key = "access_limiter_" . SERVER . "_" . PROJECT . "_${hour}_${uid}";
        \error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
        $redis = new RedisClient(
            [
            'server' => 'localhost:6377',
            'timeout' => 1,
            ]
        );
        try {
            $val = (int) @$redis->get($key);
        } catch (\Exception $e) {
            return null;
        }
        if ($val > self::MAX_API_HITS) {
            // over limit!
            $this->setLocation("/err/420");
        }
        try {
            @$redis->multi();
            @$redis->incr($key);
            @$redis->expire($key, self::ACCESS_TIME_LIMIT);
            @$redis->exec();
        } catch (\Exception $e) {
            return null;
        }
        $val++;
        return $val;
    }
}
