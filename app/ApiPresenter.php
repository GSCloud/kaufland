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
    const MAX_RECORDS = 200;
    const USE_CACHE = true;

    /**
     * Main controller
     * 
     * @return self
     */
    public function process()
    {
        \setlocale(LC_ALL, 'cs_CZ.utf8');

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
                "role" => null,
                "avatar" => $this->getIdentity()["avatar"] ?? null,
                "login_type" => $this->getIdentity()["id"] ?
                    "Google OAuth 2.0" : null,
                "security_level" => $this->getIdentity()["id"] ?
                    "advanced" : null,
                "permissions" => [],
            ];
            return $this->writeJsonData($data, $extras);
            break;
        case "GetVersion":
            $data = [
                "version" => $this->getData('VERSION'),
            ];
            return $this->writeJsonData($data, $extras);
            break;

        case "GetChangeLog":
            $data = [
                "changelog" => str_replace("\n", '<br>', file_get_contents(WWW . '/changelog.txt')),
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
     * @param [string] $api_key REST API key
     * 
     * @return true
     */
    public function checkKey($api_key)
    {
        return true;
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
