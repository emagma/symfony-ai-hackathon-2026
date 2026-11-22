<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Locale;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Locale\UpsertLocales;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertLocales::class)]
final class UpsertLocalesTest extends McpToolTestCase
{
    public function testItCreatesTheMissingCodesWithTheSingleToolsBody(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn([
            'it_IT' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'fr_FR' => ['ok' => true, 'body' => ['code' => 'fr_FR']],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['it_IT' => ['path' => 'locales', 'body' => ['code' => 'it_IT']]])
            ->willReturn(['it_IT' => ['ok' => true, 'body' => ['code' => 'it_IT']]]);

        $result = $this->call(new UpsertLocales($client, $this->audit()), items: [['code' => 'it_IT'], ['code' => 'fr_FR']], confirm: true);

        self::assertSame('created', $result['results']['it_IT']['action']);
        self::assertSame('unchanged', $result['results']['fr_FR']['action']);
    }

    public function testModeUpdateOnAnUnknownLocaleIsNotFound(): void
    {
        $client = $this->apiClient();
        $client->method('getDecodedMany')->willReturn(['xx_XX' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404']]);
        $client->expects(self::never())->method('postMany');

        $result = $this->call(new UpsertLocales($client, $this->audit()), items: [['code' => 'xx_XX']], mode: 'update', confirm: true);

        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'not_found'], $result['results']['xx_XX']);
    }
}
