<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit;

/**
 * Reads module source for the tests that pin wiring the unit suite cannot run.
 */
trait SourceCode
{
    private function codeWithoutComments(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
