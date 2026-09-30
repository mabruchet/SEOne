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

namespace SEOne\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SEOne\Service\EditionLanguageResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Lang;

final class EditionLanguageResolverTest extends TestCase
{
    #[Test]
    public function itFallsBackOnTheAdministratorLanguageWhenNoLanguageIsRequested(): void
    {
        $adminLang = (new Lang())->setLocale('fr_FR');

        $session = $this->createMock(Session::class);
        $session->method('getAdminLang')->willReturn($adminLang);
        $session->expects(self::never())->method('getAdminEditionLang');

        $request = Request::create('/admin/module/SEOne');
        $request->setSession($session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $resolver = new EditionLanguageResolver($requestStack);

        self::assertSame($adminLang, $resolver->resolve());
        self::assertSame('fr_FR', $resolver->resolveLocale());
    }
}
