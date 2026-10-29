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

if (Web::$state === 'confirmed') { ?>
    <p><?php echo osc_esc_html(__('Thank you. Your e-mail is on our list, and we will write when there is news.', 'shop-emails')); ?></p>
<?php } else { ?>
    <p><?php echo osc_esc_html(__('The link has expired or is not complete. Sign up again and use the newest mail we send.', 'shop-emails')); ?></p>
<?php } ?>
<p><a href="<?php echo osc_esc_html(osc_base_url()); ?>"><?php echo osc_esc_html(__('Back to the shop', 'shop-emails')); ?></a></p>
