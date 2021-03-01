<?php


namespace app\api\service;

use app\api\model\ConfigNew;
use app\BaseService;
use think\Db;
use think\exception\ErrorException;

/**
 * Class UpgradeService
 * @package app\api\service
 */
class UpgradeService extends BaseService {
    /**粉丝*/
    const FEN_SI = 0;
    /**掌柜*/
    const ZHANG_GUI = 1;
    /**店主*/
    const DIAN_ZHU = 2;
    /**经销商*/
    const JING_XIAO_SHANG = 3;
    /**区代*/
    const QU_DAI = 4;
    /**市代*/
    const SHI_DAI = 5;
    /**省代*/
    const SHENG_DAI = 6;
    /**购买礼包*/
    const BUY_GIFT = 1;
    /**团队消费*/
    const TEAM_CONSUME = 2;
    /**后台修改*/
    const ADMIN_MODIFY = 3;
    /**直推粉丝*/
    const ZHITUI = 4;

    public function start() {
        $redis = RedisServer::objectInit();
        $redis->setOption(\Redis::OPT_READ_TIMEOUT, -1);
        while (1) {
            $data = $redis->BrPop('UpgradeService');
            $db = Db::connect([], true);
            try {
                $this->userUpgradeCheckStart($data['uid']);
            } catch (\Exception $e) {
                Db::name('upgrade_log')->insert([
                    'uid' => $data['uid'],
                    'create_time' => time(),
                    'msg' => '用户升级过程中发生错误,原因:' . $e->getMessage()
                ]);
            }
            $db->close();
        }
    }

    protected function initialization() {
        //$this->uid = 1259;
        //empty($uid) ? $this->uid = TokenService::getCurrentUid() : $this->uid = $uid;
        //$this->member = Db::name('members')->where('id', $this->uid)->find();
    }

