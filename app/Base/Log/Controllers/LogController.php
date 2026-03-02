<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Log\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class LogController
{
    /**
     * Show log files and selected tail content.
     */
    public function index(Request $request): View
    {
        $logPath = storage_path('logs');
        $selectedFile = basename($request->string('file', '')->toString());

        $files = collect(File::files($logPath))
            ->filter(fn ($file) => $file->getExtension() === 'log')
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->values();

        $tailContent = null;
        if ($selectedFile !== '') {
            $path = $logPath.DIRECTORY_SEPARATOR.$selectedFile;
            if (File::exists($path) && str_starts_with(realpath($path), realpath($logPath))) {
                $lines = file($path, FILE_IGNORE_NEW_LINES);
                $tailContent = implode("\n", array_slice($lines, -100));
            }
        }

        return view('admin.system.logs.index', compact('files', 'selectedFile', 'tailContent'));
    }
}
