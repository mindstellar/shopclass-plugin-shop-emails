<?php

/*
 * This file is part of the Shop emails plugin for Shopclass.
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\shopemails;

/**
 * The rows in core's t_key_value, through the osc_kv_* helpers. Each call is one primary-key
 * statement; page() reads a range of the key.
 */
final class KvStore implements Store
{
    public function get(string $group, string $key)
    {
        return osc_kv_get($group, $key);
    }

    public function set(string $group, string $key, $value, ?int $ttl = null): void
    {
        osc_kv_set($group, $key, $value, $ttl);
    }

    public function delete(string $group, string $key): bool
    {
        return osc_kv_delete($group, $key);
    }

    public function page(string $group, int $limit, ?string $updatedBefore = null, ?string $afterKey = null): array
    {
        return osc_kv_list($group, $limit, $updatedBefore, $afterKey);
    }
}
