<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes\controllers;

use arjanbrinkman\craftshortcodes\Plugin;
use arjanbrinkman\craftshortcodes\services\ShortCodeService;
use Craft;
use craft\web\Controller;
use Throwable;
use yii\web\Response;

final class CodesController extends Controller
{
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
}
