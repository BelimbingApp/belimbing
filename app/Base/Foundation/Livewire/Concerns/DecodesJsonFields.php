<?php
namespace App\Base\Foundation\Livewire\Concerns;

use App\Base\Support\Json;

trait DecodesJsonFields
{
    protected function decodeJsonField(?string $value): ?array
    {
        return Json::decodeArray($value);
    }
}
