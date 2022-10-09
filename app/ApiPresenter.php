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
use Nette\Neon\Neon;
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
    const MAX_RECORDS = 500;
    const USE_CACHE = true;

    /**
     * Main controller
     * 
     * @return self
     */
    public function process()
    {
        \setlocale(LC_ALL, 'cs_CZ.UTF-8');
        \error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

        $cfg = $this->getCfg();
        $d = $this->getData();
        $match = $this->getMatch();
        $view = $this->getView();

        // view properties
        $presenter = $this->getPresenter();
        $use_key = $presenter[$view]["use_key"] ?? false;
        $allow_key = $presenter[$view]["allow_key"] ?? false;
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
            "cached" => false,
            "records_quota" => self::MAX_RECORDS,
            "private" => $priv,
            "allow_key" => $allow_key,
            "use_key" => $use_key,
            "uuid" => $this->getUID(),
        ];

        // ACCESS VALIDATION
        $access = null;
        $pin = null;

        // PRIVATE & NOT OAUTH2
        if ($priv && !$user_id) {
            return $this->writeJsonData(401, $extras);
        }
        // PRIVATE && OAUTH2 && NOT ALLOWED
        if ($priv && $user_id && !$user_group) {
            return $this->writeJsonData(401, $extras);
        }
        // NO API KEY
        if ($use_key && !$api_key) {
            return $this->writeJsonData(403, $extras);
        }

        // API KEY MANDATORY
        if ($use_key && $api_key) {
            $pin = $check = $this->checkKey($api_key);
            // NO GROUP && API KEY FAILED
            if (!$user_group && $check === false) {
                return $this->writeJsonData(401, $extras);
            }
            // IN GROUP && API KEY FAILED
            if ($user_group && $check === false) {
                $access = strtoupper($user_group);
            }
            // IN GROUP && API KEY SUCCESS
            if ($user_group && $check) {
                $access = strtoupper($check) . '&nbsp;' . strtoupper($user_group);
            }
            // NOT IN GROUP && API KEY SUCCESS
            if (!$user_group && $check) {
                $access = strtoupper($check);
            }
        }

        // API KEY ALLOWED
        if ($allow_key && $api_key) {
            $pin = $check = $this->checkKey($api_key);
            // IN GROUP && API KEY FAILED
            if ($user_group && $check === false) {
                $access = strtoupper($user_group);
            }
            // IN GROUP && API KEY SUCCESS
            if ($user_group && $check) {
                $access = strtoupper($check) . ' ' . strtoupper($user_group);
            }
            // NOT IN GROUP && API KEY SUCCESS
            if (!$user_group && $check) {
                $access = strtoupper($check);
            }
        }

        // svae last_seen Unix timestamp
        $this->saveLastSeen();

        // process API calls
        switch ($view) {
        case "GetUser":
            $data["user"] = [
                "name" => $this->getIdentity()["name"] ?? null,
                "email" => $this->getIdentity()["email"] ?? null,
                "country" => $this->getIdentity()["country"] ?? null,
                "role" => $access,
                "pin" => $pin,
                "avatar" => $this->getIdentity()["avatar"] ?? null,
                "login_type" => $this->getIdentity()["id"]
                    ? "Google OAuth 2.0" : ($pin ? "PIN" : null),
                "security_level" => $this->getIdentity()["id"]
                    ? "high" : ($access ? "low" : "none"),
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
            $data = [
                "today" => $this->getToday(),
                "salt" => $this->getSalt(),
            ];
            return $this->writeJsonData($data, $extras);
            break;

        case "GetDiscounts":
            $f = 'akce.data';
            $file = ROOT . '/' . $f;
            if (!\is_file($file) || !\is_readable($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $results = $this->getDiscounts($file);
            $data = [
                "file" => $f,
                "timestamp" => filemtime($file),
                "date" => date('j. n. Y', filemtime($file)),
                "description" => 'lahvové pivo dle popularity',
                "records_count" => count($results["discounts"]),
                "groups_count" => count($results["groups"]),
                "discounts" => $results["discounts"],
                "groups" => $results["groups"],
            ];
            return $this->writeJsonData($data, $extras);
            break;
            
        case "GetDiscountsAll":
            $f = 'akce-all.data';
            $file = ROOT . '/' . $f;
            if (!\is_file($file) || !\is_readable($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $results = $this->getDiscounts($file);
            $data = [
                "file" => $f,
                "timestamp" => filemtime($file),
                "date" => date('j. n. Y', filemtime($file)),
                "description" => 'pivo dle popularity',
                "groups_count" => count($results["groups"]),
                "records_count" => count($results["discounts"]),
                "discounts" => $results["discounts"],
                "groups" => $results["groups"],
            ];
            return $this->writeJsonData($data, $extras);
            break;

        case "GetDiscountsByName":
            $f = 'akce.data';
            $file = ROOT . '/' . $f;
            if (!\is_file($file) || !\is_readable($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $results = $this->getDiscounts($file);
            $data = [
                "file" => $f,
                "timestamp" => filemtime($file),
                "date" => date('j. n. Y', filemtime($file)),
                "description" => 'lahvové pivo dle názvu',
                "records_count" => count($results["discounts"]),
                "groups_count" => count($results["groups"]),
                "discounts" => $this->sortByIndex($results["discounts"]),
                "groups" => $results["groups"],
            ];
            return $this->writeJsonData($data, $extras);
            break;
            
        case "GetDiscountsAllByName":
            $f = 'akce-all.data';
            $file = ROOT . '/' . $f;
            if (!\is_file($file) || !\is_readable($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $results = $this->getDiscounts($file);
            $data = [
                "file" => $f,
                "timestamp" => filemtime($file),
                "date" => date('j. n. Y', filemtime($file)),
                "description" => 'pivo dle názvu',
                "groups_count" => count($results["groups"]),
                "records_count" => count($results["discounts"]),
                "discounts" => $this->sortByindex($results["discounts"]),
                "groups" => $results["groups"],
            ];
            return $this->writeJsonData($data, $extras);
            break;

        case "GetChangeLog":
            $file = WWW . '/changelog.txt';
            if (!\is_file($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $data = [
                "changelog" => $this->getChangelog($file),
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
     * Sort array by index key
     *
     * @param array $arr input
     * 
     * @return array results
     */
    public function sortByIndex($arr)
    {
        if (!$arr) {
            return [];
        }

        /**
         * Sorter builder
         *
         * @param string $key sorting index key
         * 
         * @return int string comparison result using a "natural order" algorithm
         */
        function build_sorter($key)
        {
            return function ($a, $b) use ($key) {
                return \strnatcmp($a[$key], $b[$key]);
            };
        }

        \usort($arr, build_sorter('title'));
        return $arr;
    }

    /**
     * Get colorized changelog
     *
     * @param string $file filename of the changelog
     * 
     * @return mixed HTML5 changelog / false
     */
    public function getChangelog($file)
    {
        if (\is_file($file) && \is_readable($file)) {
            $log = \file($file);
            foreach ($log ?? [] as $k => $v) {
                $v = \trim($v);
                $x = '[fix]';
                if (\strpos($v, $x)) {
                    $v = \str_replace($x, '[<b>fix</b>]', $v);
                    $log[$k] = "<div class=red8>$v</div>";
                }
                $x = '[var]';
                if (\strpos($v, $x)) {
                    $v = \str_replace($x, '[<b>var</b>]', $v);
                    $log[$k] = "<div class=yellow10>$v</div>";
                }
                $x = '[fn]';
                if (\strpos($v, $x)) {
                    $v = \str_replace($x, '[<b>fn</b>]', $v);
                    $log[$k] = "<div class=blue8>$v</div>";
                }
                $x = '[fn,priv]';
                if (\strpos($v, $x)) {
                    $v = \str_replace($x, '[<b>fn,priv</b>]', $v);
                    $log[$k] = "<div class=indigo10>$v</div>";
                }
                $x = '[API]';
                if (\strpos($v, '[API]')) {
                    $v = \str_replace($x, '[<b>API</b>]', $v);
                    $log[$k] = "<div class=green6>$v</div>";
                }
                $x = '[TESTER]';
                if (\strpos($v, '[TESTER]')) {
                    $v = \str_replace($x, '[<b>TESTER</b>]', $v);
                    $log[$k] = "<div class=teal8>$v</div>";
                }
                $x = '!!!';
                if (\strpos($v, '!!!')) {
                    $v = \str_replace($x, '', $v);
                    $log[$k] = "<div class='red bold'>$v</div>";
                }
            }
            $log = \implode('<br>', $log);
            $log = \preg_replace('/==+/', '<hr>', $log);
            $log = \str_replace("\n", '', $log);
            $log = \str_replace('</div><br>', '</div>', $log);
            $log = \str_replace('<br><hr><br>', '<hr>', $log);
            $log = \preg_replace('/([0-9]+\.[0-9]+\.[0-9]+)/', '<b>$1</b>', $log);
            return $log;
        }
        return false;
    }

    /**
     * Save last seen Unix timestamp
     *
     * @return self
     */
    public function saveLastSeen()
    {
        if ($email = $this->getIdentity()["email"]) {
            @file_put_contents(
                DATA . "/last_seen." . $email,
                time(),
                LOCK_EX
            );
        }
        return $this;
    }

    /**
     * Check REST API key validity
     *
     * @param string $apikey API key (SHA-256 hash)
     * 
     * @return mixed user role by PIN or false if check failed
     */
    public function checkKey($apikey)
    {
        if (!$apikey) {
            return false;
        }
        $pins = (array) $this->getData("security_pin") ?? [];
        $salt = $this->getSalt();
        foreach ($pins as $k => $v) {
            if (hash("sha256", $v . $salt) === $apikey) {
                return $k;
            }
        }
        return false;
    }

    /**
     * Get beer discounts from a file
     *
     * @param string $file filename of the datafile
     * 
     * @return array discounts and groups
     */
    public function getDiscounts($file)
    {
        $discounts = [];
        // missing translations
        $export = DATA . '/missing_translations.txt';
        if (!\is_file($export)) {
            \fopen($export, 'w');
        }
        if (\is_file($file) && \is_readable($file)) {
            // load beer title translations
            $trans = [];
            $trans_file = APP . '/beer-translation.neon';
            if (\is_file($trans_file) && \is_readable($trans_file)) {
                $trans = Neon::decode(
                    \file_get_contents($trans_file)
                );
            }

            // load beer group translations
            $gtrans = [];
            $gtrans_file = APP . '/group-translation.neon';
            if (\is_file($gtrans_file) && \is_readable($gtrans_file)) {
                $gtrans = Neon::decode(
                    \file_get_contents($gtrans_file)
                );
            }

            $c = 0;
            $groups = [];
            $count = 1;

            // parse data file
            $arr = \file($file);
            foreach ($arr ?? [] as $s) {
                $s = \trim($s);
                if (!\strlen($s)) {
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
                // code + title + groups
                if ($c == 2) {
                    $s = \strtolower($s);
                    $el["code"] = $s;
                    $gs = $s;

                    // merge codes by string replacements
                    $gs = \str_replace('budweiser', 'budvar', $gs);
                    $gs = \str_replace('svijanska', 'svijany', $gs);

                    // compute the groups
                    $g = \explode('-', $gs);
                    if (\count($g)) {
                        foreach ($g as $gname) {
                            if (!\array_key_exists($gname, $groups)) {
                                $groups[$gname] = [];
                            }
                            if (!\in_array($el["product"], $groups[$gname])) {
                                \array_push($groups[$gname], $el["product"]);
                            }
                        }
                    }
                    $el["title"] = $trans[$s] ?? $s;
                    // export missing translation
                    if (!\array_key_exists($s, $trans)) {
                        \file_put_contents($export, $s . "\n", FILE_APPEND|LOCK_EX);
                    }
                    $c++;
                    continue;
                }
                // market
                if ($c == 3) {

                    // replace market names
                    $s = \str_ireplace('eso market', 'eso', $s);
                    $s = \str_ireplace('penny market', 'penny', $s);
                    $s = \str_ireplace('tamda foods', 'tamda', $s);

                    $el["market"] = \strtolower($s);
                    $c++;
                    continue;
                }
                // price
                if ($c == 4) {
                    $s = \str_replace(',', '.', $s);
                    $s = \str_replace('Kč', '', $s);
                    $el["price"] = (int) \ceil(\floatval(\trim($s)));

                    // filter out unwanted elements
                    if ($el["code"] == 'sklenice-na-pivo') {
                        continue;
                    }

                    \array_push($discounts, $el);
                    $count++;
                    if ($count == self::MAX_RECORDS) {
                        break;
                    }
                    continue;
                }
            }
        }
        foreach ($groups as $k => $v) {
            if (\count($v) < 2) {
                unset($groups[$k]);
            }
        }

        // remove vague groups
        unset($groups["ale"]);
        unset($groups["b"]);
        unset($groups["bohemia"]);
        unset($groups["chmeleny"]);
        unset($groups["classic"]);
        unset($groups["extra"]);
        unset($groups["extra"]);
        unset($groups["ipa"]);
        unset($groups["kralovsky"]);
        unset($groups["lezak"]);
        unset($groups["medium"]);
        unset($groups["nefiltrovane"]);
        unset($groups["ochucene"]);
        unset($groups["original"]);
        unset($groups["pivo"]);
        unset($groups["pivovar"]);
        unset($groups["premium"]);
        unset($groups["psenicne"]);
        unset($groups["specialni"]);
        unset($groups["strong"]);
        unset($groups["studena"]);
        unset($groups["svetle"]);
        unset($groups["svetly"]);
        unset($groups["tmave"]);
        unset($groups["tmavy"]);
        unset($groups["urquell"]);
        unset($groups["velkopopovicky"]);
        unset($groups["vycepni"]);
        unset($groups["za"]);
        unset($groups["zlaty"]);

        // translate groups
        foreach ($groups as $k => $v) {
            if (array_key_exists($k, $gtrans)) {
                unset($groups[$k]);
                $groups[$gtrans[$k]] = $v;
            }
        }

        // sort groups
        \ksort($groups);

        return [
            "discounts" => $discounts,
            "groups" => $groups,
        ];
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
            . $this->getData('daily_salt_seed') ?? 'SALT_SEED_IS_IN_PRIVATE_CONFIG!'
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
