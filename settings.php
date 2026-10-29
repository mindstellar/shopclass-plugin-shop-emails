<?php

/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The settings page declaration. Core renders the form, checks CSRF and the admin's capability,
 * validates each field and stores it under the "shop-emails" section.
 */

use mindstellar\shopemails\Plugin;

$seRange = static function (int $min, int $max): Closure {
    return static function ($value) use ($min, $max): ?string {
        $n = filter_var($value, FILTER_VALIDATE_INT);

        return $n === false || $n < $min || $n > $max
            ? sprintf(__('Use a whole number from %1$d to %2$d.', 'shop-emails'), $min, $max)
            : null;
    };
};
$seDefaults = Plugin::defaults();
// The cart reminder is for shops: a classifieds site does not show its fields.
$seFields = static function (array $fields): array {
    if (Plugin::shopMode()) {
        return $fields;
    }

    return array_values(array_filter($fields, static fn (array $f): bool => strpos($f['name'], 'reminder_') !== 0 && $f['name'] !== 'cart_url'));
};
$seWords = __('You can use {WEB_TITLE} for the site name.', 'shop-emails');

return array(
    'title'  => __('Shop emails', 'shop-emails'),
    'menu'   => 'settings',
    'group'  => 'email',
    'intro'  => __('The newsletter sign-up list and, on a shop, the reminder for a cart left behind. See the list under Users, Newsletter subscribers.', 'shop-emails'),
    'fields' => $seFields(array(
        array(
            'type'    => 'checkbox',
            'name'    => 'double_optin',
            'label'   => __('Ask new subscribers to confirm by e-mail', 'shop-emails'),
            'help'    => __('Recommended. An address joins the list only after its owner clicks the link in the mail. Unconfirmed sign-ups are removed after 3 days.', 'shop-emails'),
            'default' => $seDefaults['double_optin'],
        ),
        array(
            'type'      => 'text',
            'name'      => 'confirm_subject',
            'label'     => __('Confirmation subject', 'shop-emails'),
            'help'      => $seWords,
            'maxlength' => 150,
            'default'   => $seDefaults['confirm_subject'],
            'depends'   => 'double_optin',
            'required'  => true,
        ),
        array(
            'type'     => 'textarea',
            'name'     => 'confirm_text',
            'label'    => __('Confirmation text', 'shop-emails'),
            'help'     => __('Plain text. The confirm button and an unsubscribe link are added below it.', 'shop-emails') . ' ' . $seWords,
            'default'  => $seDefaults['confirm_text'],
            'depends'  => 'double_optin',
            'required' => true,
        ),
        array(
            'type'     => 'number',
            'name'     => 'rate_limit',
            'label'    => __('Sign-ups per visitor per hour', 'shop-emails'),
            'help'     => __('More tries than this from one address in an hour are refused with "Please wait".', 'shop-emails'),
            'default'  => $seDefaults['rate_limit'],
            'min'      => 1,
            'max'      => 1000,
            'required' => true,
            'validate' => $seRange(1, 1000),
        ),
        array(
            'type'    => 'checkbox',
            'name'    => 'reminder_on',
            'label'   => __('Remind signed-in shoppers about a cart they left', 'shop-emails'),
            'help'    => __('One e-mail per shopper at most every 30 days. Carts of shoppers who are not signed in stay in their browser and get none.', 'shop-emails'),
            'default' => $seDefaults['reminder_on'],
        ),
        array(
            'type'     => 'number',
            'name'     => 'reminder_delay',
            'label'    => __('Send after (hours)', 'shop-emails'),
            'help'     => __('How long a cart sits unchanged before the reminder goes.', 'shop-emails'),
            'default'  => $seDefaults['reminder_delay'],
            'min'      => 1,
            'max'      => 720,
            'depends'  => 'reminder_on',
            'required' => true,
            'validate' => $seRange(1, 720),
        ),
        array(
            'type'     => 'number',
            'name'     => 'reminder_max_age',
            'label'    => __('Skip carts older than (days)', 'shop-emails'),
            'help'     => __('Counted after the wait above. Stops a first run from mailing every cart left months ago.', 'shop-emails'),
            'default'  => $seDefaults['reminder_max_age'],
            'min'      => 1,
            'max'      => 90,
            'depends'  => 'reminder_on',
            'required' => true,
            'validate' => $seRange(1, 90),
        ),
        array(
            'type'      => 'text',
            'name'      => 'reminder_subject',
            'label'     => __('Reminder subject', 'shop-emails'),
            'help'      => $seWords,
            'maxlength' => 150,
            'default'   => $seDefaults['reminder_subject'],
            'depends'   => 'reminder_on',
            'required'  => true,
        ),
        array(
            'type'     => 'textarea',
            'name'     => 'reminder_intro',
            'label'    => __('Reminder text', 'shop-emails'),
            'help'     => __('Plain text above the list of items and the link to the cart.', 'shop-emails') . ' ' . __('You can use {USER_NAME} and {WEB_TITLE}.', 'shop-emails'),
            'default'  => $seDefaults['reminder_intro'],
            'depends'  => 'reminder_on',
            'required' => true,
        ),
        array(
            'type'     => 'url',
            'name'     => 'cart_url',
            'label'    => __('Cart link', 'shop-emails'),
            'help'     => __('Where the reminder\'s button goes, on this site. Leave empty for the home page with #cart, which opens the cart in the Aisle theme.', 'shop-emails'),
            'default'  => '',
            'depends'  => 'reminder_on',
            'validate' => static function ($value): ?string {
                return trim((string) $value) === '' || Plugin::sameSite(trim((string) $value))
                    ? null
                    : __('Use an address on this site.', 'shop-emails');
            },
        ),
        array(
            'type'     => 'number',
            'name'     => 'reminder_max',
            'label'    => __('Reminders per hourly run', 'shop-emails'),
            'help'     => __('At most this many reminders are queued each hour, so a busy shop does not flood its mail server.', 'shop-emails'),
            'default'  => $seDefaults['reminder_max'],
            'min'      => 1,
            'max'      => 500,
            'depends'  => 'reminder_on',
            'required' => true,
            'validate' => $seRange(1, 500),
        ),
    )),
);
