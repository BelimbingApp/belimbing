<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Browser;

use RuntimeException;

/**
 * Thrown when a browser session lifecycle operation fails.
 *
 * Covers: invalid state transitions, session not found, concurrency
 * limit exceeded, and other session-level invariant violations.
 */
final class BrowserSessionException extends RuntimeException {}
