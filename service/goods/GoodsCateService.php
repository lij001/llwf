<?php


namespace app\api\service\goods;


use app\api\BaseService;
use app\api\model\goods\GoodsCate;
use app\api\service\Xyb2b\XyService;
use app\service\CommonService;
use think\Cache;
use think\Validate;

class GoodsCateService extends BaseService {
    /**
     * 插入数据
     */
    protected function create($d) {
        return GoodsCate::create($d);
    }

    /**
     * 行云属性插入
     * @param int $remote_pid
     * @param int $pid
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function insertXyCate($remote_pid = 0, $pid = 0) {
        $xy = XyService::objectInit();
        $response = $xy->getRemoteGoodsCategory($remote_pid);
        if (empty($response['category_list'])) return;
        foreach ($response['category_list'] as $val) {
            if ($val['category_name'] === '测试分类') {
                continue;
            }
            $info = GoodsCate::xy()->where('remote_id', $val['category_id'])->find();
            if ($info === null) {
                $info = $this->create([
                    'pid' => $pid,
                    'name' => $val['category_name'],
                    'type' => '行云',
                    'remote_id' => $val['category_id']
                ]);
            }
            $this->insertXyCate($info['remote_id'], $info['id']);
        }
    }

    /**
     * 获取行云分类
     * @param array $where 条件
     * @return bool|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getXyCate($where) {
        $model = GoodsCate::xy();
        if (!empty($where)) {
            $model->whereCV($where);
        }
        return $model->select();
    }

    /**获取一条行云分类
     * @param string|array $name
     */
    public function getXyCateFind($name) {
        $info = null;
        if (is_array($name)) {
            foreach ($name as $val) {
                $info = GoodsCate::xy()->where('name', $val)->find();
                if ($info !== null) break;
            }
        } else {
            $info = GoodsCate::xy()->where('name', $name)->find();
        }
        return $info;
    }

    private function getCateTree($type = '', $cached = true) {
        $result = null;
        if ($cached) {
            $result = Cache::get('getCateTree' . $type);
        }
        if (!$result) {
            $model = GoodsCate::objectInit()->field('id,pid,name,cate_img,describe');
            if (!empty($type)) {
                $model->whereCV('type', $type);
            }
            $result = $model->select();
            $result = $result->toArray();
            $result = CommonService::objectInit()->tree($result);
            Cache::set('getCateTree' . $type, json_encode($result, JSON_UNESCAPED_UNICODE), 86400);
        } else {
            $result = json_decode($result, true);
        }
        return $result;
    }

    /**
     * 获取子孙分类id
     * @param int cate_id
     */
    public function getProgenyId($cate_id) {
        $tree = $this->getCateTree();
        $ids = [$cate_id];
        $key = -1;
        while (++$key > -1) {
            if (!isset($ids[$key])) break;
            if (!isset($tree[$ids[$key]])) continue;
            $val = $tree[$ids[$key]];
            if (empty($val['children'])) continue;
            $ids = array_merge($ids, array_column($val['children'], 'id'));
        }
        return $ids;
    }

    /**
     * 获取祖宗分类id
     * @param $cate_id
     * @return mixed
     */
    public function getForefathersId($cate_id) {
        $tree = $this->getCateTree();
        if (!isset($tree[$cate_id])) return $cate_id;
        $pid = $tree[$cate_id]['pid'];
        while ($pid) {
            if (!isset($tree[$pid]) || $tree[$pid]['pid'] === 0) break;
            $pid = $tree[$pid]['pid'];
        }
        return $pid;
    }

    /**
     * 获取祖宗分类信息
     * @param $cate_id
     * @return mixed
     */
    public function getForefathers($cate_id) {
        $id = $this->getForefathersId($cate_id);
        return GoodsCate::_whereCV('id', $id)->find();
    }

    public function getCateListApi($param) {
        if (empty($param['type'])) throw new \Exception('类型不能为空!');
        if (!empty($param['id']) && $param['id'] > 0) {
            $list = $this->getCateTree($param['type']);
            $list = array_key_exists($param['id'], $list) ? $list[$param['id']]['children'] : null;
        } else {
            $list = GoodsCate::_whereCV(['type' => $param['type'], 'pid' => 0])->field('id,pid,name,cate_img,describe,sort')->order('sort','asc')->select();
        }
        return $list;
    }

    public function getCateList($param) {
        $model = GoodsCate::objectInit();
        if (!empty($param['type'])) $model->whereCV('type', $param['type']);
        $list = $model->order('sort','asc')->select();
        $result = $list->toArray();
        $result = CommonService::objectInit()->tree($result);
        foreach ($result as $key => $item) {
            if ($item['pid'] != 0) {
                unset($result[$key]);
            }
        }
        return array_values($result);
    }

    public function getDetail($id) {
        if ($id < 1) throw new \Exception('id不能为空!');
        return GoodsCate::_whereCV('id', $id)->find();
    }

    public function insert($param) {
        $validate = new Validate([
            'name' => 'require',
            'rate' => 'require',
            'type' => 'require',
            'pid' => 'require'
        ], [
            'name' => '名称不能为空!',
            'rate' => '溢价比不能为空!',
            'type' => '类型不能为空!'
        ]);
        if (!$validate->check($param)) throw new \Exception($validate->getError());
        if (GoodsCate::_whereCV(['type' => $param['type'], 'name' => $param['name']])->find() !== null) throw new \Exception('该分类名称已经存在了!');
        Cache::clear();
        return GoodsCate::create($param, true);
    }

    public function update($param) {
        $validate = new Validate([
            'id' => 'require',
            'name' => 'require',
            'rate' => 'require',
            'type' => 'require',
            'pid' => 'require'
        ], [
            'id' => 'id不能为空',
            'name' => '名称不能为空!',
            'rate' => '溢价比不能为空!',
            'type' => '类型不能为空!'
        ]);
        if (!$validate->check($param)) throw new \Exception($validate->getError());
        if (GoodsCate::_whereCV(['type' => $param['type'], 'name' => $param['name'], 'id' => ['<>', $param['id']]])->find() !== null) throw new \Exception('该分类名称已经存在了!');
        $ids = $this->getProgenyId($param['id']);
        if (array_search($param['pid'], $ids) !== false) throw new \Exception('不能把自己子孙分类设为自己父分类');
        GoodsCate::update($param, ['id' => $param['id']], true);
        Cache::clear();
        return 1;
    }

    public function delete($id) {
        if ($id < 1) throw new \Exception('id不能为空!');
        if (GoodsCate::_whereCV('pid', $id)->find() !== null) throw new \Exception('请先删除子分类哦!');
        return GoodsCate::destroy($id);
    }


}
