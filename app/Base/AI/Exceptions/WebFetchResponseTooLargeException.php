<?php

namespace App\Base\AI\Exceptions;

use RuntimeException;

/**
 * Internal sentinel used to abort a streamed response at the byte limit.
 */
class WebFetchResponseTooLargeException extends RuntimeException {}