    /**
     * 升级功能
     * @param int $uid 用户id
     * @return bool|void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function userUpgradeCheck($uid, $isContinue = 0) {
        RedisServer::objectInit()->lPush('UpgradeService', ['uid' => $uid]);
        return 1;
    }

    public function userUpgradeCheckStart($uid, $isContinue = 0) {
        Db::startTrans();
        try {
            $this->userUpCheck($uid, $isContinue);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    protected function userUpCheck($uid, $isContinue = 0) {
        Db::name('test')->insert(['param' => 'id:' . $uid . '进行了升级检测', 'time' => date('Y-m-d H:i:s')]);
        $user = Db::name('members')->where('id', $uid)->find();
        if (empty($user)) return;
        $star = $user['star'];
        switch ((int)$star) {
            case self::FEN_SI:
                $this->zhanggui($user);
                break;
            case self::ZHANG_GUI:
            case self::DIAN_ZHU:
                $this->jingxiaoshang($user);
                break;
            default :
                return;
//            case self::JING_XIAO_SHANG:
//                $this->qudai($user);
//                break;
//            case self::QU_DAI:
//                $this->shidai($user);
//                break;
//            case self::SHI_DAI:
//                $this->shengdai($user);
//                break;
//            case self::SHENG_DAI://已经升满级了.
//                break;
        }
        if (!$isContinue) {
            if ($user['parent_id'] >= $user['id']) return 1;
            $pid = Db::name('members')->where('id', $user['parent_id'])->value('id');
            if (empty($pid)) return 1;
            $this->userUpCheck($pid);
        }
        return true;
    }

    /**
     * 获取升级情况数据
     * @param int $uid 用户id
     * @return array|void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getUpgradeList($uid) {
        return $this->null;
        $user = Db::name('members')->where('id', $uid)->find();
        if (empty($user)) return;
        $star = $user['star'];
        $data = [];
        switch ((int)$star) {
            case self::FEN_SI:
                $data = [
                    'star' => 0,
                ];
                break;
            case self::ZHANG_GUI:
            case self::DIAN_ZHU:
                $data = [
                    'star' => 1
                ];
                break;
            case self::JING_XIAO_SHANG:
                $data = [
                    'star' => 2
                ];
                break;
            case self::QU_DAI:
                $data = [
                    'star' => 3
                ];
                break;
            case self::SHI_DAI:
                $data = [
                    'star' => 4
                ];
                break;
            case self::SHENG_DAI://已经升满级了.
                $data = ['star' => 5];
                break;
        }
        list($num, $money) = $this->getFansGoods($user['id']);
        list($num2, $money2) = $this->teamConsume($user['id'], self::ZHANG_GUI);
        list($num3, $money3) = $this->teamConsume($user['id'], self::JING_XIAO_SHANG);
        list($num4, $money4) = $this->teamConsume($user['id'], self::QU_DAI);
        list($num5, $money5) = $this->teamConsume($user['id'], self::SHI_DAI);
        $data['up_schedule'] = [
            'gift_goods' => ['quantity' => $this->getGiftGoods($user['id'])],//推荐499
            'fans' => ['quantity' => count($this->getFans($user['id']))],//推荐粉丝
            'fans_goods' => ['fans' => $num, 'money' => $money],//直推购买
            'zhitui' => [
                1 => [
                    'quantity' => $num2, 'money' => $money2,
                ],
                2 => [
                    'quantity' => $num3, 'money' => $money3,
                ],
                3 => [
                    'quantity' => $num4, 'money' => $money4,
                ],
                4 => [
                    'quantity' => $num5, 'money' => $money5,
                ],
            ]
        ];
        $data['up_condition'] = [
            0 => ConfigNew::getUpZhangGui(),
            1 => ConfigNew::getUpJingXiaoShang(),
            2 => ConfigNew::getUpQuDai(),
            3 => ConfigNew::getUpSHIDai(),
            4 => ConfigNew::getUpSHENGDai(),
        ];
        $data['up_desc'] = [
            0 => [
                '自购省钱并获得消费分红通证收益,但比掌柜慢37.5%;'
            ],
            1 => [
                '享受粉丝所有收益;',
                '可获得直推用户商城消费利润25%的消费佣金;',
                '可获得间推用户商城消费利润15%的消费佣金;',
                '可获得直推线上、线下商家销售利润的5%佣金;',
                '可获得直推用户购买掌柜礼包120元/个佣金;',
                '可获得直推供应链利润30%的佣金;',
                '可获得消费分红通证收益,但比粉丝快37.5%'
            ],
            2 => [
                '享受掌柜所有收益;',
                '可获得直推用户商城消费利润25%的消费佣金;',
                '可获得间推用户商城消费利润15%的消费佣金;',
                '可获得直推线上、线下商家销售利润的5%佣金;',
                '可获得区域用户商城消费利润5%代理佣金;',
                '可获得直推用户购买掌柜礼包160元佣金;',
                '可获得区域用户购买掌柜礼包40元/个代理佣金;',
                '可获得区域用户购买掌柜礼包3元+3元平级二代奖; ',
                '可获得直推供应链利润30%的佣金;',
                '可获得消费分红通证收益,但比粉丝快37.5%;'
            ],
            3 => [
                '享受经销商所有收益;',
                '可获得直推用户商城消费利润25%的消费佣金;',
                '可获得间推用户商城消费利润15%的消费佣金; ',
                '可获得直推线上、线下商家销售利润的5%佣金;',
                '可获得区域用户商城消费利润5%代理佣金;',
                '可获得直推用户购买掌柜礼包185元/个佣金;',
                '可获得区域用户购买掌柜礼包25元/个代理佣金;',
                '可获得区域用户购买掌柜礼包4元+4元平级二代奖; ',
                '可获得直推供应链利润30%的佣金;',
                '可获得消费分红通证收益,但比粉丝快37.5%'
            ],
            4 => [
                '享受经区代所有收益;',
                '可获得直推用户商城消费利润25%的消费佣金;',
                '可获得间推用户商城消费利润15%的消费佣金;',
                '可获得直推线上、线下商家销售利润的5%佣金;',
                '可获得区域用户商城消费利润3%代理佣金;',
                '可获得直推用户购买掌柜礼包205元/个佣金;',
                '可获得区域用户购买掌柜礼包20元/个代理佣金;',
                '可获得区域用户购买掌柜礼包5元+5元平级二代奖;',
                '可获得直推供应链利润30%的佣金;',
                '可获得消费分红通证收益,但比粉丝快37.5%'
            ],
            5 => [
                '享受市代所有收益;',
                '可获得直推用户商城消费利润25%的消费佣金;',
                '可获得间推用户商城消费利润15%的消费佣金;',
                '可获得直推线上、线下商家销售利润的5%佣金;',
                '可获得区域用户商城消费利润2%代理佣金;',
                '可获得直推用户购买掌柜礼包215元/个佣金;',
                '可获得区域用户购买掌柜礼包10元/个代理佣金;',
                '可获得区域用户购买掌柜礼包6元+6元平级二代奖;',
                '可获得掌柜礼包每月1%加权分红;',
                '可获得直推供应链利润30%的佣金;',
                '可获得消费分红通证收益,但比粉丝快37.5%;'
            ],
        ];
        return $data;
    }

    public function getGiftGoods($uid) {
        $where = ['uid' => $uid, 'order_type' => 2, 'status' => ['in', '1,3,4']];
        $order_infos = Db::name('order_info')->where($where)->select();
        $num = 0;
        foreach ($order_infos as $v) {
            $num += floor($v['amount'] / 499);
        }
        return $num;
    }

    public function getFans($uid) {
        return Db::name('members')->where('parent_id', $uid)->select()->toArray();
    }

    public function getFansGoods($uid) {
        $child = $this->getFans($uid);
        $cate_id = Db::name('ali_cate')->where('name', '高佣爆款')->value('id');
        if (!$cate_id) throw new \Exception('没有找到高佣爆款专区');
        $num = 0;
        $money = 0;
        foreach ($child as $val) {
            $payamount = Db::name('ali_order')->alias('o')
                ->join('ali_product_v2 p', 'o.feedId = p.feedId')
                ->join('ali_selection s', 'p.groupId = s.groupId')
                ->field('p.feedId,s.cateId,o.payamount')
                ->where(['o.uid' => $val['id'], 's.cateId' => $cate_id, 'o.status' => 3])
                ->sum('o.payamount');
            if ($payamount) {
                $num++;
                $money += $payamount;
            }
        }
        return [$num, $money];
    }

    /**
     * 查找团队消费
     * @param int $uid
     * @param int $star
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function teamConsume($uid, $star) {
        $num = Db::name('members')->where('parent_id', $uid)->where('star', '>=', $star)->count('id');
        $data = BalanceService::getTeamPerformance($uid);
        return [$num, $data['total']];
    }

    public function zhanggui(&$user) {

        $info = ConfigNew::getUpZhangGui();
        if (!empty($info['gift_goods'])) {
            $giftGoods = $info['gift_goods'];
            $count = $this->getGiftGoods($user['id']);
            if ($count >= $giftGoods['quantity']) {
                return $this->upStar($user['id'], $user['star'], self::ZHANG_GUI, '购买礼包', self::BUY_GIFT, $count);
            }
        }
        if (!empty($info['fans'])) {
            $fans = $info['fans'];
            $count = count($this->getFans($user['id']));
            if ($count >= $fans['quantity']) {
                return $this->upStar($user['id'], $user['star'], self::ZHANG_GUI, '直推粉丝', self::ZHITUI, 0, $count);
            }
        }
        if (!empty($info['fans_goods'])) {
            $fans_goods = $info['fans_goods'];
            list($num, $money) = $this->getFansGoods($user['id']);
            if ($num >= $fans_goods['fans'] && $money >= $fans_goods['money']) {
                return $this->upStar($user['id'], $user['star'], self::ZHANG_GUI, '直推粉丝购物', self::TEAM_CONSUME, 0, $num, $money);
            }
        }
    }

    public function dianzhu(&$user) {
    }

    /**
     * @param int $uid 用户id
     * @param array $zhitui 升级参数
     * @param int $selfStar 自己当前的等级
     * @param int $upStar 要升级的等级
     * @return bool
     * @throws \Exception
     */
    protected function zhitui($uid, $zhitui, $selfStar, $upStar) {
        if (!empty($zhitui)) {
            list($num, $money) = $this->teamConsume($uid, $selfStar);
            if ($num >= $zhitui['quantity']) {
                return $this->upStar($uid, $selfStar, $upStar, '团队消费', self::TEAM_CONSUME, 0, $num, $money);
            }
        }
    }

