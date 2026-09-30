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
use SEOne\Service\MetaTemplate\MetaTemplateService;
use Thelia\Test\IntegrationTestCase;

final class ModuleServicesTest extends IntegrationTestCase
{
    #[Test]
    public function theTestsOfTheModuleAreNotServicesOfTheShop(): void
    {
        // A fixture resolver of the tests must never give the shop a page kind of its own.
        $views = array_keys($this->getService(MetaTemplateService::class)->getResolvers());
        sort($views);

        self::assertSame(['category', 'content', 'folder', 'product'], $views);
    }
}
