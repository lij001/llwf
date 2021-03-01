<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\redpacket\RedPacket;
use app\api\model\redpacket\RedPacketGxz;
use app\api\model\redpacket\RedPacketList;
use app\api\model\redpacket\RedPacketLog;
use think\Cache;
use think\Db;
use think\db\Query;
use think\Exception;


class RedPacketService extends BaseService {
    /**
     * 获取红包日志列表
     * @param $param
     * @return array|bool|false|\PDOStatement|string|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getRedPacketListLog($param) {
        $l = $this->getActivityListInfo();
        if ($l === null) throw new \Exception('红包活动暂未开启!');
        if (empty($param['mid'])) throw new \Exception('会员id不能为空!');
        $packet = RedPacket::_whereCV(['mid' => $param['mid'], 'list_id' => $l['id']])->find();
        if ($packet === null) throw new \Exception('你还未领取红包!');
        $packet['log_list'] = RedPacketLog::_whereCV(['mid' => $param['mid'], 'packet_id' => $packet['id']])->with(['member', 'cmMember'])->paginate();
        return $packet;
    }

    /**
     * 获取活动列表
     * @return array|bool|false|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function getActivityListInfo() {
        $time = time();
        $l = RedPacketList::_whereCV('status', '启用')->where(['start_time' => ['<', $time], 'end_time' => ['>', $time]])->find();
        return $l;
    }

    /**
     * 拼团首次领取
     * @param $mid
     * @return int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function pingGroupFirstGet($mid) {
        $i = Cache::get('pingGroupFirstGet');
        if ($i) {
            throw new \Exception('请勿重复领取!');
        }
        Cache::set('pingGroupFirstGet', 1, 1);
        if ($this->getPingGroupFirstGetLog($mid) === null) {
            $money = rand(90, 95);
            if (!$this->addMoney($mid, $mid, $money, '首次领取', '拼团')) {
                return 0;
            }
            return $money;
        }
        return 0;
    }

    /**
     * 开团红包
     * @param $mid
     * @return int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function openGroupRedPacket($mid) {
        if (PingGroupService::objectInit()->getMemberOpenCount($mid) <= 100) {
            $money = rand(1, 2) / 100;
            return $this->addMoney($mid, $mid, $money, '开团领红包', '拼团');
        }
        return 1;
    }

    /**
     * 用户添加红包
     * @param int $mid 受益人id
     * @param int $cm_mid 创建收益人的id
     * @param float $money 金额
     * @param string $title 标题
     * @param string $type 类型
     * @return int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    protected function addMoney($mid, $cm_mid, $money, $title, $type) {
        $list = $this->getActivityListInfo();
        if ($list === null) return 0;
        $pack = RedPacket::_whereCV(['mid' => $mid, 'list_id' => $list['id']])->find();
        if ($pack === null) {
            $pack = $this->createPacket($mid, $list);
        }
        if ($pack['status'] == '已领取' || $pack['money'] >= 100 || $pack['end_time'] < time()) {
            return 1;
        }
        $pack['money'] += $money;
        if ($pack->save()) {
            $this->addRedPacketLog($pack, $cm_mid, $money, $title, $type);
        }
        if ($pack['money'] >= 100) {
            MemberBalanceService::objectInit()->redPacket($pack['mid'], 100);
            $pack['status'] = '已领取';
            $pack->save();
        }
        return 1;
    }

    /**
     * 获取红包日志
     * @param $pack
     * @param $cm_mid
     * @param $money
     * @param $title
     * @param $type
     * @return RedPacketLog
     */
    protected function addRedPacketLog($pack, $cm_mid, $money, $title, $type) {
        $log = [
            'packet_id' => $pack['id'],
            'mid' => $pack['mid'],
            'cm_id' => $cm_mid,
            'title' => $title,
            'type' => $type,
            'money' => $money,
            'after_money' => $pack['money'],
            'create_time' => time(),
        ];
        return RedPacketLog::create($log, true);
    }

    /**
     * 创建红包
     * @param $mid
     * @param $list
     * @return RedPacket
     */
    private function createPacket($mid, $list) {
        $data = [
            'list_id' => $list['id'],
            'mid' => $mid,
            'money' => 0,
            'start_time' => $list['start_time'],
            'end_time' => $list['end_time'],
        ];
        return RedPacket::create($data, true);
    }

