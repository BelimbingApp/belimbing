<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

// Register the extension autoloader so that Extensions\* classes
// resolve to kebab-case directories under extensions/.
// Loaded via composer.json "autoload.files" — runs before any provider.

require __DIR__.'/../ExtensionAutoloader.php';

App\Base\Foundation\ExtensionAutoloader::register();
