<?php

namespace GlpiPlugin\Glpimsteams\Tests\Unit;

use GlpiPlugin\Glpimsteams\Service\ContentSanitizer;
use PHPUnit\Framework\TestCase;

final class ContentSanitizerTest extends TestCase
{
    public function testSensitiveAssignmentsAreRedacted(): void
    {
        $sanitizer = new ContentSanitizer();

        $result = $sanitizer->text('<b>Senha</b>: super-secret-token');

        self::assertSame('Senha: [REDACTED]', $result);
    }

    public function testMarkupAndLengthAreRemoved(): void
    {
        $sanitizer = new ContentSanitizer();

        $result = $sanitizer->text('<b>abcdef</b>', 3);

        self::assertSame('abc', $result);
    }
}
