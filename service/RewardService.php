<?php


namespace app\api\service;


use app\BaseService;
use think\Db;

class RewardService extends BaseService
{
    protected $userTreePro = null;

    public function findUserTeam()
    {
        if ($this->userTreePro === null) {
            $members = Db::name('members')->field('id,parent_id pid,star,path')->select();
            $data = [];
            foreach ($members as $v) {
                $v['list'] = [];
                $data[$v['id']] = $v;
            }
            foreach ($members as $key => $v) {
                $data[$v['pid']]['list'][] = &$data[$v['id']];
            }
            $this->userTreePro = $data;
        }
        return $this->userTreePro;
    }

    public function giveLibaoReward($uid, $order_no, $goods_num, $number = 0)
    {
        Db::startTrans();
        try {
            $userTree = $this->findUserTeam();
            //获取用户直推关系树
            $user = $userTree[$uid];
            $parent_ids = explode('-', $user['path']);
            $parentArr = [];
            foreach ($parent_ids as $id) {
                $parentArr[$id] = [
                    'pid' => $userTree[$id]['pid'],
                    'star' => $userTree[$id]['star']
                ];
            }
            $userTree = [];
            $minStar = $user['star'];
            $maxStar = 6;
            foreach ($parentArr as $k => $v) {
                if ($minStar < $v['star']) {
                    $minStar = $v['star'];
                }
                for ($i = $minStar; $i <= $maxStar; $i++) {
                    if ($v['star'] == $i && $v['star'] >= $minStar) {
                        if (count($userTree[$i]) < 3) {
                            $userTree[$i][] = [
                                'id' => $k,
                                'star' => $parentArr[$k]['star']
                            ];
                        }
                    }
                }
            }
            //获取用户分佣配置,包括直推奖/推荐奖/平级奖/总推荐奖金额
            $reward = $this->rewardConfig();
            //生成分佣关系树,并计算每位用户应该获得多少分佣
            if (current(current($userTree)) === false || current($userTree) === false) return 0;
            foreach ($userTree as $k => $v) {
                if ($reward[$v[0]['star']]['dl'] > 0) $v[0] ? $userTree[$k][0]['reward'] = $reward[$v[0]['star']]['dl'] : null;
                if ($reward[$v[1]['star']]['ping1'] > 0) $v[1] ? $userTree[$k][1]['ping1'] = $reward[$v[1]['star']]['ping1'] : null;
                if ($reward[$v[2]['star']]['ping2'] > 0) $v[2] ? $userTree[$k][2]['ping2'] = $reward[$v[2]['star']]['ping2'] : null;
            }
            reset($userTree);
            $userTree[key($userTree)][0]['zhitui'] = $reward[$userTree[key($userTree)][0]['star']]['zt'];
            unset($userTree[key($userTree)][0]['reward']);
            //根据分佣树进行分佣
            foreach ($userTree as $v) {
                foreach ($v as $vv) {
                    if (isset($vv['zhitui'])) {
                        $this->giveEstimateBalance($vv['id'], $vv['zhitui'] * $goods_num, $order_no, '直推奖励');
                    }
                    if (isset($vv['reward'])) {
                        $this->giveEstimateBalance($vv['id'], $vv['reward'] * $goods_num, $order_no, '代理佣金');
                    }
                    if (isset($vv['ping1'])) {
                        $this->giveEstimateBalance($vv['id'], $vv['ping1'] * $goods_num, $order_no, '平级一代');
                    }
                    if (isset($vv['ping2'])) {
                        $this->giveEstimateBalance($vv['id'], $vv['ping2'] * $goods_num, $order_no, '平级二代');
                    }
                }
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
        }
    }

    public function giveLibaoReward2($uid, $order_no, $goods_num)
    {
        $tree = $this->findUserTeam();
        $user = $tree[$uid];
        $star = $user['star'];
        $pin = false;
        if ($user['pid'] == 0 || !isset($tree[$user['pid']])) return;
        if ($tree[$user['pid']]['star'] != 0 && $tree[$user['pid']]['star'] > $user['star']) {
            //直推奖励
            $pin = true;
            $star = $tree[$user['pid']]['star'];
        }
        $user = $tree[$user['pid']];
        $num = 1;
        while (1) {
            if ($user['star'] != 0 && $user['star'] >= $star) {
                if ($user['star'] > $star) {
                    $num = 1;
                    $star = $user['star'];
                    $pin = true;
                    //代理
                } elseif ($pin && $num < 3 && $star == $user['star']) {
                    //平级代理
                    $num++;
                }
            }
            if ($user['star'] == 6 && $num == 3) break;

            if ($user['pid'] != 0 && isset($tree[$user['pid']])) {
                $user = $tree[$user['pid']];
            } else {
                break;
            }
        }
    }

    public function rewardConfig()
    {
        $reward_new = Db::name('config_reward_new')->field('star,zt,dl,ping1,ping2')->select()->toArray();
        $rewardArr = [];
        foreach ($reward_new as $v) {
            $rewardArr[$v['star']] = $v;
        }
        return $rewardArr;
    }

    public function giveEstimateBalance($uid, $money, $order_no, $desc = '')
    {
        $count = Db::name('user_estimate_balance')->where([
            'uid' => $uid,
            'order_no' => $order_no,
        ])->count();
        if (!$count) {
            $rights_balance = Db::name('user_balance')->where('uid', $uid)->value('rights_balance');
            if ($rights_balance >= 3000) {
                $auto_pay = Db::name('members')->where('id', $uid)->value('auto_pay');
                if ($auto_pay) {
                    RightsService::autoOrder($uid);
                } else {
                    return;
                }
            }
            Db::name('user_balance')->where('uid', $uid)->update([
                'estimate_balance' => Db::raw('estimate_balance+' . $money),
                'total' => Db::raw('total+' . $money),
                'rights_balance' => Db::raw('rights_balance+' . $money)
            ]);
            Db::name('user_estimate_balance')->insert([
                'balance' => $money,
                'team_balance' => $money,
                'uid' => $uid,
                'order_no' => $order_no,
                'create_time' => date('Y-m-d H:i:s', time()),
                'desc' => $desc
            ]);
        }
    }
}
