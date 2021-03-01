<?php


namespace app\api\service;


use app\api\BaseService;

class RedisServer extends BaseService {
    /**
     * @var \Redis
     */
    protected $link;

    protected function initialization() {
        $config = $this->config();
        $this->link = new \Redis();
        $this->link->connect($config['host'], $config['port'], $config['timeout']);
        if (!$this->link->auth($config['password'])) throw new \Exception('redis连接失败!');
    }

    public function __destruct() {
        $this->link->close();
    }

    private function config() {
        $path = dirname(__FILE__) . '/../../../data/conf/redis.php';
        if (!file_exists($path)) throw new \Exception('redis文件无法加载.');
        $config = include $path;
        if (!$config) throw new \Exception('redis文件无法加载.');
        return $config;
    }

    /**
     * 往队列中插入一条数据
     * @param $key
     * @param $data
     * @return bool|int
     */
    public function lPush($key, $data) {
        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        return $this->link->lPush($key, $data);
    }

    /**删除队列左边一条数据然后返回这条数据.
     * @param $key
     * @return bool|mixed
     */
    public function lPop($key) {
        $data = $this->link->lPop($key);
        if ($data === false) return false;
        $res = json_decode($data, true);
        if ($res === null) return $data;
        return $res;
    }

    /**
     * 删除队列左边一条数据然后返回这条数据.如果队列为空,将一直阻塞.
     * @param $key
     * @param int $timeout
     * @return mixed
     */
    public function BrPop($key, $timeout = 0) {
        $data = $this->link->BrPop($key, $timeout);
        $data = $data[1];
        $res = json_decode($data, true);
        if ($res === null) return $data;
        return $res;
    }

    /**设置key过期时间 秒数
     * @param $key
     * @param $ttl
     * @return bool
     */
    public function expire($key, $ttl) {
        return $this->link->expire($key, $ttl);
    }

    /**设置key过期时间 时间戳
     * @param $key
     * @param $ttl
     * @return bool
     */
    public function expireAt($key, $ttl) {
        return $this->link->expireAt($key, $ttl);
    }

    public function __call($fn, $arguments) {
        return call_user_func_array([$this->link, $fn], $arguments);
    }

}
