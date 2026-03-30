<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Livewire;

use App\Base\Support\AppPath;
use App\Base\Support\Str as BlbStr;
use Livewire\Component;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ComponentDiscoveryService
{
    /**
     * Glob patterns for Livewire class directory discovery.
     *
     * Supports Base modules, Core modules, and extensions.
     */
    protected array $scanPatterns = [
        'app/Base/*/Livewire',
        'app/Modules/*/*/Livewire',
    ];

    /**
     * Discover all Livewire component classes and their view-derived names.
     *
     * Scans module Livewire directories for Component subclasses, then
     * derives the component name from the view('livewire.xxx') call in
     * the class source. The 'livewire.' prefix is stripped to produce the
     * component name for <livewire:name /> tags and Livewire::test('name').
     *
     * @return array<string, class-string<Component>> Component name => FQCN
     */
    public function discover(): array
    {
        $components = [];

        foreach ($this->scanPatterns as $pattern) {
            $directories = glob(base_path($pattern), GLOB_ONLYDIR);

            foreach ($directories as $directory) {
                $this->scanDirectory($directory, $components);
            }
        }

        return $components;
    }

    /**
     * Recursively scan a directory for Livewire component classes.
     *
     * @param  string  $directory  Absolute path to scan
     * @param  array<string, class-string<Component>>  $components  Accumulated mapping (mutated)
     */
    protected function scanDirectory(string $directory, array &$components): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Skip trait files in Concerns/ directories
            if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Concerns'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $class = $this->classFromPath($file->getPathname());

            if (! $class || ! class_exists($class)) {
                continue;
            }

            if (! is_subclass_of($class, Component::class)) {
                continue;
            }

            $name = $this->resolveComponentName($file->getPathname());

            if ($name !== null) {
                $components[$name] = $class;
            }
        }
    }

    /**
     * Resolve the component name from a Livewire class file.
     *
     * Extracts the first view('livewire.xxx') call from the source
     * and strips the 'livewire.' prefix. For example, a class returning
     * view('livewire.admin.companies.index') gets the name 'admin.companies.index'.
     *
     * Falls back to VIEW_NAME constant if no view() call is found.
     *
     * @param  string  $filePath  Absolute path to the PHP class file
     */
    protected function resolveComponentName(string $filePath): ?string
    {
        $source = file_get_contents($filePath);

        if ($source === false) {
            return null;
        }

        $name = null;

        // Match the first view('livewire.xxx') call in the file.
        // This covers: view('livewire.xxx'), view('livewire.xxx', [...]), view('livewire.xxx', $this->with())
        // Fallback: check for VIEW_NAME constant with 'livewire.' prefix
        if (preg_match("/view\(\s*'(livewire\.[\w.\-]+)'/", $source, $matches)
            || preg_match("/const\s+string\s+VIEW_NAME\s*=\s*'(livewire\.[\w.\-]+)'/", $source, $matches)
        ) {
            $name = BlbStr::afterPrefix($matches[1], 'livewire.', false);
        }

        return $name;
    }

    /**
     * Convert an absolute file path to a fully-qualified class name.
     *
     * Assumes PSR-4 mapping: app/ => App\
     *
     * @param  string  $path  Absolute file path
     */
    protected function classFromPath(string $path): ?string
    {
        return AppPath::toClass($path);
    }
}
