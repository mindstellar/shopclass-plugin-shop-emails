<?php
/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Loads the plugin's classes with no core, no database and no network. The few core names the
 * code calls are stood in for here.
 */

namespace mindstellar\currency {
    if (!class_exists(Money::class)) {
        final class Money
        {
            public static function format(int $micros, string $currency): string
            {
                return number_format($micros / 1000000, 2, '.', ',') . ' ' . $currency;
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/harness.php';

    date_default_timezone_set('UTC');
    ini_set('display_errors', '1');
    error_reporting(E_ALL);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'mindstellar\\shopemails\\';
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            require __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });

    function __($text, $domain = '')
    {
        return $text;
    }
    function osc_esc_html($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    function osc_apply_filter($name, $value, ...$args)
    {
        return isset($GLOBALS['filters'][$name]) ? $GLOBALS['filters'][$name]($value, ...$args) : $value;
    }
    function osc_mailBeauty($text, $params)
    {
        return str_ireplace($params[0], $params[1], (string) $text);
    }
    function osc_page_title()
    {
        return 'Tea & Co <Shop>';
    }
    function osc_base_url($withIndex = false)
    {
        return 'https://shop.example/';
    }
    function osc_route_url($id, $args = array())
    {
        $q = $args === array() ? '' : '?' . http_build_query($args);

        return 'https://shop.example/route/' . $id . $q;
    }
    function osc_route_admin_url($id, $args = array())
    {
        return 'https://shop.example/oc-admin/index.php?page=plugins&action=renderplugin&route=' . $id;
    }
    function osc_admin_base_url($withIndex = false)
    {
        return 'https://shop.example/oc-admin/' . ($withIndex ? 'index.php' : '');
    }
    function osc_currency()
    {
        return 'INR';
    }
    $GLOBALS['prefs'] = array();
    function osc_get_preference($name, $section = 'osclass')
    {
        return $GLOBALS['prefs'][$section][$name] ?? '';
    }
    function osc_set_preference($name, $value, $section = 'osclass')
    {
        $GLOBALS['prefs'][$section][$name] = $value;
    }

    // Admin and request stand-ins for Screens.
    $GLOBALS['calls']    = array();
    $GLOBALS['is_admin'] = true;
    $GLOBALS['request']  = array();
    $GLOBALS['server']   = array();
    final class Params
    {
        public static function getParamString($name)
        {
            $v = $GLOBALS['request'][$name] ?? '';

            return is_string($v) ? $v : '';
        }

        public static function getServerParam($name)
        {
            return $GLOBALS['server'][$name] ?? '';
        }
    }
    final class StopRequest extends \RuntimeException
    {
    }
    function osc_csrf_check()
    {
        $GLOBALS['calls'][] = 'csrf';
        if (empty($GLOBALS['csrf_ok'])) {
            throw new StopRequest('csrf');
        }
    }
    function osc_is_admin_user_logged_in()
    {
        return $GLOBALS['is_admin'];
    }
    function osc_is_moderator()
    {
        return false;
    }
    function osc_redirect_to($url)
    {
        $GLOBALS['calls'][] = 'redirect:' . $url;
        throw new StopRequest('redirect');
    }
    function osc_add_flash_ok_message($m, $s = '')
    {
        $GLOBALS['calls'][] = 'ok:' . $m;
    }
    function osc_add_flash_error_message($m, $s = '')
    {
        $GLOBALS['calls'][] = 'error:' . $m;
    }

    /** Core's key-value store in memory: expiry, last write time and key order as core has them. */
    final class ArrayStore implements \mindstellar\shopemails\Store
    {
        /** @var array<string,array<string,array{0:mixed,1:?int,2:int}>> value, expires, updated */
        public array $rows = array();

        public int $now = 1760000000;

        public function get(string $group, string $key)
        {
            $row = $this->rows[$group][$key] ?? null;

            return $row === null || ($row[1] !== null && $row[1] <= $this->now) ? null : $row[0];
        }

        public function set(string $group, string $key, $value, ?int $ttl = null): void
        {
            $this->rows[$group][$key] = array(json_decode(json_encode($value), true), $ttl === null ? null : $this->now + $ttl, $this->now);
        }

        public function delete(string $group, string $key): bool
        {
            $had = $this->get($group, $key) !== null;
            unset($this->rows[$group][$key]);

            return $had;
        }

        public function page(string $group, int $limit, ?string $updatedBefore = null, ?string $afterKey = null): array
        {
            $keys = array_keys($this->rows[$group] ?? array());
            $keys = array_map('strval', $keys);
            usort($keys, 'strcmp');
            $out = array();
            foreach ($keys as $key) {
                [$value, $expires, $updated] = $this->rows[$group][$key];
                if (($expires !== null && $expires <= $this->now)
                    || ($afterKey !== null && strcmp($key, $afterKey) <= 0)
                    || ($updatedBefore !== null && gmdate('Y-m-d H:i:s', $updated) >= $updatedBefore)
                ) {
                    continue;
                }
                $out[] = array('key' => $key, 'value' => $value, 'updated_at' => $updated, 'expires_at' => $expires);
                if (count($out) >= $limit) {
                    break;
                }
            }

            return $out;
        }

        /** The expiry a live row has, or false when there is none. */
        public function expiry(string $group, string $key)
        {
            return isset($this->rows[$group][$key]) ? $this->rows[$group][$key][1] : false;
        }
    }

    /** A fresh store, clock, mailer and settings for each case. */
    function fresh(array $settings = array()): ArrayStore
    {
        $store = new ArrayStore();
        \mindstellar\shopemails\Plugin::$store    = $store;
        \mindstellar\shopemails\Plugin::$clock    = static fn (): int => $store->now;
        \mindstellar\shopemails\Plugin::$settings = $settings;
        $GLOBALS['mails'] = array();
        \mindstellar\shopemails\Plugin::$mailer = static function (array $params): bool {
            $GLOBALS['mails'][] = $params;

            return empty($GLOBALS['mail_fails']);
        };
        $GLOBALS['mail_fails'] = false;

        return $store;
    }
}
