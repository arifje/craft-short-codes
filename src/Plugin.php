<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes;

use arjanbrinkman\craftshortcodes\models\Settings;
use arjanbrinkman\craftshortcodes\services\ShortCodeService;
use arjanbrinkman\craftshortcodes\variables\ShortCodesVariable;
use arjanbrinkman\craftshortcodes\web\assets\ShortCodesCpAsset;
use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\ModelEvent as CraftModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Json;
use craft\web\Application as WebApplication;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use Throwable;
use yii\base\Event;
use yii\base\ModelEvent as YiiModelEvent;

/**
 * @property-read ShortCodeService $shortCodes
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = false;

    public function init(): void
    {
        parent::init();

        $settings = $this->getSettings();
        $this->setComponents([
            'shortCodes' => [
                'class' => ShortCodeService::class,
                'settings' => $settings,
            ],
        ]);

        $this->registerEntryEvents();
        $this->registerTwigVariable();
        $this->registerSiteRoutes();
        $this->registerCpGenerator();
    }

    public function getShortCodes(): ShortCodeService
    {
        $service = $this->get('shortCodes');
        if (!$service instanceof ShortCodeService) {
            throw new \RuntimeException('The Short Codes service is not configured correctly.');
        }

        return $service;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function registerEntryEvents(): void
    {
        Event::on(
            Entry::class,
            Element::EVENT_BEFORE_SAVE,
            function(CraftModelEvent $event): void {
                $entry = $event->sender;
                if (!$entry instanceof Entry) {
                    return;
                }

                try {
                    if (!$this->getShortCodes()->prepareEntryForSave($entry)) {
                        $event->isValid = false;
                    }
                } catch (Throwable $exception) {
                    $this->getShortCodes()->addOperationalError($entry, $exception);
                    $event->isValid = false;
                }
            }
        );

        Event::on(
            Entry::class,
            Entry::EVENT_BEFORE_VALIDATE,
            function(YiiModelEvent $event): void {
                $entry = $event->sender;
                if (!$entry instanceof Entry) {
                    return;
                }

                try {
                    $this->getShortCodes()->validateEntry($entry);
                } catch (Throwable $exception) {
                    // Yii has already cleared model errors when this event runs,
                    // so this error remains visible with Craft's other feedback.
                    $this->getShortCodes()->addOperationalError($entry, $exception);
                }
            }
        );
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                $variable = $event->sender;
                if ($variable instanceof CraftVariable) {
                    $variable->set('shortCodes', ShortCodesVariable::class);
                }
            }
        );
    }

    private function registerCpGenerator(): void
    {
        $application = Craft::$app;
        if (!$application instanceof WebApplication) {
            return;
        }

        $request = $application->getRequest();
        if (!$request->getIsCpRequest() || $request->getIsActionRequest()) {
            return;
        }

        $settings = $this->getShortCodes()->getValidatedSettings();
        if (!$settings instanceof Settings) {
            return;
        }

        $view = $application->getView();
        $view->registerAssetBundle(ShortCodesCpAsset::class);
        $view->registerJs(sprintf(
            'new Craft.ShortCodesFieldGenerator(%s);',
            Json::encode([
                'fieldHandle' => $settings->getFieldHandle(),
                'action' => 'short-codes/codes/generate',
                'generateLabel' => Craft::t('short-codes', 'Generate code'),
                'regenerateLabel' => Craft::t('short-codes', 'Generate new code'),
                'confirmMessage' => Craft::t(
                    'short-codes',
                    'Replace the existing code? The new code will not be stored until you save the entry.'
                ),
                'generatedMessage' => Craft::t('short-codes', 'A new short code was generated.'),
                'unsavedMessage' => Craft::t('short-codes', 'Save the entry to store this code.'),
                'errorMessage' => Craft::t('short-codes', 'The code could not be generated. Try again.'),
            ])
        ));
    }

    private function registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['api/short-codes/resolve'] = 'short-codes/codes/resolve';
            }
        );
    }
}
