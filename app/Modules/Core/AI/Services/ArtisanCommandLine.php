<?php

namespace App\Modules\Core\AI\Services;

/**
 * Splits an artisan command string into argv tokens.
 *
 * The tokens are handed to Process as an array, so no shell ever sees the
 * string and metacharacters stay inert data. One implementation is shared by
 * the allowlist check and both execution paths on purpose: if the parse used
 * to authorize a command could differ from the parse that runs it, the
 * allowlist would be bypassable.
 */
final class ArtisanCommandLine
{
    /**
     * Split on unquoted whitespace, honouring single and double quotes
     * anywhere in a token and dropping the quote characters themselves, so
     * `--path='database/migrations'` reaches artisan as one unquoted value.
     * An unterminated quote runs to the end of the string rather than failing.
     *
     * @return list<string>
     */
    public static function tokenize(string $command): array
    {
        $tokens = [];
        $current = '';
        $started = false;
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                } else {
                    $current .= $char;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $started = true;

                continue;
            }

            if (ctype_space($char)) {
                if ($started) {
                    $tokens[] = $current;
                    $current = '';
                    $started = false;
                }

                continue;
            }

            $current .= $char;
            $started = true;
        }

        if ($started) {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
