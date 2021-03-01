<?php

namespace app\api\service;

use think\Db;


class ShopService
{
    /**
     * 店铺货款(线下店铺)
     * @param int $uid
     * @param int $orderType
     * @param int $order_no
     */
    public function shopBalance($spid, $amount, $order_no)
    {
        if ($spid <= 0) {
            return false;
        }
        if (Db::name('shop_balance_log')->where('order_id', $order_no)->find() !== null) {
            throw new \Exception('该订单已经结算过了.');
        };
        $shop = Db::name('shop')->where('id', $spid)->field('uid,rate')->find();
        //店铺让的利
        $give_money = bcdiv($amount * $shop['rate'], 100, 2);
        //店铺实际获得款
        $money = $amount - $give_money;
        $userBalance = Db::name('user_balance')->where('uid', $shop['uid'])->find();
        if ($userBalance === null) throw new \Exception('未找到该用户的账户信息');
        $re = Db::name('user_balance')->where('uid', $shop['uid'])->update([
            'shop_balance' => $userBalance['shop_balance'] + $money,
            'all_shop_balance' => $userBalance['all_shop_balance'] + $money,
        ]);
        $shopbalancelog = [];
        $shopbalancelog['uid'] = $shop['uid'];
        $shopbalancelog['spid'] = $spid;
        $shopbalancelog['type'] = 1;
        $shopbalancelog['source'] = 2;
        $shopbalancelog['source_name'] = "店铺货款";
        $shopbalancelog['balance'] = $money;
        $shopbalancelog['order_id'] = $order_no;
        $shopbalancelog['curr_balance'] = $userBalance['shop_balance'];
        $shopbalancelog['last_balance'] = $userBalance['shop_balance'] + $money;
        $shopbalancelog['create_time'] = date('Y-m-d H:i:s', time());
        $re1 = Db::name('shop_balance_log')->insert($shopbalancelog);
        if ($re && $re1) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 店铺货款(线上店铺  预估)
     * @param int $uid
     * @param int $orderType
     * @param int $order_no
     */
    public function ygShopBalance($spid, $amount, $order_no)
    {
        if ($spid <= 0) {
            return false;
        }
        $shop = Db::name('shop')->where('id', $spid)->field('uid,rate')->find();
        //店铺让的利
//        if ($shop['shop_type2']){
//            $give_money = 0;
//        }else{
            $give_money = bcdiv($amount * $shop['rate'], 100, 2);
//        }
        //店铺实际获得款
        $money = $amount - $give_money;
        $userBalance=Db::name('user_balance')->where('uid', $shop['uid'])->find();
        if($userBalance===null)throw new \Exception('未找到该用户的账户信息');
        $re = Db::name('user_balance')->where('uid', $shop['uid'])->update([
            'shop_balance' => $userBalance['shop_balance']+$money,
            'all_shop_balance' => $userBalance['all_shop_balance']+$money,
        ]);
        if ($re) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 店铺货款(线上店铺  确认收货)
     * @param int $uid
     * @param int $orderType
     * @param int $order_no
     */
    public function confirmShopBalance($spid, $amount, $order_no)
    {
        if ($spid <= 0) {
            return false;
        }
        $shop = Db::name('shop')->where('id', $spid)->field('uid,rate')->find();
        //店铺让的利
        $give_money = bcdiv($amount * $shop['rate'], 100, 2);
        //店铺实际获得款
        $money = $amount - $give_money;
        $userBalance = Db::name('user_balance')->where('uid', $shop['uid'])->find();
        if ($userBalance === null) throw new \Exception('未找到该用户的账户信息');
        $re = Db::name('user_balance')->where('uid', $shop['uid'])->update([
            'shop_balance' => $userBalance['shop_balance'] + $money,
            'all_shop_balance' => $userBalance['all_shop_balance'] + $money,
        ]);
        $shopbalancelog = [];
        $shopbalancelog['uid'] = $shop['uid'];
        $shopbalancelog['spid'] = $spid;
        $shopbalancelog['type'] = 1;
        $shopbalancelog['source'] = 2;
        $shopbalancelog['source_name'] = "店铺货款";
        $shopbalancelog['balance'] = $money;
        $shopbalancelog['order_id'] = $order_no;
        $shopbalancelog['curr_balance'] = $userBalance['shop_balance'];
        $shopbalancelog['last_balance'] = $userBalance['shop_balance'] + $money;
        $shopbalancelog['create_time'] = date('Y-m-d H:i:s', time());
        $re1 = Db::name('shop_balance_log')->insert($shopbalancelog);
        if ($re && $re1) {
            return true;
        } else {
            return false;
        }
    }
}