    public function jingxiaoshang(&$user) {
        $info = ConfigNew::getUpJingXiaoShang();
        if (!empty($info['gift_goods'])) {
            $giftGoods = $info['gift_goods'];
            $count = $this->getGiftGoods($user['id']);
            if ($count >= $giftGoods['quantity']) {
                return $this->upStar($user['id'], $user['star'], self::JING_XIAO_SHANG, '购买礼包', self::BUY_GIFT, $count);
            }
        }
        if (!empty($info['zhitui'])) $this->zhitui($user['id'], $info['zhitui'], $user['star'], self::JING_XIAO_SHANG);
    }

    public function qudai(&$user) {
        $info = ConfigNew::getUpQuDai();
        if (!empty($info['zhitui'])) $this->zhitui($user['id'], $info['zhitui'], $user['star'], self::QU_DAI);
    }

    public function shidai(&$user) {
        $info = ConfigNew::getUpShiDai();
        if (!empty($info['zhitui'])) $this->zhitui($user['id'], $info['zhitui'], $user['star'], self::SHI_DAI);
    }

    public function shengdai(&$user) {
        $info = ConfigNew::getUpShengDai();
        if (!empty($info['zhitui'])) $this->zhitui($user['id'], $info['zhitui'], $user['star'], self::SHENG_DAI);
    }

