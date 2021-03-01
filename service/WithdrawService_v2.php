<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\ConfigNew;
use app\api\model\Withdraw;
use app\api\model\withdraw\WithdrawLog;
use app\api\service\AliPay\Fund\Trans;
use app\api\service\WxPay\MerchPayService;
use think\Cache;
use think\Db;
use think\Exception;
use think\Request;
use think\Validate;

class WithdrawService_v2 extends BaseService {
    //提现接口,包括:余额,通证,商家货款
    // *
    // * balance :100              提现额度
    // * mobile :152****7177       手机号
    // * code :                    验证码
    // * source: 0余额1通证2货款    提现来源
    // * balanceType: 0:非今日货款 1:今日货款 2:全部  货款类型
    /**
     * 提现接口,包括:余额,通证,商家货款
     * @param $param
     * @return int
     * @throws \Exception
     */
    public function withdraw($param) {
        $mid = MemberService::getCurrentMid();
        MemberService::objectInit()->checkMemberSMRZ($mid);//验证实名认证
        $this->withdrawValidate($param);//验证公共信息
        if (!empty(Cache::get('drawZsBalance_' . $mid))) {
            throw new \Exception('操作过于频繁!');
        } else {
            Cache::set('drawZsBalance_' . $mid, 1, 5);
        }
        $param['mid'] = $mid;
        if ($param['source'] < 2) {
            $this->memberWithdraw($param);
        } else {
            $this->shopWithdraw($param);
        }
        return 1;
    }

    /**
     * 红包提现
     * @param $param
     * @throws Exception
     */
    public function redpacketWithdraw($param) {
        $param['mid'] = TokenService::getCurrentUid();
        $param['source'] = 0;
        $param['is_shop'] = '红包';
        $this->withdrawValidate2($param);
        if (!empty(Cache::get('drawZsBalance_' . $param['mid']))) {
            throw new \Exception('操作过于频繁!');
        } else {
            Cache::set('drawZsBalance_' . $param['mid'], 1, 5);
        }
        $status = false;
        $priceList = (new ConfigNew())->getPriceList($param['mid']);
        foreach ($priceList as $v) {
            if ($v['price'] == $param['balance'] && $v['status'] === '可提现') $status = true;
        }
        if (!$status) throw new \Exception('当前金额不可提现');
        MemberBalanceService::objectInit()->memberWithdraw($param['mid'], $param['balance'], '红包');
        $memberWithdraw = MemberService::objectInit()->getWithdrawInfo($param['memberWithdrawId'], $param['mid']);
        if ($memberWithdraw['type'] !== '微信') throw new Exception('红包提现只支持微信提现');
        $param['cost'] = 0;//红包提现不需要手续费
        $param['mobile'] = MemberService::getMemberInfo(MemberService::getCurrentMid())->mobile;
        $info = $this->addWithdrawData($param, $memberWithdraw);
        $memberWithdraw['type'] = '红包';
        if ($this->secondsTo($info, $memberWithdraw)) {
            $info['status'] = '完成';
            $info->save();
        }
    }

    /**
     * 获取红包提现价格区间列表
     * @return array
     * @throws Exception
     */
    public function getRedpacketPriceList() {
        $uid = Request::instance()->header('token') ? TokenService::getCurrentUid() : 1712;
        $list = (new ConfigNew())->getPriceList($uid);
        return $list;
    }

    /**
     * 验证提现需要的公共信息
     * @return Validate
     */
    private function withdrawValidate($param) {
        $rule = [
            'memberWithdrawId' => 'require|>:0',
            'balance' => 'require|>=:0.3',
            'mobile' => 'require|length:11',
            'code' => function ($code, $param) {
                $errorMsg = IsCode($code, $param['mobile']);
                if (!empty($errorMsg)) {
                    return $errorMsg;
                }
                return true;
            },
            'source' => 'require|in:0,1,2'
        ];
        $msg = [
            'memberWithdrawId' => '会员提现账户id不能为空',
            'balance.require' => '提现金额不能为空!',
            'balance.egt' => '提现金额必须大于等于0.3!',
            'mobile' => '手机号码不正确!',
            'code' => '验证码不能为空!',
            'source' => '提现来源不正确'
        ];
        $validate = new Validate($rule, $msg);
        if (!$validate->check($param)) {
            throw new \Exception($validate->getError());
        }
    }

