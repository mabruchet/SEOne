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

namespace SEOne\Tests\Fixtures;

use SEOne\Service\MetaTemplate\VariableResolverInterface;

/**
 * What another module declares to give its own pages meta templates: a resolver, nothing else.
 */
final readonly class ForeignVariableResolver implements VariableResolverInterface
{
    public const string VIEW = 'recipe';

    public function getView(): string
    {
        return self::VIEW;
    }

    public function getVariableNames(): array
    {
        return ['name', 'cooking_time'];
    }

    public function resolve(int $id, string $locale): array
    {
        if (42 !== $id) {
            return [];
        }

        return [
            'name' => 'fr_FR' === $locale ? 'Tarte aux pommes' : 'Apple pie',
            'cooking_time' => '45 min',
        ];
    }
}
