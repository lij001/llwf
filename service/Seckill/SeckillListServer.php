<?php


namespace app\api\service\Seckill;


use app\api\BaseService;
use app\api\model\Seckill\SeckillGoods;
use app\api\model\Seckill\SeckillGoodsAttr;
use app\api\model\Seckill\SeckillList;
use app\api\model\Seckill\SeckillListTime;
use app\api\service\RedisServer;
use think\Cache;
use think\Db;
use think\Validate;

class SeckillListServer extends BaseService {
    /**
     * 获取活动列表
     * @param $param
     * @param int $limit
     * @return \think\Paginator
     * @throws \think\exception\DbException
     */
    public function skList($param, $limit = 20) {
        $model =SeckillList::objectInit();
        $model->order('id', 'desc');
        if (!empty($param['title'])) {
            $model->whereLike('title', $param['title'] . '%');
        }
        if (!empty($param['is_sync'])) {
            $model->whereCV('is_sync', $param['is_sync']);
        }
        if (!empty($param['status'])) {
            $model->whereCV('status', $param['status']);
        }
        if (!empty($param['start_time'])) {
            $model->where('start_time', '>', strtotime($param['start_time']));
        }
        if (!empty($param['end_time'])) {
            $model->where('end_time', '<', strtotime($param['end_time']));
        }
        if (!empty($param['start_time_min'])) {
            $model->where('start_time', strtotime($param['start_time_min']));
        }
        return $model->with('listTime')->paginate($limit, false, ['page' => $param['start']]);
    }

    protected function validateTitle($post) {
        if (empty($post['title'])) throw new \Exception('秒杀标题不能为空!');
        $model = (new SeckillList())->where('title', $post['title']);
        if (!empty($post['id'])) {
            $model->where('id', '<>', $post['id']);
        }
        if ($model->find()) throw new \Exception('秒杀标题不能重复');
    }

    /**
     * 添加秒杀活动
     * @param $post
     * @throws \think\exception\PDOException
     */
    public function addSeckill($post) {
        $this->validateTitle($post);
        $model = SeckillList::objectInit();
        try {
            $model->startTrans();
            if (!$model->save(['title' => $post['title']])) throw new \Exception('添加失败,请重新尝试!');
            $id = $model->getLastInsID();
            $seckillTile = SeckillListTime::objectInit();
            $seckillTile->isUpdate(false)->saveAll([
                ['list_id' => $id, 'start_time' => date('Y-m-d 10:00:00'), 'end_time' => date('Y-m-d 23:59:59'), 'title' => '时间段1'],
                ['list_id' => $id, 'start_time' => date('Y-m-d 15:00:00'), 'end_time' => date('Y-m-d 23:59:59'), 'title' => '时间段2'],
                ['list_id' => $id, 'start_time' => date('Y-m-d 20:00:00'), 'end_time' => date('Y-m-d 23:59:59'), 'title' => '时间段3']
            ]);
            $model->commit();
        } catch (\Exception $e) {
            $model->rollback();
            throw new \Exception($e->getMessage());
        }
    }

    protected function validateId($post) {
        if (empty($post['id'])) throw new \Exception('活动id不能为空!');
    }

    public function editSeckill($post) {
        $this->validateId($post);
        return SeckillList::get(['id' => $post['id']]);
    }

    protected function validate(&$post) {
        $this->validateId($post);
        $this->validateTitle($post);
        $validate = new Validate([
            'start_time' => 'require|date',
            'quantity' => 'require|number',
            'quantity_limit' => 'require|number',
            'price_discount' => 'require|number|<=:100',
            'is_sync' => 'require'
        ],
            [
                'start_time' => '开始时间格式有误',
                'quantity' => '数量不能为空或格式有误',
                'quantity_limit' => '折扣不能为空且不能大于100',
                'is_sync' => '是否同步不能为空'
            ]);
        if (!$validate->check($post)) throw new \Exception($validate->getError());
    }

    public function updateSeckill($post) {
        $this->validate($post);
        $info = SeckillList::get($post['id']);
        if (!empty(SeckillList::get(['start_time' => strtotime($post['start_time']), 'id' => ['<>', $post['id']]]))) throw new \Exception('不能有相同时间的秒杀活动');
        if (empty($info)) return true;
        if ($info->status == '开启') throw new \Exception('开启后不能更改数据');
        try {
            $info->startTrans();
            $info->save($post, ['id' => $post['id']]);
            foreach (SeckillListTime::all(['list_id' => $post['id']]) as $item) {
                $this->updateSeckillTime([
                        'start_time' => $item['start_time'],
                        'end_time' => $item['end_time'],
                        'status' => $item['status'],
                        'id' => $item['id'],
                        'list_id' => $item['list_id']
                    ]
                );
            }
            if ($info->is_sync = '开启') SeckillGoodsServer::syncs($info->id, $info);
            if ($info->status == '开启') $this->addToRedis($post['id']);
            $info->commit();
            return true;
        } catch (\Exception $e) {
            $info->rollback();
            throw new \Exception($e->getMessage());
        }

    }

