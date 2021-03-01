<?php


namespace app\api\service;


use app\api\model\ConfigNew;
use app\api\model\MemberBalanceLog;
use app\api\model\MemberBalance;
use think\Cache;
use think\Db;

class MemberBalanceService extends \app\api\BaseService {

    protected $order_no = '';

    public function getWallet($mid) {
        return MemberBalance::where('uid', $mid)->find();
    }

    private function balanceLog($mid, $num, $title, $source, $type, $balance_type) {
        $data = [
            'uid' => $mid,
            'type' => $type,
            'source' => $source,
            'source_name' => $title,
            'balance_type' => $balance_type,
            'balance' => 0,
            'finish_time' => date('Y-m-d H:i:s'),
            'create_time' => date('Y-m-d H:i:s'),
            'order_id' => $this->order_no
        ];
        switch ($balance_type) {
            case '通证':
                $data['tz_coupon'] = $num;
                break;
            case '贡献值':
                $data['gxz'] = $num;
                $data['calculator'] = $num;
                break;
            case '商家货款':
            case '运营中心收益':
            case '余额':
                $data['balance'] = $num;
                break;
            case '预估收益':
                $data['estimate_balance'] = $num;
                break;
            case '红包':
                $data['balance'] = $num;
                break;
        }
        $this->order_no = '';
        return MemberBalanceLog::create($data);
    }

    /**
     * 检测是否已经发放了
     * @param $mid
     * @param $type
     * @param $title
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    private function checkIsGive($mid, $type, $title) {
        if (!empty($this->order_no)) {
            if (MemberBalanceLog::_whereCV(['uid' => $mid, 'type' => $type, 'source_name' => $title, 'order_id' => $this->order_no])->find() !== null)
                throw new \Exception('已发放了');
        }
    }

    /**
     *
     * @param int $mid 用户id
     * @param string $field gxz|tz|balance
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @param string $type 增加|减少
     * @return int
     * @throws \think\exception\PDOException
     */
    protected function update($mid, $field, $num, $title, $source, $type) {
        $this->checkIsGive($mid, $type, $title);
        try {
            MemberBalance::startTrans();
            $balance = MemberBalance::where('uid', $mid)->find();
            if ($balance === null) throw new \Exception('未找到用户钱包!');
            $balance_type = $this->$field($balance, $num, $type);
            if ($balance->isUpdate()->save()) {
                $this->balanceLog($mid, $num, $title, $source, $type, $balance_type);
            }
            MemberBalance::commit();
        } catch (\Exception $e) {
            MemberBalance::rollback();
            throw new \Exception($e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine());
        }
        return 1;
    }

    private function gxz($balance, $num, $type) {
        if ($type == '增加') {
            $balance['gxz'] += abs($num);
            $balance['calculator'] += abs($num);
        } elseif ($type == '减少') {
            //$balance['gxz'] -= abs($num);
            //if ($balance['gxz'] < 0) throw new \Exception('贡献值不足');
            $balance['calculator'] -= abs($num);
            if ($balance['calculator'] < 0) throw new \Exception('榴莲值不足');
        }
        return '贡献值';
    }

    private function tz($balance, $num, $type) {
        if ($type == '增加') {
            $balance['tz_coupon'] += abs($num);
        } elseif ($type == '减少') {
            $balance['tz_coupon'] -= abs($num);
            if ($balance['tz_coupon'] < 0) throw new \Exception('通证不足');
        }
        return '通证';
    }

    private function balance($balance, $num, $type) {
        if ($type == '增加') {
            $balance['balance'] += abs($num);
        } elseif ($type == '减少') {
            $balance['balance'] -= abs($num);
            if ($balance['balance'] < 0) throw new \Exception('余额不足');
        }
        return '余额';
    }
    private function ocBalance($balance, $num, $type) {
        if ($type == '增加') {
            $balance['oc_balance'] += abs($num);
            $balance['oc_total_balance'] += abs($num);
        } elseif ($type == '减少') {
            $balance['oc_balance'] -= abs($num);
            if ($balance['oc_balance'] < 0) throw new \Exception('余额不足');
        }
        return '运营中心收益';
    }
    private function redpacketBalance($balance, $num, $type) {
        if ($type == '增加') {
            $balance['redpacket'] += abs($num);
        } elseif ($type == '减少') {
            $balance['redpacket'] -= abs($num);
            if ($balance['redpacket'] < 0) throw new \Exception('红包不足');
        }
        return '红包';
    }

