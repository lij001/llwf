<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\member\MemberEstimateBalance;
use think\Db;

class MemberEstimateBalanceService extends BaseService {
    /**
     *
     * @param $uid
     * @param $order_no
     * @param $balance
     * @param $type
     * @param $desc
     */
    public function update($mid, $order_no, $balance, $type, $desc) {
        $info = MemberEstimateBalance::_whereCV(['uid' => $mid, 'order_no' => $order_no, 'type' => $type])->find();
        if ($info === null) {
            $info = MemberEstimateBalance::create([
                'uid' => $mid,
                'order_no' => $order_no,
                'balance' => 0,
                'team_balance' => 0,
                'create_time' => date('Y-m-d H:i:s'),
                'type' => $type,
                'status' => '未转',
                'desc' => $desc
            ]);
        }
        $info['balance'] += $balance;
        $info->save();

        return 1;
    }

    public function pingGroup($mid, $order_no, $reward) {
        return $this->update($mid, $order_no, $reward, '拼团', '开团收益');
    }



    /**
     * 发放收益
     */
    public function giveEstimateBalance($mid, $order_no) {
        $info = MemberEstimateBalance::_whereCV(['uid' => $mid, 'order_no' => $order_no])->find();
        $info['status'] = '已转';
        $info->save();
        return $info;
    }


}
