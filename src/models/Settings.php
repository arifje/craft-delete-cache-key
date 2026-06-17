<?php

namespace arifje\deletecachekey\models;

use craft\base\Model;

class Settings extends Model
{
    public string $registeredTags = '';

    public int $searchLimit = 200;

    protected function defineRules(): array
    {
        return [
            [['registeredTags'], 'string'],
            [['searchLimit'], 'integer', 'min' => 1, 'max' => 1000],
        ];
    }
}
