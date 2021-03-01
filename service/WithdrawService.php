<?php

namespace app\api\service;

use think\Db;
use think\Request;
use app\api\service\TokenService;
use app\api\service\AliPay\Fund\Trans;
use app\api\service\WxPay\MerchPayService;

/**
 * 提现功能
 * Class WithDrawService
 * @package app\api\Service
 */
class WithdrawService {
    const SCENES_BALANCE = 1;   // 余额
    const SCENES_TOKEN = 2;     // 通证
    const SCENES_PAYMENT = 3;   // 商家货款

    const TYPE_HISTORY = 2;     // 历史
    const TYPE_THAT_DAY = 1;    // 当日

    //业务产品码
    const ACCOUNT = 'TRANS_ACCOUNT_NO_PWD';       // 单笔无密转账到支付宝账户
    const BANKCARD = 'TRANS_BANKCARD_NO_PWD';     // 单笔无密转账到银行卡
    //收款方标识类型
    const USER_ID = 'ALIPAY_USER_ID';         // 支付宝的会员ID
    const LOGIN_ID = 'ALIPAY_LOGON_ID';       // 支付宝登录号，支持邮箱和手机号格式
    // 支付类型
    const TYPE_ALIPAY = 1;    // 支付宝
    const TYPE_BANKCARD = 2;  // 银行卡

    /**
     * 会员提现到微信
     * 限制:付款金额超出限制。低于最小金额1.00元或累计超过5000.00元。
     * 返回
     *  ["return_code"] => string(7) "SUCCESS"
     *  ["result_code"] => string(7) "SUCCESS"
     *  ["nonce_str"] => string(32) "p49buwh675cf6luxqqe6apyochwqbg27"
     *  ["partner_trade_no"] => string(21) "WD2020042614361570789"
     *  ["payment_no"] => string(32) "10101131041902004260924315937266"
     *  ["payment_time"] => string(19) "2020-04-26 14:36:16"
     */
    public function weixinpay($money, $desc = '提现', $uid = 0) {
        //参数准备
        if ($uid === 0) {
            $uid = TokenService::getCurrentUid();
        }
        $openid = Db::name('members')->where('id', $uid)->value('openid');
        $trade_no = 'WD' . date('YmdHis', time()) . rand(10000, 99999);
        $service = new MerchPayService();
        $realname = Db::name('members')->where('id', $uid)->value('realname_for_wx');
        if (empty($realname)) {
            return ['code' => 2, 'msg' => '缺少真实姓名,请重新绑定微信'];
        }
        if (empty($openid)) {
            return ['code' => 2, 'msg' => '您未绑定微信'];
        }
        $count = Db::name('withdraw_log')->where([
            'type' => 'weixinpay',
            'mobile' => $openid,
            'realname' => $realname,
            'create_time' => ['>', date('Y-m-d 00:00:00')],
            'status' => 1,
        ])->count();
        $limit = Db::name('config_new')->where('name', 'draw_limit')->value('info');
        if ($limit <= $count) {
            return ['code' => 2, 'data' => '自动到账次数上限,T+1到账', 'out_no' => []];
        }
        //请求结果
        //写入记录
        $logId = Db::name('withdraw_log')->insertGetId([
            'uid' => $uid,
            'out_no' => $trade_no,
            'type' => 'weixinpay',
            'amount' => $money / 100,
            'mobile' => $openid,
            'realname' => $realname,
            'create_time' => date('Y-m-d H:i:s'),
            'status' => 0,
        ]);
        $res = $service->pay($openid, $trade_no, $money, $desc, $realname);
        Db::name('test')->insert(['param' => json_encode($res, JSON_UNESCAPED_UNICODE), 'time' => date('Y-m-d H:i:s')]);
        if ($res['return_code'] == 'SUCCESS') {
            Db::name('withdraw_log')->where('id', $logId)->update(['status' => 1]);
        } else {
            Db::name('withdraw_log')->where('id', $logId)->update(['error_reson' => json_encode($res, JSON_UNESCAPED_UNICODE)]);
            return ['code' => 1, 'data' => $res];
        }
        return ['code' => 0, 'data' => $res];
    }

    /**
     * 会员提现到支付宝
     * 返回
     * {
     *   "alipay_fund_trans_uni_transfer_response":
     *   {
     *     "code": "10000",
     *     "msg": "Success",
     *     "out_biz_no": "201808080001",
     *     "order_id": "20190801110070000006380000250621",
     *     "pay_fund_order_id": "20190801110070001506380000251556",
     *     "status": "SUCCESS",
     *     "trans_date": "2019-08-21 00:00:00"
     *   },
     *   "sign": "ERITJKEIJKJHKKKKKKKHJEREEEEEEEEEEE"
     * }
     */
    // $res = [
    //     "alipay_fund_trans_uni_transfer_response" =>
    //     [
    //         "code" => "10000",
    //         "msg" => "Success",
    //         "out_biz_no" => "201808080001",
    //         "order_id" => "20190801110070000006380000250621",
    //         "pay_fund_order_id" => "20190801110070001506380000251556",
    //         "status" => "SUCCESS",
    //         "trans_date" => "2019-08-21 00:00:00"
    //     ],
    //     "sign" => "ERITJKEIJKJHKKKKKKKHJEREEEEEEEEEEE"
    // ];
    public function alipay($phone, $realname, $amount, $uid = 0) {
        $count = Db::name('withdraw_log')->where([
            'type' => 'alipay',
            'mobile' => $phone,
            'realname' => $realname,
            'create_time' => ['>', date('Y-m-d 00:00:00')],
            'status' => 1
        ])->count();
        $limit = Db::name('config_new')->where('name', 'draw_limit')->value('info');
        if ($limit <= $count) {
            return ['code' => 2, 'data' => '自动到账次数上限,T+1到账', 'out_no' => []];
        }
        //参数准备
        if ($uid === 0) {
            $uid = TokenService::getCurrentUid();
        }
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
        //参数写入
        $time = time();
        $out_no = 'TX' . date('YmdHis', $time) . rand(1000, 9999);
        $param = [
            'out_biz_no' => $out_no,
            'trans_amount' => $amount,
            'product_code' => 'TRANS_ACCOUNT_NO_PWD', //TRANS_BANKCARD_NO_PWD 银行卡(暂未开放) TRANS_ACCOUNT_NO_PWD 支付宝账户
            'payee_info' => [
                'identity' => $phone,
                'identity_type' => 'ALIPAY_LOGON_ID',
                'name' => $realname
            ],
            'biz_scene' => 'DIRECT_TRANSFER'
        ];
        //请求结果
        //写入记录
        $logId = Db::name('withdraw_log')->insertGetId([
            'uid' => $uid,
            'out_no' => $out_no,
            'type' => 'alipay',
            'amount' => $amount,
            'mobile' => $phone,
            'realname' => $realname,
            'create_time' => date('Y-m-d H:i:s', $time),
            'status' => 0,
        ]);
        $res = $trans->apply($param);
        $keyname = 'alipay_fund_trans_uni_transfer_response';
        if ($res[$keyname]['code'] == '10000') {
            Db::name('withdraw_log')->where('id', $logId)->update(['status' => 1]);
        } else {
            Db::name('withdraw_log')->where('id', $logId)->update(['error_reson' => json_encode($res, JSON_UNESCAPED_UNICODE)]);
        }
        //输出
        return ['code' => 0, 'data' => $res, 'out_no' => $out_no];
    }


}
