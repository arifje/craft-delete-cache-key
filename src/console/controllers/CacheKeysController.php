<?php

namespace arifje\deletecachekey\console\controllers;

use arifje\deletecachekey\Plugin;
use craft\console\Controller;
use yii\console\ExitCode;

class CacheKeysController extends Controller
{
    public string $mode = 'all';

    public bool $wildcard = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'mode',
            'wildcard',
        ]);
    }

    public function actionSearch(string $pattern = ''): int
    {
        $result = Plugin::getInstance()->getCacheKeys()->search($pattern, $this->mode);

        foreach ($result['keys'] as $row) {
            $this->stdout(sprintf("[key] %s expires %s\n", $row['id'], $row['expires']));
        }

        foreach ($result['tags'] as $row) {
            $this->stdout(sprintf("[tag] %s (%s)\n", $row['tag'], $row['label']));
        }

        foreach ($result['messages'] as $message) {
            $this->stderr($message . "\n");
        }

        return ExitCode::OK;
    }

    public function actionClear(string $pattern): int
    {
        $result = Plugin::getInstance()->getCacheKeys()->clear($pattern, $this->mode, $this->wildcard);

        foreach ($result['deletedKeys'] as $key) {
            $this->stdout(sprintf("[deleted key] %s\n", $key));
        }

        foreach ($result['invalidatedTags'] as $tag) {
            $this->stdout(sprintf("[invalidated tag] %s\n", $tag));
        }

        foreach ($result['messages'] as $message) {
            $this->stderr($message . "\n");
        }

        return ExitCode::OK;
    }
}
