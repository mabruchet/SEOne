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

namespace SEOne\Service\MetaTemplate;

use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Connection\ConnectionInterface;
use SEOne\SEOne;
use SEOne\Service\SeoRequestMemo;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ModuleConfigI18nQuery;
use Thelia\Model\ModuleConfigQuery;

/**
 * Meta templates live in the module settings, next to the default title and description SEOne
 * already keeps per language: one setting per view and field, translated, plus one maximum
 * length per field shared by every language. No table of its own.
 */
final readonly class MetaTemplateRepository
{
    private const string TEMPLATE_KEY_PREFIX = 'meta_template_';
    private const string MAX_LENGTH_KEY_PREFIX = 'meta_template_max_length_';
    private const string STORE_NAME_KEY = 'title';

    public function __construct(private SeoRequestMemo $seoRequestMemo)
    {
    }

    public static function templateKey(string $view, MetaTemplateField $field): string
    {
        return self::TEMPLATE_KEY_PREFIX.$view.'_'.$field->value;
    }

    public static function maxLengthKey(MetaTemplateField $field): string
    {
        return self::MAX_LENGTH_KEY_PREFIX.$field->value;
    }

    /**
     * The template configured for this view, field and language; '' when there is none.
     */
    public function getTemplate(string $view, MetaTemplateField $field, string $locale): string
    {
        return trim((string) $this->seoRequestMemo->getConfigValue(self::templateKey($view, $field), null, $locale));
    }

    public function saveTemplate(string $view, MetaTemplateField $field, string $locale, string $template): void
    {
        $template = trim($template);

        if ('' === $template) {
            $this->removeSetting(self::templateKey($view, $field), $locale);

            return;
        }

        SEOne::setConfigValue(self::templateKey($view, $field), $template, $locale);
    }

    /**
     * Drops the settings that hold nothing: the empty templates and the default maximum lengths
     * a save of the form stored before they stopped being stored. Run on update, so a shop that
     * saved the form with an earlier version reads no setting on its pages without saving again.
     */
    public function removeUnusedSettings(?ConnectionInterface $con = null): void
    {
        $settings = ModuleConfigQuery::create()
            ->filterByModuleId(SEOne::getModuleId())
            ->filterByName(self::TEMPLATE_KEY_PREFIX.'%', Criteria::LIKE)
            ->find($con);

        $defaultMaxLengths = [];

        foreach (MetaTemplateField::cases() as $field) {
            $defaultMaxLengths[self::maxLengthKey($field)] = (string) $field->defaultMaxLength();
        }

        foreach ($settings as $setting) {
            $unusedValues = ['', $defaultMaxLengths[$setting->getName()] ?? ''];
            $isUsed = false;

            foreach (ModuleConfigI18nQuery::create()->filterById($setting->getId())->find($con) as $translation) {
                if (\in_array(trim((string) $translation->getValue()), $unusedValues, true)) {
                    $translation->delete($con);
                } else {
                    $isUsed = true;
                }
            }

            if (!$isUsed) {
                $setting->delete($con);
            }
        }
    }

    /**
     * An empty template is not stored. Every product, category, content and folder page asks for
     * the template of its kind, and a setting that exists costs a read of its translated value
     * even when that value is empty; a setting that does not exist is answered from the module
     * settings the request has already loaded. The setting goes once no language uses it.
     *
     * @param string|null $locale the language to empty, null for a setting shared by every language
     */
    private function removeSetting(string $key, ?string $locale = null): void
    {
        $setting = ModuleConfigQuery::create()
            ->filterByModuleId(SEOne::getModuleId())
            ->filterByName($key)
            ->findOne();

        if (null === $setting) {
            return;
        }

        if (null === $locale) {
            $setting->delete();

            return;
        }

        foreach (ModuleConfigI18nQuery::create()->filterById($setting->getId())->filterByLocale($locale)->find() as $translation) {
            $translation->delete();
        }

        $isUsedByAnotherLanguage = ModuleConfigI18nQuery::create()
            ->filterById($setting->getId())
            ->filterByValue('', Criteria::NOT_EQUAL)
            ->exists();

        if (!$isUsedByAnotherLanguage) {
            $setting->delete();
        }
    }

    /**
     * The store name in this language: the one SEOne keeps per language in its settings (the same
     * the SEO models fall back on for an empty title), then the store name of the core.
     */
    public function getStoreName(string $locale): string
    {
        $storeName = trim((string) $this->seoRequestMemo->getConfigValue(self::STORE_NAME_KEY, null, $locale));

        if ('' === $storeName) {
            $storeName = trim((string) ConfigQuery::read('store_name'));
        }

        return $storeName;
    }

    public function getMaxLength(MetaTemplateField $field): int
    {
        $value = $this->seoRequestMemo->getConfigValue(self::maxLengthKey($field));

        if (null === $value || '' === $value || !ctype_digit($value) || (int) $value <= 0) {
            return $field->defaultMaxLength();
        }

        return (int) $value;
    }

    /**
     * The default length is not stored, for the same reason as an empty template: the pages
     * would read it for nothing.
     *
     * @param int|null $maxLength null, a non-positive value or the default restores the field's default
     */
    public function saveMaxLength(MetaTemplateField $field, ?int $maxLength): void
    {
        if (null === $maxLength || $maxLength <= 0 || $maxLength === $field->defaultMaxLength()) {
            $this->removeSetting(self::maxLengthKey($field));

            return;
        }

        SEOne::setConfigValue(self::maxLengthKey($field), (string) $maxLength);
    }
}
