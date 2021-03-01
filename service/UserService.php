<?php


namespace app\api\service;


use app\api\model\member\MemberWithdraw;
use app\extend\Ali;
use app\extend\WeChat;
use app\BaseService;
use think\Cache;
use think\Db;
use think\Request;

class UserService extends BaseService {
    protected function initialization($uid = null) {
        $this->uid = $uid;
    }


    /**
     * 获取我的团队信息,包括:一级人数,二级人数,直推商店数
     * @return mixed
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getMyTeam() {

        $levelOneArr = Db::name('members')->field('id')->where('parent_id', $this->uid)->where('is_disabled', 0)->select()->toArray();
        $data['levelOneNum'] = count($levelOneArr);
        $data['levelTwoNum'] = Db::name('members')->where('path', 'like', "%-$this->uid-%")->where('is_disabled', 0)->count();
        $ids = implode(',', array_column($levelOneArr, 'id'));
        $data['shopsNum'] = Db::name('shop')->where('uid', 'in', $ids)->count();
        return $data;
    }

    /**
     * 获取推荐用户详情
     * @param $type 类型:one 一级推荐,two 二级推荐,shop 商家
     * @param int $page 分页
     * @return mixed|void
     * @throws \think\exception\DbException\
     */
    public function getUserDetails($type, $page = 1) {
        $page < 1 ? $page = 1 : $page;
        $pageNum = 10;
        $field = 'id,mobile,avatar,star,create_time';
        $field2 = 'M.id,M.mobile,M.avatar,M.star,M.create_time';
        $table = Db::name('members')->alias('M');
        $where = [];
        switch ($type) {
            case 'one':
                $where['M.parent_id'] = $this->uid;
                $members = $table->field($field)->where($where);
                break;
            case 'two':
                $where['M.path'] = ['like', "%-$this->uid-%"];
                $members = $table->field($field)->where($where);
                break;
            case 'shop':
                $where['parent_id'] = $this->uid;
                $members = $table->field($field2)->join('shop S', 'S.uid = M.id', 'right')->where($where);
                break;
            default:
                return;
        }
        $membersSql = $members->page($page, $pageNum)->order('star desc')->buildSql();
        $members = Db::table($membersSql)->alias('mem')->select()->toArray();
        foreach ($members as &$v) {
            $v['create_time'] = date('Y-m-d H:i:s', $v['create_time']);
            if ($type == 'shop') {
                $v['shopInfo'] = Db::name('shop')->field('title,logo,shop_cate_name,shop_descript,shop_type')->where('uid', $v['id'])->find();
            }
            if (empty($v['mobile'])) {
                $v['mobile'] = '手机号未完善';
            }
        }
        return $members;
    }

    /**
     * 获取用户等级说明
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getStar() {
        $stars = Db::name('user_star')->select()->toArray();
        return $stars;
    }

    /**
     * 插入绑定的第三方用户
     * @param $type
     * @param $mid
     * @param $info
     * @throws \Exception
     */
    private function insertBinUser($type, $mid, $info) {
        $table = null;
        $d = [];
        if ($type == 'ali') {
            $d = [
                'mid' => $mid,
                'avatar' => $info['avatar'] ?: '',
                'nick_name' => $info['nick_name'] ?: '',
                'province' => $info['province'] ?: '',
                'city' => $info['city'] ?: '',
                'gender' => $info['gender'] ? ($info['gender'] == 'M' ? 1 : 2) : 0,
                'alipay_user_id' => $info['alipay_user_id'],
            ];
            $table = 'members_ali';
        } elseif ($type == 'wechat') {
            $d = [
                'mid' => $mid,
                'openid' => $info['openid'],
                'nickname' => $info['nickname'],
                'sex' => $info['sex'],
                'province' => $info['province'],
                'city' => $info['city'],
                'country' => $info['country'],
                'headimgurl' => $info['headimgurl'],
                'privilege' => $info['privilege'],
                'unionid' => $info['unionid'],
            ];
            $table = 'members_wechat';
        } elseif ($type == 'wechat_mini') {
            $d = [
                'mid' => $mid,
                'mini_openid' => $info['openid'],
                'nickname' => $info['nickname'] ?: '',
                'sex' => 1,
                'province' => '',
                'city' => '',
                'country' => '',
                'headimgurl' => $info['headerimg'] ?: '',
                'privilege' => '',
                'unionid' => $info['unionid'] ?: '',
            ];
            $table = 'members_wechat';
        }
        if ($table === null) throw new \Exception('类型不正确!');
        if (!Db::name($table)->insert($d)) throw new \Exception('插入失败!');
        return $d;
    }

