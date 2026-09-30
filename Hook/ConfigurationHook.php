<?php

namespace SEOne\Hook;

use SEOne\Controller\ConfigurationController;
use SEOne\Form\CategoryLimitForm;
use SEOne\Form\EditRobotTxtForm;
use SEOne\Form\MetaTemplateForm;
use SEOne\Form\StoreSeoForm;
use SEOne\Model\Robots;
use SEOne\Model\RobotsQuery;
use SEOne\SEOne;
use SEOne\Service\MetaTemplate\MetaTemplateService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Tools\URL;

class ConfigurationHook extends BaseHook
{
    use TemplateFallbackTrait;

    /** Records offered for the preview of each page kind; any other one is reachable by its identifier. */
    private const int PREVIEW_RECORD_LIMIT = 50;

    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        private readonly MetaTemplateService $metaTemplateService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
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

        $metaTemplateEditLanguageId = $this->getMetaTemplateEditLanguageId();

        $event->add(
            $this->render('SEOne/module_configuration.html.twig', [
                'store_form' => $storeForm->getView(),
                'category_form' => $categoryForm->getView(),
                'robot_forms' => $robotForms,
                'meta_template_form' => $metaTemplateForm->getView(),
                'meta_template_views' => $this->getMetaTemplateViews($this->getLocaleOfLanguage($metaTemplateEditLanguageId)),
                'meta_template_edit_language_id' => $metaTemplateEditLanguageId,
                'meta_template_preview_token' => $this->csrfTokenManager
                    ->getToken(ConfigurationController::META_TEMPLATE_PREVIEW_TOKEN_ID)
                    ->getValue(),
            ])
        );
    }

    /**
     * One entry per view served by a resolver, in declaration order, each carrying the
     * variables its templates may use, the records offered for the preview, and the values
     * of the variables on the first of them as an example.
     *
     * @return list<array{view: string, variables: list<string>, records: array<int, string>, example_id: int|null, example_values: array<string, string>}>
     */
    protected function getMetaTemplateViews(string $locale): array
    {
        $views = [];

        foreach (array_keys($this->metaTemplateService->getResolvers()) as $view) {
            $records = $this->metaTemplateService->listPreviewRecords($view, $locale, self::PREVIEW_RECORD_LIMIT);
            $exampleId = array_key_first($records);

            $views[] = [
                'view' => $view,
                'variables' => $this->metaTemplateService->getVariableNames($view),
                'records' => $records,
                'example_id' => $exampleId,
                'example_values' => null === $exampleId ? [] : $this->metaTemplateService->getVariableValues($view, $exampleId, $locale),
            ];
        }

        return $views;
    }

    private function getLocaleOfLanguage(int $languageId): string
    {
        return (string) (LangQuery::create()->findPk($languageId) ?? Lang::getDefaultLanguage())->getLocale();
    }

    /**
     * The language the templates are shown and saved in: the one the switcher asked for, then
     * the administrator's own, which is what the controller resolves on save.
     */
    protected function getMetaTemplateEditLanguageId(): int
    {
        $request = $this->getRequest();
        $requestedLanguageId = $request?->query->get('edit_language_id') ?? $request?->request->get('edit_language_id');

        if (null !== $requestedLanguageId && null !== LangQuery::create()->findOneById($requestedLanguageId)) {
            return (int) $requestedLanguageId;
        }

        return (int) $this->getSession()->getAdminLang()->getId();
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
