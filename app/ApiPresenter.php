<?php
/**
 * GSC Tesseract
 * php version 8.2
 *
 * @category CMS
 * @package  Framework
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
 * @category CMS
 * @package  Framework
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
     * @param mixed $param optional parameter
     * 
     * @return object controller
     */
    public function process($param = null)
    {
        \setlocale(LC_ALL, 'cs_CZ.UTF-8');
        \error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

        $cfg = $this->getCfg();
        $d = $this->getData();
        $match = $this->getMatch();
        $view = $this->getView();

        // view properties
        $presenter = $this->getPresenter();
        $use_key = false;
        if (is_array($presenter)) {
            $use_key = \array_key_exists('use_key', $presenter[$view])
                ? $presenter[$view]['use_key'] : false;
        }
        $allow_key = false;
        if (is_array($presenter)) {
            $allow_key = \array_key_exists('allow_key', $presenter[$view])
                ? $presenter[$view]['allow_key'] : false;
        }
        $priv = false;
        if (is_array($presenter)) {
            $priv = \array_key_exists('private', $presenter[$view])
                ? $presenter[$view]['private'] : false;
        }

        // user data, permissions and authorizations
        $api_key = $_GET['apikey'] ?? null;
        $user_id = null;
        $user_group = null;
        if (is_array($d)) {
            $d['user'] = $this->getCurrentUser();
            $user_id = $d['user']['id'] ?? null;
            $d['admin'] = $user_group = $this->getUserGroup();
            if ($user_group) {
                $d["admin_group_{$user_group}"] = true;
            }
        }

        // general API properties
        $extras = [
            'fn' => $view,
            'name' => 'LAHVE REST API',
            'api_quota' => (int) self::MAX_API_HITS,
            'api_usage' => $this->accessLimiter(),
            'access_time_limit' => self::ACCESS_TIME_LIMIT,
            'cache_time_limit' => $this->getData('cache_profiles')[self::API_CACHE],
            'cached' => false,
            'records_quota' => self::MAX_RECORDS,
            'private' => $priv,
            'allow_key' => $allow_key,
            'use_key' => $use_key,
            'uuid' => $this->getUID(),
        ];

        // ACCESS VALIDATION
        $access = null;
        $pin = null;

        // PRIVATE & NOT OAUTH2
        if ($priv && !$user_id) {
            return $this->writeJsonData(401, $extras);
        }
        // PRIVATE && OAUTH2 && NOT ALLOWED
        if ($priv && $user_id > 0 && !$user_group) {
                return $this->writeJsonData(401, $extras);
        }
        // NO API KEY
        if ($use_key && !$api_key) {
            return $this->writeJsonData(403, $extras);
        }

        // API KEY MANDATORY
        if ($use_key) {
            $pin = $this->checkKey($api_key);
            $check = $this->checkKey($api_key);

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
        case 'GetUser':
            $data['user'] = [
                'name' => $this->getIdentity()['name'] ?? null,
                'email' => $this->getIdentity()['email'] ?? null,
                'country' => $this->getIdentity()['country'] ?? null,
                'role' => $access,
                'pin' => $pin ?: null,
                'avatar' => $this->getIdentity()['avatar'] ?? null,
                'login_type' => $this->getIdentity()['id']
                    ? 'Google OAuth 2.0' : ($pin ? 'PIN' : null),
                'security_level' => $this->getIdentity()['id']
                    ? 'high' : ($access ? 'low' : 'none'),
            ];
            return $this->writeJsonData($data, $extras);

        case 'GetVersion':
            $data = [
                'version' => $this->getData('VERSION'),
            ];
            return $this->writeJsonData($data, $extras);

        case 'GetSalt':
            $data = [
                'today' => $this->getToday(),
                'salt' => $this->getSalt(),
            ];
            return $this->writeJsonData($data, $extras);

        case 'GetDiscounts':
            $f = 'akce.data';
            $file = null;
            defined('ROOT') && $file = ROOT . '/' . $f;
            if (!$file) {
                return ErrorPresenter::getInstance()->process(404);
            }
            if (!\is_file($file) || !\is_readable($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $results = $this->getDiscounts($file);
            $data = [
                'file' => $f,
                'timestamp' => \filemtime($file) ?: null,
                'datetime' => \filemtime($file)
                    ? date('j. n. Y H:i', \filemtime($file)) : null,
                'description' => 'lahvové pivo dle popularity',
                'records_count' => count($results['discounts']),
                'groups_count' => count($results['groups']),
                'discounts' => $results['discounts'],
                'groups' => $results['groups'],
            ];
            return $this->writeJsonData($data, $extras);

        case 'GetDiscountsAll':
            $f = 'akce-all.data';
            $file = null;
            defined('ROOT') && $file = ROOT . '/' . $f;
            if (!$file) {
                return ErrorPresenter::getInstance()->process(404);
            }
            if (!\is_file($file) || !\is_readable($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $results = $this->getDiscounts($file);
            $data = [
                'file' => $f,
                'timestamp' => \filemtime($file) ?: null,
                'datetime' => \filemtime($file)
                    ? date('j. n. Y H:i', \filemtime($file)) : null,
                'description' => 'veškeré pivo dle popularity',
                'groups_count' => count($results['groups']),
                'records_count' => count($results['discounts']),
                'discounts' => $results['discounts'],
                'groups' => $results['groups'],
            ];
            return $this->writeJsonData($data, $extras);

        case 'GetDiscountsByName':
            $f = 'akce.data';
            $file = null;
            defined('ROOT') && $file = ROOT . '/' . $f;
            if (!$file) {
                return ErrorPresenter::getInstance()->process(404);
            }
            if (!\is_file($file) || !\is_readable($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $results = $this->getDiscounts($file);
            $data = [
                'file' => $f,
                'timestamp' => \filemtime($file) ?: null,
                'datetime' => \filemtime($file)
                    ? date('j. n. Y H:i', \filemtime($file)) : null,
                'description' => 'lahvové pivo dle názvu',
                'records_count' => count($results['discounts']),
                'groups_count' => count($results['groups']),
                'discounts' => $this->sortByIndex($results['discounts'], 'title'),
                'groups' => $results['groups'],
            ];
            return $this->writeJsonData($data, $extras);

        case 'GetDiscountsAllByName':
            $f = 'akce-all.data';
            $file = null;
            defined('ROOT') && $file = ROOT . '/' . $f;
            if (!$file) {
                return ErrorPresenter::getInstance()->process(404);
            }
            if (!\is_file($file) || !\is_readable($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $results = $this->getDiscounts($file);
            $data = [
                'file' => $f,
                'timestamp' => \filemtime($file) ?: null,
                'datetime' => \filemtime($file)
                    ? date('j. n. Y H:i', \filemtime($file)) : null,
                'description' => 'veškeré pivo dle názvu',
                'groups_count' => count($results['groups']),
                'records_count' => count($results['discounts']),
                'discounts' => $this->sortByindex($results['discounts'], 'title'),
                'groups' => $results['groups'],
            ];
            return $this->writeJsonData($data, $extras);

        case 'GetChangeLog':
            $file = null;
            defined('WWW') && $file = WWW . '/changelog.txt';
            if (!$file) {
                return ErrorPresenter::getInstance()->process(404);
            }
            if (!\is_file($file) && \is_readable($file)) {
                return ErrorPresenter::getInstance()->process(404);
            }
            $data = [
                'timestamp' => \filemtime($file) ?: null,
                'datetime' => \filemtime($file)
                    ? date('j. n. Y H:i', \filemtime($file)) : null,
                'changelog' => $this->getChangelog($file),
            ];
            return $this->writeJsonData($data, $extras);

        default:
            // TODO: uncomment in production
            //sleep(5);
            return ErrorPresenter::getInstance()->process(404);
        }
    }

    /**
     * Sort array by index key
     *
     * @param array<mixed> $arr   input
     * @param string       $index index key
     * 
     * @return array<mixed> results / empty array
     */
    public function sortByIndex($arr, $index)
    {
        if (!$arr) {
            return [];
        }
        if (!$index) {
            return $arr;
        }

        /**
         * Sorter builder
         *
         * @param string $key sorting index key
         * 
         * @return int string comparison result using a "natural order" algorithm
         */
        // @codingStandardsIgnoreStart
        /** @phpstan-ignore-next-line */
        function build_sorter($key)
        {
            return function ($a, $b) use ($key) {
                return \strnatcmp($a[$key], $b[$key]);
            };
        }

        /** @phpstan-ignore-next-line */
        \usort($arr, build_sorter($index));
        // @codingStandardsIgnoreEnd
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
            $log = \file($file) ?: [];
            foreach ($log as $k => $v) {
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
            $log = \str_replace("\n", '', (array) $log);
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
        if (!defined('DATA')) {
            return $this;
        }
        if ($email = $this->getIdentity()['email']) {
            @file_put_contents(
                DATA . '/last_seen.' . $email,
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
        $pins = $this->getData('security_pin') ?: [];
        $salt = $this->getSalt();
        foreach ($pins as $k => $v) {
            if (hash('sha256', $v . $salt) === $apikey) {
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
     * @return array<mixed> discounts and groups
     */
    public function getDiscounts($file)
    {
        $discounts = [];
        $groups = [];

        // open for writing - missing translations
        $export = null;
        defined('DATA') && $export = DATA . '/missing_translations.txt';
        if ($export && !\is_file($export)) {
            \fopen($export, 'w');
        }
        if (\is_file($file) && \is_readable($file)) {
            // load beer translations
            $trans = [];
            $trans_file = '';
            defined('APP') && $trans_file = APP . '/beer-translation.neon';
            if (\is_file($trans_file) && \is_readable($trans_file)) {
                $trans = Neon::decode(
                    \file_get_contents($trans_file) ?: ''
                );
            }
            // load group translations
            $gtrans = [];
            $gtrans_file = '';
            defined('APP') && $gtrans_file = APP . '/group-translation.neon';
            if (\is_file($gtrans_file) && \is_readable($gtrans_file)) {
                $gtrans = Neon::decode(
                    \file_get_contents($gtrans_file) ?: ''
                );
            }

            // parse data file
            $c = 0;
            $count = 1;
            $arr = \file($file);
            foreach ($arr ?: [] as $s) {
                $s = \trim($s);
                if (!\strlen($s)) {
                    continue;
                }
                // item separator
                if ($s == '---') {
                    $c = 1;
                    $el = [];
                    //$el['id'] = $count;
                    continue;
                }
                // product
                if ($c == 1) {
                    $el['product'] = (int) $s;
                    $c++;
                    continue;
                }
                // code + title + groups
                if ($c == 2) {
                    $s = \strtolower($s);
                    $el['code'] = $s;
                    $gs = $s;

                    // merge several group names together
                    $gs = \str_replace('budweiser', 'budvar', $gs);
                    $gs = \str_replace('polotmava', 'polotmave', $gs);
                    $gs = \str_replace('polotmavy', 'polotmave', $gs);
                    $gs = \str_replace('specialni', 'special', $gs);
                    $gs = \str_replace('svijanska', 'svijany', $gs);
                    $gs = \str_replace('svijanske', 'svijany', $gs);
                    $gs = \str_replace('svijansky', 'svijany', $gs);
                    $gs = \str_replace('tmava', 'tmave', $gs);
                    $gs = \str_replace('tmavy', 'tmave', $gs);

                    // compute groups
                    $g = \explode('-', $gs);
                    if (\is_array($g)) {
                        foreach ($g as $gname) {
                            if (!\array_key_exists($gname, $groups)) {
                                $groups[$gname] = [];
                            }
                            if (!\in_array($el['product'], $groups[$gname])) {
                                \array_push($groups[$gname], $el['product']);
                            }
                        }
                    }
                    $el['title'] = $trans[$s] ?? $s;

                    // export missing translation
                    if (!\array_key_exists($s, $trans)) {
                        if ($export) {
                            \file_put_contents(
                                $export,
                                $s . "\n",
                                FILE_APPEND|LOCK_EX
                            );
                        }
                    }
                    $c++;
                    continue;
                }
                // market
                if ($c == 3) {

                    // rename markets
                    $s = \str_ireplace('eso market', 'eso', $s);
                    $s = \str_ireplace('penny market', 'penny', $s);
                    $s = \str_ireplace('tamda foods', 'tamda', $s);

                    $el['market'] = \strtolower($s);
                    $c++;
                    continue;
                }
                // price
                if ($c == 4) {
                    $s = \str_replace(',', '.', $s);
                    $s = \str_replace('Kč', '', $s);
                    $el['price'] = (int) \ceil(\floatval(\trim($s)));

                    // exclude products starting with 'sklenice-na-pivo'
                    if (\strpos($el['code'], 'sklenice-na-pivo') === 0) {
                        continue;
                    }

                    // exclude products which title gets translated to 'x'
                    if (array_key_exists(
                        $el['code'], $trans
                    ) && $trans[$el['code']] == 'x'
                    ) {
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
        // @codingStandardsIgnoreStart
        foreach ($groups as $k => $v) {
            /** @phpstan-ignore-next-line */
            if (\count($v) < 3) {
                unset($groups[$k]);
            }
        }
        // @codingStandardsIgnoreEnd

        // remove vague and duplicate groups
        $rem = [
            'ale',
            'b', 'bohemia',
            'chmelene', 'chmeleny', 'classic', 'cool',
            'extra',
            'horka',
            'india', 'ipa',
            'jedenactka',
            'kralovska', 'kralovske', 'kralovsky',
            'lezak',
            'maz', 'medium',
            'na', 'nova',
            'nepasterizovana', 'nepasterizovane', 'nepasterizovany',
            'nepasterovana', 'nepasterovane', 'nepasterovany',
            'original',
            'pale', 'pardubicka', 'pardubicke', 'pardubicky',
            'pivo', 'pivovar', 'premium',
            'psenicna', 'psenicne', 'psenicny',
            'radler',
            'stare',
            'strong', 'studena', 'studene', 'studeny',
            'svetla', 'svetle', 'svetly', 'svatecni',
            'urquell',
            'vanocni',
            'velkopopovicka', 'velkopopovicke', 'velkopopovicky', 'vyber', 'vycepni',
            'za', 'zlata', 'zlate', 'zlaty',
        ];
        foreach ($rem as $x) {
            unset($groups[$x]);
        }

        // translate group names
        if ($gtrans ?? null) {
            foreach ($groups as $k => $v) {
                if (array_key_exists($k, $gtrans)) {
                    unset($groups[$k]);
                    $groups[$gtrans[$k]] = $v;
                }
            }
        }

        \ksort($groups);

        return [
            'discounts' => $discounts,
            'groups' => $groups,
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
            . $this->getData('daily_salt_seed') ?: 'SALT_SEED_IS_IN_PRIVATE_CONFIG!'
        );
    }

    /**
     * Redis access limiter
     *
     * @return mixed access count or null
     */
    public function accessLimiter()
    {
        $hour = date('H');
        $uid = $this->getUID();
        defined('SERVER') || define(
            'SERVER',
            strtolower(
                preg_replace(
                    "/[^A-Za-z0-9]/", '', $_SERVER['SERVER_NAME'] ?? 'localhost'
                )
            )
        );
        defined('PROJECT') || define('PROJECT', 'LASAGNA');
        $key = 'access_limiter_' . SERVER . '_' . PROJECT . "_{$hour}_{$uid}";
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
            $this->setLocation('/err/420');
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
