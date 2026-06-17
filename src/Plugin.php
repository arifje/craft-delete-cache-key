<?php

namespace arifje\deletecachekey;

use arifje\deletecachekey\models\Settings;
use arifje\deletecachekey\services\CacheKeys;
use arifje\deletecachekey\utilities\DeleteCacheKey;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\services\Utilities;
use craft\utilities\ClearCaches;
use craft\web\View;
use yii\base\Event;

/**
 * @property CacheKeys $cacheKeys
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@arifje/deletecachekey', __DIR__);

        $this->setComponents([
            'cacheKeys' => CacheKeys::class,
        ]);

        $this->registerTemplateRoot();
        $this->registerUtility();
        $this->registerCacheTagOptions();
    }

    public function getCacheKeys(): CacheKeys
    {
        /** @var CacheKeys */
        return $this->get('cacheKeys');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('delete-cache-key/settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerTemplateRoot(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            static function(RegisterTemplateRootsEvent $event): void {
                $event->roots['delete-cache-key'] = __DIR__ . '/templates';
            }
        );
    }

    private function registerUtility(): void
    {
        $eventName = defined(Utilities::class . '::EVENT_REGISTER_UTILITIES')
            ? constant(Utilities::class . '::EVENT_REGISTER_UTILITIES')
            : constant(Utilities::class . '::EVENT_REGISTER_UTILITY_TYPES');

        Event::on(
            Utilities::class,
            $eventName,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = DeleteCacheKey::class;
            }
        );
    }

    private function registerCacheTagOptions(): void
    {
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_TAG_OPTIONS,
            function(RegisterCacheOptionsEvent $event): void {
                foreach ($this->getCacheKeys()->registeredTagOptions() as $option) {
                    $event->options[] = $option;
                }
            }
        );
    }
}
