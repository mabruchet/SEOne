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

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;

/**
 * The language the back-office edits the meta templates in.
 *
 * The screen reads the templates, the form fills its fields and the controller saves them: the
 * three must agree on the language, or a field comes back empty right after a successful save.
 * The order is the one of BaseAdminController::getCurrentEditionLang(), which the save uses: the
 * language the back-office selector posts, then the administrator's own, then the shop's default
 * when there is no session at all.
 */
final readonly class EditionLanguageResolver
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function resolve(): Lang
    {
        $request = $this->requestStack->getMainRequest();

        $editionLanguageId = $request?->query->get('edit_language_id') ?? $request?->request->get('edit_language_id');

        if (null !== $editionLanguageId) {
            $editionLanguage = LangQuery::create()->findOneById($editionLanguageId);

            if (null !== $editionLanguage) {
                return $editionLanguage;
            }
        }

        if (null !== $request && $request->hasSession()) {
            $session = $request->getSession();

            if ($session instanceof Session) {
                return $session->getAdminLang();
            }
        }

        return Lang::getDefaultLanguage();
    }

    public function resolveLocale(): string
    {
        return (string) $this->resolve()->getLocale();
    }
}
