<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes\controllers;

use arjanbrinkman\craftshortcodes\Plugin;
use arjanbrinkman\craftshortcodes\services\ShortCodeService;
use Craft;
use craft\web\Controller;
use Throwable;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

final class CodesController extends Controller
{
    protected array|bool|int $allowAnonymous = ['resolve'];

    public function actionGenerate(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        try {
            $code = Plugin::getInstance()->getShortCodes()->generateUniqueCode();
        } catch (Throwable $exception) {
            Craft::error(
                sprintf('Short Codes CP generation failed: %s', $exception->getMessage()),
                ShortCodeService::class
            );

            return $this->asFailure(
                Craft::t('short-codes', 'The code could not be generated. Try again.')
            ) ?? $this->asJson([]);
        }

        if ($code === null) {
            return $this->asFailure(
                Craft::t('short-codes', 'A unique code could not be generated. Try again.')
            ) ?? $this->asJson([]);
        }

        return $this->asJson(['code' => $code]);
    }

    public function actionResolve(): Response
    {
        if (!$this->request->getIsGet()) {
            throw new MethodNotAllowedHttpException('GET request required.');
        }

        $this->response->getHeaders()->set('Cache-Control', 'no-store');

        $submittedCode = $this->request->getQueryParam('code');
        if (!is_string($submittedCode)) {
            return $this->jsonError(
                'invalid_code',
                Craft::t('short-codes', 'Provide a valid short code.'),
                400
            );
        }

        $service = Plugin::getInstance()->getShortCodes();
        if ($service->getValidatedSettings() === null) {
            return $this->jsonError(
                'unavailable',
                Craft::t('short-codes', 'The short code resolver is unavailable.'),
                503
            );
        }

        $code = ShortCodeService::normalizeCode($submittedCode);
        if ($code === '' || $service->getFormatErrors($code) !== []) {
            return $this->jsonError(
                'invalid_code',
                Craft::t('short-codes', 'Provide a valid short code.'),
                400
            );
        }

        $entry = $service->findEntry($code);
        if ($entry === null) {
            return $this->jsonError(
                'not_found',
                Craft::t('short-codes', 'No live entry was found for this code.'),
                404,
                ['code' => $code]
            );
        }

        return $this->asJson([
            'code' => $code,
            'url' => (string)$entry->getUrl(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function jsonError(string $error, string $message, int $status, array $data = []): Response
    {
        $this->response->setStatusCode($status);

        return $this->asJson($data + [
            'error' => $error,
            'message' => $message,
        ]);
    }
}
