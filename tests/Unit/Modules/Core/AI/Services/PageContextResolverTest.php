<?php

use App\Modules\Core\AI\Contracts\ProvidesLaraPageContext;
use App\Modules\Core\AI\DTO\PageContext;
use App\Modules\Core\AI\Services\PageContextResolver;
use App\Modules\Core\Employee\Models\Employee;
use Tests\TestCase;

uses(TestCase::class);

class PageContextResolverTestComponent implements ProvidesLaraPageContext
{
    public static ?string $fixedTab = null;

    public string $slug = '';

    public Employee $owner; // typed model prop — raw segment must be skipped, not fatal

    public function pageContext(?string $pageUrl = null): PageContext
    {
        return new PageContext(
            route: 'page-context-resolver-test.show',
            url: '',
            title: 'Case study '.$this->slug,
            activeTab: self::$fixedTab,
        );
    }
}

beforeEach(function (): void {
    PageContextResolverTestComponent::$fixedTab = null;

    $route = app('router')
        ->get('page-context-resolver-test/{owner}/{slug}', fn (): string => '')
        ->name('page-context-resolver-test.show');

    $route->setAction(array_merge($route->getAction(), [
        'livewire_component' => PageContextResolverTestComponent::class,
    ]));

    app('router')->getRoutes()->refreshNameLookups();

    $this->resolver = new PageContextResolver(app('router'));
});

it('hydrates string route parameters and skips incompatible typed properties', function (): void {
    $context = $this->resolver->resolveFromUrl('http://localhost/page-context-resolver-test/jane/cresbld-8591');

    expect($context)->not->toBeNull()
        ->and($context->title)->toBe('Case study cresbld-8591');
});

it('enriches component context with the client URL and hash tab', function (): void {
    $url = 'http://localhost/page-context-resolver-test/jane/cresbld-8591#advise';
    $context = $this->resolver->resolveFromUrl($url);

    expect($context)->not->toBeNull()
        ->and($context->url)->toBe($url)
        ->and($context->activeTab)->toBe('advise');
});

it('keeps a component-provided active tab over the hash fragment', function (): void {
    PageContextResolverTestComponent::$fixedTab = 'financials';

    $context = $this->resolver->resolveFromUrl('http://localhost/page-context-resolver-test/jane/cresbld-8591#advise');

    expect($context)->not->toBeNull()
        ->and($context->activeTab)->toBe('financials');
});
