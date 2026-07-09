<?php

namespace App\Base\Livewire;

use App\Base\Foundation\Services\DomainState;
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
     * Supports Base modules and Core modules.
     */
    protected array $scanPatterns = [
        'app/Base/*/Livewire',
        'app/Modules/*/*/Livewire',
    ];

    /**
     * Extension Livewire directories. Extension component names are prefixed
     * with their view namespace ("kiat-investment.widgets.foo") so different
     * extensions can never collide with each other or with core components.
     */
    protected array $extensionScanPatterns = [
        'extensions/*/*/Livewire',
    ];

    /**
     * Discover all Livewire component classes and their view-derived names.
     *
     * Scans module Livewire directories for Component subclasses, then
     * derives the component name from the view('livewire.xxx') or
     * view('namespace::livewire.xxx') call in the class source. The view
     * namespace and 'livewire.' prefix are stripped to produce the component
     * name for <livewire:name /> tags and Livewire::test('name').
     *
     * @return array<string, class-string<Component>> Component name => FQCN
     */
    public function discover(): array
    {
        $components = [];

        foreach ($this->scanPatterns as $pattern) {
            $directories = DomainState::filterPaths(glob(base_path($pattern), GLOB_ONLYDIR) ?: []);

            foreach ($directories as $directory) {
                $this->scanDirectory($directory, $components);
            }
        }

        foreach ($this->extensionScanPatterns as $pattern) {
            $directories = DomainState::filterPaths(glob(base_path($pattern), GLOB_ONLYDIR) ?: []);

            foreach ($directories as $directory) {
                $this->scanDirectory($directory, $components, prefixViewNamespace: true);
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
    protected function scanDirectory(string $directory, array &$components, bool $prefixViewNamespace = false): void
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

            $name = $this->resolveComponentName($file->getPathname(), $prefixViewNamespace);

            if ($name !== null) {
                $components[$name] = $class;
            }
        }
    }

    /**
     * Resolve the component name from a Livewire class file.
     *
     * Extracts the first view('livewire.xxx') or namespaced
     * view('namespace::livewire.xxx') call from the source and strips the
     * namespace and 'livewire.' prefix. For example, a class returning
     * view('livewire.admin.companies.index') or
     * view('core-user::livewire.admin.companies.index') gets the name
     * 'admin.companies.index'.
     *
     * Falls back to VIEW_NAME constant if no view() call is found.
     *
     * @param  string  $filePath  Absolute path to the PHP class file
     */
    protected function resolveComponentName(string $filePath, bool $prefixViewNamespace = false): ?string
    {
        $source = file_get_contents($filePath);

        if ($source === false) {
            return null;
        }

        $name = null;

        // Match the first view('livewire.xxx') or view('namespace::livewire.xxx') call in the file.
        // This covers: view('livewire.xxx'), view('namespace::livewire.xxx', [...]), view('livewire.xxx', $this->with())
        // Fallback: check for VIEW_NAME constant with optional namespace and 'livewire.' prefix.
        if (preg_match("/view\(\s*'(?:([\w.\-]+)::)?(livewire\.[\w.\-]+)'/", $source, $matches)
            || preg_match("/const\s+string\s+VIEW_NAME\s*=\s*'(?:([\w.\-]+)::)?(livewire\.[\w.\-]+)'/", $source, $matches)
        ) {
            $name = BlbStr::afterPrefix($matches[2], 'livewire.', false);

            if ($prefixViewNamespace && $matches[1] !== '') {
                $name = $matches[1].'.'.$name;
            }
        }

        return $name;
    }

    /**
     * Convert an absolute file path to a fully-qualified class name.
     *
     * app/ maps to App\ (PSR-4); extensions/{owner}/{module}/ maps to
     * Extensions\{Owner}\{Module}\ per ExtensionAutoloader's kebab-to-Pascal
     * convention.
     *
     * @param  string  $path  Absolute file path
     */
    protected function classFromPath(string $path): ?string
    {
        return AppPath::toClass($path) ?? $this->extensionClassFromPath($path);
    }

    private function extensionClassFromPath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = rtrim(str_replace('\\', '/', base_path('extensions')), '/').'/';

        if (! str_starts_with($normalized, $base)) {
            return null;
        }

        $segments = explode('/', substr($normalized, strlen($base)));

        if (count($segments) < 3) {
            return null;
        }

        $owner = str()->studly(array_shift($segments));
        $module = str()->studly(array_shift($segments));
        $rest = str_replace('.php', '', implode('\\', $segments));

        return 'Extensions\\'.$owner.'\\'.$module.'\\'.$rest;
    }
}
