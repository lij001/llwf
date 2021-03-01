<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\Saomajinqun;
use app\api\model\Saomajinqunwechat;
use cmf\lib\Upload;

class SaoMaJinQunService extends BaseService {
    /**
     * 二维码列表
     * @param $param
     * @return mixed
     */
    public function indexList($param) {
        $model = Saomajinqun::objectInit();
        $model->order('id', 'desc');
        $list = $this->returnLimitData($model);
        $day7 = 86400 * 7;
        foreach ($list as $item) {
            $wechat = Saomajinqunwechat::_whereCV(['status' => '上架', 'list_id' => $item['id']])->order('id', 'desc')->find();
            if ($wechat === null) {
                $item['shengxia_time'] = '0';
                $item['shengxia_num'] = '0';
            } else {
                $time = $wechat->getData('create_time') + $day7 - time();
                $item['shengxia_time'] = $time > 0 ? (int)($time / 60 / 60 / 24) : 0;
                $item['shengxia_num'] = 180 - $wechat['num'];
            }
        }
        return $list;
    }

    /**
     * 获取单个信息
     * @param $id
     * @param int $isException 未找到信息是否抛异常
     * @return Saomajinqun|null
     * @throws \think\exception\DbException
     */
    public function getQr($id, $isException = 0) {
        if (empty($id)) throw new \Exception('id不能为空!');
        $info = Saomajinqun::get($id);
        if ($isException && $info === null) throw new \Exception('id不正确!');
        return $info;
    }

    /**
     * 微信群二维码列表
     * @param $param
     * @return mixed
     * @throws \Exception
     */
    public function wechatList($param) {
        if (empty($param['list_id'])) throw new \Exception('列表id不能为空!');
        $model = Saomajinqunwechat::objectInit();
        $model->where('list_id', $param['list_id'])->order('id', 'desc');
        return $this->returnLimitData($model);
    }

    /**
     * 添加二维码
     * @param array $param
     */
    public function add($param) {
        if (empty($param['name'])) throw new \Exception('名称不能为空!');
        if (Saomajinqun::get(['name' => $param['name']]) !== null) throw new \Exception('名称不能重复!');
        if (!Saomajinqun::objectInit()->save(['name' => $param['name']])) throw new \Exception('新增失败!');
        return 1;
    }

    /**
     * 删除二维码
     * @param array $param
     */
    public function del($param) {
        if (empty($param['ids'])) throw new \Exception('ids不能为空!');
        Saomajinqun::destroy($param['ids']);
        return 1;
    }

    /**
     * 添加微信群二维码
     * @param $param
     */
    public function addWechat($param) {
        if (empty($param['list_id'])) throw new \Exception('列表id不能为空');
        if (empty($param['file'])) throw new \Exception('请上传群二维码!');
        $uploader = new Upload();
        $result = $uploader->upload();
        if ($result === false) throw new \Exception($uploader->getError());
        $list = Saomajinqunwechat::objectInit()->whereCV(['m_number' => $param['m_number'], 'list_id' => $param['list_id'], 'status' => ['<>', '过期']])->select();
        $data = [
            'list_id' => $param['list_id'],
            'url' => $result['url'],
            'm_number' => $param['m_number'],
        ];
        if ($list->isEmpty()) {
            $data['status'] = '上架';
        } else {
            $time = time();
            $day7 = 86400 * 7;
            $bool = false;
            foreach ($list as $val) {
                if (($val->getData('create_time') + $day7) < $time) {
                    $val['status'] = '过期';
                    $val->save();
                    continue;
                }
                if ($val['status'] === '上架') {
                    $bool = true;
                }
            }
            if ($bool) {
                $data['status'] = '有效';
            } else {
                $data['status'] = '上架';
            }
        }
        Saomajinqunwechat::objectInit()->save($data);
        return 1;
    }

    public function delWechat($param) {
        if (empty($param['ids'])) throw new \Exception('ids不能为空!');
        Saomajinqunwechat::destroy($param['ids']);
        return 1;
    }

    public function getWechatStatusList() {
        return Saomajinqunwechat::objectInit()->getStatusList();
    }

    public function updateWechat($param) {
        if (empty($param['id'])) throw new \Exception('id不能为空');
        if (empty($param['status'])) throw new \Exception('状态不能为空!');
        $info = Saomajinqunwechat::get($param['id']);
        if ($info === null) throw new \Exception('未找到该信息!');
        $info['status'] = $param['status'];
        $info->save();
        return 1;
    }

    public function getWeChatQrCode($param) {
        if (empty($param['id'])) throw new \Exception('id不能为空!');
        if (Saomajinqun::get($param['id']) === null) throw new \Exception('id不正确!');
        $info = Saomajinqunwechat::_whereCV(['list_id' => $param['id'], 'status' => '上架'])->order('id', 'desc')->find();
        $day = 86400 * 6;
        $time = time();
        if ($info === null || ($info->getData('create_time') + $day) < $time || $info['num'] >= 180) {
            if ($info !== null) {
                $info['status'] = '过期';
                $info->save();
            }
            $info = Saomajinqunwechat::_whereCV(['list_id' => $param['id'], 'status' => '有效'])->order('id', 'desc')->find();
            if ($info === null) return 0;
            if (($info->getData('create_time') + $day) < $time) {
                Saomajinqunwechat::_whereCV(['list_id' => 1, 'status' => '有效'])->update(['status' => '过期']);
                return 0;
            }
            $info['status'] = '上架';
        }
        $info['num'] += 1;
        $info->save();
        return $info;
    }


}