    /**
     * 升级并写日志
     * @param int $uid 用户id
     * @param int $old_star 用户源等级
     * @param int $star 升级的等级
     * @param string $msg 信息
     * @param $type 1.礼包购买2.团队消费3后台修改
     * @param int $gift_num 礼包数量
     * @param int $peo_num 直推数量
     * @param int $total_amount 团队总销售金额
     * @return bool
     * @throws \Exception
     */
    static public function upStar($uid, $old_star, $star, $msg, $type, $gift_num = 0, $peo_num = 0, $total_amount = 0) {
        Db::startTrans();
        try {
            if (!Db::name('members')->where('id', $uid)->setField('star', $star)) throw new \Exception('等级更新失败 ');
            $data = [];
            $data['uid'] = $uid;
            $data['old_star'] = $old_star;
            $data['star'] = $star;
            $data['gift_num'] = $gift_num;
            $data['peo_num'] = $peo_num;
            $data['month_amount'] = $total_amount;
            $data['type'] = $type;
            $data['type_name'] = $msg;
            $data['create_time'] = date('Y-m-d H:i:s');
            if (!Db::name("members_star_log")->insert($data)) throw new \Exception('用户升级日志插入失败!');
            Db::commit();
            UpgradeService::objectInit()->userUpCheck($uid, 1);
        } catch (\Exception $e) {
            Db::rollback();
            throw new \Exception($e->getMessage());
        }
        return 1;
    }

//    /**
//     * 用户升级检测,如果符合条件,则升级
//     * @return array
//     * @throws \think\Exception
//     * @throws \think\db\exception\DataNotFoundException
//     * @throws \think\db\exception\ModelNotFoundException
//     * @throws \think\exception\DbException
//     */
//    public function userUpgradeCheck()
//    {
//        $uid = $this->uid;
//        //获取当前星级,获取当前升级条件
//        $star = $this->member['star'];
//
//        $starTemp = $star + 1;
//        $conf = Db::name('config_reward')->where('type', 1)->where('star', $starTemp)->find();
//        if ($star > 5) {
//            return ['status' => true, 'msg' => '恭喜您已经达到了顶点'];
//        }
//        //推荐
//        if ($star == 0) {
//            $directPush = Db::name('members')->field('id')->where('parent_id', $uid)->select()->toArray();
//            $buyNum = 0;
//            $money = 0;
//            foreach ($directPush as $v) {
//                $condition = Db::name('ali_order')->alias('o')
//                    ->join('ali_product p', 'o.feedId = p.feedId')
//                    ->join('ali_selection s', 'p.groupId = s.groupId')
//                    ->field('p.feedId,s.cateId,o.payamount')
//                    ->where(['o.uid' => $v['id'], 's.cateId' => 36, 'o.status' => 3])
//                    ->sum('o.payamount');
//                if ($condition) {
//                    $buyNum++;
//                    $money += $condition;
//                }
//            }
//            if ($buyNum >= 5 && $money) {
//                $result = self::userUpgrade($uid, $starTemp, $star, 0, $buyNum, 0, "推荐购买高佣爆卖专区", 2);
//                if ($result) {
//                    return ['status' => true, 'msg' => '升级成功'];
//                }
//            }
//        }
//        //礼包升级
//        if ($star < 2) {
//            $count = Db::name('order_info')->alias('oi')->join('order_detail od', 'oi.order_no= od.order_no')
//                ->where('oi.uid', $uid)->where('oi.order_type', 2)->where('oi.status', 'in', '1,3,4')->sum('od.goods_num');
//            //礼包订单数量满足升级条件,升级
//            if ($count >= $conf['up2_p']) {
//                //用户升级,并写入记录
//                $result = self::userUpgrade($uid, $starTemp, $star, $count, 0, 0, "购买礼包", 1);
//                if ($result) {
//                    return ['status' => true, 'msg' => '升级成功'];
//                } else {
//                    return ['status' => false, 'msg' => '升级失败'];
//                }
//            } else {
//                return ['status' => false, 'msg' => '不满足升级条件'];
//            }
//        }
//        //直推人数
//        $zt_count = Db::name('members')->where('parent_id', $uid)->where('star', $star)->count('id');
//        if ($zt_count >= $conf['up1_p']) {
//            //获取用户的团队业绩
//            $total = BalanceService::getTeamPerformance($uid);
//            if ($total['total'] >= $conf['up1_m']) {
//                //用户升级并写入记录
//                $result = self::userUpgrade($uid, $starTemp, $star, 0, $zt_count, $total, "直推升与月消费", 2);
//                if ($result) {
//                    return ['status' => true, 'msg' => '升级成功'];
//                } else {
//                    return ['status' => false, 'msg' => '升级失败'];
//                }
//            } else {
//                return ['status' => false, 'msg' => '业绩未达标'];
//            }
//        } else {
//            return ['status' => false, 'msg' => '推荐人数未达标'];
//        }
//    }

    /**
     * 用户升级并写入记录
     * @param $uid 用户uid
     * @param $starTemp 新的等级
     * @param $star 旧等级
     * @param $count 购物总数
     * @param $zt_count 推荐总数
     * @param $total 总业绩
     * @param $msg 升级原因
     * @param $type 升级类型
     * @return bool|\Exception
     */
    static public function userUpgrade($uid, $starTemp, $star, $count, $zt_count, $total, $msg, $type) {
        Db::startTrans();
        try {
            $result = Db::name('members')->where('id', $uid)->setField('star', $starTemp);
            $data = [];
            $data['uid'] = $uid;
            $data['old_star'] = $star;
            $data['star'] = $starTemp;
            $data['gift_num'] = $count;
            $data['peo_num'] = $zt_count;
            $data['month_amount'] = $total;
            $data['type'] = $type;
            $data['type_name'] = $msg;
            $data['create_time'] = date('Y-m-d H:i:s', time());
            $result2 = Db::name("members_star_log")->insertGetId($data);
            if ($result && $result2) {
                Db::commit();
                return true;
            }
        } catch (\Exception $e) {
            return $e;
        }
    }
}