    /**
     * 获取绑定了微信的uid
     * @return int|mixed|string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function getWeChatUid() {
        $weChat = WeChat::objectInit()->jsApiConfig()->getUserInfo();
        $info = Db::name('members_wechat')->where('unionid', $weChat['unionid'])->find();
        $mid = null;
        if ($info === null || empty($info['mid'])) {
            $mid = $this->offlinePayCreateMember();
            if ($info === null) {
                $this->insertBinUser('wechat', $mid, $weChat);
            } else {
                Db::name('members_wechat')->where('unionid', $info['unionid'])->update(['mid' => $mid]);
            }
            Db::name('members')->where('id', $mid)->update(['nickname' => $weChat['nickname']]);
        } else {
            $mid = $info['mid'];
        }
        return $mid;
    }

    /**
     * 获取绑定了ali 的uid
     * @return int|mixed|string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function getAliUid() {
        $user = Ali::objectInit()->getH5UserInfo();
        $info = Db::name('members_ali')->where('alipay_user_id', $user['alipay_user_id'])->find();
        $mid = null;
        if ($info === null || empty($info['mid'])) {
            $mid = $this->offlinePayCreateMember();
            if ($info === null) {
                $this->insertBinUser('ali', $mid, $user);
            } else {
                Db::name('members_ali')->where('alipay_user_id', $info['alipay_user_id'])->update(['mid' => $mid]);
            }
            Db::name('members')->where('id', $mid)->update(['nickname' => '商家会员']);
        } else {
            $mid = $info['mid'];
        }
        return $mid;
    }

    /**
     * 线下支付自动创建账户
     * @return int|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function offlinePayCreateMember() {
        $shopUid = session('mid');
        if (!$shopUid) {
            $shopId = session('shop_id');
            if (!$shopId) throw new \Exception('商户id不能为空!');
            $shopUid = Db::name('shop')->where('id', $shopId)->value('uid');
        }
        if (!$shopUid) throw new \Exception('未找到商家uid!');
        $shopUser = Db::name('members')->where('id', $shopUid)->find();
        if ($shopUser === null) throw new \Exception('未找到商家账号信息!');
        $default_avatar = cmf_get_domain() . "/static/images/dd.jpg";
        $user = [
            'parent_id' => $shopUser['id'],
            'path' => $shopUser['id'] . '-' . $shopUser['path'],
            'password' => md5(time()),
            'province' => $shopUser['province'],
            'city' => $shopUser['city'],
            'county' => $shopUser['county'],
            'area_id' => $shopUser['area_id'],
            'number' => getRandNumberInt(8),
            'create_time' => time(),
            'avatar' => $default_avatar,
            'token' => md5(time())
        ];
        Db::startTrans();
        try {
            $uid = Db::name('members')->insertGetId($user);
            if ($uid) {
                $this->registeredSuccess($uid, $user, false);
                Db::commit();
            }
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return $uid;
    }

    /**
     * 获取推荐人
     * @param $area_id
     * @return bool|float|int|mixed|string
     */
    protected function getNumber($area_id) {
        $number = 0;
        for ($i = 0; $i < 3; $i++) {
            $number = Db::name('members')->where('oc_area_id', $area_id)->value('number');
            if (!empty($number)) break;
            $area_id = Db::name('baidu_map')->where('id', $area_id)->value('pid');
            if (empty($area_id)) break;
        }
        return empty($number) ? 88888888 : $number;
    }

