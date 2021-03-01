<?php


namespace app\api\service\goods;


use app\api\BaseService;

abstract class GoodsService extends BaseService {
    protected function initialization() {
        $tree = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        dump($tree);
        $className = end($tree)['class'];
        if ($className !== 'app\api\service\GoodsService') {
            throw new \Exception('请通过GoodsService调用!');
        }
    }
    abstract public function getGoodsInfo($param);
}
