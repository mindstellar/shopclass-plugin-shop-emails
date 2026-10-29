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
 * Where the plugin keeps its rows: core's key-value store, or an array in tests.
 */
interface Store
{
    /** @return mixed the value, or null when absent or expired */
    public function get(string $group, string $key);

    /** @param mixed $value */
    public function set(string $group, string $key, $value, ?int $ttl = null): void;

    public function delete(string $group, string $key): bool;

    /**
     * One page of live rows in key order, as osc_kv_list() returns them.
     *
     * @return list<array{key:string, value:mixed, updated_at:?int, expires_at:?int}>
     */
    public function page(string $group, int $limit, ?string $updatedBefore = null, ?string $afterKey = null): array;
}
