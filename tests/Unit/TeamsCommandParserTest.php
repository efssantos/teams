<?php

namespace GlpiPlugin\Glpimsteams\Tests\Unit;

use GlpiPlugin\Glpimsteams\Service\ContentSanitizer;
use GlpiPlugin\Glpimsteams\Service\TeamsCommandParser;
use PHPUnit\Framework\TestCase;

final class TeamsCommandParserTest extends TestCase
{
    public function testCreateCommandIsStructuredAndSanitized(): void
    {
        $parser = new TeamsCommandParser(new ContentSanitizer());

        self::assertSame([
            'name' => 'create',
            'title' => 'VPN',
            'content' => 'Senha: [REDACTED]',
        ], $parser->parse('novo chamado | VPN | Senha: abc123'));
    }

    public function testStatusAndLinkCommandsAreRecognized(): void
    {
        $parser = new TeamsCommandParser(new ContentSanitizer());

        self::assertSame(['name' => 'status', 'ticket_id' => 12, 'status_id' => 5], $parser->parse('status #12 | 5'));
        self::assertSame(['name' => 'link'], $parser->parse('vincular'));
    }
}
