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

use SEOne\Form\CategoryLimitForm;
use SEOne\Form\EditRobotTxtForm;
use SEOne\Form\MetaTemplateForm;
use SEOne\Form\StoreSeoForm;
use SEOne\SEOne;
use SEOne\Service\MetaTemplate\MetaTemplateField;
use SEOne\Service\MetaTemplate\MetaTemplateRepository;
use SEOne\Service\MetaTemplate\MetaTemplateService;
use SEOne\Service\RobotTxtService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Controller\Admin\AdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\ParserContext;
use Thelia\Form\Exception\FormValidationException;

#[Route('/admin/module/seone', name: 'seone_config_')]
class ConfigurationController extends AdminController
{
    public const string META_TEMPLATE_PREVIEW_TOKEN_ID = 'seone_meta_template_preview';

    /**
     * The back-office resource that guards the records of each native page kind: the preview
     * reads a record, so it asks for the right to see it. A page kind brought by another module
     * is guarded by the SEOne module right alone.
     */
    private const array META_TEMPLATE_PREVIEW_RESOURCES = [
        'product' => AdminResources::PRODUCT,
        'category' => AdminResources::CATEGORY,
        'content' => AdminResources::CONTENT,
        'folder' => AdminResources::FOLDER,
    ];

    #[Route('/configuration/category', name: 'category_configuration', methods: 'POST')]
    public function saveCategoryConfiguration(ParserContext $parserContext): RedirectResponse|Response|null
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], ['Seone'], AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(CategoryLimitForm::getName());
        try {
            $data = $this->validateForm($form)->getData();

            SEOne::setConfigValue(SEOne::BETTER_SE0_LIMIT_CONFIG_KEY, $data['category_limit']);

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $error_message = $this->createStandardFormValidationErrorMessage($e);
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
        }

        $form->setErrorMessage($error_message);

        $parserContext
            ->addForm($form)
            ->setGeneralError($error_message);

        return $this->generateErrorRedirect($form);
    }

    #[Route('/configuration/store', name: 'store_configuration', methods: 'POST')]
    public function saveStoreConfiguration(ParserContext $parserContext): RedirectResponse|Response|null
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], ['Seone'], AccessManager::UPDATE)) {
            return $response;
        }

        $baseForm = $this->createForm(StoreSeoForm::getName());

        $errorMessage = null;

        // Get current edition language locale
        $locale = $this->getCurrentEditionLocale();

        try {
            $form = $this->validateForm($baseForm);
            $data = $form->getData();

            // Save data
            SEOne::setConfigValue('title', $data['title'], $locale);
            SEOne::setConfigValue('description', $data['description'], $locale);
            SEOne::setConfigValue('keywords', $data['keywords'], $locale);
        } catch (FormValidationException $ex) {
            // Invalid data entered
            $errorMessage = $this->createStandardFormValidationErrorMessage($ex);
        } catch (\Exception $ex) {
            // Any other error
            $errorMessage = $this->getTranslator()->trans('Sorry, an error occurred: %err', ['%err' => $ex->getMessage()], SEOne::DOMAIN_NAME, $locale);
        }

        if (null !== $errorMessage) {
            // Mark the form as with error
            $baseForm->setErrorMessage($errorMessage);

            // Send the form and the error to the parser
            $this->getParserContext()
                ->addForm($baseForm)
                ->setGeneralError($errorMessage);
        } else {
            $this->getParserContext()
                ->set('success', true);
        }

        return $this->generateErrorRedirect($baseForm);
    }

    #[Route('/configuration/meta-templates', name: 'meta_templates_configuration', methods: 'POST')]
    public function saveMetaTemplates(
        ParserContext $parserContext,
        MetaTemplateService $metaTemplateService,
        MetaTemplateRepository $metaTemplateRepository,
    ): RedirectResponse|Response|null {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], ['Seone'], AccessManager::UPDATE)) {
            return $response;
        }

        $baseForm = $this->createForm(MetaTemplateForm::getName());

        $errorMessage = null;

        $locale = $this->getCurrentEditionLocale();

        try {
            $data = $this->validateForm($baseForm)->getData();

            foreach (array_keys($metaTemplateService->getResolvers()) as $view) {
                foreach (MetaTemplateField::cases() as $field) {
                    $metaTemplateRepository->saveTemplate(
                        $view,
                        $field,
                        $locale,
                        (string) $data[MetaTemplateForm::templateFieldName($view, $field)],
                    );
                }
            }

            foreach (MetaTemplateField::cases() as $field) {
                $maxLength = $data[MetaTemplateForm::maxLengthFieldName($field)];
                $metaTemplateRepository->saveMaxLength($field, null === $maxLength ? null : (int) $maxLength);
            }
        } catch (FormValidationException $ex) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($ex);
        } catch (\Exception $ex) {
            $errorMessage = $this->getTranslator()->trans('Sorry, an error occurred: %err', ['%err' => $ex->getMessage()], SEOne::DOMAIN_NAME, $locale);
        }

        if (null !== $errorMessage) {
            $baseForm->setErrorMessage($errorMessage);

            $parserContext
                ->addForm($baseForm)
                ->setGeneralError($errorMessage);

            $this->addFlash('danger', $errorMessage);

            return $this->generateErrorRedirect($baseForm);
        }

        $this->addFlash('success', $this->getTranslator()->trans('Configuration correctly saved', [], SEOne::DOMAIN_NAME, $locale));

        return $this->generateSuccessRedirect($baseForm);
    }

    /**
     * Renders the templates typed on the screen, saved or not, on a record the administrator
     * picks, with the same rendering and maximum lengths the pages use. Nothing is written.
     */
    #[Route('/configuration/meta-templates/preview', name: 'meta_templates_preview', methods: 'POST')]
    public function previewMetaTemplates(
        Request $request,
        MetaTemplateService $metaTemplateService,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], ['Seone'], AccessManager::UPDATE)) {
            return $response;
        }

        $locale = (string) $this->getCurrentEditionLocale();

        if (!$csrfTokenManager->isTokenValid(new CsrfToken(self::META_TEMPLATE_PREVIEW_TOKEN_ID, (string) $request->request->get('_token')))) {
            return $this->previewError('meta_template.preview.error.token', Response::HTTP_FORBIDDEN);
        }

        $view = (string) $request->request->get('view');

        if (null === $metaTemplateService->getResolver($view)) {
            return $this->previewError('meta_template.preview.error.view', Response::HTTP_BAD_REQUEST);
        }

        $recordResource = self::META_TEMPLATE_PREVIEW_RESOURCES[$view] ?? null;

        if (null !== $recordResource && null !== $response = $this->checkAuth([$recordResource], [], AccessManager::VIEW)) {
            return $response;
        }

        $id = filter_var($request->request->get('id'), \FILTER_VALIDATE_INT) ?: 0;
        $values = $metaTemplateService->getVariableValues($view, $id, $locale);

        if ([] === $values) {
            return $this->previewError('meta_template.preview.error.record', Response::HTTP_NOT_FOUND);
        }

        $fields = [];

        foreach (MetaTemplateField::cases() as $field) {
            $template = trim((string) $request->request->get($field->value));
            $maxLength = filter_var($request->request->get('max_length_'.$field->value), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            $rendered = $metaTemplateService->renderTemplate($view, $field, $template, $id, $locale, $maxLength);

            $fields[$field->value] = [
                'rendered' => $rendered,
                'length' => mb_strlen($rendered),
                'unknown' => $metaTemplateService->findUnknownVariables($view, $template),
            ];
        }

        return new JsonResponse(['id' => $id, 'values' => $values, 'fields' => $fields]);
    }

    private function previewError(string $message, int $status): JsonResponse
    {
        // In the administrator's own language, not in the edition language of the templates.
        return new JsonResponse(
            ['error' => $this->getTranslator()->trans($message, [], SEOne::DOMAIN_NAME)],
            $status,
        );
    }

    #[Route('/edit-robottxt', name: 'edit_robottxt', methods: 'POST')]
    public function editRobotTxt(ParserContext $parserContext, RobotTxtService $robotTxtService): RedirectResponse|Response
    {
        if (null !== $response = $this->checkAuth([AdminResources::MODULE], ['Seone'], AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(EditRobotTxtForm::getName());

        try {
            $editRobotTxtForm = $this->validateForm($form);

            $robotTxtService->saveRobotTxtByDomain($editRobotTxtForm->get('domainName')->getData(), $editRobotTxtForm->get('robotContent')->getData());

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $e) {
            $error_message = $this->createStandardFormValidationErrorMessage($e);
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
        }

        $form->setErrorMessage($error_message);

        $parserContext
            ->addForm($form)
            ->setGeneralError($error_message);

        return $this->generateErrorRedirect($form);
    }
}