    private function shopBalance($balance, $num, $type) {
        if ($type == '增加') {
            $balance['shop_balance'] += abs($num);
        } elseif ($type == '减少') {
            $balance['shop_balance'] -= abs($num);
            if ($balance['shop_balance'] < 0) throw new \Exception('店铺货款不足');
        }
        return '商家货款';
    }

    private function estimateBalance($balance, $num, $type) {
        if ($type == '增加') {
            $balance['estimate_balance'] += abs($num);
        } elseif ($type == '减少') {
            $balance['estimate_balance'] -= abs($num);
            if ($balance['estimate_balance'] < 0) throw new \Exception('预估收益低于0');
        }
        return '预估收益';
    }

    /**
     * 增加贡献值
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function addGxz($mid, $num, $title, $source) {
        return $this->update($mid, 'gxz', $num, $title, $source, '增加');
    }

    /**
     * 减少贡献值
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function subGxz($mid, $num, $title, $source) {
        return $this->update($mid, 'gxz', $num, $title, $source, '减少');
    }

    /**
     * 增加通证
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function addTz($mid, $num, $title, $source) {
        return $this->update($mid, 'tz', $num, $title, $source, '增加');
    }

    /**
     * 减少通证
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function subTz($mid, $num, $title, $source) {
        return $this->update($mid, 'tz', $num, $title, $source, '减少');
    }

    /**
     * 增加余额
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function addBalance($mid, $num, $title, $source) {
        return $this->update($mid, 'balance', $num, $title, $source, '增加');
    }

    /**
     * 减少余额
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function subBalance($mid, $num, $title, $source) {
        return $this->update($mid, 'balance', $num, $title, $source, '减少');
    }

    /**
     * 减少红包
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function subRedpacket($mid, $num, $title, $source) {
        return $this->update($mid, 'redpacketBalance', $num, $title, $source, '减少');
    }

    /**
     * 增加商家余额
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function addShopBalance($mid, $num, $title, $source) {
        return $this->update($mid, 'shopBalance', $num, $title, $source, '增加');
    }

    /**
     * 减少商家余额
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function subShopBalance($mid, $num, $title, $source) {
        return $this->update($mid, 'shopBalance', $num, $title, $source, '减少');
    }

    /**
     * 增加运营中心余额
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function addOcBalance($mid, $num, $title, $source) {
        return $this->update($mid, 'ocBalance', $num, $title, $source, '增加');
    }

    /**
     * 减少运营中心余额
     * @param int $mid 用户id
     * @param float $num 金额
     * @param string $title 日志标题
     * @param string $source 日志类型
     * @return int
     * @throws \Exception
     */
    protected function subOcBalance($mid, $num, $title, $source) {
        return $this->update($mid, 'ocBalance', $num, $title, $source, '减少');
    }

    /**
     * 增加预估收益
     */
    protected function addEstimateBalance($mid, $num, $title, $source) {
        return $this->update($mid, 'estimateBalance', $num, $title, $source, '增加');
    }

    /**
     * 减少预估收益
     * @param $mid
     * @param $num
     * @param $title
     * @param $source
     * @return int
     * @throws \think\exception\PDOException
     */
    protected function subEstimateBalance($mid, $num, $title, $source) {
        return $this->update($mid, 'estimateBalance', $num, $title, $source, '减少');
    }

    /**
     * 任务中心分享成功送贡献值
     * @param $mid
     * @param $gxz
     */
    public function taskShareGoods($mid, $gxz) {
        $model = MemberBalanceLog::objectInit();
        $start_time = date('Y-m-d');
        $info = $model->whereCV(['uid' => $mid, 'source' => '任务中心', 'balance_type' => '贡献值', 'source_name' => '分享商品', 'create_time' => ['>', $start_time]])->find();
        if ($info !== null) throw new \Exception('分享成功!');
        $this->addGxz($mid, $gxz, '分享商品', '任务中心');
        return 1;
    }

