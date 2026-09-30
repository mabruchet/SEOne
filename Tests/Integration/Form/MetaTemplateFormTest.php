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

namespace SEOne\Tests\Integration\Form;

use PHPUnit\Framework\Attributes\Test;
use SEOne\Form\MetaTemplateForm;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Test\IntegrationTestCase;

final class MetaTemplateFormTest extends IntegrationTestCase
{
    #[Test]
    public function aTemplateNamingAnUnknownVariableIsRefusedWithItsName(): void
    {
        $form = $this->submit(['product_title' => '%titel% %brand%']);

        self::assertFalse($form->isValid());

        $errors = iterator_to_array($form->get('product_title')->getErrors());
        self::assertCount(1, $errors);
        self::assertStringContainsString('titel', $errors[0]->getMessage());
    }

    #[Test]
    public function templateEngineSyntaxIsAcceptedAsPlainText(): void
    {
        $form = $this->submit(['product_title' => '{{ 7*7 }} %title%']);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame('{{ 7*7 }} %title%', $form->get('product_title')->getData());
    }

    /**
     * @param array<string, string> $templates
     */
    private function submit(array $templates): FormInterface
    {
        $form = $this->getService(TheliaFormFactory::class)
            ->createForm(MetaTemplateForm::getName(), FormType::class, [], ['csrf_protection' => false])
            ->getForm();

        $form->submit($templates + ['max_length_title' => '60', 'max_length_description' => '160'], false);

        return $form;
    }
}
