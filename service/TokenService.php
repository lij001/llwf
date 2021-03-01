<?php
/**
 * Created by 七月
 * Author: 七月
 * 微信公号: 小楼昨夜又秋风
 * 知乎ID: 七月在夏天
 * Date: 2017/2/24
 * Time: 17:18
 */

namespace app\api\service;


use app\lib\enum\ScopeEnum;
use app\lib\exception\ForbiddenException;
use app\lib\exception\ParameterException;
use app\lib\exception\TokenException;
use think\Cache;
use think\Db;
use think\Exception;
use think\Request;


class TokenService {

    // 生成令牌
    public static function generateToken() {
        $randChar = getRandChar(32);
        $timestamp = $_SERVER['REQUEST_TIME_FLOAT'];
        $tokenSalt = config('secure.token_salt');
        return md5($randChar . $timestamp . $tokenSalt);
    }
    //验证token是否合法或者是否过期
    //验证器验证只是token验证的一种方式
    //另外一种方式是使用行为拦截token，根本不让非法token
    //进入控制器
    public static function needPrimaryScope() {
        $scope = self::getCurrentTokenVar('scope');
        if ($scope) {
            if ($scope >= ScopeEnum::User) {
                return true;
            } else {
                throw new ForbiddenException();
            }
        } else {
            throw new TokenException();
        }
    }

    // 用户专有权限
    public static function needExclusiveScope() {
        $scope = self::getCurrentTokenVar('scope');
        if ($scope) {
            if ($scope == ScopeEnum::User) {
                return true;
            } else {
                throw new ForbiddenException();
            }
        } else {
            throw new TokenException();
        }
    }

    public static function needSuperScope() {
        $scope = self::getCurrentTokenVar('scope');
        if ($scope) {
            if ($scope == ScopeEnum::Super) {
                return true;
            } else {
                throw new ForbiddenException();
            }
        } else {
            throw new TokenException();
        }
    }

    public static function getCurrentTokenVar($key) {
        $token = Request::instance()->header('token');
        $system = Request::instance()->header('system');
        if ($system == 'ios' || $system == 'android') {
            $obj = Db::name('members')->where('token', $token)->field('id,is_disabled,reason')->find();
        } else if ($system == 'wechat') {
            $obj = Db::name('members')->where('wetoken', $token)->field('id,is_disabled,reason')->find();
        } else {
            die(json_encode([
                'code' => 405,
                'msg' => '访问受限',
                'data' => []
            ], JSON_UNESCAPED_UNICODE));
        }
        if (!$token || !$obj || !$obj['id']) {
            Db::name('test')->insert([
                'param' => json_encode([
                    'token' => $token,
                    'system' => $system,
                    'obj' => $obj
                ], JSON_UNESCAPED_UNICODE)
            ]);
            die(json_encode([
                'code' => 403,
                'msg' => '账号在其它设备登陆，请重新登陆',
                'data' => []
            ], JSON_UNESCAPED_UNICODE));
        } else {
            if ($obj['is_disabled'] == 1) {
                if (!empty($obj['reason'])) {
                    die(json_encode([
                        'code' => 404,
                        'msg' => $obj['reason'],
                        'data' => []
                    ], JSON_UNESCAPED_UNICODE));
                }
                die(json_encode([
                    'code' => 404,
                    'msg' => '您的账户已冻结,请联系管理员处理',
                    'data' => []
                ], JSON_UNESCAPED_UNICODE));
            }
            Db::name('members')->where('id', $obj['id'])->update(['last_login_ip' => request()->ip()]);
            return $obj['id'];
        }
    }

    public static function getCurrentTokenVarZuJi($key) {
        $token = Request::instance()
            ->header('token');
        $vars = Cache::get($token);
        if (!$vars) {
            return '';
        } else {
            if (!is_array($vars)) {
                $vars = json_decode($vars, true);
            }
            if (array_key_exists($key, $vars)) {
                return $vars[$key];
            } else {
                return '';
            }
        }
    }

    /**
     * 从缓存中获取当前用户指定身份标识
     * @param array $keys
     * @return array result
     * @throws \app\lib\exception\TokenException
     */
    public static function getCurrentIdentity($keys) {
        $token = Request::instance()
            ->header('token');
        $identities = Cache::get($token);
        //cache 助手函数有bug
        // $identities = cache($token);
        if (!$identities) {
            throw new TokenException();
        } else {
            $identities = json_decode($identities, true);
            $result = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $identities)) {
                    $result[$key] = $identities[$key];
                }
            }
            return $result;
        }
    }

    /**
     * 当需要获取全局UID时，应当调用此方法
     *而不应当自己解析UID
     *
     */
    public static function getCurrentUid() {
        return self::getCurrentTokenVar('uid');
    }

    public static function getCurrentUidZuJi() {
        $uid = self::getCurrentTokenVarZuJi('uid');
        $scope = self::getCurrentTokenVarZuJi('scope');
        if ($scope == ScopeEnum::Super) {
            // 只有Super权限才可以自己传入uid
            // 且必须在get参数中，post不接受任何uid字段
            $userID = input('get.uid');
            if (!$userID) {
                return '';
            }
            return $userID;
        } else {
            return $uid;
        }
    }


    /**
     * 检查操作UID是否合法
     * @param $checkedUID
     * @return bool
     * @throws Exception
     * @throws ParameterException
     */
    public static function isValidOperate($checkedUID) {
        if (!$checkedUID) {
            throw new Exception('检查UID时必须传入一个被检查的UID');
        }
        $currentOperateUID = self::getCurrentUid();
        if ($currentOperateUID == $checkedUID) {
            return true;
        }
        return false;
    }

    public static function verifyToken($token) {
        $exist = Cache::get($token);
        if ($exist) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 重置token
     * @param unknown $uid
     * @return string[]|number[] ['res','token']
     */
    public static function reset_token($uid) {
        $token = self::generateToken();
        $header = Request::instance()->header();
        $res = null;
        if ($header['system'] == 'ios' || $header['system'] == 'android') {
            $res = Db::name('members')->where("id", $uid)->update(['token' => $token]);
        } else if ($header['system'] == 'wechat') {
            $res = Db::name('members')->where("id", $uid)->update(['wetoken' => $token]);
        } else {
            die(json_encode([
                'code' => 405,
                'msg' => '访问受限',
                'data' => []
            ], JSON_UNESCAPED_UNICODE));
        }
        return [$res, $token];
    }

}
