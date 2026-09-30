<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SEOne\Tests\Unit\Service\MetaTemplate;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SEOne\Service\MetaTemplate\MetaTemplateField;
use SEOne\Service\MetaTemplate\MetaTemplateRenderer;
use SEOne\Service\MetaTemplate\MetaTemplateRepository;
use SEOne\Service\MetaTemplate\MetaTemplateService;
use SEOne\Service\MetaTemplate\VariableResolverInterface;
use SEOne\Service\SeoRequestMemo;

final class MetaTemplateServiceTest extends TestCase
{
    #[Test]
    public function itListsAndValidatesTheVariablesOfAForeignResolver(): void
    {
        $service = $this->createService([]);

        self::assertArrayHasKey('dummy', $service->getResolvers());
        self::assertSame(['foo', 'store_name'], $service->getVariableNames('dummy'));
        self::assertSame(['nope'], $service->findUnknownVariables('dummy', '%foo% %nope%'));
    }

    #[Test]
    public function itRendersTheStoreNameTheModuleKeepsForTheLanguage(): void
    {
        $service = $this->createService([
            'title|fr_FR' => 'Ma boutique',
            MetaTemplateRepository::templateKey('dummy', MetaTemplateField::Title).'|fr_FR' => '%foo% - %store_name%',
        ]);

        self::assertSame('bar - Ma boutique', $service->render('dummy', MetaTemplateField::Title, 1, 'fr_FR'));
    }

    /**
     * @param array<string, string> $settings module settings keyed by "name|locale"
     */
    private function createService(array $settings): MetaTemplateService
    {
        $resolver = new class implements VariableResolverInterface {
            public function getView(): string
            {
                return 'dummy';
            }

            public function getVariableNames(): array
            {
                return ['foo'];
            }

            public function resolve(int $id, string $locale): array
            {
                return ['foo' => 'bar'];
            }
        };

        $seoRequestMemo = $this->createMock(SeoRequestMemo::class);
        $seoRequestMemo->method('getConfigValue')->willReturnCallback(
            static fn (string $name, ?string $default = null, ?string $locale = null): ?string => $settings[$name.'|'.$locale] ?? $default,
        );

        return new MetaTemplateService([$resolver], new MetaTemplateRepository($seoRequestMemo), new MetaTemplateRenderer());
    }
}