    /**
     * 验证提现需要的公共信息
     * @return Validate
     */
    private function withdrawValidate2($param) {
        $rule = [
            'memberWithdrawId' => 'require|>:0',
            'balance' => 'require|>=:0.3',
            'payPassword' => function ($payPassword, $param) {
                $payPassword2 = Db::name('members')->where('id', $param['mid'])->value('pay_password');
                if (empty($payPassword2) || empty($payPassword)) {
                    return '支付密码不可为空';
                }
                if ($payPassword2 != md5($payPassword)) {
                    return '支付密码不正确';
                }
                return true;
            },
            'source' => 'require|in:0,1,2'
        ];
        $msg = [
            'memberWithdrawId' => '会员提现账户id不能为空',
            'balance.require' => '提现金额不能为空!',
            'balance.egt' => '提现金额必须大于等于0.3!',
            'payPassword' => '支付密码不正确!',
            'source' => '提现来源不正确'
        ];
        $validate = new Validate($rule, $msg);
        if (!$validate->check($param)) {
            throw new \Exception($validate->getError());
        }
    }

    /**
     * 会员提现
     * @param $param
     */
    private function memberWithdraw($param) {
        $source = $param['source'] == 1 ? '通证' : '余额';
        $start_time = date('Y-m-d 00:00:00');
        $end_time = date('Y-m-d 23:59:59');
        $model = Withdraw::_whereCV('is_shop', $source);
        $info = $model->where(['uid' => $param['mid'], 'create_time' => ['between', "$start_time,$end_time"], 'status' => ['<', 3]])->find();
        if ($info !== null) throw new \Exception("今日" . $source . "已提现");
        if ($param['balance'] < 100) throw new \Exception('提现金额必须大于等于100!');
        if (!is_int($param['balance'] * 1) || $param['balance'] % 100 != 0) throw new \Exception('提现金额必须为100整数倍!');
        $memberWithdraw = MemberService::objectInit()->getWithdrawInfo($param['memberWithdrawId'], $param['mid']);
        if ($source === '通证' && $param['balance'] > 5000) throw new \Exception('通证每日提现不可大于5000');
        try {
            Withdraw::startTrans();
            $param['is_shop'] = $source;
            $config_coin = Db::name('config_reward')->where('type', 2)->find();
            if ($source === '余额') {
                $param['cost'] = $param['balance'] * $config_coin['fee'] / 100;
            } else {
                $param['cost'] = $param['balance'] * $config_coin['tz_rate'] / 100;
            }
            if ($memberWithdraw['type'] === '微信' && $param['balance'] > 5000) throw new \Exception('微信提现金额单笔不能大于5000元');
            MemberBalanceService::objectInit()->memberWithdraw($param['mid'], $param['balance'], $source);
            $info = $this->addWithdrawData($param, $memberWithdraw);
            Withdraw::commit();
        } catch (\Exception $e) {
            Withdraw::rollback();
            throw new \Exception($e->getMessage());
        }
        if (ConfigNew::getAutoWithdraw() < $param['balance']) {
            $info['remark'] = 'T+1到账,超过限额';
            $info->save();
            throw new \Exception('提现成功,T+1到账', 200);
        }
        return $this->whiteListSuccess($info, $memberWithdraw);
    }

    /**
     * 商家提现
     * @param $param
     */
    private function shopWithdraw($param) {
        $validate = new Validate(['balanceType' => 'require|in:0,1,2'], ['balanceType' => 'balanceType类型不正确']);
        if (!$validate->check($param)) throw new \Exception($validate->getError());
        $H = date('H');
        $i = date('i');
        if ($H < 9) {
            throw new \Exception("商家货款请在9:00-22:30进行提现!");
        }
        if ($H > 22 && $i > 30) {
            throw new \Exception("商家货款请在9:00-22:30进行提现!");
        }

        $memberWithdraw = MemberService::objectInit()->getWithdrawInfo($param['memberWithdrawId'], $param['mid']);

        $shop = Db::name('shop')->where('uid', $param['mid'])->find();
        if ($shop['shop_type'] == 1) {
            $param['is_shop'] = '线上';
            $tableName = 'order_info';
            $moneyName = 'total';
        } elseif ($shop['shop_type'] == 2) {
            $param['is_shop'] = '线下';
            $tableName = 'order_offline';
            $moneyName = 'total';
        } else {
            throw new \Exception('不存在商家类型');
        }
        $today = date('Y-m-d 00:00:00');
        $tomorrow = date('Y-m-d 23:59:59');
        if ($memberWithdraw['type'] === '微信' && $param['balance'] > 5000) throw new \Exception('微信提现金额单笔不能大于5000元');

        $shop_balance = MemberBalanceService::objectInit()->getShopBalanceMoney($param['mid']);
        $pay_amount = Db::name($tableName)->where(['spid' => $shop['id'], 'status' => 1, 'finish_time' => ['between', "$today,$tomorrow"]])->sum($moneyName);
        $today_amount = $pay_amount * (100 - $shop['rate']) / 100; //商家今日货款
        $old_balance = $shop_balance - $today_amount; //商家非今日货款
        if ($param['balanceType'] == 0 && $param['balance'] > $old_balance) {
            throw new \Exception("非今日货款不足");
        }
        if ($param['balanceType'] == 1 && $param['balance'] > $today_amount) {
            throw new \Exception("今日货款不足");
        }
        if ($param['balanceType'] == 2 && $param['balance'] > $shop_balance) {
            throw new \Exception("货款不足");
        }
        $param['cost'] = 0;
        if ($param['balanceType'] > 0) {
            //计算今日销售额,并扣除费率
            $rate = ConfigNew::getShopRate();
            $param['cost'] = round($param['balance'] * $rate / 100, 2);
        }
        try {
            Withdraw::startTrans();
            MemberBalanceService::objectInit()->shopWithdraw($param['mid'], $param['balance']);
            $info = $this->addWithdrawData($param, $memberWithdraw);
            Withdraw::commit();
        } catch (\Exception $e) {
            Withdraw::rollback();
        }
        if (ConfigNew::getShopAutoWithdraw() < $param['balance']) {
            $info['remark'] = 'T+1到账,超过限额';
            $info->save();
            throw new \Exception('提现成功,T+1到账', 200);
        }
        return $this->whiteListSuccess($info, $memberWithdraw);
    }