    protected function addToRedis($id) {
        $seckill = SeckillList::get(['id' => $id]);
        $timeIds = SeckillListTime::objectInit()->where('list_id', $id)->whereCV('status', '开启')->column('id');
        foreach ($timeIds as $key => $timeId) {
            $key1 = 'seckill' . $id . '-' . $timeId;
            Cache::rm($key1);
        }
        if (empty($timeIds)) return;
        $goodsList = SeckillGoods::all(function ($query) use ($id, $timeIds) {
            $query->where('list_id', $id)->whereIn('time_id', $timeIds);
        });
        if ($goodsList->isEmpty()) return;
        $redis = RedisServer::objectInit();
        foreach ($goodsList as $feedId => $goods) {
            $attrs = SeckillGoodsAttr::all(['list_id' => $id, 'feedId' => $goods['feedId']]);
            if ($attrs->isEmpty()) {
                $key = implode('-', [$goods['list_id'], $goods['feedId']]);
                $redis->set($key, $goods['quantity']);
                $redis->set($key . '-quantity_limit', $goods['quantity_limit']);
                $redis->expireAt($key, $seckill->getData('end_time') + 300);
                $redis->expireAt($key . '-quantity_limit', $seckill->getData('end_time') + 300);
                continue;
            }
            foreach ($attrs as $attr) {
                $key = implode('-', [$attr['list_id'], $attr['feedId'], $attr['skuId']]);
                $redis->set($key, $attr['quantity']);
                $redis->set($key . '-quantity_limit', $goods['quantity_limit']);
                $redis->expireAt($key, $seckill->getData('end_time') + 300);
                $redis->expireAt($key . '-quantity_limit', $seckill->getData('end_time') + 300);
            }
        }

        $key = 'seckillApi';
        Cache::rm($key);
        return;
    }

    public function status(array $param) {
        $this->validTime($param, ['id', 'status']);
        if (empty($param['status'])) throw new \Exception('状态不能为空!');
        $model = new SeckillList();
        try {
            $model->startTrans();
            $model->save(['status' => $param['status']], ['id' => $param['id']]);
            if ($model->status == '开启') $this->addToRedis($param['id']);
            $model->commit();
        } catch (\Exception $e) {
            $model->rollback();
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    static public function validTime(&$param, array $field) {
        $valid = [];
        $error = [];
        foreach ($field as $val) {
            switch ($val) {
                case 'start_time':
                    $valid[$val] = 'require|date';
                    $error[$val] = '开始时间格式有误';
                    break;
                case 'end_time':
                    $valid[$val] = 'require|date';
                    $error[$val] = '结束时间格式有误';
                    break;
                case 'status':
                    $valid[$val] = 'require';
                    $error[$val] = '状态不能为空';
                    break;
                case 'id':
                    $valid[$val] = 'require';
                    $error[$val] = 'id不能为空';
                    break;
                case 'list_id':
                    $valid[$val] = 'require';
                    $error[$val] = 'list_id不能为空';
                    break;
                case 'time_id':
                    $valid[$val] = 'require';
                    $error[$val] = 'time_id不能为空';
                    break;
                case 'feedId':
                    $valid[$val] = 'require';
                    $error[$val] = '商品id(feedId)不能为空';
                    break;
            }
        }
        $validate = new Validate($valid, $error);
        if (!$validate->check($param)) throw new \Exception($validate->getError());
    }

    public function updateSeckillTime(array $param) {
        $this->validTime($param, ['start_time', 'end_time', 'status', 'id', 'list_id']);
        $seckill = SeckillList::get(['id' => $param['list_id']]);
        if (!$seckill) throw new \Exception('未找到活动list');
        if ($seckill->status == '开启') throw new \Exception('活动列表开启后不能更新数据');
        $seckillTime = SeckillListTime::get(['id' => $param['id']]);
        if (!$seckillTime) throw new \Exception('id不正确!');
        $seckillTime->start_time = date('Y-m-d ' . $param['start_time'], $seckill->getData('start_time'));
        $seckillTime->end_time = date('Y-m-d ' . $param['end_time'], $seckill->getData('start_time'));
        $seckillTime->status = $param['status'];
        $seckillTime->isUpdate()->save();
        return true;
    }

    public function apiList() {
        $key = 'seckillApi';
        $list = Cache::get($key);
        if (!$list || $list == '[]') {
            $list = $this->skList(['status' => '开启', 'start_time_min' => date('Y-m-d')], 100);
            $list = json_encode($list->items(), JSON_UNESCAPED_UNICODE);
            Cache::set($key, $list, 14400);
        }
        $list = json_decode($list, true);
        $data = [];
        $time = time();
        foreach ($list as $key => $item) {
            $data[$key] = $item;
            $data[$key]['start_time'] = strtotime($item['start_time']);
            $data[$key]['end_time'] = strtotime($item['end_time']);
            $data[$key]['list_time'] = [];
            foreach ($item['list_time'] as $k => $val) {
                if ($val['status'] == '关闭') continue;
                $val['checked'] = 0;
                if (strtotime($val['start_time']) < $time && strtotime($val['end_time']) > $time) {
                    $val['checked'] = 1;
                }
                $data[$key]['list_time'][$k] = $val;
                $data[$key]['list_time'][$k]['start_time'] = strtotime($val['start_time']);
                $data[$key]['list_time'][$k]['end_time'] = strtotime($val['end_time']);
            }
            $data[$key]['list_time'] = array_values($data[$key]['list_time']);
        }
        return $data;
    }

    public function allDelete(array $param) {
        if (empty($param['ids'])) return;
        $model = new SeckillList();
        try {
            $model->startTrans();
            $model->whereIn('id', $param['ids'])->delete();
            SeckillListTime::objectInit()->whereIn('list_id', $param['ids'])->delete();
            $goodsService = SeckillGoodsServer::objectInit();
            $goodsModel = SeckillGoods::objectInit();
            foreach ($param['ids'] as $id) {
                $ids = $goodsModel->where('list_id', $id)->column('id');
                try {
                    $goodsService->allDeleteGoods(['list_id' => $id, 'ids' => $ids]);
                } catch (\Exception $e) {
                    continue;
                }
            }
            $model->commit();
        } catch (\Exception $e) {
            $model->rollback();
            throw new \Exception($e->getMessage());
        }
    }


}
