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
use SEOne\Form\MetaTemplateForm;
use SEOne\Service\MetaTemplate\MetaTemplateField;
use SEOne\Service\MetaTemplate\MetaTemplateRenderer;
use SEOne\Service\MetaTemplate\MetaTemplateRepository;
use SEOne\Service\MetaTemplate\MetaTemplateService;
use SEOne\Service\MetaTemplate\VariableResolverInterface;
use SEOne\Service\SeoRequestMemo;
use SEOne\Tests\Fixtures\ForeignVariableResolver;
use Symfony\Component\DependencyInjection\Compiler\RegisterAutoconfigureAttributesPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * A module serving its own kind of page declares a resolver, as the fixture does, and nothing
 * else: the tag, the configuration fields, the validation and the rendering follow.
 */
final class ForeignPageKindTest extends IntegrationTestCase
{
    #[Test]
    public function aResolverDeclaredAsAnyServiceIsTaggedAndItsPagesRenderTheirTemplate(): void
    {
        $service = $this->compileMetaTemplateService();

        self::assertArrayHasKey(ForeignVariableResolver::VIEW, $service->getResolvers());

        $repository = new MetaTemplateRepository(new SeoRequestMemo());
        $repository->saveTemplate(ForeignVariableResolver::VIEW, MetaTemplateField::Title, 'en_US', '%name%, ready in %cooking_time%');
        $repository->saveTemplate(ForeignVariableResolver::VIEW, MetaTemplateField::Title, 'fr_FR', '%name%, prête en %cooking_time%');

        self::assertSame('Apple pie, ready in 45 min', $service->render(ForeignVariableResolver::VIEW, MetaTemplateField::Title, 42, 'en_US'));
        self::assertSame('Tarte aux pommes, prête en 45 min', $service->render(ForeignVariableResolver::VIEW, MetaTemplateField::Title, 42, 'fr_FR'));
        // A record the resolver does not know leaves the page to its own fallback.
        self::assertSame('', $service->render(ForeignVariableResolver::VIEW, MetaTemplateField::Title, 7, 'en_US'));
    }

    #[Test]
    public function theConfigurationFormGetsFieldsForThePageKindAndChecksItsVariables(): void
    {
        $form = $this->createForm($this->compileMetaTemplateService());

        self::assertTrue($form->has('recipe_title'));
        self::assertTrue($form->has('recipe_description'));

        $form->submit([
            'recipe_title' => '%name% | %store_name%',
            'recipe_description' => '%name% %price%',
            'max_length_title' => '60',
            'max_length_description' => '160',
        ], false);

        self::assertCount(0, $form->get('recipe_title')->getErrors());

        $errors = iterator_to_array($form->get('recipe_description')->getErrors());
        self::assertCount(1, $errors);
        self::assertStringContainsString('price', $errors[0]->getMessage());
    }

    /**
     * The services a shop gets for a module declaring the fixture resolver, compiled the way
     * the kernel compiles them: autowired and autoconfigured, no tag written by hand.
     */
    private function compileMetaTemplateService(): MetaTemplateService
    {
        $container = new ContainerBuilder();

        // What loading the SEOne services does for the shop: the interface is scanned with the
        // rest of the module, and its tag attribute applies to every service implementing it,
        // whichever module declares that service.
        (new RegisterAutoconfigureAttributesPass())->processClass($container, new \ReflectionClass(VariableResolverInterface::class));

        foreach ([ForeignVariableResolver::class, SeoRequestMemo::class, MetaTemplateRenderer::class, MetaTemplateRepository::class] as $class) {
            $container->register($class, $class)->setAutowired(true)->setAutoconfigured(true);
        }

        $container->register(MetaTemplateService::class, MetaTemplateService::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setPublic(true);

        $container->compile();

        $service = $container->get(MetaTemplateService::class);
        self::assertInstanceOf(MetaTemplateService::class, $service);

        return $service;
    }

    private function createForm(MetaTemplateService $service): FormInterface
    {
        $factory = $this->getService(TheliaFormFactory::class);

        // The shop's form factory builds the form from the shop's own services; this one is
        // built the same way from the services compiled above.
        $form = new MetaTemplateForm(
            $this->getService(RequestStack::class),
            $service,
            new MetaTemplateRepository(new SeoRequestMemo()),
        );

        (function (MetaTemplateForm $form): void {
            $form->init(
                $this->requestStack->getMainRequest(),
                $this->eventDispatcher,
                $this->translator,
                $this->formFactoryBuilder,
                $this->validatorBuilder,
                $this->tokenStorage,
                FormType::class,
                [],
                ['csrf_protection' => false],
            );
        })->call($factory, $form);

        return $form->getForm();
    }
}
