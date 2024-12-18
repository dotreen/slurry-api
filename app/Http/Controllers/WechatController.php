<?php

namespace App\Http\Controllers;

use App\Traits\WechatClientTrait;
use EasyWeChat\MiniApp\Decryptor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class WechatController extends Controller
{
    use WechatClientTrait;

    public function getSession(Request $request)
    {
        $this->validate($request, [
            'code' => 'required|string',
        ]);

        $data = $this->code2session($request->code);

        //判断code是否过期
        if (isset($data['errcode'])) {
            $errcode = $data['errcode'];
            if($errcode == 40163) {
                return $this->jsonError('code已经过期');
            }

            Log::error('code2session失败', $data);
            return $this->jsonError('code2session失败');
        }

        //https://developers.weixin.qq.com/miniprogram/dev/framework/open-ability/login.html
        //会话密钥 session_key 是对用户数据进行 加密签名 的密钥。为了应用自身的数据安全，开发者服务器不应该把会话密钥下发到小程序，也不应该对外提供这个密钥。
        //同一个用户短时间内多次调用 wx.login，并非每次调用都导致 session_key 刷新
        //一般为了安全起见，unionid和openid都不会发往客户端

        $logininfo = [
            'unionid' => $data['unionid'] ?? null,
            'openid' => $data['openid'],
            'session_key' => $data['session_key'],
        ];
        $logininfoJSON = $this->toJson($logininfo);

        $login_key = uniqid();
        $ttl = 60 * 60 * 24; //24小时有效
        Redis::setex('login_key:' . $login_key, $ttl, $logininfoJSON);

        return $this->jsonOk([
            "login_key" => $login_key
        ]);
    }

    public function login(Request $request) {
        $this->validate($request, [
            'login_key' => 'required|string',
            'encrypted_data' => 'required|string',
            'iv' => 'required|string',
            'referrer' => 'nullable|int',
        ]);

        $login_key = 'login_key:' . $request->login_key;
        $encrypted_data = $request->encrypted_data;
        $iv = $request->iv;
        $referrer = intval($request->referrer);

        //从redis中取数据
        $logininfoJSON = Redis::get($login_key);
        if(is_null($logininfoJSON)) {
            Log::error('login_key not found', ["login_key" => $login_key]);
            return $this->jsonError('login_key无效或已过期');
        }

        $logininfo = json_decode($logininfoJSON, true);
        if(empty($logininfo)) {
            Log::error('logininfo not found', ['logininfoJSON' => $logininfoJSON]);
            return $this->jsonError('login_key无效或已过期');
        }

        $wechatOpenid = $logininfo['openid'];
        $wechatSessionKey = $logininfo['session_key'];
        $wechatUnionid = $logininfo['unionid'];

        $app = app('easywechat.mini_app');
        if(is_null($app)) {
            Log::error('wechat not found');
            return $this->jsonError('not found easywechat.mini_app');
        }

        //https://developers.weixin.qq.com/miniprogram/dev/framework/open-ability/getPhoneNumber.html
        $decryptedData = null;
        try {
            $decryptedData = Decryptor::decrypt($wechatSessionKey, $iv, $encrypted_data);
        } catch (\Throwable $e) {
            Log::error($e);
            return $this->jsonError('解密失败');
        }

        if(!isset($decryptedData['phoneNumber'])){
            return $this->jsonError('未找到手机号信息');
        }

        $phone =  $decryptedData['phoneNumber']; //用户绑定的手机号（国外手机号会有区号）
        //$purePhoneNumber = $decryptedData['purePhoneNumber']; //没有区号的手机号
        $country_code =  $decryptedData['countryCode']; //区号

        $userinfo = [];
        $userinfo_db = DB::table('users')
            ->select('id', 'username', 'phone_number','nickname','avatar', 'status')
            ->where('wx_openid', $wechatOpenid) //手机号不是唯一标识！用户授权手机号时，可以选择不是当前微信绑定的手机号进行授权。
            ->where('phone_number', $phone)
            ->first();
        if(is_null($userinfo_db)) {
            //注意新建用户时才允许写入referrer
            $userinfo_t = $this->createUser($phone, $country_code, $referrer, $wechatUnionid, $wechatOpenid, $wechatSessionKey);
            $userinfo['id'] = $userinfo_t['id'];
            $userinfo['username'] = $userinfo_t['username'];
            $userinfo['nickname'] = $userinfo_t['nickname'];
            $userinfo['avatar'] = $userinfo_t['avatar'];
            $userinfo['phone_number'] = $userinfo_t['phone_number'];

        } else {
            if($userinfo_db->status != 'ENABLED') {
                return $this->jsonError('用户已被禁用');
            }

            $userinfo['id'] = $userinfo_db->id;
            $userinfo['username'] = $userinfo_db->username;
            $userinfo['nickname'] = $userinfo_db->nickname;
            $userinfo['avatar'] = $userinfo_db->avatar;
            $userinfo['phone_number'] = $userinfo_db->phone_number;

            DB::table('users')
                ->where('id', $userinfo_db->id)
                ->update([
                    'wx_session_key' => $wechatSessionKey,
                    'last_login_ip' => $this->getRealIp(),
                    'last_login_time' => date('Y-m-d H:i:s'),
                ]);
        }

        $userId = $userinfo['id'];

        $token = $this->createToken($userId, $userinfo['username'], $userinfo['phone_number']);

        Redis::del($login_key);

        return $this->jsonOk([
            'token' => $token,
            'user' => [
                'user_id' => $userId,
                //'login_name' => $login_name,
                'nickname' => $userinfo['nickname'],
                'avatar' => $this->getStaticUrl($userinfo['avatar']),
            ]
        ]);
    }

    public function logout(Request $request) {
        $user_id = $this->getUserId();
        if($user_id > 0){
            Redis::del("user:token:$user_id");
        }

        $token = $request->bearerToken();
        Redis::del("token:$token");

        return $this->jsonOk();
    }

    protected function createUser(string $phone, string $country_code, int $referrer, ?string $wechatUnionid, ?string $wechatOpenid, string $wechatSessionKey) {
        $nickname = substr_replace($phone, '****', 3, 4);
        $gender = 'UNKNOWN';
        $city = '';
        $country = '';
        $province = '';

        $username = str_replace('+','00', $phone);
        $userinfo = [
            'username' => $username,
            'password'=> '',
            'nickname' => $nickname,
            'phone_number' => $phone,
            'country_code' => $country_code,
            'avatar' => '/avatar/preset.jpg',
            'gender' => $gender,
            'birthday' => null,
            'city' => $city,
            'country' => $country,
            'province' => $province,
            'referrer' => $referrer,
            'api_token' => '',
            'wx_unionid' => $wechatUnionid,
            'wx_openid' => $wechatOpenid,
            'wx_session_key' => $wechatSessionKey,
            'last_login_ip' => $this->getRealIp(),
            'last_login_time' => date('Y-m-d H:i:s'),
            'status' => 'ENABLED',
        ];
        $userId = DB::table('users')->insertGetId($userinfo);
        $userinfo['id'] = $userId;

        return $userinfo;
    }

    protected function createToken($userId, $username, $phone_number): string {
        $token = md5(uniqid(mt_rand(), true));
        $userinfo = [
            'id' => $userId,
            'username' => $username,
            'phone_number' => $phone_number,
        ];
        $userinfoJSON = $this->toJson($userinfo);
        $ttl = 60 * 60 * 24 * 30; //30天
        Redis::setex("token:$token", $ttl, $userinfoJSON);
        Redis::setex("user:token:$userId", $ttl, $token);

        DB::table('users')
            ->where('id', $userId)
            ->update(['api_token' => $token]);

        return $token;
    }


}