    /**
     * 看视频广告成功送贡献值
     * @param $mid
     * @return int
     * @throws \think\Exception
     */
    public function taskViewAdvertisingSuccess($mid) {
        $config = ConfigNew::getViewAdvertising();
        $model = MemberBalanceLog::objectInit();
        $start_time = date('Y-m-d');
        $cache_key = 'view_advertising' . $mid;
        if (Cache::get($cache_key)) throw new \Exception('观看完成!');
        $this->addGxz($mid, $config['gxz'], '观看广告', '任务中心');
        $num = $model->whereCV(['uid' => $mid, 'source' => '任务中心', 'balance_type' => '贡献值', 'source_name' => '观看广告', 'create_time' => ['>=', $start_time]])->count('id');
        if ($num % 2 === 0) {
            Cache::set($cache_key, 1, 3600 * 3);
        }
        return 1;
    }

    /**
     * 签到成功送贡献值
     * @param $mid
     * @param $num
     * @param null $config
     * @return int
     * @throws \Exception
     */
    public function signInSuccess($mid, $num, $config = null) {
        if ($config === null) {
            $config = ConfigNew::getSignIn();
        }
        $this->addGxz($mid, $config['signIn'], '每日签到', '任务中心');
        if ($num % 7 === 0) {
            $this->addGxz($mid, $config['signIn7'], '连续7天签到', '任务中心');
        }
        return 1;
    }

    /**
     * 闪电玩充值成功后送贡献值
     * @param $mid
     * @param $num
     * @return int
     * @throws \Exception
     */
    public function sdwSuccess($mid, $order_no, $num) {
        $this->order_no = $order_no;
        $this->addGxz($mid, $num, '游戏充值', '任务中心');
        return 1;

    }

    /**
     * 获取指定时间 指定类型的贡献值
     * @param int $mid 会员id
     * @param null $type 类型
     * @param null $start_time 开始时间 时间戳格式
     * @param null $end_time 结束时间 时间戳格式
     * @return float|int|string|null
     */
    public function getSumGxz($mid, $type = null, $start_time = null, $end_time = null) {
        $model = $this->getSumModel($mid, $type, $start_time, $end_time);
        return $model->sum('gxz');
    }

    protected function getSumModel($mid, $type = null, $start_time = null, $end_time = null) {
        $model = MemberBalanceLog::objectInit();
        $model->where('uid', $mid);
        if ($type !== null) {
            $model->whereCV('source', $type);
        }
        if ($start_time !== null) {
            $model->where('create_time', '>=', date('Y-m-d H:i:s', $start_time));
        }
        if ($end_time !== null) {
            $model->where('create_time', '<=', date('Y-m-d H:i:s', $end_time));
        }
        return $model;
    }

    /**
     * 会员提现
     * @param $mid
     * @param $money
     * @param $type
     * @param bool $rejected true 驳回
     * @return void
     * @throws \Exception
     */
    public function memberWithdraw($mid, $money, $type, $rejected = false) {
        $fn = $rejected ? 'add' : 'sub';
        $msg = '提现(' . $type . ')' . ($rejected ? '驳回' : '');
        switch ($type) {
            case '余额':
                $fn .= 'Balance';
                $this->$fn($mid, $money, $msg, '提现');
                break;
            case '通证':
                $fn .= 'Tz';
                $this->$fn($mid, $money, $msg, '提现');
                break;
            case '红包':
                $this->subRedpacket($mid, $money, '提现(' . $type . ')', '提现');
                break;
            default:
                throw new \Exception('未知类型!!!');
        }
    }

    /**
     *获取商家钱包总金额
     */
    public function getShopBalanceMoney($mid) {
        $info = MemberBalance::where('uid', $mid)->find();
        if ($info === null) {
            throw new \Exception('未找到商家钱包!');
        }
        return $info['shop_balance'];
    }

    /**
     * 商家提现
     * @param $mid
     * @param $money
     * @param bool $rejected true 驳回
     * @return int
     * @throws \Exception
     */
    public function shopWithdraw($mid, $money, $rejected = false) {
        $msg = '提现(店铺货款)' . ($rejected ? '驳回' : '');
        if ($rejected) {
            $this->addShopBalance($mid, $money, $msg, '提现');
        } else {
            $this->subShopBalance($mid, $money, $msg, '提现');
        }
    }

