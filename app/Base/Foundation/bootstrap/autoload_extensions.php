<?php

use App\Base\Foundation\ExtensionAutoloader;

// Register the extension autoloader so that Extensions\* classes
// resolve to kebab-case directories under extensions/.
// Loaded via composer.json "autoload.files" — runs before any provider.

require_once __DIR__.'/../ExtensionAutoloader.php';

ExtensionAutoloader::register();
