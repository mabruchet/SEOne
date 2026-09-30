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

/**
 * A resolver that can name a few records of its page kind, so the configuration screen offers
 * them for the preview of a template. Optional: a resolver without it still gets a preview, on
 * a record the administrator designates by its identifier.
 */
interface PreviewRecordsInterface
{
    /**
     * @return array<int, string> record identifier => label in $locale, at most $limit entries
     */
    public function listPreviewRecords(string $locale, int $limit): array;
}
