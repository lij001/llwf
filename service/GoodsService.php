<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\AliProduct;
use app\api\model\TaskShareGoods;
use app\api\service\goods\AliGoodsService;

class GoodsService extends BaseService {
    protected $service;

    protected function _taskShareGoodsIndex($param) {
        $model = TaskShareGoods::objectInit();
        $model->order('sort,id', 'asc');
        return $model;
    }

    /**
     * 任务中心分享商品列表
     * @param array $param
     * @return bool|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function taskShareGoodsList($param = []) {
        $model = $this->_taskShareGoodsIndex($param);
        return $model->select();
    }

    /**任务中心分享商品列表-分页
     * @param array $param
     * @return \think\Paginator
     * @throws \think\exception\DbException
     */
    public function taskShareGoodsListPage($param = []) {
        $model = $this->_taskShareGoodsIndex($param);
        return $model->paginate(20);
    }

    /**任务中心分享商品带详情列表
     * @param array $param
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function taskShareGoodsDetailList($param = []) {
        $model = $this->_taskShareGoodsIndex($param);
        $model->with('goods');
        return $this->deleteTaskGoodsNull($model->select());
    }

    /**任务中心分享商品带详情列表-分页
     * @param array $param
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function taskShareGoodsDetailListPage($param = []) {
        $model = $this->_taskShareGoodsIndex($param);
        $model->with('goods');
        return $this->deleteTaskGoodsNull($model->paginate(20));
    }

    /**
     * 删除任务中心分享商品不存在的商品
     * @param $list
     * @return mixed
     */
    protected function deleteTaskGoodsNull($list) {
        foreach ($list as $k => $val) {
            if ($val['goods'] === null) {
                $val->delete();
                unset($list[$k]);
            }
        }
        return $list;
    }

    public function addTaskShareGoods($param) {
        if (empty($param['feedId'])) throw new \Exception('商品id不能为空!');
        $info = TaskShareGoods::get(['gid' => $param['feedId']]);
        if (!$info) {
            if (!TaskShareGoods::create([
                'gid' => $param['feedId'],
                'gxz' => 2,
                'type' => '阿里'
            ])) throw new \Exception('添加失败!');
        }
        return 1;
    }

    public function allAddTaskShareGoods($param) {
        if (empty($param['feedIds'])) return 1;
        foreach ($param['feedIds'] as $feedId) {
            $this->addTaskShareGoods(['feedId' => $feedId]);
        }
        return 1;
    }

    public function deleteTaskShareGoods($param) {
        if (empty($param['ids'])) throw new \Exception('ids不能为空!');
        TaskShareGoods::destroy($param['ids']);
        return 1;
    }

    /**
     * 任务中心分享商品成功后事件
     * @param $param
     */
    public function taskShareGoodsSuccess($param) {
        if (empty($param['id'])) throw new \Exception('id不能为空!');
        $mid = MemberService::getCurrentMid();
        $info = TaskShareGoods::get($param['id']);
        if ($info === null) throw new \Exception('为找到该分享商品!');
        MemberBalanceService::objectInit()->taskShareGoods($mid, $info['gxz']);
        return 1;
    }

    public function updateTaskShareGoods($param) {
        if (empty($param['id'])) throw new \Exception('id不能为空!');
        if (empty($param['sort'])) throw new \Exception('排序编号不能为空');
        $info = TaskShareGoods::get($param['id']);
        if ($info === null) throw new \Exception('未找到数据!');
        $info['sort'] = $param['sort'];
        $info->save();
        return 1;
    }

    public static function ali() {
        $s = self::objectInit();
        $s->service = AliGoodsService::objectInit();
        return $s;
    }

    /**
     *获取商品信息
     * @param $param |feedId
     * @return mixed
     */
    public function getGoodsInfo($param) {
        return $this->service->getGoodsInfo($param);
    }


}
