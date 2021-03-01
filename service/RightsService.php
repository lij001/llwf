<?php

namespace app\api\service;

use app\api\model\ConfigNew;
use app\api\model\MemberBalanceLog;
use app\api\service\Alibaba\AlibabaServiceV2;
use think\Db;
use think\Exception;


class RightsService {
    private static $notReferralAward = ['PTME'];
    private static $notTeamAward = ['PTME'];
    private static $notOcAward = [];
    private static $teamerAward = ['PTME'];

    /*
     * 用户升级检测,旧接口已弃用
     */
    public static function upcheck($star, $uid, $path) {
        if ($star > 5) {
            return true;
        }
        $starTemp = $star + 1;
        $conf = Db::name('config_reward')->where('type', 1)->where('star', $starTemp)->find();
        //礼包升级
        if ($star < 2) {
            $count = Db::name('order_info')->alias('oi')->join('order_detail od', 'oi.order_no= od.order_no')
                ->where('oi.uid', $uid)->where('oi.order_type', 2)->where('oi.status', 'in', '1,3,4')->sum('od.goods_num');
            //礼包订单数量满足升级条件,升级
            if ($count >= $conf['up2_p']) {
                Db::name('members')->where('id', $uid)->setField('star', $starTemp);
                $data = [];
                $data['uid'] = $uid;
                $data['old_star'] = $star;
                $data['star'] = $starTemp;
                $data['gift_num'] = $count;
                $data['type'] = 1;
                $data['type_name'] = "购买礼包";
                //$data['ip']= get_client_ip(0, true);;
                $data['create_time'] = date('Y-m-d H:i:s', time());
                Db::name("members_star_log")->insert($data);
                return true;
            }
        }
        //团队消费升级

        //直推人数
        $zt_count = Db::name('members')->where('parent_id', $uid)->where('star', $star)->count('id');
        if ($zt_count >= $conf['up1_p']) {
            //校验团队消费额
            $mypath = $uid . "-" . $path;
            $monthFist = date('Y-m-01 00:00:00', time());
            // $total = Db::name("members")->alias('m')->join('order_info oi', 'm.id= oi.uid')
            //     ->join('pay_info pi', 'oi.order_no= pi.order_no')
            //     ->where('pi.pay_status', 1)->where('pi.finish_time', '>=', $monthFist)
            //     ->where("m.path like '%{$mypath}'")->sum('pi.pay_amount');
            // $mytotal = Db::name("order_info")->alias('oi')->join('pay_info pi', 'oi.order_no= pi.order_no')
            //     ->where('pi.pay_status', 1)->where('pi.finish_time', '>=', $monthFist)
            //     ->where("oi.uid", $uid)->sum('pi.pay_amount');
            $total = Db::name("members")->alias('m')->join('order_info oi', 'm.id= oi.uid')
                ->join('pay_info pi', 'oi.order_no= pi.order_no')
                ->where('pi.pay_status', 1)
                ->where("m.path like '%{$mypath}'")->sum('pi.pay_amount');
            $mytotal = Db::name("order_info")->alias('oi')->join('pay_info pi', 'oi.order_no= pi.order_no')
                ->where('pi.pay_status', 1)
                ->where("oi.uid", $uid)->sum('pi.pay_amount');

            $total += $mytotal;

            if ($total >= $conf['up1_m']) {
                Db::name('members')->where('id', $uid)->setField('star', $starTemp);
                $data = [];
                $data['uid'] = $uid;
                $data['old_star'] = $star;
                $data['star'] = $starTemp;
                $data['peo_num'] = $zt_count;
                $data['month_amount'] = $total;
                $data['type'] = 2;
                $data['type_name'] = "直推升与月消费";
                //$data['ip']= get_client_ip(0, true);
                $data['create_time'] = date('Y-m-d H:i:s', time());
                Db::name("members_star_log")->insert($data);
                return true;
            }
        }
        return false;
    }

    /**
     * 团队升级及权益分配
     * @param int $uid
     * @param int $orderType
     * @param int $order_no
     */
    public function check($uid, $orderType, $order_no) {
        $user = Db::name('members')->where('id', $uid)->field('star,path')->find();
        //1.升级检测，升级需校验上个星级的配置star+1

        if ($user['star'] < 6) {
            //1.1如果有升级，要循环检查自己升级
            for ($i = 1; $i <= 6; $i++) {
//                $flag = self::upcheck($user['star'], $uid, $user['path']);
                $Upgrade = UpgradeService::objectInit();
                $flag = $Upgrade->userUpgradeCheck($uid);
                if ($flag['status']) {
                    $user['star'] += 1;
                } else {
                    break;
                }
            }
        }

        //2.重置自己的权益冻结

        if ($orderType == 2) {
            Db::name('user_balance')->where('uid', $uid)->setField('rights_balance', 0);
        }

        //权益冻结金额配置
        $resp = Db::name('config_reward')->where('type', 1)->where('star', 1)->value('resp');
        if ($resp <= 0) {
            $resp = 3000;
        }
        //权益金额配置
        $rewardList = Db::name('config_reward')->where('type', 1)->field('star,rights1,rights2,shop1,shop2')->order('star asc')->select()->toArray();
        $rewards = [];
        $shoper = [];
        foreach ($rewardList as $v) {
            $rewards[$v['star']] = $v['rights1'];
            $rewards[($v['star'] + 4)] = $v['rights2'];
            if ($v['star'] == 2) {
                $shoper[0] = $v['shop1'];
                $shoper[1] = $v['shop2'];
            }
        }

        //3.上级权益分配
        $my_upers = explode("-", $user['path']);
        $uper_count = count($my_upers) - 1; //去除path最后一个0
        $maxstar = $user['star']; //上级最大等级
        $maxj = 0; //最大等级出现次数
        $teamMoney = 0; //上级的团队收益
        //查询订单下商品数量
        $goods_num = Db::name('order_detail')->where('order_no', $order_no)->sum('goods_num');
        for ($j = 0; $j < $uper_count; $j++) {
            $up_user = Db::name('members')->alias('m')->join('user_balance ub', 'm.id=ub.uid')
                ->where('m.id', $my_upers[$j])->field('m.star,m.path,ub.rights_balance,m.auto_pay')->find();

            if ($up_user['star'] == 0) {
                continue;
            }
            if ($up_user['star'] < 6) {
                //3.1上级升级
//                $flag = self::upcheck($up_user['star'], $my_upers[$j], $up_user['path']);
                $upgrade = UpgradeService::objectInit();
                $flag = $upgrade->userUpgradeCheck($my_upers[$j]);
                if ($flag['status']) {
                    $up_user['star'] += 1;
                }
            }
            //3.2是否冻结
            if ($up_user['rights_balance'] > $resp) {
                //自动扣费下单
                $auto_flag = false;
                if ($up_user['auto_pay'] == 1) {
                    $auto_flag = self::autoOrderautoOrder($my_upers[$j]);
                }
                if (!$auto_flag) {
                    continue;
                }
            }
            //3.3权益分配

            $re = self::rights(($j + 1), $up_user['star'], $my_upers[$j], $maxstar, $maxj, $rewards, $shoper, $teamMoney, $goods_num);

            //查询预估收益
            $estimate_balance = Db::name('user_balance')->where('uid', $my_upers[$j])->value('estimate_balance');
            if ($estimate_balance < $re['money']) {
                $estimate_tmp = $estimate_balance;
            } else {
                $estimate_tmp = $re['money'];
            }
            //收益增加/扣除预估收益
            Db::name('user_balance')->where('uid', $my_upers[$j])->update([
                'balance' => Db::raw('balance+' . $re['money']),
                'total' => Db::raw('total+' . $re['money']),
                'rights_balance' => Db::raw('rights_balance+' . $re['teamMoney']),
                'estimate_balance' => Db::raw('estimate_balance-' . $estimate_tmp)
            ]);

            $balancelog = [];
            $balancelog['uid'] = $my_upers[$j];
            $balancelog['type'] = 1;
            $balancelog['source'] = 8;
            $balancelog['source_name'] = "会员收益";
            $balancelog['balance'] = $re['money'];
            $balancelog['team_balance'] = $re['teamMoney'];
            $balancelog['create_time'] = date('Y-m-d H:i:s', time());
            $balancelog['order_id'] = $order_no;
            $balancelog['from_friend_id'] = $uid;
            $balancelog['balance_type'] = 1;

            Db::name('user_balance_log')->insert($balancelog);

            $maxstar = $re['maxstar'];
            $maxj = $re['maxj'];
            $teamMoney = $re['teamMoney'];
        }
    }

