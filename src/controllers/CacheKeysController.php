<?php

namespace arifje\deletecachekey\controllers;

use arifje\deletecachekey\Plugin;
use arifje\deletecachekey\utilities\DeleteCacheKey;
use Craft;
use craft\web\Controller;
use InvalidArgumentException;
use yii\web\BadRequestHttpException;
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

        try {
            return $this->asJson(Plugin::getInstance()->getCacheKeys()->search($pattern, $mode));
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        }
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

        try {
            return $this->asJson(Plugin::getInstance()->getCacheKeys()->clear($pattern, $mode, $wildcard));
        } catch (InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        }
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
