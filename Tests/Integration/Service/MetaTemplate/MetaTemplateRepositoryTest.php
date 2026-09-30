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
use SEOne\Service\SeoRequestMemo;
use Thelia\Model\ModuleConfig;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Test\IntegrationTestCase;

final class MetaTemplateRepositoryTest extends IntegrationTestCase
{
    #[Test]
    public function anEmptyTemplateLeavesNoSettingThePagesWouldRead(): void
    {
        $key = MetaTemplateRepository::templateKey('product', MetaTemplateField::Title);

        // What a save of the form left behind before: a setting holding an empty value per language.
        SEOne::setConfigValue($key, '', 'en_US');
        SEOne::setConfigValue($key, '', 'fr_FR');

        $this->createRepository()->saveTemplate('product', MetaTemplateField::Title, 'en_US', '');

        self::assertNull($this->findSetting($key));
    }

    #[Test]
    public function emptyingOneLanguageKeepsTheTemplateOfAnother(): void
    {
        $key = MetaTemplateRepository::templateKey('product', MetaTemplateField::Title);
        $repository = $this->createRepository();

        $repository->saveTemplate('product', MetaTemplateField::Title, 'en_US', '%title% in English');
        $repository->saveTemplate('product', MetaTemplateField::Title, 'fr_FR', '%title% en français');
        $repository->saveTemplate('product', MetaTemplateField::Title, 'en_US', '');

        self::assertNotNull($this->findSetting($key));
        self::assertSame('', $this->createRepository()->getTemplate('product', MetaTemplateField::Title, 'en_US'));
        self::assertSame('%title% en français', $this->createRepository()->getTemplate('product', MetaTemplateField::Title, 'fr_FR'));
    }

    #[Test]
    public function aTemplateIsStoredForItsLanguage(): void
    {
        $this->createRepository()->saveTemplate('category', MetaTemplateField::Description, 'fr_FR', '  %title%, la sélection  ');

        self::assertSame('%title%, la sélection', $this->createRepository()->getTemplate('category', MetaTemplateField::Description, 'fr_FR'));
    }

    #[Test]
    public function theDefaultMaximumLengthLeavesNoSettingThePagesWouldRead(): void
    {
        $key = MetaTemplateRepository::maxLengthKey(MetaTemplateField::Title);

        // What a save of the form left behind before: the default length, stored.
        SEOne::setConfigValue($key, (string) MetaTemplateField::Title->defaultMaxLength());

        $this->createRepository()->saveMaxLength(MetaTemplateField::Title, MetaTemplateField::Title->defaultMaxLength());

        self::assertNull($this->findSetting($key));
        self::assertSame(MetaTemplateField::Title->defaultMaxLength(), $this->createRepository()->getMaxLength(MetaTemplateField::Title));
    }

    #[Test]
    public function aMaximumLengthOtherThanTheDefaultIsStored(): void
    {
        $this->createRepository()->saveMaxLength(MetaTemplateField::Description, 120);

        self::assertSame(120, $this->createRepository()->getMaxLength(MetaTemplateField::Description));

        $this->createRepository()->saveMaxLength(MetaTemplateField::Description, null);

        self::assertNull($this->findSetting(MetaTemplateRepository::maxLengthKey(MetaTemplateField::Description)));
    }

    private function createRepository(): MetaTemplateRepository
    {
        return new MetaTemplateRepository(new SeoRequestMemo());
    }

    private function findSetting(string $name): ?ModuleConfig
    {
        return ModuleConfigQuery::create()
            ->filterByModuleId(SEOne::getModuleId())
            ->filterByName($name)
            ->findOne();
    }
}
