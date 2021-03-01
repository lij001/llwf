<?php


namespace app\api\service;


use app\api\BaseService;
use app\api\model\type\Type;
use think\Cache;

class TypeService extends BaseService {
    protected $data;
    protected $key = 'TypeService';

    public function getType() {
        if (empty($this->data)) {
            $data = Cache::get($this->key);
            if ($data === false) {
                $d = Type::select();
                $data = [];
                foreach ($d as $k) {
                    $data[$k['id']] = $k['name'];
                }
                Cache::set($this->key, json_encode($data, JSON_UNESCAPED_UNICODE));
            } else {
                $data = json_decode($data, true);
            }
            $this->data = $data;
        }
        return $this->data;
    }
}
