<?php


namespace app\api\service;


use app\api\BaseService;
use think\Db;

class RankingService extends BaseService {
    /**
     * 对阿里订单进行排行
     * @param $start
     * @param $end
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function forAli($start, $end) {
        $sql = Db::name('ali_order')->field('uid,sum(total) total,count(id) num')->where([
            'pay_status' => 1,
            'pay_time' => ['between', [$start, $end]],
            'status' => ['in', [1, 2, 3]]
        ])->group('uid')->buildSql();
        $totals = Db::table($sql)->alias('a')
            ->field('a.*,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.uid = m.id')
            ->order('total desc,num desc')
            ->page(1, 20)
            ->select()->toArray();
        $nums = Db::table($sql)->alias('a')
            ->field('a.*,m.mobile,m.nickname,m.realname,m.avatar')
            ->join('members m', 'a.uid = m.id')
            ->order('num desc,total desc')
            ->page(1, 20)
            ->select()->toArray();
        $m=Db::name('members')->field('id,mobile,nickname,realname,avatar')->where('id','120')->find();
        $m1=Db::name('members')->field('id,mobile,nickname,realname,avatar')->where('id','125')->find();
        array_unshift($totals,$m,$m1);
        $m=Db::name('members')->field('id,mobile,nickname,realname,avatar')->where('id','130')->find();
        array_unshift($nums,$m);
        self::nicknameDeal($totals);
        self::nicknameDeal($nums);
        return ['totals' => $totals, 'nums' => $nums];
    }

    /**
     * 用户显示昵称处理
     * @param $list
     */
    static function nicknameDeal(&$list) {
        foreach ($list as &$v) {
            if ($v['nickname'] == $v['realname']) {
                $v['nickname'] = '';
            }
            if (empty($v['nickname']) || $v['nickname'] == $v['mobile'] || is_numeric($v['nickname'])) {
                if (empty($v['realname'])) {
                    $v['nickname'] = '尾号' . substr($v['mobile'], -4);
                } else {
                    switch (mb_strlen($v['realname'])) {
                        case 2:
                            $v['nickname'] = mb_substr($v['realname'], 0, 1) . '*';
                            break;
                        case 3:
                            $v['nickname'] = mb_substr($v['realname'], 0, 1) . '*' . mb_substr($v['realname'], -1);
                            break;
                        case 4:
                            $v['nickname'] = mb_substr($v['realname'], 0, 1) . '**' . mb_substr($v['realname'], -1);
                            break;
                        default:
                            $v['nickname'] = $v['realname'];
                            break;
                    }
                }
            }
            unset($v['mobile']);
            unset($v['realname']);
        }
        unset($v);
    }
}
