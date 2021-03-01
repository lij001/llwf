<?php

namespace app\api\service;

use think\Db;

class BalanceService {
    public function __construct($uid) {
        $this->uid = $uid;
        $this->balance = Db::name('user_balance')->where('uid', $uid)->find();
    }

    /**
     * 获取用户的留莲券
     * @param $uid
     */
    public function getUserLLj() {
        $llj_new = $this->balance['llj'];
        $llj_used = Db::name('llj_log')->where('uid', $this->uid)->where('val', '<', 0)->sum('val');
        $llj_all = $llj_new + abs($llj_used);
        return [
            'llj_new' => $llj_new,
            'llj_used' => abs($llj_used),
            'llj_all' => $llj_all
        ];
    }

    public function lljLogList($param) {
        return Db::name('llj_log')->where('uid', $this->uid)->order('id desc')->paginate(10, false, ['page' => $param['start']]);
    }

    /**
     * 获取用户余额/通证/贡献值/今日新增通证
     * @param $uid 用户id
     * @return array|bool|false|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getBalance($head = []) {
        $todayTz = Db::name('tz_log')->where(['uid' => $this->uid, 'date' => date('Y-m-d 00:00:00', time())])->value('tzNum');
        $user_balance = $this->balance;
        if (!empty($todayTz)) {
            $user_balance['todayTz'] = $todayTz;
        } else {
            $user_balance['todayTz'] = 0;
        }
//        if (!empty($head)) {
//            Db::name('test')->insert(['param' => '版本:' . $head['version'] . ',系统:' . $head['system']]);
//        }
        return $user_balance;
    }

    /**
     * 获取用户钱包搜索条件
     * @param $type
     * @return mixed
     */
    public function getBalanceWhere(&$type) {
        switch ($type) {
            case '余额':
                $type = 'balance';
                $where[$type] = ['>', 0];
                $where['balance_type'] = 1;
                break;
            case '贡献值':
                $type = 'gxz';
                $where[$type] = ['>', 0];
                break;
            case '留莲值':
                $type = 'calculator';
                $where[$type] = ['>', 0];
                break;
            case '通证':
                $type = 'tz_coupon';
                $where[$type] = ['>', 0];
                break;
            case '红包':
                $type = 'balance';
                $where[$type] = ['>', 0];
                $where['balance_type'] = 7;
                break;
            default:
                return $this->exitJson(2, 'error');
        }
        return $where;
    }

    /**
     * 余额详情
     * @param $type
     * @param $page
     * @param $addSub
     * @return mixed
     * @throws \think\exception\DbException
     */
    public function balanceDetials($type, $page, $addSub) {
        $where = $this->getBalanceWhere($type);
        $where['uid'] = $this->uid;
        $addSub !== null ? $where['type'] = $addSub : null;
        $user_balance_log = Db::name('user_balance_log')->where($where)->order('create_time desc')->paginate(10)->toArray()['data'];
        return $user_balance_log;
    }

    /**
     * 总计获得余额/通证/贡献值
     * @param $type
     * @param $page
     * @return int|string
     * @throws \think\Exception
     */
    public function balanceAdd($type, $page) {
        $where = $this->getBalanceWhere($type);
        $where['uid'] = $this->uid;
        $where['type'] = 1;
        $user_balance_sum = Db::name('user_balance_log')->where($where)->order('create_time desc')->sum($type);
        if ($type == 'tz_coupon') {
            $user_balance_sum = Db::name('tz_log')->where('uid', $this->uid)->sum('tzNum');
        }
        return $user_balance_sum;
    }

    /**
     * 总计消费余额/通证/贡献值
     * @param $type
     * @param $page
     * @return int|string
     * @throws \think\Exception
     */
    public function balanceSub($type, $page) {
        $where = $this->getBalanceWhere($type);
        $where['uid'] = $this->uid;
        $where['type'] = 0;
        $user_balance_log = Db::name('user_balance_log')->where($where)->order('create_time desc')->sum($type);
        return $user_balance_log;
    }

    public function tzDetials($page = 1) {
        $page < 1 ? $page = 1 : null;
        $tz_log = Db::name('tz_log')->where('uid', $this->uid)->order('date desc')->page($page, 10)->select()->toArray();
        return $tz_log;
    }

    /**
     * 获取用户的团队业绩
     * @param $uid
     * @return bool|float|int|string|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    static public function getTeamPerformance($uid) {
        return ['total' => ''];
        $members = Db::name('members')->field('path,star')->where('id', $uid)->find();
        $mypath = $uid . "-" . $members['path'];
        //校验团队消费额
        $ids = Db::name("members")->field('id')->where("path", "like", "%$mypath")->select()->toArray();
        $ids = array_column($ids, 'id');
        //找到平级的团队
        $ids2 = Db::name("members")->field('id,path')->where("path", "like", "%$mypath")->where('star', '>=', $members['star'])->select()->toArray();
        foreach ($ids2 as $v) {
            foreach ($ids2 as $k => $v2) {
                $pathArr = explode('-', $v2['path']);
                if (in_array($v['id'], $pathArr)) {
                    unset($ids2[$k]);
                }
            }
        }
        $team = [];
        foreach ($ids2 as $v) {
            $pathHere = $v['id'] . '-' . $v['path'];
            $findTeam = Db::name("members")->field('id')->where("path", "like", "%$pathHere")->select()->toArray();
            $team = array_merge($team, $findTeam);
        }
        $team = array_column($team, 'id');
        //剔除平级用户团队
        $ids = array_diff($ids, $team);
        //计算团队业绩
        $teamTotal = 0;
        foreach ($ids as $v) {
            $teamTotal += Db::name('pay_info')->where(['pay_status' => 1, 'uid' => $v])->sum('pay_amount');
            $teamTotal -= Db::name('ali_order')->alias('o')
                ->join('pay_info p', 'o.order_no = p.order_no')
                ->where(['p.pay_status' => 1, 'o.uid' => $v, 'o.status' => 4])
                ->sum('o.total');
        }
        $myTotal = Db::name('pay_info')->where(['pay_status' => 1, 'uid' => $uid])->sum('pay_amount');
        $myTotal -= Db::name('ali_order')->alias('o')
            ->join('pay_info p', 'o.order_no = p.order_no')
            ->where(['p.pay_status' => 1, 'o.uid' => $v, 'o.status' => 4])
            ->sum('o.total');
        return ['teamTotal' => $teamTotal, 'myTotal' => $myTotal, 'total' => $teamTotal + $myTotal];
    }
}
