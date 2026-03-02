<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Controllers;

use Illuminate\Support\Number;
use Illuminate\View\View;

class SystemInfoController
{
    /**
     * Show system information.
     */
    public function index(): View
    {
        $php = [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'extensions' => get_loaded_extensions(),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
        ];

        $laravel = [
            'version' => \Illuminate\Foundation\Application::VERSION,
            'environment' => app()->environment(),
            'debug' => config('app.debug'),
            'url' => config('app.url'),
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
        ];

        $database = [
            'connection' => config('database.default'),
            'driver' => config('database.connections.'.config('database.default').'.driver'),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'host' => config('database.connections.'.config('database.default').'.host'),
        ];

        $server = [
            'os' => PHP_OS_FAMILY.' '.php_uname('r'),
            'hostname' => gethostname(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? __('CLI'),
            'disk_free' => Number::fileSize(disk_free_space(base_path())),
            'disk_total' => Number::fileSize(disk_total_space(base_path())),
        ];

        return view('admin.system.info.index', compact('php', 'laravel', 'database', 'server'));
    }
}
