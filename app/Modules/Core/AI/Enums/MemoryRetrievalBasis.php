<?php
namespace App\Modules\Core\AI\Enums;

/**
 * How a memory search result was retrieved.
 *
 * Included in every search result so retrieval quality is transparent
 * to agents and operators.
 */
enum MemoryRetrievalBasis: string
{
    case Keyword = 'keyword';
    case Vector = 'vector';
    case Hybrid = 'hybrid';
}