    /**
     * 预估收益
     * @param int $uid
     * @param int $orderType
     * @param int $order_no
     */
    public function estimate_check($uid, $orderType, $order_no) {
        $user = Db::name('members')->where('id', $uid)->field('star,path,auto_pay')->find();
        //1.升级检测，升级需校验上个星级的配置star+1

        if ($user['star'] < 6) {
            //1.1如果有升级，要循环检查自己升级
            for ($i = 1; $i <= 6; $i++) {
//                $flag = self::upcheck($user['star'], $uid, $user['path']);
                $upgrade = UpgradeService::objectInit();
                $flag = $upgrade->userUpgradeCheck($uid);
                if ($flag['status']) {
                    $user['star'] += 1;
                } else {
                    break;
                }
            }
        }
        //只针对设计礼包订单
        if ($orderType != 2) {
            return '';
        }
        //2.重置自己的权益冻结

        if ($orderType == 2) {
            Db::name('user_balance')->where('uid', $uid)->setField('rights_balance', 0);
        }

        //权益冻结金额配置
        $resp = Db::name('config_reward')->where('type', 1)->where('star', 1)->value('resp');
        if ($resp <= 0) {
            $resp = 3000;
        }
        //权益金额配置
        $rewardList = Db::name('config_reward')->where('type', 1)->field('star,rights1,rights2,shop1,shop2')->order('star asc')->select()->toArray();
        $rewards = [];
        $shoper = [];
        foreach ($rewardList as $v) {
            $rewards[$v['star']] = $v['rights1'];
            $rewards[($v['star'] + 4)] = $v['rights2'];
            if ($v['star'] == 2) {
                $shoper[0] = $v['shop1'];
                $shoper[1] = $v['shop2'];
            }
        }

        //3.上级权益分配
        $my_upers = explode("-", $user['path']);
        $uper_count = count($my_upers) - 1; //去除path最后一个0
        $maxstar = $user['star']; //上级最大等级
        $maxj = 0; //最大等级出现次数
        $teamMoney = 0; //上级的团队收益
        //查询订单下商品数量
        $goods_num = Db::name('order_detail')->where('order_no', $order_no)->sum('goods_num');
        for ($j = 0; $j < $uper_count; $j++) {
            $up_user = Db::name('members')->alias('m')->join('user_balance ub', 'm.id=ub.uid')
                ->where('m.id', $my_upers[$j])->field('m.star,m.path,ub.rights_balance,m.auto_pay')->find();

            if ($up_user['star'] == 0) {
                continue;
            }
            if ($up_user['star'] < 6) {
                //3.1上级升级
//                $flag = self::upcheck($up_user['star'], $my_upers[$j], $up_user['path']);
                $upgrade = UpgradeService::objectInit();
                $flag = $upgrade->userUpgradeCheck($my_upers[$j]);
                if ($flag['status']) {
                    $up_user['star'] += 1;
                }
            }
            //3.2是否冻结
            if ($up_user['rights_balance'] > $resp) {
                //自动扣费下单
                $auto_flag = false;
                if ($up_user['auto_pay'] == 1) {
                    $auto_flag = self::autoOrder($my_upers[$j]);
                }
                if (!$auto_flag) {
                    continue;
                }
            }
            //3.3权益分配
            $re = self::rights(($j + 1), $up_user['star'], $my_upers[$j], $maxstar, $maxj, $rewards, $shoper, $teamMoney, $goods_num);
            if ($re['money'] > 0) {
                Db::name('user_balance')->where('uid', $my_upers[$j])->update([
                    'estimate_balance' => Db::raw('estimate_balance+' . $re['money']),
                    'total' => Db::raw('total+' . $re['money']),
                    'rights_balance' => Db::raw('rights_balance+' . $re['money'])
                ]);
                Db::name('user_estimate_balance')->insert([
                    'balance' => $re['money'],
                    'team_balance' => $re['money'],
                    'uid' => $my_upers[$j],
                    'order_no' => $order_no,
                    'create_time' => date('Y-m-d H:i:s', time())
                ]);
            }

            $maxstar = $re['maxstar'];
            $maxj = $re['maxj'];
            $teamMoney = $re['teamMoney'];
        }
    }

    /**
     * 确认收货,预估收益转余额
     * @param int $uid
     * @param int $orderType
     * @param int $order_no
     */
    public function confirm_check($order_no = '', $order_uid) {
        $list = Db::name('user_estimate_balance')->where('order_no', $order_no)->where('status', 2)->select()->toArray();
        $flag = true;
        if (!empty($list)) {
            foreach ($list as $u) {
                $re1 = Db::name('user_balance')->where('uid', $u['uid'])->update([
                    'balance' => Db::raw('balance+' . $u['balance']),
                    'estimate_balance' => Db::raw('estimate_balance-' . $u['balance'])
                ]);

                $re2 = Db::name('user_estimate_balance')->where('order_no', $order_no)->where('uid', $u['uid'])->setField('status', 1);

                $balancelog = [];
                $balancelog['uid'] = $u['uid'];
                $balancelog['type'] = 1;
                $balancelog['source'] = 8;
                $balancelog['source_name'] = "会员收益";
                if ($u['type'] == 2) {
                    $balancelog['source_name'] = "分享商品";
                } else if ($u['type'] == 3) {
                    $balancelog['source_name'] = "分享商品(间接)";
                }
                $balancelog['balance'] = $u['balance'];
                $balancelog['team_balance'] = $u['team_balance'];
                $balancelog['create_time'] = date('Y-m-d H:i:s', time());
                $balancelog['order_id'] = $order_no;
                $balancelog['from_friend_id'] = $order_uid;
                $balancelog['balance_type'] = 1;

                $re3 = Db::name('user_balance_log')->insert($balancelog);
                if ($re1 && $re2 && $re3) {
                } else {
                    $flag = false;
                }
            }
        }
        return $flag;
    }

    /**
     * 权益分配
     * @param int j 第几级上级,由1开始
     * @param int star 星级
     * @param int uid
     * @param int maxstar 上级最大等级
     * @param int maxj 最大等级出现次数
     * @param array rewards 各个星级权益金额[125,10,40(3),25(4),20(5),8(6)]
     * @param array shoper 店主权益2-8代条件
     * @param array teamMoney 上级的团队收益(改为个人收益)
     */
    public static function rights($j, $star, $uid, $maxstar, $maxj, $rewards, $shoper, $teamMoney, $goods_num = 1) {
        $money = 0;
        //直推人
        if ($j == 1) {
            $money += $rewards[1];
            if ($star >= 4) {
                $money += $rewards[3];
            }
            if ($star >= 5) {
                $money += $rewards[4];
            }
            if ($star >= 6) {
                $money += $rewards[5];
            }
        }

        if ($star >= 1) {
        }

//        if ($star >= 2) {
//            if ($j >= $shoper[0] && $j <= $shoper[1]) {
//                $money += $rewards[2]; //2-8代加10元
//            }
//        }

        if ($star == 3) { //1星
            if ($star > $maxstar) {
                $money += $rewards[3];
            } else if ($star == $maxstar) {
                if ($maxj < 3) { //平级一代、二代
                    $money += $rewards[7];
                }
            }
        }

        if ($star == 4) { //2星
            if ($star > $maxstar) {
                $money += $rewards[4];
            } else if ($star == $maxstar) {
                if ($maxj < 3) { //平级一代、二代
                    $money += $rewards[8];
                }
            }
        }

        if ($star == 5) { //3星
            if ($star > $maxstar) {
                $money += $rewards[5];
            } else if ($star == $maxstar) {
                if ($maxj < 3) { //平级一代、二代
                    $money += $rewards[9];
                }
            }
        }
        if ($star == 6) {
            if ($star > $maxstar) {
                $money += $rewards[6];
            } else if ($star == $maxstar) {
                if ($maxj < 3) { //平级一代、二代
                    $money += $rewards[10];
                }
            }
        }

        if ($star > $maxstar) {
            $maxj = 1;
            $maxstar = $star;
        } else if ($star == $maxstar) {
            $maxj += 1;
        }
        $money = $money * $goods_num;
        $teamMoney += $money;

        return ['maxstar' => $maxstar, 'maxj' => $maxj, 'teamMoney' => $teamMoney, 'money' => $money];
    }

