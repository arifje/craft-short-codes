<?php

declare(strict_types=1);

namespace arjanbrinkman\craftshortcodes;

use arjanbrinkman\craftshortcodes\models\Settings;
use arjanbrinkman\craftshortcodes\services\ShortCodeService;
use arjanbrinkman\craftshortcodes\variables\ShortCodesVariable;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\ModelEvent as CraftModelEvent;
use craft\web\twig\variables\CraftVariable;
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
}
