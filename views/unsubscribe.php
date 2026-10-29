<?php
/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\shopemails\Web;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

if (Web::$state === 'ask') { ?>
    <p><?php echo osc_esc_html(__('You will get no more newsletters from us. Order e-mails still come.', 'shop-emails')); ?></p>
    <form method="post" action="<?php echo osc_esc_html(Web::url(Web::UNSUBSCRIBE, Web::$token)); ?>">
        <input type="hidden" name="token" value="<?php echo osc_esc_html(Web::$token); ?>">
        <p><button type="submit"><?php echo osc_esc_html(__('Unsubscribe', 'shop-emails')); ?></button></p>
    </form>
<?php } elseif (Web::$state === 'removed') { ?>
    <p><?php echo osc_esc_html(__('Your e-mail is off the list and we have deleted it.', 'shop-emails')); ?></p>
<?php } else { ?>
    <p><?php echo osc_esc_html(__('This link is not complete, or the e-mail is no longer on the list.', 'shop-emails')); ?></p>
<?php } ?>
<p><a href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(__('Back to the shop', 'shop-emails')); ?></a></p>