    private function whiteListSuccess($info, $memberWithdraw) {
        if ($this->isWhiteList($info['mid'], $memberWithdraw)) {
            try {
                if ($this->secondsTo($info, $memberWithdraw)) {
                    $info['status'] = '完成';
                    $info->save();
                }
            } catch (\Exception $e) {
                $info['remark'] = $e->getMessage();
                $info->save();
                throw new \Exception($e->getMessage(), 200);
            }
        } else {
            $info['remark'] = 'T+1到账,实名与绑定账号不匹配';
            $info->save();
            throw new \Exception('提现成功,T+1到账', 200);
        }
        return 1;
    }

    /**
     * 新增提现记录
     * @param array $param ['mid','mobile','cost','balance','is_shop']
     * @param array $memberWithdraw ['type','account','name']
     * @return Withdraw
     */
    private function addWithdrawData($param, $memberWithdraw) {
        $alipay = $memberWithdraw['account'];
        if ($memberWithdraw['type'] === '微信') {
            $alipay = 'wx:' . $alipay;
        }
        $info = Withdraw::create([
            'type' => $memberWithdraw['type'],
            'status' => '待审核',
            'uid' => $param['mid'],
            'mobile' => $param['mobile'],
            'cost' => $param['cost'],
            'price' => bcsub($param['balance'], $param['cost'], 2),
            'create_time' => date('Y-m-d H:i:s'),
            'is_shop' => $param['is_shop'],
            'alipay' => $alipay,
            'real_name' => $memberWithdraw['name']
        ], true);
        $info['mid'] = $param['mid'];
        return $info;
    }

    /**
     * 判断是否可以秒到
     * @param $mid
     * @param $name
     * @param $memberWithdrawId
     * @return int
     * @throws \Exception
     */
    private function isWhiteList($mid, $memberWithdraw) {
        $smrz = MemberService::objectInit()->getMemberSmrz($mid);
        if ($memberWithdraw['white_list'] === '是' || $smrz['realname'] == $memberWithdraw['name']) {
            return 1;
        }
        return 0;
    }

    /**
     * 秒到账
     */
    private function secondsTo($info, $memberWithdraw) {
        $f = WithdrawLog::_whereCV([
            'type' => $memberWithdraw['type'],
            'status' => '成功'
        ])->where([
            'account' => $memberWithdraw['account'],
            'realname' => $memberWithdraw['name'],
            'create_time' => ['>', date('Y-m-d 00:00:00')]
        ])->count();
        if ($f >= ConfigNew::getDrawLimit()) {
            throw new \Exception('自动到账次数上限,T+1到账', 200);
        }
        $out_sn = $this->createWithdrawOutNo($memberWithdraw['type']);
        $log = WithdrawLog::create([
            'uid' => $memberWithdraw['mid'],
            'out_no' => $out_sn,
            'amount' => $info['price'],
            'mobile' => $info['mobile'],
            'account' => $memberWithdraw['account'],
            'realname' => $memberWithdraw['name'],
            'create_time' => date('Y-m-d H:i:s'),
            'type' => $memberWithdraw['type'],
            'status' => '成功'
        ]);
        try {
            switch ($memberWithdraw['type']) {
                case '微信':
                    $this->weixinpay($memberWithdraw, $out_sn, $info['price'], '提现');
                    break;
                case '支付宝':
                    $this->alipay($memberWithdraw, $out_sn, $info['price']);
                    break;
                case '红包':
                    $this->weixinpay($memberWithdraw, $out_sn, $info['price'], '提现', false);
                    break;
                default:
                    throw new \Exception('提现类型失败!转为T+1到账', 200);
            }
        } catch (\Exception $e) {
            $log['status'] = '失败';
            $log['error_reson'] = $e->getMessage();
            $log->save();
            $msg = json_decode($e->getMessage(), 1);
            throw new \Exception('自动到账失败!转为T+1到账.原因:' . $msg['error_msg'], 200);
        }
        return 1;
    }

