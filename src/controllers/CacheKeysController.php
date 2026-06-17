<?php

namespace arifje\deletecachekey\controllers;

use arifje\deletecachekey\Plugin;
use arifje\deletecachekey\utilities\DeleteCacheKey;
use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class CacheKeysController extends Controller
{
    public function actionSearch(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireUtilityPermission();

        $request = Craft::$app->getRequest();
        $pattern = (string)$request->getBodyParam('pattern', '');
        $mode = (string)$request->getBodyParam('mode', 'all');

        return $this->asJson(Plugin::getInstance()->getCacheKeys()->search($pattern, $mode));
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireUtilityPermission();

        $request = Craft::$app->getRequest();
        $pattern = (string)$request->getBodyParam('pattern', '');
        $mode = (string)$request->getBodyParam('mode', 'all');
        $wildcard = (bool)$request->getBodyParam('wildcard', false);

        return $this->asJson(Plugin::getInstance()->getCacheKeys()->clear($pattern, $mode, $wildcard));
    }

    private function requireUtilityPermission(): void
    {
        $user = Craft::$app->getUser();

        if ($user->getIsAdmin() || $user->checkPermission('utility:' . DeleteCacheKey::id())) {
            return;
        }

        throw new ForbiddenHttpException('User is not permitted to use the Delete Cache Key utility.');
    }
}
