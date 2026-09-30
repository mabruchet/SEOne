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
use SEOne\Service\MetaTemplate\MetaTemplateService;
use SEOne\Service\SeoRequestMemo;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Template\Helper\FormatService;
use Thelia\Model\Country;
use Thelia\Model\Currency;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * The price variables of a product page read the price the way the front does: in the currency
 * the visitor browses in, converted from the default currency when the shopkeeper typed no
 * price in that currency.
 */
final class ProductVariableResolverPriceTest extends IntegrationTestCase
{
    private const string LOCALE = 'en_US';

    private const float DEFAULT_CURRENCY_PRICE = 10.0;

    private const float RATE = 2.0;

    private FixtureFactory $fixtures;

    private Currency $browsingCurrency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = $this->createFixtureFactory();
        $this->browsingCurrency = $this->fixtures->currency(['code' => 'XTS', 'symbol' => 'X', 'rate' => self::RATE]);
        $this->session()->setCurrency($this->browsingCurrency);
    }

    protected function tearDown(): void
    {
        $this->session()->setCurrency(Currency::getDefaultCurrency());

        parent::tearDown();
    }

    #[Test]
    public function aProductPricedInTheDefaultCurrencyOnlyIsConvertedAtTheRateOfTheBrowsingCurrency(): void
    {
        $product = $this->productPricedInTheDefaultCurrency();

        $values = $this->variableValues($product);

        self::assertSame($this->money(self::DEFAULT_CURRENCY_PRICE * self::RATE), $values['untaxed_price']);
        self::assertSame($this->taxedMoney($product, self::DEFAULT_CURRENCY_PRICE * self::RATE), $values['taxed_price']);
    }

    #[Test]
    public function aRowConvertedFromTheDefaultCurrencyIsRecomputedNotReadAsIs(): void
    {
        $product = $this->productPricedInTheDefaultCurrency();
        $this->fixtures->productPrice(
            ProductSaleElementsQuery::create()->filterByProductId($product->getId())->filterByIsDefault(true)->findOne(),
            $this->browsingCurrency,
            ['price' => '999.000000', 'promoPrice' => '999.000000', 'fromDefaultCurrency' => true],
        );

        $values = $this->variableValues($product);

        self::assertSame($this->money(self::DEFAULT_CURRENCY_PRICE * self::RATE), $values['untaxed_price']);
    }

    private function productPricedInTheDefaultCurrency(): Product
    {
        return $this->fixtures->product(
            $this->fixtures->category(),
            $this->fixtures->taxRule(),
            Currency::getDefaultCurrency(),
            ['basePrice' => self::DEFAULT_CURRENCY_PRICE, 'title' => 'Chair', 'locale' => self::LOCALE],
        );
    }

    /**
     * @return array<string, string>
     */
    private function variableValues(Product $product): array
    {
        // The kernel lives for the whole file: what a previous test read must not answer this one.
        $this->getService(SeoRequestMemo::class)->reset();
        $service = $this->getService(MetaTemplateService::class);
        $service->reset();

        return $service->getVariableValues('product', $product->getId(), self::LOCALE);
    }

    private function money(float $amount): string
    {
        return $this->getService(FormatService::class)->money($amount, $this->browsingCurrency->getId(), self::LOCALE);
    }

    private function taxedMoney(Product $product, float $amount): string
    {
        return $this->money((float) $product->getTaxedPrice(Country::getDefaultCountry(), $amount));
    }

    private function session(): Session
    {
        $session = $this->getService(RequestStack::class)->getCurrentRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);

        return $session;
    }
}