    /**
     * 自动下单
     *
     */
    public static function autoOrder($uid) {
        $goods = Db::name('goods')->alias('g')->join('goods_attr ga', 'g.id=ga.goods_id', 'left')
            ->field('g.spid,g.id as g_pid,g.goods_name,g.more,g.ex_price as goods_p,ga.*')
            ->where('g.status', 1)->where('g.gold_zd', 1)->where('g.total>0 or ga.stock>0')->find();

        $goods['field'] = '';
        if (empty($goods['id'])) {
            $payment = $goods['goods_p'];
        } else {
            $payment = $goods['sale_price'];

            $descript = '';
            if (!empty($goods['field1_name'])) {
                $descript .= $goods['field1_name'] . '：' . $goods['field1'] . " ";
            }
            unset($goods['field1_name']);
            unset($goods['field1']);
            if (!empty($goods['field2_name'])) {
                $descript .= $goods['field2_name'] . '：' . $goods['field2'];
            }
            unset($goods['field2_name']);
            unset($goods['field2']);
            $goods['field'] = $descript;
        }
        $more = json_decode($goods['more'], true);
        $goods['goodsImg'] = !empty($more['thumbnail']) ? cmf_get_image_url($more['thumbnail']) : '';

        $balance = Db::name('user_balance')->where('uid', $uid)->find();
        $can_balance = $balance['balance'] - $balance['frozen_balance'];
        if ($payment > $can_balance) {
            return false;
        }

        $config_pay = Db::name('config_pay')->where('id', 1)->find();

        $address = Db::name('member_address')->where('uid', $uid)->where('is_del', 0)->order('is_default desc,id desc')->find();

        $parent_no = "PW" . $uid . date('YmdHis', time()) . randStr(5);
        $now_time = date('Y-m-d H:i:s', time());
        $parent_order = [];
        $parent_order['coupon_id'] = 0;
        $parent_order['parent_no'] = $parent_no;
        $parent_order['uid'] = $uid;
        $parent_order['total'] = $payment;
        $parent_order['pay_amount'] = $payment;
        $parent_order['goods_mei'] = 0;
        $parent_order['goods_tz'] = 0; //商品的通证
        $parent_order['postage'] = 0;
        $parent_order['tz_yue'] = 0;
        $parent_order['yue_money'] = 0;
        $parent_order['tz_money'] = 0; //手动输入的通证
        $parent_order['create_time'] = date('Y-m-d H:i:s', time());

        $order = [];
        $now_time = time();
        $orderNo = "WM" . randStr(4) . date('YmdHis', time()) . randStr(5);
        $order['coupon_id'] = 0;
        $order['pay_amount'] = $payment;
        $order['total'] = $payment; //商品总金额+邮费
        $order['amount'] = $payment;
        $order['order_type'] = 2;
        $order['status'] = 3; //待付款
        $order['create_time'] = date('Y-m-d H:i:s', $now_time);
        $order['finish_time'] = date('Y-m-d H:i:s', $now_time);
        $order['uid'] = $uid;
        $order['order_no'] = $orderNo;
        $order['parent_no'] = $parent_no;
        $order['cal_money'] = $payment * $config_pay['zgxz'];
        $order['sp_gxz'] = $payment * $config_pay['zgxz'];
        $order['source'] = 4; //自动下单
        $order['recept_name'] = $address['name'];
        $order['recept_mobile'] = $address['mobile'];
        $order['province'] = $address['province'];
        $order['city'] = $address['city'];
        $order['country'] = $address['country'];
        $order['detail'] = $address['detail'];
        //订单明细
        $orderDetail = [];
        $orderDetail['item_amount'] = 0;
        $orderDetail['spid'] = $goods['spid'];
        $orderDetail['sale_price'] = $payment;
        $orderDetail['goods_name'] = $goods['goods_name'];
        $orderDetail['goods_num'] = 1;
        $orderDetail['free_delivery'] = 1;
        $orderDetail['field'] = $goods['field'];
        $orderDetail['postage'] = 0;
        $orderDetail['tid'] = 0;
        $orderDetail['thumb'] = $goods['goodsImg'];
        $orderDetail['goods_attr_id'] = $goods['id'];
        $orderDetail['order_no'] = $orderNo;
        $orderDetail['goods_id'] = $goods['goods_id'];
        $orderDetail['create_time'] = date('Y-m-d H:i:s', $now_time);
        //支付信息
        $pay = [];
        $pay['pay_no'] = '';
        $pay['order_no'] = $orderNo;
        $pay['create_time'] = date('Y-m-d H:i:s', $now_time);
        $pay['pay_status'] = 2;
        $pay['pay_amount'] = $payment;
        $pay['pay_type'] = 3; //余额
        $pay['uid'] = $uid;
        $pay['pay_status'] = 1;
        $pay['finish_time'] = date('Y-m-d H:i:s', $now_time);

        $order['order_json'] = json_encode($order);
        Db::startTrans();
        try {
            //父订单信息
            Db::name('order_parent')->insert($parent_order);
            //生成订单
            $orderID = Db::name('order_info')->insertGetId($order);
            //订单详情
            $orderDetail['order_id'] = $orderID;
            $pay['order_id'] = $orderID;
            $orderD = Db::name('order_detail')->insertGetId($orderDetail);
            //支付信息
            $payD = Db::name('pay_info')->insert($pay);
            //扣减库存
            if (empty($goods['id'])) {
                $stock = Db::name('goods')->where('id', $goods['g_pid'])->update([
                    'total' => Db::raw('total-1'),
                    'sales' => Db::raw('sales+1'),
                ]);
            } else {
                $t = Db::name('goods')->where('id', $goods['goods_id'])->setInc('sales', 1);
                if ($t) {
                    $stock = Db::name('goods_attr')->where('id', $goods['id'])->setDec('stock', 1);
                } else {
                    Db::rollback();
                    return false;
                }
            }
            $mybalance = Db::name('user_balance')->where('uid', $uid)->update([
                'balance' => Db::raw('balance-' . $payment),
                'rights_balance' => 0
            ]);
            $balancelog = [];
            $balancelog['uid'] = $uid;
            $balancelog['type'] = 0;
            $balancelog['source'] = 4;
            $balancelog['source_name'] = "支付(自动)";
            $balancelog['order_id'] = $orderNo;
            $balancelog['balance'] = $payment;
            $balancelog['balance_type'] = 1;

            $balancelog['create_time'] = date('Y-m-d H:i:s', $now_time);
            $ubl = Db::name('user_balance_log')->insert($balancelog);

            if ($orderID && $orderD && $stock && $payD && $ubl && $mybalance) {
                Db::commit();
//                (new self())->estimate_check($uid, 2, $orderNo);
                $reward = RewardService::objectInit();
                $reward->giveLibaoReward($uid, $orderNo, 1);
                return true;
            } else {
                Db::rollback();
                return false;
            }
        } catch (\Exception $e) {
            Db::rollback();
            Db::name('error_log')->insert([
                'source' => 'right/autoPay',
                'desc' => $e->getMessage(),
                'uid' => $uid,
                'date' => date('Y-m-d H:i:s')
            ]);
            return false;
        }
        return false;
    }

