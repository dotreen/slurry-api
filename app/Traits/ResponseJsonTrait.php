<?php

namespace App\Traits;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

trait ResponseJsonTrait {

    protected function jsonOk($data = null) {
        return response()->json([
            "code" => 0,
            "message" => "ok",
            "data" => $data
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);
    }

    protected function jsonError(string $msg, $data = null) {
        return response()->json([
            "code" => 1,
            "message" => $msg,
            "data" => $data
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE);
    }

    protected function jsonInternalError(\Throwable $e) {
        Log::error($e);

        return $this->jsonError('系统异常');
    }
}
