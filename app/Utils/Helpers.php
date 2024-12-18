<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;

/*
 * 添加或者删除函数后执行composer dump-autoload
 * */

function getAppConfig(string $key) {
    return Config::get("self.$key");
}
