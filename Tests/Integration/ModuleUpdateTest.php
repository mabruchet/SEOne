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

namespace SEOne\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use SEOne\SEOne;
use SEOne\Service\MetaTemplate\MetaTemplateField;
use SEOne\Service\MetaTemplate\MetaTemplateRepository;
use SEOne\Service\SeoRequestMemo;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\ModuleQuery;
use Thelia\Test\IntegrationTestCase;
use Thelia\Test\Trait\RecordsSqlQueries;

/**
 * A shop that saved the meta template form with SEOne 1.2.1 kept a setting per template and
 * language even when empty, and the default maximum lengths: the update drops what holds
 * nothing, so its pages read no setting without the form being saved again.
 */
final class ModuleUpdateTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const array VIEWS = ['product', 'category', 'content', 'folder'];

    #[Test]
    public function theUpdateDropsTheEmptyTemplatesAndDefaultLengthsA121SaveLeftBehind(): void
    {
        $this->saveTheFormTheWay121Did();

        $this->updateTheModule();

        self::assertSame(
            [
                MetaTemplateRepository::maxLengthKey(MetaTemplateField::Description),
                MetaTemplateRepository::templateKey('product', MetaTemplateField::Title),
            ],
            $this->metaTemplateSettingNames(),
        );

        $repository = new MetaTemplateRepository(new SeoRequestMemo());
        self::assertSame('%title% chez %store_name%', $repository->getTemplate('product', MetaTemplateField::Title, 'fr_FR'));
        self::assertSame(120, $repository->getMaxLength(MetaTemplateField::Description));
        self::assertSame(MetaTemplateField::Title->defaultMaxLength(), $repository->getMaxLength(MetaTemplateField::Title));
    }

    #[Test]
    public function afterTheUpdateAPageWithoutTemplatesReadsNoSetting(): void
    {
        $this->saveTheFormTheWay121Did();

        $this->updateTheModule();

        // Settings already loaded, as they are on a page once any other setting was read.
        SEOne::getConfigValue('is_initialized');

        $statements = $this->recordSqlQueries(static function (): void {
            $repository = new MetaTemplateRepository(new SeoRequestMemo());

            foreach (MetaTemplateField::cases() as $field) {
                self::assertSame('', $repository->getTemplate('category', $field, 'en_US'));
            }
        });

        self::assertSame([], $statements);
    }

    #[Test]
    public function theUpdateCanRunTwice(): void
    {
        $this->saveTheFormTheWay121Did();

        $this->updateTheModule();
        $names = $this->metaTemplateSettingNames();
        $this->updateTheModule();

        self::assertSame($names, $this->metaTemplateSettingNames());
    }

    /**
     * The form saved every field of every page kind for the language edited, empty or not,
     * and the two maximum lengths as typed.
     */
    private function saveTheFormTheWay121Did(): void
    {
        foreach (['en_US', 'fr_FR'] as $locale) {
            foreach (self::VIEWS as $view) {
                foreach (MetaTemplateField::cases() as $field) {
                    SEOne::setConfigValue(MetaTemplateRepository::templateKey($view, $field), '', $locale);
                }
            }
        }

        SEOne::setConfigValue(MetaTemplateRepository::templateKey('product', MetaTemplateField::Title), '%title% chez %store_name%', 'fr_FR');
        SEOne::setConfigValue(MetaTemplateRepository::maxLengthKey(MetaTemplateField::Title), (string) MetaTemplateField::Title->defaultMaxLength());
        SEOne::setConfigValue(MetaTemplateRepository::maxLengthKey(MetaTemplateField::Description), '120');
    }

    private function updateTheModule(): void
    {
        ModuleQuery::create()->findOneByCode('SEOne')
            ->createInstance()
            ->update('1.2.1', '1.2.2', $this->getPropelConnection());
    }

    /**
     * @return list<string>
     */
    private function metaTemplateSettingNames(): array
    {
        $names = [];

        foreach (ModuleConfigQuery::create()->filterByModuleId(SEOne::getModuleId())->orderByName()->find() as $setting) {
            if (str_starts_with($setting->getName(), 'meta_template_')) {
                $names[] = $setting->getName();
            }
        }

        return $names;
    }
}
