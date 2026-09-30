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

namespace SEOne\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use Propel\Runtime\Propel;
use SEOne\Service\MetaTemplate\MetaTemplateField;
use SEOne\Service\MetaTemplate\MetaTemplateRepository;
use SEOne\Service\SeoRequestMemo;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Model\Admin;
use Thelia\Model\LangQuery;
use Thelia\Model\Product;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;

/**
 * The meta template card of the SEOne configuration screen: the order of priority, the example
 * values of the variables, and the preview of a template on a record, saved or not.
 */
final class MetaTemplateConfigurationScreenTest extends WebIntegrationTestCase
{
    private const string SCREEN = '/admin/module/SEOne';
    private const string PREVIEW = '/admin/module/seone/configuration/meta-templates/preview';

    private AdminSessionInjector $injector;

    private FixtureFactory $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->injector = new AdminSessionInjector();
        $this->getContainer()->get(EventDispatcherInterface::class)->addSubscriber($this->injector);

        // Built on the connection, not through createFixtureFactory(): no synthetic request
        // must become the main request of the client's calls.
        $this->fixtures = new FixtureFactory(Propel::getConnection('TheliaMain'));

        // The core keeps the parser of the last render in a static: after a refusal page, the
        // next request of the same process would render the module screen with it, empty.
        (new \ReflectionProperty(ParserResolver::class, 'currentParser'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $this->injector->clear();

        parent::tearDown();
    }

    #[Test]
    public function theScreenStatesTheOrderOfPriorityAndShowsTheVariablesWithAnExample(): void
    {
        $product = $this->product();
        $this->logIn($this->fixtures->admin());

        $crawler = $this->client->request('GET', self::SCREEN, ['edit_language_id' => $this->englishId()]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'the meta title or description typed on the record comes first',
            $crawler->filter('[data-seone-priority]')->text(),
        );

        $examples = $crawler->filter('[data-seone-examples="product"]');
        self::assertStringContainsString('%title%', $examples->text());
        self::assertStringContainsString('%store_name%', $examples->text());
        // The first record offered for the preview lends its values as the example.
        self::assertNotSame('', trim($examples->filter('[data-seone-example-value="title"]')->text()));
        self::assertGreaterThan(0, $crawler->filter('#seone-preview-records-product option[value="'.$product->getId().'"]')->count());
    }

    #[Test]
    public function thePreviewRendersATemplateThatIsNotSavedOnTheChosenRecord(): void
    {
        $product = $this->product();
        $this->logIn($this->fixtures->admin());

        $answer = $this->preview($product->getId(), '%title% | %ref% %titel%', '%title% '.str_repeat('word ', 40), maxLengthDescription: 30);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('Chair | '.$product->getRef(), $answer['fields']['title']['rendered']);
        self::assertSame(['titel'], $answer['fields']['title']['unknown']);
        self::assertSame('Chair word word word word word', $answer['fields']['description']['rendered']);
        self::assertSame(30, $answer['fields']['description']['length']);
        self::assertSame('Chair', $answer['values']['title']);

        // A preview writes nothing.
        self::assertSame('', (new MetaTemplateRepository(new SeoRequestMemo()))->getTemplate('product', MetaTemplateField::Title, 'en_US'));
    }

    #[Test]
    public function thePreviewOfAnUnknownRecordSaysSo(): void
    {
        $this->logIn($this->fixtures->admin());

        $answer = $this->preview(999999999, '%title%', '');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertArrayHasKey('error', $answer);
    }

    #[Test]
    public function thePreviewIsRefusedWithoutTheTokenOfTheScreen(): void
    {
        $product = $this->product();
        $this->logIn($this->fixtures->admin());

        $this->client->request('POST', self::PREVIEW, [
            'view' => 'product',
            'id' => (string) $product->getId(),
            'title' => '%title%',
            'edit_language_id' => (string) $this->englishId(),
            '_token' => 'forged',
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function thePreviewIsRefusedToAnAdministratorWhoCannotUpdateTheModule(): void
    {
        $product = $this->product();
        $this->logIn($this->fixtures->admin());
        $token = $this->screenToken();

        $this->logIn($this->fixtures->restrictedAdmin([
            AdminResources::MODULE => [AccessManager::VIEW],
            AdminResources::PRODUCT => [AccessManager::VIEW],
        ]));

        $this->client->request('POST', self::PREVIEW, [
            'view' => 'product',
            'id' => (string) $product->getId(),
            'title' => '%title%',
            'edit_language_id' => (string) $this->englishId(),
            '_token' => $token,
        ]);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Chair', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(int $id, string $title, string $description, ?int $maxLengthDescription = null): array
    {
        $token = $this->screenToken();

        $this->client->request('POST', self::PREVIEW, [
            'view' => 'product',
            'id' => (string) $id,
            'title' => $title,
            'description' => $description,
            'max_length_title' => '60',
            'max_length_description' => (string) ($maxLengthDescription ?? 160),
            'edit_language_id' => (string) $this->englishId(),
            '_token' => $token,
        ]);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function screenToken(): string
    {
        $crawler = $this->client->request('GET', self::SCREEN, ['edit_language_id' => $this->englishId()]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return (string) $crawler->filter('#seone-meta-templates')->attr('data-seone-preview-token');
    }

    private function logIn(Admin $admin): void
    {
        $admin->eraseCredentials();
        $this->injector->setAdmin($admin);
    }

    private function product(): Product
    {
        return $this->fixtures->product(
            $this->fixtures->category(),
            $this->fixtures->taxRule(),
            $this->fixtures->currency(),
            ['title' => 'Chair', 'locale' => 'en_US'],
        );
    }

    private function englishId(): int
    {
        return (int) LangQuery::create()->findOneByLocale('en_US')?->getId();
    }
}
