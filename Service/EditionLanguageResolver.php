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

namespace SEOne\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * The language the back-office edits the module settings in, resolved once, the same way the
 * core controllers do on save: the language the switcher posts, then the administrator's own,
 * then the edition language kept in session, then the default language. The screen that shows
 * a setting and the request that saves it read the same language, so a saved value never
 * comes back empty.
 */
final readonly class EditionLanguageResolver
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    public function resolve(): Lang
    {
        $request = $this->requestStack->getMainRequest();
        $requestedLanguageId = $request?->query->get('edit_language_id') ?? $request?->request->get('edit_language_id');

        if (null !== $requestedLanguageId && null !== $requestedLanguage = LangQuery::create()->findOneById($requestedLanguageId)) {
            return $requestedLanguage;
        }

        $session = null !== $request && $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof Session) {
            return $session->getAdminLang() ?? $session->getAdminEditionLang();
        }

        return Lang::getDefaultLanguage();
    }

    public function resolveLocale(): string
    {
        return (string) $this->resolve()->getLocale();
    }
}
