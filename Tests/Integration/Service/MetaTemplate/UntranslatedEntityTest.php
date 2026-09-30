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

namespace SEOne\Tests\Integration\Service\MetaTemplate;

use PHPUnit\Framework\Attributes\Test;
use SEOne\SEOne;
use SEOne\Service\MetaTemplate\MetaTemplateField;
use SEOne\Service\MetaTemplate\MetaTemplateRepository;
use SEOne\Service\MetaTemplate\MetaTemplateService;
use SEOne\Service\SeoRequestMemo;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\Product;
use Thelia\Test\IntegrationTestCase;

/**
 * A page served in a language the record was never translated in: the template composes with
 * the values the shop falls back on, and nothing breaks.
 */
final class UntranslatedEntityTest extends IntegrationTestCase
{
    private const string UNTRANSLATED_LOCALE = 'es_ES';

    private const string TEMPLATE = '%title% %brand% | %store_name%';

    private const string STORE_NAME = 'Tienda';

    private string $defaultLangWithoutTranslation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultLangWithoutTranslation = (string) ConfigQuery::getDefaultLangWhenNoTranslationAvailable();
    }

    protected function tearDown(): void
    {
        ConfigQuery::write('default_lang_without_translation', $this->defaultLangWithoutTranslation);

        parent::tearDown();
    }

    #[Test]
    public function theTemplateComposesWithTheValuesOfTheDefaultLanguage(): void
    {
        ConfigQuery::write('default_lang_without_translation', (string) Lang::REPLACE_BY_DEFAULT_LANGUAGE);
        $product = $this->productTranslatedInTheDefaultLanguageOnly();

        self::assertSame(
            'Chair MILAN | '.self::STORE_NAME,
            $this->render($product),
        );
    }

    #[Test]
    public function theTemplateComposesWithoutTheMissingValuesWhenTheShopNeverSubstitutes(): void
    {
        ConfigQuery::write('default_lang_without_translation', (string) Lang::STRICTLY_USE_REQUESTED_LANGUAGE);
        $product = $this->productTranslatedInTheDefaultLanguageOnly();

        self::assertSame(self::STORE_NAME, $this->render($product));
    }

    private function render(Product $product): string
    {
        SEOne::setConfigValue('title', self::STORE_NAME, self::UNTRANSLATED_LOCALE);
        (new MetaTemplateRepository(new SeoRequestMemo()))
            ->saveTemplate('product', MetaTemplateField::Title, self::UNTRANSLATED_LOCALE, self::TEMPLATE);

        // The kernel lives for the whole file: what a previous test read must not answer this one.
        $this->getService(SeoRequestMemo::class)->reset();
        $service = $this->getService(MetaTemplateService::class);
        $service->reset();

        return $service->render('product', MetaTemplateField::Title, $product->getId(), self::UNTRANSLATED_LOCALE);
    }

    private function productTranslatedInTheDefaultLanguageOnly(): Product
    {
        $defaultLocale = Lang::getDefaultLanguage()->getLocale();
        self::assertNotSame(self::UNTRANSLATED_LOCALE, $defaultLocale);

        $fixtures = $this->createFixtureFactory();
        $brand = $fixtures->brand(['title' => 'MILAN', 'locale' => $defaultLocale]);
        $product = $fixtures->product(
            $fixtures->category(),
            $fixtures->taxRule(),
            $fixtures->currency(),
            ['title' => 'Chair', 'locale' => $defaultLocale],
        );
        $product->setBrandId($brand->getId())->save();

        return $product;
    }
}