    /**
     * 用户注册功能
     * @param $param
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function registered($param) {
        $mobile = $param['mobile'] ?: '';
        $code = $param['code'] ?: '';
        $number = $param['number'] ?: '';
        $password = $param['password'] ?: '';
        $repassword = $param['repassword'] ?: '';
        $province = $param['province'] ?: '';
        $city = $param['city'] ?: '';
        $country = $param['country'] ?: '';
        $area_id = $param['area_id'] ?: '';
        if (empty($mobile)) {
            throw new \Exception('手机号不能为空');
        }
        if (empty($code)) {
            throw new \Exception('短信验证码不能为空');
        }

        if (empty($password)) {
            throw new \Exception('密码不能为空');
        }

        if (empty($repassword)) {
            throw new \Exception('确认密码不能为空');
        }

        if ($password != $repassword) {
            throw new \Exception('两次输入的密码不一致');
        }
        $flag = Cache::get($mobile);
        if ($flag) {
            throw new \Exception('操作太快');
        } else {
            Cache::set($mobile, 1, 5);
        }
        if (empty($area_id)) {
            throw new \Exception('地区编码错误');
        }
        if (empty($province) || empty($city)) {
            throw new \Exception('地区信息不完整');
        }

        $count = Db::name('members')->where("mobile", $mobile)->count();
        if ($count > 0) {
            throw new \Exception('该手机号已注册');
        }
        if (empty($number)) {
            $number = $this->getNumber($area_id);
        }
        $IsCode = IsCode($code, trim($mobile));
        if (!empty($IsCode)) {
            throw new \Exception($IsCode);
        }

        if (strlen($password) < 6 && strlen($password) > 20) {
            throw new \Exception('密码为6-20位的字符、数字组合');
        }
        $parent_id = 0;
        $tjr_obj = Db::name('members')->where("number", $number)->field('id,path,star')->find();
        if (empty($tjr_obj)) {
            $path = 0;
            throw new \Exception('推荐人不存在');
        } else {
            if (!empty($tjr_obj['path'])) {
                $path = $tjr_obj['id'] . '-' . $tjr_obj['path'];
            } else {
                $path = $tjr_obj['id'] . '-0';
            }

            $parent_id = $tjr_obj['id'];
        }
        $temp_id = getRandNumberInt(8);
        //$name_tmp = rand(100000, 999999);
        $default_avatar = cmf_get_domain() . "/static/images/dd.jpg";
        $data = [
            'mobile' => $mobile,
            'nickname' => $mobile,
            'number' => $temp_id,
            'avatar' => $default_avatar,
            'password' => cmf_password($password),
            'parent_id' => $parent_id,
            'path' => $path,
            'create_time' => time(),
            'province' => $province,
            'city' => $city,
            'county' => $country,
            'area_id' => $area_id
        ];
        $token = TokenService::generateToken();
        $data['token'] = $token;
        Db::startTrans();
        try {
            $uid = Db::name('members')->insertGetId($data);
            if ($uid) {
                $this->registeredSuccess($uid, $data);
                Db::commit();
            }
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return $data['token'];

    }

    /**
     * 注册成功事件
     * @param $uid
     * @param $data
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function registeredSuccess($uid, $data, $send = true) {
        Db::name('user_balance')->insertGetId(['uid' => $uid]);
        if ($send) {
            $this->redgisteredSend($uid);
        }
        UpgradeService::objectInit()->userUpgradeCheck($data['parent_id']);
    }

    private function redgisteredSend($uid) {
        $rights = new RightsService();
        $redpacket = RedPacketService::objectInit();
        $reg_gxz = Db::name('config_reward')->where('type', 2)->value('reg_gxz');
        $info = Db::name('members')->field('parent_id,mobile')->where('id', $uid)->find();
        //注册送贡献值红包
        $redpacket->giveGxzRedpacket($uid, $uid, $reg_gxz, '注册送贡献值', 1);
        //推荐人送贡献值红包
        $redpacket->giveGxzRedpacket($info['parent_id'], $uid, $redpacket->getRandomGxz(), '推荐(' . $info['mobile'] . ')送贡献值', 2, 0);
        $rights->givellj($uid, Db::name('config_new')->where('name', 'register_llj')->value('info'), '注册送留莲券', 1);
    }

    /**
     * 第三方账户绑定注册
     * @param $param
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function bindingRegistered($param) {
        if (empty($param['type'])) throw new \Exception('类型不能为空!');
        if (empty($param['openid'])) throw new \Exception('openid不能为空!');
        $info = null;
        $model = null;
        if ($param['type'] == 'ali') {
            $info = Db::name('members_ali')->where('alipay_user_id', $param['openid'])->find();
            $model = Db::name('members_ali')->where('alipay_user_id', $param['openid']);
        } else if ($param['type'] == 'wechat') {
            $info = Db::name('members_wechat')->where('unionid', $param['openid'])->find();
            $model = Db::name('members_wechat')->where('unionid', $param['openid']);
        }
        if ($info === null) throw new \Exception('未找到第三方账户信息!');
        if ($info['bin'] === 1) throw new \Exception('该账户已经绑定了!');
        $token = $this->registered($param);
        $user = Db::name('members')->where('token', $token)->find();
        $model->update(['mid' => $user['id'], 'bin' => 1]);
        $this->loginSuccess($user['id']);
        return $token;
    }

    /**
     * 第三方账户绑定会员
     * @param $param
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function bindingMembers($param) {
        if (empty($param['type'])) throw new \Exception('类型不能为空!');
        if (empty($param['mobile'])) throw new \Exception('手机号码不能为空!');
        if (empty($param['code'])) throw new \Exception('验证码不能为空!');
        if (empty($param['openid'])) throw new \Exception('openid不能为空!');
        $IsCode = IsCode($param['code'], trim($param['mobile']));
        if (!empty($IsCode)) {
            throw new \Exception($IsCode);
        }
        $info = null;
        $model = null;
        $tableName = '';
        if ($param['type'] == 'ali') {
            $info = Db::name('members_ali')->where('alipay_user_id', $param['openid'])->find();
            $model = Db::name('members_ali')->where('alipay_user_id', $param['openid']);
            $tableName = 'members_ali';
        } else if ($param['type'] == 'wechat') {
            $info = Db::name('members_wechat')->where('unionid', $param['openid'])->find();
            $model = Db::name('members_wechat')->where('unionid', $param['openid']);
            $tableName = 'members_wechat';
        }
        if ($info === null) throw new \Exception('未找到第三方账户信息!');
        if ($info['bin'] === 1) throw new \Exception('该账户已经绑定了!');
        $user = DB::name('members')->where('mobile', $param['mobile'])->find();
        if (Db::name($tableName)->where('mid', $user['id'])->find() !== null) {
            throw new \Exception('该账户已经绑定了,无法在绑定!');
        }
        if ($user === null) throw new \Exception('该手机号码未注册!');
        if (Db::name($tableName)->where('mid', $user['id'])->find() !== null) throw new \Exception('该账户已经绑定了,请先解绑该账户!');
        $model->update(['mid' => $user['id'], 'bin' => 1]);
        list($res, $token) = TokenService::reset_token($user['id']);
        $this->loginSuccess($user['id']);
        return $token;
    }


    public function bindingMobile($param) {
        if (empty($param['mobile'])) throw new \Exception('手机号码不能为空!');
        if (empty($param['code'])) throw new \Exception('验证码不能为空!');
        if (empty($param['unionid'])) throw new \Exception('unionid不能为空!');
        if (empty($param['password'])) throw new \Exception('密码不能为空!');
        $IsCode = IsCode($param['code'], trim($param['mobile']));
        if (!empty($IsCode)) {
            throw new \Exception($IsCode);
        }
        $tableName = 'members_wechat';
        $info = Db::name($tableName)->where('unionid', $param['unionid'])->find();
        if (empty($info)) throw new \Exception('未找到第三方账户信息!');

        $mUser = Db::name('members')->alias('m')
            ->join('members_wechat w', 'm.id = w.mid', 'left')
            ->field('m.id,w.unionid')
            ->where('m.mobile', $param['mobile'])->find();
        $wUser = Db::name('members')->alias('m')
            ->join('members_wechat w', 'm.id = w.mid', 'left')
            ->field('m.id,w.unionid,m.mobile')
            ->where('w.unionid', $param['unionid'])->find();
        if (empty($wUser)) throw new \Exception('未创建账户');
        if (!empty($wUser['mobile'])) throw new \Exception('该账号已已绑定手机号');
        if (!empty($mUser['unionid']) && $mUser['unionid'] != $param['unionid']) throw new \Exception('该手机号已被绑定');


        if (empty($mUser)) {//手机号没注册时
            $res = Db::name('members')->where('id', $wUser['id'])->update([
                'mobile' => $param['mobile'],
                'password' => cmf_password($param['password'])
            ]);

            if (!$res) throw new \Exception('绑定失败');
            $thisUid = $wUser['id'];
            Db::name($tableName)->where('unionid', $param['unionid'])->update(['bin' => 1]);
            $config_mei = Db::name('config_reward')->where('type', 2)->find();
            $redpacket = RedPacketService::objectInit();
            $redpacket->giveGxzRedpacket($thisUid, $thisUid, $config_mei['reg_gxz'], '注册送贡献值', 1);
            //推荐人送贡献值红包
            $redpacket->giveGxzRedpacket(Db::name('members')->where('id', $thisUid)->value('parent_id'), $thisUid, $redpacket->getRandomGxz(), '推荐(' . $param['mobile'] . ')送贡献值', 1, 0);
        } else {//手机号已注册
            $mUserBind = Db::name($tableName)->where('mid', $mUser['id'])->find();

            if (!empty($mUserBind)) throw new \Exception('该手机号已被绑定');
            Db::name('members')->where('id', $mUser['id'])->update([
                'unionid' => $param['unionid']
            ]);

            $thisUid = $this->merge($mUser['id'], $wUser['id']);
            Db::name('members')->where('id', $thisUid)->update(['unionid' => $param['unionid']]);
            //$thisUid = $mUser['id'];
            Db::name($tableName)->where('unionid', $param['unionid'])->update(['mid' => $thisUid, 'bin' => 1]);
        }


        list($res, $token) = TokenService::reset_token($thisUid);
        $this->loginSuccess($thisUid);
        return ['token' => $token];
    }

    /**
     * 登录成功后事件
     * @param $mid
     */
    protected function loginSuccess($mid) {
        Db::name('members')->where('id', $mid)->update(['last_login_ip' => request()->ip()]);
        $date = date('Y-m-d 00:00:00', time());
        $login_num = Db::name('login_num')->where('date', $date)->find();
        if (!empty($login_num)) {
            $login_id = explode(',', $login_num['login_id']);
            if (!in_array($mid, $login_id)) {
                $login_num['login_id'] = $login_num['login_id'] . "," . $mid;
                Db::name('login_num')->where('date', $date)->update(['login_id' => $login_num['login_id']]);
            }
        } else {
            $insert = [
                'date' => $date,
                'login_id' => $mid
            ];
            Db::name('login_num')->insert($insert);
        }
    }

