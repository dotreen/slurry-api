<?php

namespace App\Http\Controllers;

use App\Traits\ResponseJsonTrait;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    use ResponseJsonTrait;

    protected function getUserId(): int
    {
        $user_id = intval(Auth::id());

        return $user_id;
    }

    protected function getUserName()
    {
        return Auth::user()->username ?? null;
    }

    protected function toJson($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function getRealIp(): string
    {
        $ip_addr = '';
        if (isset($_SERVER['HTTP_X_REAL_IP'])) {
            //nginx使用fastcgi_param传入
            $ip_addr = $_SERVER['HTTP_X_REAL_IP'];
        } else if (isset($_SERVER['REMOTE_ADDR'])) {
            $ip_addr = $_SERVER['REMOTE_ADDR'];
        } else {
            $ip_addr = 'UNKNOWN';
        }
        return $ip_addr;
    }

    protected function getStaticUrl(string $ossName): string
    {
        $hytrip_static_url = getAppConfig('static_url');
        return $hytrip_static_url . $ossName;
    }

}