    /**
     * 获取首次领取红包日志
     * @param $mid
     * @return array|bool|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function getPingGroupFirstGetLog($mid) {
        $l = $this->getActivityListInfo();
        if ($l === null) return null;
        $packet = RedPacket::_whereCV(['mid' => $mid, 'list_id' => $l['id']])->find();
        if ($packet === null) return null;
        $log = RedPacketLog::_whereCV(['mid' => $mid, 'type' => '拼团', 'packet_id' => $packet['id'], 'title' => '首次领取'])->find();
        if ($log === null) return null;
        return $log;
    }

    /**
     * 邀请参团领红包
     * @param $mid
     * @param $cm_mid
     * @param $money
     * @return float|int
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function PingGroupCommission($mid, $cm_mid, $money) {
        $log = $this->getPingGroupFirstGetLog($mid);
        if ($log === null) return 0;
        $openGroupSumMoney = RedPacketLog::_whereCV(['mid' => $mid, 'type' => '拼团', 'packet_id' => $log['packet_id'], 'title' => '开团领红包'])->sum('money');
        $subMoney = $money * rand(10, 20) / 100;
        $redMoney = sprintf("%.2f", ((100 - $log['money'] - $openGroupSumMoney) * $subMoney / 100));
        if ($redMoney === 0) return 0;
        $this->addMoney($mid, $cm_mid, $redMoney, '邀请参团领红包', '拼团');
        return $subMoney;
    }

    /**
     * 发放贡献值红包
     * @param $mid
     * @param $money
     * @param $title
     * @return int|string
     */
    public function giveGxzRedpacket($mid, $from_mid, $money, $title, $type, $active = 1) {
        $redpacket = new RedPacketGxz();
        $insert = [
            'mid' => $mid,
            'from_mid' => $from_mid,
            'gxz' => $money,
            'title' => $title,
            'type' => $type
        ];
        $find = $redpacket->where($insert)->find();
        if (empty($find)) {
            $insert['status'] = 1;
            $insert['active'] = $active;
            $insert['create_time'] = time();
            $res = $redpacket->insertGetId($insert);
            if (!$res) throw new Exception('发放失败');
        } else {
            throw new Exception('红包已发放');
        }
        return $res;
    }

    /**
     * 领取红包
     * @param $packetId
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function receiveGxzRedPacket($mid, $packetId) {
        $redpacket = new RedPacketGxz();
        $find = $redpacket->where(['id' => $packetId, 'mid' => $mid])->find()->toArray();
        if (empty($find)) throw new Exception('红包不存在');
        if ($find['active'] == '未激活') throw new Exception('红包未激活,请邀请用户登陆app以激活红包');
        if ($find['status'] == '已领取' || $find['status'] == '过期') throw new Exception('红包已领取');
        $res = RightsService::giveGxz($find['mid'], $find['gxz'], $find['title']);
        if ($res) {
            $redpacket->where('id', $packetId)->update(['status' => '已领取']);
        } else {
            throw new Exception('领取失败');
        }
    }

    /**
     * 获取个人贡献值红包列表
     * @param $mid
     * @param null $status
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getGxzRedPacketList($mid, $status = null, $type = null, $active = 1) {
        $list = (new RedPacketGxz())->where(function (Query $query) use ($mid, $status, $type, $active) {
            $query->where('mid', $mid);
            if ($status !== null) $query->where('status', $status);
            if ($type !== null) $query->where('type', $type);
            if ($active !== null) $query->where('active', $active);
        })->select()->toArray();
        return ['data' => $list, 'num' => count($list)];
    }

    /**
     * 获取随机贡献值
     * @return float|int|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getRandomGxz() {
        $beilv = 100;
        $arr = Db::name('random_gxz')->where('status', 1)->select()->toArray();
        $jilvArr = array_column($arr, 'jilv');
        $total = array_sum($jilvArr) * $beilv;
        $rand = rand(1, $total);
        $index = 0;
        foreach ($arr as $v) {
            $s = $index;
            $e = $index += $v['jilv'] * $beilv;
            if ($s < $rand && $rand <= $e) {
                $start = $v['start'];
                $end = $v['end'];
            }
        }
        if ($start && $end) {
            $gxz = rand($start * $beilv, $end * $beilv) / $beilv;
        } else if (!$start && !$end) {
            $gxz = 0;
        } else if ($start) {
            $gxz = $start;
        } else {
            $gxz = $end;
        }
        return $gxz;
    }

    /**
     * 激活红包
     * @param $mid
     */
    public function activeRedpacket($mid) {
        RedPacketGxz::objectInit()->where([
            'from_mid' => $mid,
            'active' => 0
        ])->update(['active' => 1]);
    }
}