    /**
     * 授权登录
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function authLogin($param) {
        if (empty($param['code'])) throw new \Exception('授权code不能为空!');
        if (empty($param['type'])) throw new \Exception('类型不能为空!');
        $number = $param['number'] ? $param['number'] : 0;
        $tableName = null;
        $info = null;
        $userInfo = null;
        $openid = null;
        if ($param['type'] == 'ali') {
            $tableName = 'members_ali';
            request()->get(['auth_code' => $param['code']]);
            $userInfo = Ali::objectInit()->getH5UserInfo();
            $info = DB::name($tableName)->where('alipay_user_id', $userInfo['alipay_user_id'])->find();
            $openid = $userInfo['alipay_user_id'];
        } elseif ($param['type'] == 'wechat') {
            $tableName = 'members_wechat';
            $userInfo = Wechat::objectInit()->config()->getUserInfo();
            $info = DB::name($tableName)->where('unionid', $userInfo['unionid'])->find();
            if (!empty($info) && empty($info['openid'])) {
                $d = [
                    'openid' => $info['openid'],
                    'nickname' => $info['nickname'],
                    'sex' => $info['sex'],
                    'province' => $info['province'],
                    'city' => $info['city'],
                    'country' => $info['country'],
                    'headimgurl' => $info['headimgurl'],
                    'privilege' => $info['privilege'],
                    'unionid' => $info['unionid'],
                ];
                Db::name($tableName)->where('unionid', $userInfo['unionid'])->update($d);
            }
            $openid = $userInfo['unionid'];
        } elseif ($param['type'] == 'wechat_mini') {
            $tableName = 'members_wechat';
            $userInfo = WechatMiniService::objectInit()->config()->getUserInfo();
            if (empty($userInfo['unionid'])) returnJson(3, '登录失败,请关注公众号');
            $info = DB::name($tableName)->where('unionid', $userInfo['unionid'])->find();
            if (!empty($info) && empty($info['mini_openid'])) {
                Db::name($tableName)->where('unionid', $userInfo['unionid'])->update([
                    'mini_openid' => $userInfo['openid'],
                ]);
            }
            $openid = $userInfo['unionid'];
            $userInfo['headerimg'] = $param['headerimg'] ?: cmf_get_domain() . "/static/images/dd.jpg";
            $userInfo['nickname'] = $param['nickname'] ?: '小莲会员';
        }
        if ($info === null || empty($info['mid'])) {
            if ($param['type'] == 'wechat_mini') {
                $token = $this->createWechatUserInfo($userInfo, $number);
                return ['status' => 'unMobile', 'token' => $token, 'openid' => $openid];
            } else {
                if ($info === null) {
                    $this->insertBinUser($param['type'], 0, $userInfo);
                }
                return ['status' => 'unRegistered', 'openid' => $openid];
            }
        } else {
            $mUser = Db::name('members')->where('id', $info['mid'])->find();
            list($res, $token) = TokenService::reset_token($info['mid']);
            if (!$res) throw new \Exception('登录失败!');
            $this->loginSuccess($info['mid']);
            RedPacketService::objectInit()->activeRedpacket($info['mid']);
            if (empty($mUser['mobile'])) {
                return ['status' => 'unMobile', 'token' => $token, 'openid' => $openid];
            } else {
                return ['status' => 'loginSuccess', 'token' => $token, 'openid' => $openid];
            }
        }
    }

    /**
     * 小程序解密后登陆
     * @param $param
     * @return array
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function WxMiniLogin($param) {
        if (empty($param['code'])) throw new \Exception('授权code不能为空!');
        $userInfo = WechatMiniService::objectInit()->config()->getUserInfo();
        $data = WechatMiniService::objectInit()->config()->getUnionid($param, $userInfo['session_key']);
        if (empty($data['unionId']) || empty($data['openId'])) throw new \Exception('授权失败!');
        $openid = $data['openId'];
        $tableName = 'members_wechat';
        $info = DB::name($tableName)->where('unionid', $data['unionId'])->find();
        if (empty($info)) {
            $token = $this->createWechatUserInfo([
                'unionid' => $data['unionId'],
                'openid' => $data['openId'],
                'headerimg' => $data['avatarUrl'] ?: cmf_get_domain() . "/static/images/dd.jpg",
                'nickname' => $data['nickName']
            ], $param['number'] ?: null);
            return ['status' => 'unMobile', 'token' => $token, 'openid' => $data['unionId']];
        } else {
            $mUser = Db::name('members')->where('id', $info['mid'])->find();
            list($res, $token) = TokenService::reset_token($info['mid']);
            if (!$res) throw new \Exception('登录失败!');
            $this->loginSuccess($info['mid']);
            if (empty($mUser['mobile'])) {
                return ['status' => 'unMobile', 'token' => $token, 'openid' => $openid];
            } else {
                return ['status' => 'loginSuccess', 'token' => $token, 'openid' => $openid];
            }
        }
    }

    /**
     * 创建用户信息
     * @param $unionid
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function createWechatUserInfo($param, $number) {
        $unionid = $param['unionid'];
        $openid = $param['openid'];
        if (!empty($number)) {
            $xiaolianUid = Db::name('members')->where('number', $number)->value('id');
        } else {
            $xiaolianUid = 1260;
        }
        $xiaolian = Db::name('members')->field('path,province,city,county,area_id')->where('id', $xiaolianUid)->find();
        $data = [
            'avatar' => $param['headerimg'],
            'unionid' => $unionid,
            'create_time' => time(),
            'number' => getRandNumberInt(8),
            'parent_id' => $xiaolianUid,
            'path' => $xiaolianUid . "-" . $xiaolian['path'],
            'province' => $xiaolian['province'],
            'city' => $xiaolian['city'],
            'county' => $xiaolian['county'],
            'area_id' => $xiaolian['area_id'],
            'nickname' => $param['nickname']
        ];
        //写入用户信息表
        $re = Db::name('members')->insertGetId($data);
        list($res, $token) = TokenService::reset_token($re);
        $data['token'] = $token;
        Db::name('members')->where('id', $re)->update(['token' => $data['token']]);
        //创建小程序绑定
        if (!empty(Db::name('members_wechat')->where('unionid', $param['unionid'])->find())) {
            Db::name('members_wechat')->where('unionid', $param['unionid'])->update([
                'mid' => $re
            ]);
        } else {
            $this->insertBinUser('wechat_mini', $re, $param);
        }
        //写入用户余额
        $date_vb = [];
        $date_vb['uid'] = $re;
        Db::name('user_balance')->insertGetId($date_vb);
        return $token;
    }

    /**
     * 更新已有用户的手机号
     */
    public function updateUserMobile($param) {
        if (empty($param['mobile'])) throw new \Exception('手机号不能为空!');
        if (empty($param['code'])) throw new \Exception('验证码不能为空!');
        $IsCode = IsCode($param['code'], trim($param['mobile']));
        if (!empty($IsCode)) {
            throw new \Exception($IsCode);
        }
        $uid = TokenService::getCurrentUid();
        $m = Db::name('members')->where('id', $uid)->find();
        if (!empty($m['mobile'])) throw new \Exception('该账户已有手机号了不能更换!');
        $user = Db::name('members')->where('mobile', $param['mobile'])->find();
        try {
            Db::startTrans();
            $update = ['bin' => 1];
            if ($user !== null) {
                if ($user['id'] == $uid) throw new \Exception('不要重复绑定账号!');

                $update['mid'] = $this->merge($user['id'], $uid);
            } else {
                Db::name('members')->where('id', $uid)->update(['mobile' => $param['mobile']]);
                $this->redgisteredSend($uid);
            }
            Db::name('members_ali')->where('mid', $uid)->update($update);
            Db::name('members_wechat')->where('mid', $uid)->update($update);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return 1;
    }

    /**
     * 绑定账户
     * @param $param
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function bindingAccount($param) {
        if (empty($param['code'])) throw new \Exception('授权code不能为空!');
        if (empty($param['type'])) throw new \Exception('类型不能为空!');
        $tableName = null;
        $info = null;
        $userInfo = null;
        $uid = TokenService::getCurrentUid();
        if ($param['type'] == 'ali') {
            $tableName = 'members_ali';
            request()->get(['auth_code' => $param['code']]);
            $userInfo = Ali::objectInit()->getH5UserInfo();
            $info = DB::name($tableName)->where('alipay_user_id', $userInfo['alipay_user_id'])->find();
        } elseif ($param['type'] == 'wechat') {
            $tableName = 'members_wechat';
            $userInfo = Wechat::objectInit()->config()->getUserInfo();
            $info = DB::name($tableName)->where('unionid', $userInfo['unionid'])->find();
            if (!empty($info) && empty($info['openid'])) {
                DB::name($tableName)->where('unionid', $userInfo['unionid'])->update([
                    'openid' => $info['openid'],
                    'nickname' => $info['nickname'],
                    'sex' => $info['sex'],
                    'province' => $info['province'],
                    'city' => $info['city'],
                    'country' => $info['country'],
                    'headimgurl' => $info['headimgurl'],
                    'privilege' => $info['privilege'],
                ]);
            }
        }
        if ($tableName === null) throw new \Exception('类型不正确!');
        if ($info === null) {
            $this->insertBinUser($param['type'], $uid, $userInfo);
            Db::name($tableName)->where('mid', $uid)->update(['bin' => 1]);
        } elseif ($info['bin'] === 1) {
            throw new \Exception('该账户已经绑定了其他账户,不能再绑定了');
        } else {
            Db::startTrans();
            try {
                if ($info['mid'] !== 0) {
                    $uid = $this->merge($uid, $info['mid']);
                }
                Db::name($tableName)->where('mid', $info['mid'])->update(['mid' => $uid, 'bin' => 1]);
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                throw new \Exception($e->getMessage());
            }
        }
    }

    public function binAccountList() {
        $mid = TokenService::getCurrentUid();
        $data = [
            'wechat' => Db::name('members_wechat')->where('mid', $mid)->find(),
            'ali' => Db::name('members_ali')->where('mid', $mid)->find(),
        ];
        return $data;
    }

    /**
     * 账户合并
     * @param int $uid 被合并的uid
     * @param int $mergeUid 合并的uid
     */
    protected function merge($uid, $mergeUid) {
        //更新账户信息
        $user = Db::name('members')->where('id', $uid)->find();
        $merUser = Db::name('members')->where('id', $mergeUid)->find();
        if ($user === null || $merUser === null) throw new \Exception('其中一个账户信息未找到!');
        if (!empty($user['mobile']) && !empty($merUser['mobile'])) throw new \Exception('该账户已经绑定过,不能再绑定了!');
        if ($user['id'] > $merUser['id']) {
            //合并和被合并id互换
            $temp_mid = $uid;
            $uid = $mergeUid;
            $mergeUid = $temp_mid;
        }
        $mobile = $user['mobile'];
        if (empty($mobile)) {
            $mobile = $merUser['mobile'];
        }
        Db::name('members')->where('id', $uid)->update([
            'mobile' => $mobile,
            'star'=>$user['star']>$merUser['star']?$user['star']:$merUser['star'],
        ]);
        Db::name('members')->where('id', $mergeUid)->update([
            'mobile' => $mobile . '_merge',
            'wetoken' => '',
            'token' => '',
            'is_dis_award' => 1,
            'is_disabled' => 1,
            'star'=>0
        ]);
        //1合并钱包
        $info = Db::name('user_balance')->where('uid', $uid)->find();
        $mergeInfo = Db::name('user_balance')->where('uid', $mergeUid)->find();
        if ($info === null || $mergeInfo === null) throw new \Exception('其中一个账户的信息未找到!');
        $info['total'] += $mergeInfo['total'];//总积分
        $info['balance'] += $mergeInfo['balance'];//可用积分
        $info['tz_coupon'] += $mergeInfo['tz_coupon'];//通证
        $info['gxz'] += $mergeInfo['gxz'];//贡献值
        $info['calculator'] += $mergeInfo['calculator'];//榴莲值
        $info['llj'] += $mergeInfo['llj'];//榴莲卷
        $info['cal_time'] = strtotime($info['cal_time']) > strtotime($mergeInfo['cal_time']) ? $info['cal_time'] : $mergeInfo['cal_time'];//领取通证时间
        Db::startTrans();
        Db::name('user_balance')->where('uid', $uid)->update($info);
        Db::name('user_balance')->where('uid', $mergeUid)->update([
            'total' => 0,
            'balance' => 0,
            'tz_coupon' => 0,
            'gxz' => 0,
            'calculator' => 0,
            'llj' => 0,
        ]);
        //2合并订单
        Db::name('ali_order')->where('uid', $mergeUid)->update(['uid' => $uid]);
        Db::name('order_offline')->where('uid', $mergeUid)->update(['uid' => $uid]);
        Db::name('gas_order')->where('uid', $mergeUid)->update(['uid' => $uid]);
        Db::name('gas_card_order')->where('uid', $mergeUid)->update(['uid' => $uid]);
        Db::name('ping_group_open')->where('mid', $mergeUid)->update(['mid' => $uid]);
        //3合并支付日志
        Db::name('tz_log')->where('uid', $mergeUid)->update(['uid' => $uid]);
        Db::name('pay_info')->where('uid', $mergeUid)->update(['uid' => $uid]);
        Db::name('user_balance_log')->where('uid', $mergeUid)->update(['uid' => $uid]);
        Db::name('shop')->where('uid', $mergeUid)->update(['uid' => $uid]);
        SignInService::objectInit()->mergeMember($uid, $mergeUid);
        $param = json_encode([
            'url' => request()->url(),
            'param' => request()->param(),
            'balance' => $mergeInfo
        ], JSON_UNESCAPED_UNICODE);
        Db::name('members_merge_log')->insert([
            'mid' => $uid,
            'merge_mid' => $mergeUid,
            'create_time' => time(),
            'param' => $param
        ]);
        Db::commit();
        return $uid;
    }

    public function getBalance($uid) {
        $info = Db::name('user_balance')->where('uid', $uid)->find();
        return $info;
    }

    public function getNewGXZSum($uid, $start_time, $end_time = null) {
        $model = Db::name('user_balance_log')->where(['uid' => $uid, 'type' => 1])->where('create_time', '>', date('Y-m-d', $start_time));
        if ($end_time !== null) {
            $model->where('create_time', '<', date('Y-m-d', $end_time));
        }
        $gxz = $model->sum('gxz');
        return $gxz;
    }

    public function getNewTzSum($uid, $start_time, $end_time = null) {
        $model = Db::name('tz_log')->where(['uid' => $uid])->where('date', '>=', date('Y-m-d', $start_time));
        if ($end_time !== null) {
            $model->where('date', '<=', date('Y-m-d H:i:s', $end_time));
        }
        $tzNum = $model->value('tzNum');
        return $tzNum ?: 0;
    }


}
