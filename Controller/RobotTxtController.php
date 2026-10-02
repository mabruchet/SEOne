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

namespace SEOne\Controller;

use SEOne\Model\RobotsQuery;
use SEOne\SEOne;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Translation\Translator;

class RobotTxtController extends BaseFrontController
{
    #[Route('/robots.txt', name: 'robot_txt', methods: 'GET')]
    public function showRobotTxt(Request $request): Response
    {
        $domain = $request->getHttpHost();

        $robot = RobotsQuery::create()->findOneByDomainName('http://'.$domain);
        if ($robot === null) {
            $robot = RobotsQuery::create()->findOneByDomainName('https://'.$domain);
        }
        if ($robot === null) {
            $robot = RobotsQuery::create()->findOneByDomainName($domain);
        }
        // A domain without a robots.txt of its own has none to serve: a 404, which a crawler reads as
        // "no restriction", and not a 500, which it reads as "site down" and retries.
        if ($robot === null) {
            throw new NotFoundHttpException(Translator::getInstance()->trans(
                'No robot.txt found for this domain name. Check your module in your backoffice.',
                [],
                SEOne::DOMAIN_NAME
            ));
        }

        return new Response($robot->getRobotsContent(), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
