<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\goods\AliGoods;
use think\Db;

class HotService extends BaseService {
    public function start() {
        $redis = RedisServer::objectInit();
        $fns = [
            'seckillAddCountDispose' => 1,
            'aliProAddHotDispose' => 1,
        ];
        while (1) {
            foreach ($fns as $fn => &$val) {
                $data = $redis->lPop($fn);
                if ($data === false) {
                    $val = 0;
                    $a = array_unique($fns);
                    if (count($a) === 1 && !reset($a)) {
                        exit;
                    }
                } else {
                    $this->$fn($data);
                }
            }
        }

    }

    /**
     * 秒杀增加统计
     * @param int $detail
     * @param int $mid
     */
    public function seckillAddCount($detail = 0,$mid=1001) {
        RedisServer::objectInit()->lPush('seckillAddCountDispose', ['detail' => $detail,'mid'=>$mid]);
    }

    /**
     * 秒杀增加统计处理
     * @param $detail
     */
    private function seckillAddCountDispose($data) {
        $detail=$data['detail'];
        $mid=$data['mid'];
        $start_time = strtotime(date('Y-m-d'));
        $end_time = strtotime(date('Y-m-d 23:59:59'));
        $db = Db::name('seckill_count');
        $db->where('detail', $detail)
            ->where('uid', $mid)
            ->where('create_time', ['>', $start_time], ['<', $end_time], 'and');
        if (empty($db->find())) {
            $db->insert([
                'uid' => $mid,
                'create_time' => time(),
                'detail' => $detail
            ]);
        } else {
            $db->where('detail', $detail)
                ->where('uid', $mid)
                ->where('create_time', ['>', $start_time], ['<', $end_time], 'and')->setInc('num');
        }
    }

    /**
     * 阿里商品增加热度
     * @param int $feedId
     */
    public function aliProAddHot($feedId) {
        RedisServer::objectInit()->lPush('aliProAddHotDispose', ['feedId' => $feedId]);
    }
    /**
     * 阿里商品增加热度处理
     * @param $feedId
     */
    private function aliProAddHotDispose($feedId) {
        AliGoods::_whereCV('feedId',$feedId['feedId'])->setInc('hot');
    }

}
