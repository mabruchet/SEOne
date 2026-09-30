<?php

namespace SEOne\Hook;

use SEOne\Form\CategoryLimitForm;
use SEOne\Form\EditRobotTxtForm;
use SEOne\Form\MetaTemplateForm;
use SEOne\Form\StoreSeoForm;
use SEOne\Model\Robots;
use SEOne\Model\RobotsQuery;
use SEOne\SEOne;
use SEOne\Service\EditionLanguageResolver;
use SEOne\Service\MetaTemplate\MetaTemplateService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Tools\URL;

class ConfigurationHook extends BaseHook
{
    use TemplateFallbackTrait;

    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        private readonly MetaTemplateService $metaTemplateService,
        private readonly EditionLanguageResolver $editionLanguageResolver,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $configRobotTxt = $this->getRobotTxtConfiguration();

        $storeForm = $this->formFactory->createForm(StoreSeoForm::getName());
        $storeForm->createView();

        $categoryForm = $this->formFactory->createForm(CategoryLimitForm::getName());
        $categoryForm->createView();

        $robotForms = [];
        foreach ($configRobotTxt as $robotId => $robot) {
            $robotForm = $this->formFactory->createForm(EditRobotTxtForm::getName(), data: [
                'id' => $robotId,
                'domainName' => $robot[0],
                'robotContent' => $robot[1],
            ]);
            $robotForm->createView();
            $robotForms[] = $robotForm->getView();
        }

        $metaTemplateForm = $this->formFactory->createForm(MetaTemplateForm::getName());
        $metaTemplateForm->createView();

        $event->add(
            $this->render('SEOne/module_configuration.html.twig', [
                'store_form' => $storeForm->getView(),
                'category_form' => $categoryForm->getView(),
                'robot_forms' => $robotForms,
                'meta_template_form' => $metaTemplateForm->getView(),
                'meta_template_views' => $this->getMetaTemplateViews(),
                'meta_template_edit_language_id' => $this->getMetaTemplateEditLanguageId(),
            ])
        );
    }

    /**
     * One entry per view served by a resolver, in declaration order, each carrying the
     * variables its templates may use.
     *
     * @return list<array{view: string, variables: list<string>}>
     */
    protected function getMetaTemplateViews(): array
    {
        $views = [];

        foreach (array_keys($this->metaTemplateService->getResolvers()) as $view) {
            $views[] = [
                'view' => $view,
                'variables' => $this->metaTemplateService->getVariableNames($view),
            ];
        }

        return $views;
    }

    /**
     * The language the templates are shown and saved in, the one the form reads and the controller saves into.
     */
    protected function getMetaTemplateEditLanguageId(): int
    {
        return (int) $this->editionLanguageResolver->resolve()->getId();
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'module.configuration' => [
                [
                    'type' => 'back',
                    'method' => 'onModuleConfiguration',
                ],
            ],
        ];
    }

    protected function getRobotTxtConfiguration(): array
    {
        $config = [];

        $robots = RobotsQuery::create()->find();

        if (0 >= $robots->count()) {
            if (!ConfigQuery::read('one_domain_foreach_lang')) {
                $domain = URL::getInstance()->getBaseUrl();
                $robot = (new Robots())
                    ->setDomainName($domain)
                    ->setRobotsContent(SEOne::getDefaultRobotsContent($domain));
                $robot->save();
                $robots[] = $robot;
            } else {
                $langs = LangQuery::create()->filterByActive(true)->find();
                foreach ($langs as $lang) {
                    if ($url = $lang->getUrl()) {
                        $robot = (new Robots())
                            ->setDomainName($url)
                            ->setRobotsContent(SEOne::getDefaultRobotsContent($url));
                        $robot->save();
                        $robots[] = $robot;
                    }
                }
            }
        }

        foreach ($robots as $robot) {
            $config[$robot->getId()][0] = $robot->getDomainName();
            $config[$robot->getId()][1] = $robot->getRobotsContent();
        }

        return $config;
    }
}