    /**
     * 推荐入住商家奖励（线上\线下）
     */
    public static function shopRights($uid, $order_no, $amount, $spid) {
        //店铺让利比例
        $shop = Db::name('shop')->where('id', $spid)->field('rate,title,uid')->find();

        //店主的推荐人
        $user = Db::name('members')->where('id', $shop['uid'])->field('parent_id')->find();
        if ($user['parent_id'] == 0) {
            return true;
        }
        //推荐店铺商家让利的比
        $shop_rate = Db::name('config_reward')->where('type', 1)->where('star', 1)->value('shop_rate');
        $cashFlow = Db::name('config_reward')->where('type', 5)->value('fee');
        $money = bcdiv($amount * ($shop['rate'] - $cashFlow) * $shop_rate, 100 * 100, 2);
        if ($money == 0) {
            return true;
        }
        $re = Db::name('user_balance')->where('uid', $user['parent_id'])->update([
            'balance' => Db::raw('balance+' . $money),
            'total' => Db::raw('total+' . $money),
        ]);
        $balancelog = [];
        $balancelog['uid'] = $user['parent_id'];
        $balancelog['type'] = 1;
        $balancelog['source'] = 8;
        $balancelog['source_name'] = "会员收益(推荐商家)";
        $balancelog['balance'] = $money;
        $balancelog['order_id'] = $order_no;
        $balancelog['create_time'] = date('Y-m-d H:i:s', time());
        $balancelog['balance_type'] = 1;
        $re1 = Db::name('user_balance_log')->insert($balancelog);
        if ($re && $re1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     *团队消费佣金(非淘客部分)
     * 根据下订单的人计算(校验上下级级别,出现平级则阻断)
     */
    public static function teamRights($uid, $order_no = "", $amount, $spid, $number = 0) {
        $user = Db::name('members')->where('id', $uid)->field('path,star')->find();
        $money = 0;
        $saleMoney = 0;
        $cashFlow = Db::name('config_reward')->where('type', 5)->value('fee');
        //自营商品

        if (empty($spid)) {
            $orderInfo = Db::name('order_info')->where('order_no', $order_no)->find();

            if ($orderInfo['llj']) {
                $cost_rate = Db::name('config_new')->where('name', 'gas_card_cost_price')->value('info');
                $llj_rate = (100 - $cost_rate - $cashFlow) / 100;
                $money = $orderInfo['total'] * $llj_rate;
            } else if ($orderInfo['total'] > 0) {
                $ods = Db::name('order_detail')->where('order_no', $order_no)->field('sale_price,cost_price,goods_num')->select()->toArray();
                foreach ($ods as $od) {
                    $saleMoney += $od['sale_price'] * $od['goods_num'];
                    $costMoney = $od['cost_price'] * $od['goods_num'];
                }
                $money = $saleMoney - bcdiv($saleMoney * $cashFlow, 100, 2) - $costMoney;
            }

        } else {
            //店铺让利比例
            if ($amount > 0) {
                $shop = Db::name('shop')->where('id', $spid)->field('rate,title,shop_type')->find();
                $money = bcdiv($amount * ($shop['rate'] - $cashFlow), 100, 2);
            }
        }

        if ($money <= 0) {
            return '';
        }

        //1.经销商级别以上团队分佣
        RightsService::giveTeamAward($money, $uid, $order_no);
        //直推分佣
        RightsService::giveReferralAward($money, $uid, $order_no, $number);
    }

    public static function teamRightsForAli($uid, $order_no = "", $amount) {
        $money = 0;
        $cashFlow = Db::name('config_reward')->where('type', 5)->value('fee');
        //获取阿里订单并计算其中的利润
        $order = Db::name('ali_order')->where('order_no', $order_no)->find();
        //获取成本价
        $alibaba2 = new AlibabaServiceV2();
        $buyerView = $alibaba2->buyerView(['orderId' => $order['orderId']]);
        $consignPrice = $buyerView['result']['baseInfo']['totalAmount'];
        if ($consignPrice <= 0) {
            Db::name('error_log')->insert([
                'uid' => $uid,
                'source' => 'RightService/teamRightsForAli',
                'desc' => $order_no . ':成本异常',
                'date' => date('Y-m-d H:i:s')
            ]);
            throw new \Exception('价格异常,请联系客服解决');
        }
        if ($order['llj']) {
            $cost_rate = Db::name('config_new')->where('name', 'gas_card_cost_price')->value('info');
            $llj_rate = (100 - $cost_rate - $cashFlow) / 100;
            $money = $order['total'] * $llj_rate;
        } else {
            $money = $order['total'] - bcdiv($order['total'] * $cashFlow, 100, 2) - $consignPrice;
        }
        //分发贡献值
        $times = 1;
        $double = Db::name('double')->where(['start' => ['<', $order['pay_time']], 'end' => ['>', $order['pay_time']]])->find();
        if (!empty($double)) $times = $double['times'];
        RightsService::giveGxz($uid, $order['total'] * $times, '商品消费', 1, $order_no);
        //分发留莲券
        if ($order['llj']) {
            RightsService::givellj($uid, $order['llj'], '购物获取留莲券', 1);
        }
        if ($money <= 0) {
            Db::name('error_log')->insert([
                'uid' => $uid,
                'source' => 'RightService/teamRightsForAli',
                'desc' => $order_no . ':利润为0',
                'date' => date('Y-m-d H:i:s')
            ]);
            return 0;
        }
        $tempOrderNo = substr($order_no, 0, 4);

        if (!in_array($tempOrderNo, self::$notReferralAward)) {
            //直推分佣
            RightsService::giveReferralAward($money, $uid, $order_no, $order['number']);
        }
        if (!in_array($tempOrderNo, self::$notTeamAward)) {
            //团队发佣
            RightsService::giveTeamAward($money, $uid, $order_no);
        }
        if (!in_array($tempOrderNo, self::$notOcAward)) {
            //运营中心
            RightsService::giveOcAward($order['total'], $uid, $order_no, 0, 2, '自营商家');
        }
        if (in_array($tempOrderNo, self::$teamerAward)) {
            RightsService::giveTeamerAward($order_no, $uid, $order['total']);
        }
        return $money;
    }

    public static function teamRightsForAli_v2($uid, $order, $pay) {
        $money = 0;
        $cashFlow = Db::name('config_reward')->where('type', 5)->value('fee');
        if ($order['llj'] > 0) {
            $cost_rate = Db::name('config_new')->where('name', 'gas_card_cost_price')->value('info');
            $llj_rate = (100 - $cost_rate - $cashFlow) / 100;
            $money = $order['total_price'] * $llj_rate;
        } else {
            $money = $order['total_price'] - bcdiv($order['total_price'] * $cashFlow, 100, 2) - $order['total_cost_price'];
        }
        //分发贡献值
        $times = 1;
        $double = Db::name('double')->where(['start' => ['<', $pay['finish_time']], 'end' => ['>', $pay['finish_time']]])->find();
        if (!empty($double)) $times = $double['times'];
        RightsService::giveGxz($uid, $order['total_price'] * $times, '商品消费', 1, $order['order_no']);
        //分发留莲券
        if ($order['llj'] > 0) {
            RightsService::givellj($uid, $order['llj'], '购物获取留莲券', 1);
        }
        if ($money <= 0) {
            Db::name('error_log')->insert([
                'uid' => $uid,
                'source' => 'RightService/teamRightsForAli',
                'desc' => $order['order_no'] . ':利润为0',
                'date' => date('Y-m-d H:i:s')
            ]);
            return 0;
        }
        $tempOrderNo = substr($order['order_no'], 0, 4);

        if (!in_array($tempOrderNo, self::$notReferralAward)) {
            //直推分佣
            RightsService::giveReferralAward($money, $uid, $order['order_no'], $order['number']);
        }
        if (!in_array($tempOrderNo, self::$notTeamAward)) {
            //团队发佣
            RightsService::giveTeamAward($money, $uid, $order['order_no']);
        }
        if (!in_array($tempOrderNo, self::$notOcAward)) {
            //运营中心
            RightsService::giveOcAward($order['total_price'], $uid, $order['order_no'], 0, 2, '自营商家');
        }
        if (in_array($tempOrderNo, self::$teamerAward)) {
            RightsService::giveTeamerAward($order['order_no'], $uid, $order['total_price']);
        }
        return $money;
    }

    /**
     * 商品分享预估收益(支付成功时)
     * @param $number
     * @param string $order_no
     * @param $amount
     * @param $spid
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public static function estimateShareGoods($number, $order_no = "", $amount, $spid) {
        //直接分享人
        $user = Db::name('members')->where('number', $number)->field('id,parent_id,star')->find();
        //直接分享人不是掌柜，本次分享无效
        if ($user['star'] < 1) {
            return '';
        }
        $money = 0;
        //自营商品
        if (empty($spid)) {
            //分享商品是立即购买，不存在多种商品
            $od = Db::name('order_detail')->where('order_no', $order_no)->field('sale_price,cost_price')->find();

            $m_tmp = $od['sale_price'] - $od['cost_price'];
            if ($m_tmp > 0) {
                $money = $m_tmp;
            }
        } else {
            //店铺让利比例
            if ($amount > 0) {
                $shop = Db::name('shop')->where('id', $spid)->field('rate,title')->find();
                $money = bcdiv($amount * $shop['rate'], 100, 2);
            }
        }
        if ($money <= 0) {
            return '';
        }
        $config = Db::name('config_reward')->where('type', 2)->field('up1_m,up2_p')->find();
        $money_1 = bcdiv($money * $config['up1_m'], 100, 2);
        Db::name('user_balance')->where('uid', $user['id'])->update([
            'estimate_balance' => Db::raw('estimate_balance+' . $money_1),
            'total' => Db::raw('total+' . $money_1)
        ]);
        Db::name('user_estimate_balance')->insert([
            'balance' => $money_1,
            'uid' => $user['id'],
            'order_no' => $order_no,
            'type' => 2,
            'create_time' => date('Y-m-d H:i:s', time())
        ]);
        //过滤初始人
        if (empty($user['parent_id'])) {
            return '';
        }
        //间接分享人
        $user_other = Db::name('members')->where('id', $user['parent_id'])->field('id,parent_id,star')->find();
        //直接分享人不是掌柜，本次分享无效
        if ($user_other['star'] < 1) {
            return '';
        }
        $money_2 = bcdiv($money * $config['up2_p'], 100, 2);
        Db::name('user_balance')->where('uid', $user['parent_id'])->update([
            'estimate_balance' => Db::raw('estimate_balance+' . $money_2),
            'total' => Db::raw('total+' . $money_2)
        ]);
        Db::name('user_estimate_balance')->insert([
            'balance' => $money_2,
            'uid' => $user['parent_id'],
            'order_no' => $order_no,
            'type' => 2,
            'create_time' => date('Y-m-d H:i:s', time())
        ]);
    }

    /**
     * 运营中心奖励
     * @param $type 1.掌柜专区2线上3线下4阿里
     * @param $order_no 订单号
     * @param $area_id 用户位置、店铺位置
     * @param $price 订单支付金额
     * @param int $spid 店铺id
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public static function ocRight($type, $order_no, $area_id, $price, $spid = 0) {
        if (empty($area_id)) {
            return '';
        }
        if ($type == 1 && $price < 499) {
            return '';
        }
        if ($type == 1) {
            $num = floor($price / 499);
        } else {
            $num = 1;
        }
        if ($type > 1 && $type < 4 && $spid > 0) {
            $shop_type = Db::name('shop')->where('id', $spid)->value('shop_type');
            $type = $shop_type + 1;
        } else if ($type == 1 && $spid == 0) {
        } else if ($type == 4 && $spid == 0) {
        } else {
            return '';
        }
        //1.查询省、市、区区域
        $area_ids = Db::name('baidu_map')->alias('b')->join('baidu_map m', 'b.id=m.pid or b.id=m.id')
            ->join('baidu_map p', 'p.pid=m.id or p.id=m.id')->where('p.id', $area_id)->field('DISTINCT b.id,b.type')
            ->select()->toArray();
        $area_p = ''; //省
        $area_c = ''; //市
        $area_co = ''; //区
        if (!empty($area_ids)) {
            foreach ($area_ids as $area) {
                if ($area['type'] == 1) {
                    $area_p = $area['id'];
                } else if ($area['type'] == 2) {
                    $area_c = $area['id'];
                } else if ($area['type'] == 3) {
                    $area_co = $area['id'];
                }
            }
        } else {
            return '';
        }
        //2.查询并计算相应运营中心金额
        $confs = [];
        $conf_d = Db::name('config_reward')->where('type', 3)->field('star,up1_m,resp,reg_gxz,zy_rate')->select()->toArray();
        $descript = '';
        if ($type == 1) { //499礼包
            foreach ($conf_d as $val) {
                $confs[$val['star']] = $val['reg_gxz'];
            }
            $descript = '运营中心(掌柜专区)';
        } else if ($type == 2) { //线上
            foreach ($conf_d as $val) {
                $v = bcmul($price, $val['up1_m'] * 0.01, 2);
                $confs[$val['star']] = $v;
            }
            $descript = '运营中心(线上商家)';
        } else if ($type == 3) { //线下
            foreach ($conf_d as $val) {
                $v = bcmul($price, $val['resp'] * 0.01, 2);
                $confs[$val['star']] = $v;
            }
            $descript = '运营中心(线下商家)';
        } else if ($type == 4) { //自营
            foreach ($conf_d as $val) {
                $v = bcmul($price, $val['zy_rate'] * 0.01, 2);
                $confs[$val['star']] = $v;
            }
            $descript = '运营中心(自营商家)';
        }
        //3.查找地区运营人分钱
        $logs = [];
        $balancelog = [];

        $balancelog['type'] = 1;
        $balancelog['source'] = $type;
        $balancelog['order_no'] = $order_no;
        $balancelog['area_id'] = $area_id;
        $balancelog['spid'] = $spid;
        $balancelog['create_time'] = date('Y-m-d H:i:s', time());
        if (!empty($area_p) && $confs[1] >= 0.01) {
            $pid = Db::name('members')->where('oc_area_id', $area_p)->value('id');
            $count = Db::name('oc_balance_log')->where([
                'uid' => $pid,
                'order_no' => $order_no
            ])->count();
            if ($pid > 0 && $count == 0) {
                Db::name('user_balance')->where('uid', $pid)->update([
                    'oc_balance' => Db::raw('oc_balance+' . $confs[1]),
                    'oc_total_balance' => Db::raw('oc_total_balance+' . $confs[1]),
                ]);
                $balancelog['uid'] = $pid;
                $balancelog['source_name'] = "省" . $descript;
                $balancelog['balance'] = $confs[1] * $num;
                $logs[] = $balancelog;
            }
        }
        //查人分钱
        if (!empty($area_c) && $confs[2] >= 0.01) {
            $cid = Db::name('members')->where('oc_area_id', $area_c)->value('id');
            $count = Db::name('oc_balance_log')->where([
                'uid' => $cid,
                'order_no' => $order_no
            ])->count();
            if ($cid > 0 && $count == 0) {
                Db::name('user_balance')->where('uid', $cid)->update([
                    'oc_balance' => Db::raw('oc_balance+' . $confs[2]),
                    'oc_total_balance' => Db::raw('oc_total_balance+' . $confs[2]),
                ]);
                $balancelog['uid'] = $cid;
                $balancelog['source_name'] = "市" . $descript;
                $balancelog['balance'] = $confs[2] * $num;
                $logs[] = $balancelog;
            }
        }
        //查人分钱
        if (!empty($area_co) && $confs[3] >= 0.01) {
            $coid = Db::name('members')->where('oc_area_id', $area_co)->value('id');
            $count = Db::name('oc_balance_log')->where([
                'uid' => $coid,
                'order_no' => $order_no
            ])->count();
            if ($coid > 0 && $count == 0) {
                Db::name('user_balance')->where('uid', $coid)->update([
                    'oc_balance' => Db::raw('oc_balance+' . $confs[3]),
                    'oc_total_balance' => Db::raw('oc_total_balance+' . $confs[3]),
                ]);
                $balancelog['uid'] = $coid;
                $balancelog['source_name'] = "区" . $descript;
                $balancelog['balance'] = $confs[3] * $num;
                $logs[] = $balancelog;
            }
        }
        if (!empty($logs)) {
            Db::name('oc_balance_log')->insertAll($logs);
        }
    }

    /**
     *团队消费佣金(淘客佣金部分)
     * 根据下订单的人计算(校验上下级级别,出现平级则阻断)
     * @param $uid
     * @param $price
     * @param $type
     * @param $info
     * @param $tmall
     * @return string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public static function taokeRight($uid, $price, $type, $info, $tmall) {
        if ($price < 0.01) {
            self::setYg($info['id'], 'yg', $tmall);
            return '';
        }
        $datatemp = Db::name('config_new')->where('name', 'taoke_self')->find();

        $config_info = json_decode($datatemp['info'], true);
        //下单人
        $user = Db::name('members')->where('id', $uid)->field('path,star')->find();

        $data = [];
        $data['role'] = 'backAward';
        $data['types'] = 'balance';
        $data['order_no'] = $info['order_no'];
        $data['itemid'] = $info['itemid'];
        $data['payid'] = '';
        $data['check'] = $tmall;
        $data['order_price'] = $price;

        //1.下单人自己也拿佣金
        $money = bcdiv($price * $config_info[$user['star']], 100, 2);
        if ($money >= 0.01) {
            if ($tmall == 1) {
                $remark = "淘宝订单佣金奖励(自购)";
            } elseif ($tmall == 3) {
                $remark = "京东订单佣金奖励(自购)";
            } else {
                $remark = "拼多多订单佣金奖励(自购)";
            }
            $data['remark'] = $remark;
            if ($type == 'yg') {
                $data['uid'] = $uid;
                $data['price'] = $money;
                $data['create_time'] = time();
                $data['xiadan_time'] = $info['create_time'];
                $data['is_me'] = 1;
                Db::name('rebate_yg_demo')->insert($data);
            } else {
                $data['uid'] = $uid;
                $data['price'] = $money;
                $data['create_time'] = time();
                $data['status'] = 0;
                $data['is_me'] = 1;
                $data['js_time'] = $info['js_time'];
                Db::name('rebate_demo')->insert($data);
            }
        }
        $data['is_me'] = 0;

        //佣金比例
        $rewardList = Db::name('config_reward')->where('type', 1)->field('star,team_rate')->order('star asc')->select()->toArray();
        $rewards = [];
        foreach ($rewardList as $v) {
            $rewards[$v['star']] = $v['team_rate'];
        }

        //2.上级团队分佣
        if ($tmall == 1) {
            $remark = "淘宝推荐佣金奖励(团队)";
        } elseif ($tmall == 3) {
            $remark = "京东推荐佣金奖励(团队)";
        } else {
            $remark = "拼多多推荐佣金奖励(团队)";
        }
        $data['remark'] = $remark;
        $my_upers = explode("-", $user['path']);
        $uper_count = count($my_upers) - 1; //去除path最后一个0
        $max_satr = $user['star'];
        for ($j = 0; $j < $uper_count; $j++) {
            $up_user = Db::name('members')->where('id', $my_upers[$j])->field('star')->find();

            if ($max_satr < $up_user['star']) {
                if ($rewards[$up_user['star']] > 0) {
                    $my_money = bcdiv($price * $rewards[$up_user['star']], 100, 2);
                    if ($my_money < 0.01) {
                        self::setYg($info['id'], 'yg', $tmall);
                        continue;
                    }
                    if ($type == 'yg') {
                        $data['uid'] = $my_upers[$j];
                        $data['price'] = $my_money;
                        $data['create_time'] = time();
                        $data['xiadan_time'] = $info['create_time'];
                        Db::name('rebate_yg_demo')->insert($data);
                    } else {
                        $data['uid'] = $my_upers[$j];
                        $data['price'] = $my_money;
                        $data['create_time'] = time();
                        $data['status'] = 0;
                        $data['js_time'] = $info['js_time'];
                        Db::name('rebate_demo')->insert($data);
                    }

                    $max_satr = $up_user['star'];
                }
            } else {
                self::setYg($info['id'], 'yg', $tmall);
                //出现平级阻断
                //break;
            }
        }

        //3.上级团队分佣
        if ($tmall == 1) {
            $remark = "淘宝推荐佣金奖励(推荐)";
        } elseif ($tmall == 3) {
            $remark = "京东推荐佣金奖励(推荐)";
        } else {
            $remark = "拼多多推荐佣金奖励(推荐)";
        }
        $data['remark'] = $remark;
        $config_tui = Db::name('config_reward')->where('type', 2)->field('up1_m,up2_p')->find();
        $money_1 = bcdiv($price * $config_tui['up1_m'], 100, 2);
        $money_2 = bcdiv($price * $config_tui['up2_p'], 100, 2);

        if ($type == 'yg') {

            $data['create_time'] = time();
            $data['xiadan_time'] = $info['create_time'];
            //直推人
            if ($money_1 >= 0.01 && $my_upers[0]) {
                $data['uid'] = $my_upers[0];
                $data['price'] = $money_1;
                Db::name('rebate_yg_demo')->insert($data);
            }
            //二级推荐人
            if ($money_2 >= 0.01 && $my_upers[1]) {
                $data['uid'] = $my_upers[1];
                $data['price'] = $money_2;
                Db::name('rebate_yg_demo')->insert($data);
            }
        } else {

            $data['create_time'] = time();
            $data['status'] = 0;
            $data['js_time'] = $info['js_time'];

            //直推人
            if ($money_1 >= 0.01 && $my_upers[0]) {
                $data['uid'] = $my_upers[0];
                $data['price'] = $money_1;
                Db::name('rebate_demo')->insert($data);
            }

            //二级推荐人
            if ($money_2 >= 0.01 && $my_upers[1]) {
                $data['uid'] = $my_upers[1];
                $data['price'] = $money_2;
                Db::name('rebate_demo')->insert($data);
            }
        }
    }

    /**
     * @param $id
     * @param $type
     * @param int $tmall
     * @return int|string
     * @throws \think\Exception
     * @throws \think\exception\PDOException
     */
    public static function setYg($id, $type, $tmall = 1) {
        if ($tmall == 1) {
            if ($type == 'yg') {
                Db::name('taoke_order')->where('id', $id)->setField('yg_status', 1);
            } elseif ($type == 'all') {
                Db::name('taoke_order')->where('id', $id)->update(['js_status' => 1, 'yg_status' => 1]);
            } else {
                Db::name('taoke_order')->where('id', $id)->setField('js_status', 1);
            }
        } elseif ($tmall == 3) {
            if ($type == 'yg') {
                Db::name('jd_order')->where('id', $id)->setField('yg_status', 1);
            } elseif ($type == 'all') {
                return Db::name('jd_order')->where('id', $id)->update(['js_status' => 1, 'yg_status' => 1]);
            } else {
                Db::name('jd_order')->where('id', $id)->setField('js_status', 1);
            }
        } else {
            if ($type == 'yg') {
                Db::name('pdd_order')->where('id', $id)->setField('yg_status', 1);
            } elseif ($type == 'all') {
                Db::name('pdd_order')->where('id', $id)->update(['js_status' => 1, 'yg_status' => 1]);
            } else {
                Db::name('pdd_order')->where('id', $id)->setField('js_status', 1);
            }
        }
    }

    /**
     * 发放贡献值
     * @param int $uid 用户id
     * @param float $gxz 贡献值
     * @param string $name 记录名称
     * @param int $type 1:增加,0减少
     * @param null $order_no 订单号
     * @throws \think\Exception
     */
    static public function giveGxz($uid, $gxz, $name, $type = null, $order_no = null) {
        $log = [
            'uid' => $uid,
            'type' => 1,
            'source_name' => $name,
            'order_id' => $order_no,
        ];
        $find = Db::name('user_balance_log')->where($log)->find();
        if (empty($find) || $order_no == null) {
            $log = [
                'uid' => $uid,
                'type' => 1,
                'source' => 10,
                'source_name' => $name,
                'create_time' => date('Y-m-d H:i:s', time()),
                'order_id' => $order_no,
                'gxz' => $gxz,
                'calculator' => $gxz,
                'balance_type' => 3
            ];
            Db::name('user_balance')->where('uid', $uid)->setInc('gxz', $gxz);
            Db::name('user_balance')->where('uid', $uid)->setInc('calculator', $gxz);
            Db::name('user_balance_log')->insert($log);
            return true;
        }
        return false;
    }

    /**
     * 扣除贡献值
     * @param $uid 用户id
     * @param $gxz 贡献值
     * @param $name 记录名称
     * @param $type 1:增加,0减少
     * @param null $order_no 订单号
     * @throws \think\Exception
     */
    static public function deductGxz($uid, $gxz, $name, $order_no = null) {
        $log = [
            'uid' => $uid,
            'type' => 0,
            'source_name' => $name,
            'order_id' => $order_no,
        ];
        $find = Db::name('user_balance_log')->where($log)->find();
        if (empty($find) || $order_no == null) {
            $log = [
                'uid' => $uid,
                'type' => 0,
                'source' => 10,
                'source_name' => $name,
                'create_time' => date('Y-m-d H:i:s', time()),
                'order_id' => $order_no,
                'gxz' => 0,
                'calculator' => $gxz,
                'balance_type' => 3
            ];
            Db::name('user_balance')->where('uid', $uid)->setDec('calculator', $gxz);
            Db::name('user_balance_log')->insert($log);
        }
    }

    /**
     * 发放佣金
     * @param $uid
     * @param $money
     * @param $title
     * @param $type
     * @param $orderId
     * @param string $time
     * @param int $balance_type
     * @param int $source
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static public function giveBalance($uid, $money, $title, $type, $orderId, $time = '', $balance_type = 1, $source = 8) {
        if ($time == '') {
            $time = date('Y-m-d H:i:s', time());
        }
        $balancelog = [];
        $balancelog['uid'] = $uid;
        $balancelog['type'] = $type;
        $balancelog['source'] = $source;
        $balancelog['source_name'] = $title;
        $balancelog['balance'] = $money;
        $balancelog['order_id'] = $orderId;
        $balancelog['balance_type'] = $balance_type;
        $find = Db::name('user_balance_log')->where($balancelog)->find();
        if (empty($find)) {
            $balancelog['create_time'] = $time;
            try {
                Db::name('user_balance')->where('uid', $uid)->update([
                    'balance' => Db::raw('balance+' . $money),
                    'total' => Db::raw('total+' . $money),
                ]);
                Db::name('user_balance_log')->insert($balancelog);
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * 发放佣金
     * @param $uid
     * @param $money
     * @param $title
     * @param $type
     * @param $orderId
     * @param string $time
     * @param int $balance_type
     * @param int $source
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static public function giveRedpacket($uid, $money, $title, $orderId, $time = '') {
        if ($time == '') $time = date('Y-m-d H:i:s', time());
        $balancelog = [];
        $balancelog['uid'] = $uid;
        $balancelog['type'] = '增加';
        $balancelog['source'] = '任务中心';
        $balancelog['source_name'] = $title;
        $balancelog['balance'] = $money;
        $balancelog['order_id'] = $orderId;
        $balancelog['balance_type'] = '红包';
        $find = MemberBalanceLog::objectInit()->whereCv($balancelog)->find();
        if (!empty($find)) throw new Exception('红包已发放');
        $balancelog['create_time'] = $time;
        $res = Db::name('user_balance')->where('uid', $uid)->update([
            'redpacket' => Db::raw('redpacket+' . $money)
        ]);
        $res2 = MemberBalanceLog::create($balancelog, true);
        if (!$res) throw new Exception('发放失败');
        if (!$res2) throw new Exception('发放日志错误');
        return true;
    }

    /**
     * 余额抵扣
     * @param $uid
     * @param $money
     * @param $title
     * @param $orderId
     * @param string $time
     * @param int $balance_type
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static public function deductBalance($uid, $money, $title, $orderId, $time = '', $balance_type = 1) {
        if ($time == '') {
            $time = date('Y-m-d H:i:s', time());
        }
        $balancelog = [];
        $balancelog['uid'] = $uid;
        $balancelog['type'] = 0;
        $balancelog['source'] = 4;
        $balancelog['source_name'] = $title;
        $balancelog['balance'] = $money;
        $balancelog['order_id'] = $orderId;
        $balancelog['balance_type'] = 1;
        $find = Db::name('user_balance_log')->where($balancelog)->find();
        if (empty($find)) {
            $balancelog['create_time'] = $time;
            try {
                Db::name('user_balance')->where('uid', $uid)->update([
                    'balance' => Db::raw('balance-' . $money),
                ]);
                Db::name('user_balance_log')->insert($balancelog);
            } catch (\Exception $e) {
                return;
            }
        }
    }

    /**
     * 发放通证
     * @param $uid
     * @param $money
     * @param $title
     * @param $orderId
     * @param string $time
     * @param int $source
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static public function giveTz($uid, $money, $title, $orderId, $time = '', $source = 8) {
        if ($time == '') {
            $time = date('Y-m-d H:i:s', time());
        }
        $balancelog = [];
        $balancelog['uid'] = $uid;
        $balancelog['type'] = 1;
        $balancelog['source'] = $source;
        $balancelog['source_name'] = $title;
        $balancelog['tz_coupon'] = $money;
        $balancelog['order_id'] = $orderId;
        $balancelog['balance_type'] = 2;
        $find = Db::name('user_balance_log')->where($balancelog)->find();
        if (empty($find)) {
            $balancelog['create_time'] = $time;
            try {
                Db::name('user_balance')->where('uid', $uid)->update([
                    'tz_coupon' => Db::raw('tz_coupon+' . $money),
                ]);
                Db::name('user_balance_log')->insert($balancelog);
            } catch (\Exception $e) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * 通证抵扣
     * @param $uid
     * @param $money
     * @param $title
     * @param $orderId
     * @param string $time
     * @param int $balance_type
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static public function deductTz($uid, $money, $title, $orderId, $time = '', $balance_type = 1) {
        if ($time == '') {
            $time = date('Y-m-d H:i:s', time());
        }
        $balancelog = [];
        $balancelog['uid'] = $uid;
        $balancelog['type'] = 0;
        $balancelog['source'] = 4;
        $balancelog['source_name'] = $title;
        $balancelog['tz_coupon'] = $money;
        $balancelog['order_id'] = $orderId;
        $balancelog['balance_type'] = 2;
        $find = Db::name('user_balance_log')->where($balancelog)->find();
        if (empty($find)) {
            $balancelog['create_time'] = $time;
            try {
                Db::name('user_balance')->where('uid', $uid)->update([
                    'tz_coupon' => Db::raw('tz_coupon-' . $money),
                ]);
                Db::name('user_balance_log')->insert($balancelog);
            } catch (\Exception $e) {
                return;
            }
        }
    }

    /**
     * 给一二级直推人发放分佣
     * @param $money 利润
     * @param $uid 用户id
     * @param $order_no 单号
     * @param $number 用户推荐码
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static public function giveReferralAward($money, $uid, $order_no, $number = 0) {
        $user = Db::name('members')->where('id', $uid)->find();
        $my_upers = explode("-", $user['path']);
        //2.推荐人25%，间接推荐人15%
        $config_tui = Db::name('config_reward')->where('type', 2)->field('up1_m,up2_p')->find();
        $money_1 = bcdiv($money * $config_tui['up1_m'], 100, 2);
        $money_2 = bcdiv($money * $config_tui['up2_p'], 100, 2);
        //直推人
        if (!$number) {
            if ($money_1 > 0 && !empty($my_upers[0])) {
                self::giveBalance($my_upers[0], $money_1, "会员收益(购物推荐)", 1, $order_no);
            }
        } else {
            $share_uper = Db::name('members')->where('number', $number)->value('id');
            if ($money_1 > 0 && !empty($share_uper)) {
                self::giveBalance($share_uper, $money_1, "会员收益(购物推荐)", 1, $order_no);
            }
        }
        //间接人
        if ($money_2 > 0 && !empty($my_upers[1])) {
            $star = Db::name('members')->where('id', $my_upers[1])->value('star');
            if ($star > 0) {
                self::giveBalance($my_upers[1], $money_2, "会员收益(购物推荐)", 1, $order_no);
            }
        }
    }

    /**
     * 给团队发放分佣,
     * @param $money 利润
     * @param $uid 用户id
     * @param $order_no 单号
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static public function giveTeamAward($money, $uid, $order_no) {
        $user = Db::name('members')->where('id', $uid)->find();
        $my_upers = explode("-", $user['path']);
        //1.经销商级别以上团队分佣
        $rewardList = Db::name('config_reward')->where('type', 1)->field('star,team_rate')->order('star asc')->select()->toArray();
        $rewards = [];
        foreach ($rewardList as $v) {
            $rewards[$v['star']] = $v['team_rate'];
        }
        $my_upers = explode("-", $user['path']);
        $uper_count = count($my_upers) - 1; //去除path最后一个0
        $max_satr = $user['star'];
        for ($j = 0; $j < $uper_count; $j++) {
            $up_user = Db::name('members')->where('id', $my_upers[$j])->field('star')->find();
            if ($max_satr < $up_user['star']) {
                if ($rewards[$up_user['star']] > 0) {
                    $my_money = bcdiv($money * $rewards[$up_user['star']], 100, 2);
                    if ($my_money < 0.01) {
                        continue;
                    }
                    self::giveBalance($my_upers[$j], $my_money, "会员收益(代理佣金)", 1, $order_no);
                    $max_satr = $up_user['star'];
                }
            } else {
                //出现平级阻断
                //break;
            }
        }
    }

    /**
     * 商家直推奖
     * @param $money 利润
     * @param $shopUid 商家的用户id
     * @param $order_no 单号
     */
    static public function giveReferralShopAward($money, $shopUid, $order_no) {
        $uid = $shopUid;
        $referralUid = Db::name('members')->where('id', $uid)->value('parent_id'); //获取商家推荐人Id
        $referraMoney = $money * 0.05;
        self::giveBalance($referralUid, $referraMoney, "会员收益(商家推荐)", 1, $order_no);
    }


    /**
     * 发放运营中心奖
     * @param $amount 销售额
     * @param $uid 用户id
     * @param $order_no 单号
     * @param $spid 商家id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static public function giveOcAward($amount, $uid, $order_no, $spid, $source = 3, $name = '报单系统') {
        $areaId = Db::name('members')->where('id', $uid)->value('area_id');
        if (!$areaId) { //如果用户不存在地区ID,则返回
            return;
        }
        $area = Db::name('baidu_map')->where('id', $areaId)->find();
        $city = Db::name('baidu_map')->where('id', $area['pid'])->find();
        $province = Db::name('baidu_map')->where('id', $city['pid'])->find();
        //发放地区运营中心奖励
        $areaOcUid = Db::name('members')->where('oc_area_id', $area['id'])->value('id');
        $cityOcUid = Db::name('members')->where('oc_area_id', $city['id'])->value('id');
        $provinceOcUid = Db::name('members')->where('oc_area_id', $province['id'])->value('id');
        if ($areaOcUid) {
            $result = self::giveOcBalance($areaOcUid, $amount, $order_no, $spid, $source, $name);
        }
        if ($cityOcUid) {
            $result = self::giveOcBalance($cityOcUid, $amount, $order_no, $spid, $source, $name);
        }
        if ($provinceOcUid) {
            $result = self::giveOcBalance($provinceOcUid, $amount, $order_no, $spid, $source, $name);
        }
    }

    /**
     * 分发团队长佣金
     */
    static public function giveTeamerAward($order_no, $uid, $total) {

        $member = Db::name('members')->field('path,star')->where('id', $uid)->find();
        $pathArr = explode('-', $member['path']);
        $upsArr = [];
        $minStar = $member['star'];

        foreach ($pathArr as $v) {
            $ups = Db::name('members')->field('id,star,teamer')->where('id', $v)->where('star', '>', $minStar)->find();
            if (!empty($ups)) {
                $minStar = $ups['star'];
                $upsArr[] = $ups;
            }
        }
        $rates = ConfigNew::getTeamerRate();
        foreach ($upsArr as $v) {
            if ($v['teamer'] && $v['id']) {
                self::giveBalance($v['id'], $total * $rates[$v['star']] / 100, "团队长收益", 1, $order_no);
            }
        }
    }

    //给运营中心发放奖励,并写下记录(一般不直接调用)
    static public function giveOcBalance($uid, $money, $order_no, $spid, $source = 3, $name = '报单系统') {
        //用户信息处理
        $user = Db::name('members')->where('id', $uid)->find();
        if (!$user['oc_area_id']) { //如果用户不存在地区ID,则返回
            return;
        }
        if ($user['oc_type'] == 1) {
            $source_name = "省运营中心($name)";
        }
        switch ($user['oc_type']) {
            case  1:
                $source_name = "省运营中心($name)";
                break;
            case  2:
                $source_name = "市运营中心($name)";
                break;
            case  3:
                $source_name = "区运营中心($name)";
                break;
            default:
        }
        //发放奖励计算
        $conf = Db::name('config_reward')->where('type', 3)->field('star,up1_m,resp,reg_gxz,zy_rate')->select()->toArray();
        foreach ($conf as $c) {
            $rate[$c['star']] = $c['resp'];
        }
        $balance = $money * $rate[$user['oc_type']] / 100;

        $count = Db::name('oc_balance_log')->where(['order_no' => $order_no, 'uid' => $uid])->count();
        if (!$count) {
            //进行发放
            Db::name('user_balance')->where('uid', $uid)->update([
                'oc_balance' => Db::raw('oc_balance+' . $balance),
                'oc_total_balance' => Db::raw('oc_total_balance+' . $balance),
            ]);
            $result = Db::name('oc_balance_log')->insertGetId([
                'uid' => $uid,
                'type' => 1,
                'source' => $source,
                'source_name' => $source_name,
                'balance' => $balance,
                'order_no' => $order_no,
                'create_time' => date('Y-m-d H:i:s', time()),
                'area_id' => $user['oc_area_id'],
                'spid' => $spid
            ]);
        }
        return true;
    }

    /**
     * 发放留莲券
     * @param $uid 用户id
     * @param $val 留莲券
     * @param $title 标题
     * @param $type 0减少,1增加
     */
    static public function givellj($uid, $val, $title, $type) {
        if ($val <= 0) return;
        Db::startTrans();
        $db = Db::name('user_balance');
        $llj = $db->where('uid', $uid)->value('llj');
        if ($type === 1) {
            $llj = $llj + $val;
        } else {
            $llj = $llj - $val;
            if ($llj < 0) throw new \Exception('榴莲卷余额不能为负数');
            $val = 0 - $val;
        }
        $db->where('uid', $uid)->update(['llj' => $llj]);
        if (Db::name('llj_log')->insert([
            'uid' => $uid,
            'val' => $val,
            'create_time' => time(),
            'title' => $title,
            'last_llj' => $llj
        ])) {
            Db::commit();
            return true;
        }
        throw new \Exception('llj日志插入失败');
    }

    public function confirmOrder($uid, $order) {
        if (substr($order['order_no'],0,2) == 'KJ') return true;
        //店铺款
        if ($order['spid'] > 0 && $order['total'] > 0) {
            $shopService = new ShopService();
            $shopService->confirmShopBalance($order['spid'], $order['total'], $order['order_no']);
        }
        //线上店铺推荐奖励
        if ($order['spid'] > 0 && $order['total'] > 0) {
            $rights = new RightsService();
            $rights->shopRights($order['uid'], $order['order_no'], $order['total'], $order['spid']);
        }
        //用户及店铺贡献值增加
        if ($order['cal_money'] > 0) {
            $buyer_gxz = $order['total'];
            //用户贡献值增加
            $rights = new RightsService();
            $rights->giveGxz($order['uid'], $buyer_gxz, '商品消费', 1, $order['order_no']);
            //店铺贡献值增加
            if ($order['spid'] > 0) {
                $shop_cal = Db::name('shop')->where('id', $order['spid'])->field('rate,uid')->find();
                $shop_gxz = bcmul($buyer_gxz, ($shop_cal['rate'] / 100), 2);

                $rights->giveGxz($shop_cal['uid'], $shop_gxz, '店铺贡献值收益', 1);
            }
        }
        //499礼包专区 无  团队消费和购物推荐25% 15%
        //团队消费收益
        if ($order['order_type'] != 2) {
            $rights = new RightsService();
            $flag = $rights->teamRights($uid, $order['order_no'], $order['total'], $order['spid'], $order['number']);
        }
        //运营中心奖励
        if ($order['spid'] > 0 && $order['total'] > 0) {
            $oc_area_id = Db::name('shop')->where('id', $order['spid'])->value('area_id');
            $rights = new RightsService();
            $rights->ocRight(2, $order['order_no'], $oc_area_id, $order['total'], $order['spid']);
        } else if ($order['order_type'] == 2) {
            $oc_area = Db::name('members')->where('id', $uid)->value('area_id');
            $rights = new RightsService();
            $rights->ocRight(1, $order['order_no'], $oc_area, $order['total']);
        } else if ($order['order_type'] == 1 && $order['spid'] == 0) {
            $oc_area = Db::name('members')->where('id', $uid)->value('area_id');
            $rights = new RightsService();
            $rights->ocRight(4, $order['order_no'], $oc_area, $order['total']);
        }
        return true;
    }
}
