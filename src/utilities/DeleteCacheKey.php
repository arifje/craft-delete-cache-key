<?php

namespace arifje\deletecachekey\utilities;

use Craft;
use craft\base\Utility;

class DeleteCacheKey extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('delete-cache-key', 'Delete Cache Key');
    }

    public static function id(): string
    {
        return 'delete-cache-key';
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@arifje/deletecachekey/icon.svg');
    }

    public static function iconPath(): ?string
    {
        return Craft::getAlias('@arifje/deletecachekey/icon.svg');
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('delete-cache-key/_utility');
    }
}
