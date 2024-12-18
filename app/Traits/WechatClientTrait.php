<?php

namespace App\Traits;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

trait WechatClientTrait
{
    //https://developers.weixin.qq.com/miniprogram/dev/api-backend/open-api/login/auth.code2Session.html
    private function code2session($code)
    {
        $app = app('easywechat.mini_app');
        if (is_null($app)) {
            throw new \RuntimeException('not found easywechat.mini_app');
        }

        return $app->getHttpClient()->request('GET', '/sns/jscode2session', [
            'query' => [
                'appid' => $app->getAccount()->getAppId(),
                'secret' => $app->getAccount()->getSecret(),
                'js_code' => $code,
                'grant_type' => 'authorization_code',
            ],
        ])->toArray(false);
    }
}