    /**
     * 运营中心提现
     * @param $mid
     * @param $money
     * @param bool $rejected true 驳回
     * @return int
     * @throws \Exception
     */
    public function ocWithdraw($mid, $money, $rejected = false) {
        $msg = '提现(运营中心)' . ($rejected ? '驳回' : '');
        if ($rejected) {
            $this->addOcBalance($mid, $money, $msg, '提现');
        } else {
            $this->subOcBalance($mid, $money, $msg, '提现');
        }
    }

    /**
     * 拼团增加预估收益
     * @param $mid
     * @param $money
     * @param $order_no
     */
    public function pingGroupEstimateBalance($mid, $money, $order_no) {
        try {
            Db::startTrans();
            $this->addEstimateBalance($mid, $money, '开团预估收益', '预估收益');
            MemberEstimateBalanceService::objectInit()->pingGroup($mid, $order_no, $money);//添加预估收益
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            Db::name('error_log')->insert([
                'source' => '增加预估收益',
                'desc' => $e->getMessage() . $e->getFile() . $e->getLine(),
                'uid' => $mid,
                'order' => $order_no,
                'date' => date('Y-m-d H:i:s'),
            ]);
            throw new \Exception($e->getMessage());
        }
        return 1;
    }

    /**
     * 拼团发放预估收益
     * @param $mid
     * @param $money
     * @param $order_no
     */
    public function givePingGroupEstimateBalance($mid, $order_no) {
        try {
            Db::startTrans();
            $info = MemberEstimateBalanceService::objectInit()->giveEstimateBalance($mid, $order_no);
            $this->subEstimateBalance($info['uid'], $info['balance'], '开团预估收益转余额', '预估收益');
            $this->PingGroupOrder($info['uid'], $info['balance']);//增加拼团收益
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            Db::name('error_log')->insert([
                'source' => '发放预估收益',
                'desc' => $e->getMessage() . $e->getFile() . $e->getLine(),
                'uid' => $mid,
                'order' => $order_no,
                'date' => date('Y-m-d H:i:s'),
            ]);
            throw new \Exception($e->getMessage());
        }

    }

    /**
     * 团长佣金收益
     * @param $mid
     * @param $money
     * @return int
     * @throws \Exception
     */
    public function PingGroupOrder($mid, $money) {
        return $this->addBalance($mid, $money, '拼团收益', '获赠');
    }

    /**
     * 团长押金返还
     * @param $mid
     * @param $money
     * @return int
     * @throws \Exception
     */
    public function PingGroupOpenReturn($mid, $money) {
        return $this->addBalance($mid, $money, '拼团押金返还', '获赠');
    }

    /**
     * 红包收益
     * @param $mid
     * @param $money
     * @return int
     * @throws \Exception
     */
    public function redPacket($mid, $money) {
        return $this->addBalance($mid, $money, '红包收益', '获赠');
    }

    /**
     * 余额抵扣扣款
     * @param $mid
     * @param $money
     * @param $order_no
     * @param bool $return 是否退单
     * @return int
     * @throws \Exception
     */
    public function balancePayOrder($mid, $money, $order_no, $return = false) {
        $this->order_no = $order_no;
        if ($return) {
            return $this->addBalance($mid, $money, '余额抵扣退还', '退单');
        } else {
            return $this->subBalance($mid, $money, '余额支付抵扣', '支付');
        }
    }

    /**
     * 通证抵扣扣款
     * @param $mid
     * @param $money
     * @param $order_no
     * @param bool $return 是否退单
     * @return int
     * @throws \Exception
     */
    public function tzPayOrder($mid, $money, $order_no, $return = false) {
        $this->order_no = $order_no;
        if ($return) {
            return $this->addTz($mid, $money, '通证抵扣退还', '退单');
        } else {
            return $this->subTz($mid, $money, '通证支付抵扣', '支付');
        }
    }

    /**
     * @param $mid
     * @param $num
     * @param $type
     * @param $fn
     */
    public function chouJiang($mid, $num, $type, $fn){
        if ($type === '贡献值') {
            $fn .= 'Gxz';
            $this->$fn($mid, $num, '抽奖' . ($fn === 'addGxz' ? '增加' : '减少') . '贡献值', '抽奖');
        } elseif ($type === '余额') {
            $fn .= 'Balance';
            $this->$fn($mid, $num, '抽奖' . ($fn === 'addBalance' ? '增加' : '减少') . '余额', '抽奖');
        }

    }
}