    private function weixinpay($memberWithdraw, $out_sn, $price, $desc, $check = true) {
        $service = new MerchPayService();
        $res = $service->pay($memberWithdraw['account'], $out_sn, $price * 100, $desc, $memberWithdraw['name'], $check);
        if (empty($res['return_code']) || $res['return_code'] !== 'SUCCESS') {
            $res['error_msg'] = $res['msg'];
            throw new \Exception(json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        return 1;
    }

    private function alipay($memberWithdraw, $out_sn, $price) {
        $config = Db::name("config")->where("id", 1)->find();
        $public_key = file_get_contents(ROOT_PATH . 'cert/llwfPublic.txt');
        $private_key = file_get_contents(ROOT_PATH . 'cert/llwfPrivate.txt');
        $trans = Trans::instance([
            'appid' => trim($config['zfb_appid']),                                     //应用Id
            'public_key' => $public_key,                                               //应用公钥
            'private_key' => $private_key,                                             //应用私钥
            'app_cert' => ROOT_PATH . '/cert/appCertPublicKey_2019091067164034.crt',   //应用公钥证书
            'root_cert' => ROOT_PATH . '/cert/alipayRootCert.crt',                     //支付宝根证书
        ]);
        $param = [
            'out_biz_no' => $out_sn,
            'trans_amount' => $price,
            'product_code' => 'TRANS_ACCOUNT_NO_PWD', //TRANS_BANKCARD_NO_PWD 银行卡(暂未开放) TRANS_ACCOUNT_NO_PWD 支付宝账户
            'payee_info' => [
                'identity' => $memberWithdraw['account'],
                'identity_type' => 'ALIPAY_LOGON_ID',
                'name' => $memberWithdraw['name']
            ],
            'biz_scene' => 'DIRECT_TRANSFER'
        ];
        $res = $trans->apply($param);
        if ($res['alipay_fund_trans_uni_transfer_response']['code'] !== '10000') {
            $res['alipay_fund_trans_uni_transfer_response']['error_msg'] = $res['alipay_fund_trans_uni_transfer_response']['sub_msg'];
            throw new \Exception(json_encode($res['alipay_fund_trans_uni_transfer_response'], JSON_UNESCAPED_UNICODE));
        }
    }

    private function createWithdrawOutNo($type) {
        $sn = '';
        switch ($type) {
            case '红包':
            case '微信':
                $sn = 'WD' . date('YmdHis', time()) . rand(10000, 99999);
                break;
            case '支付宝':
                $sn = 'TX' . date('YmdHis', time()) . rand(10000, 99999);
                break;
        }
        return $sn;
    }

    /**
     * 手动打款
     */
    public function remit($id) {
        $info = Withdraw::_whereCV(['status' => '通过审核,处理中', 'id' => $id])->find();
        if ($info === null) throw new \Exception('未找到提现记录');
        $out_sn = $this->createWithdrawOutNo($info['type']);
        $log = WithdrawLog::create([
            'uid' => $info['uid'],
            'out_no' => $out_sn,
            'amount' => $info['price'],
            'mobile' => $info['mobile'],
            'account' => $info['alipay'],
            'realname' => $info['real_name'],
            'create_time' => date('Y-m-d H:i:s'),
            'type' => $info['type'],
            'status' => '成功'
        ]);
        try {
            $memberWithdraw = [
                'name' => $info['real_name'],
                'account' => $info['alipay']
            ];
            switch ($info['type']) {
                case '微信':
                    $r = explode(':', $memberWithdraw['account']);
                    $memberWithdraw['account'] = end($r);
                    $this->weixinpay($memberWithdraw, $out_sn, $info['price'], '提现');
                    break;
                case '支付宝':
                    $this->alipay($memberWithdraw, $out_sn, $info['price']);
                    break;
                default:
                    throw new \Exception('提现类型失败!转为T+1到账', 200);
            }
        } catch (\Exception $e) {
            $log['status'] = '失败';
            $log['error_reson'] = $e->getMessage();
            $log->save();
            $msg = json_decode($e->getMessage(), 1);
            throw new \Exception('自动到账失败!转为T+1到账.原因:' . $msg['error_msg'], 200);
        }
    }

}
