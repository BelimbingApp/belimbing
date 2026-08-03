<?php
namespace App\Base\Locale\DTO;

use App\Base\Locale\Enums\LocaleSource;

final readonly class ResolvedLocale
{
    public function __construct(
        public string $locale,
        public string $language,
        public string $carbonLocale,
        public string $intlLocale,
        public string $numberLocale,
        public LocaleSource $source,
        public ?string $inferredCountry = null,
    ) {}
}
