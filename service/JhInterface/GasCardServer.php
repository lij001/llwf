<?php

namespace app\api\service\JhInterface;

use app\api\model\ConfigNew;
use app\api\service\CurlServer;
use app\api\service\OrderService;
use app\api\service\RightsService;
use app\api\service\TokenService;
use app\api\model\GasCardOrder;
use app\api\service\UpgradeService;
use think\Db;

class GasCardServer extends JhInterfaceServer {
    private $appKey = '793c91f24a57fd318cb3e8cd33add9bb';
    private $baseUrl = 'http://op.juhe.cn/ofpay/sinopec/';
    private $game_state = [0 => '充值中', 1 => '充值成功', 9 => '充值失败'];

    protected function sign($data) {
        $md5 = [
            $this->openId,
            $this->appKey,
            $data['proid'],
            $data['cardnum'],
            $data['game_userid'],
            $data['orderid'],
        ];
        return md5(implode('', $md5));
    }

    protected function url($url, $data) {
        $temp = [];
        foreach ($data as $key => $value) {
            $temp[] = $key . '=' . $value;
        }
        return $this->baseUrl . $url . '?' . implode('&', $temp);

    }

    /**
     * @param $curl CurlServer
     */
    public function curlRes($curl) {
        $output = $curl->getRes();
        $res = json_decode($output, true);
        if (!$res) {
            $msg = date('Y-m-d H:i:s') . $output;
            writeLog('gascared.log', $msg);
            throw new \Exception('访问聚合油卡api失败');
        }
        if ($res['error_code'] == 0 || $res['error_code'] == 10014) {
            return $res;
        }

        throw new \Exception('错误码:' . $res['error_code'] . ',错误信息:' . $res['reason']);
    }

    /**
     * @param $gasCardOrder  GasCardOrder GasCardOrder模型
     */
    public function originGasCardRecharge($gasCardOrder) {
        $url = 'onlineorder';
        $data = [
            'proid' => $gasCardOrder->proid,
            'cardnum' => $gasCardOrder->cardnum,
            'orderid' => $gasCardOrder->order_no,
            'game_userid' => $gasCardOrder->game_userid,
            'gasCardTel' => $gasCardOrder->gasCardTel,
            'gasCardName' => $gasCardOrder->gasCardName,
            'key' => $this->appKey
        ];
        $data['sign'] = $this->sign($data);
        $url = $this->url($url, $data);
        try {
            $res = CurlServer::init($url, $this)->curl();
            if ($res['error_code'] == 10014) {
                $order = $this->originOrderDetail($gasCardOrder->order_no);
                $gasCardOrder->game_state = $this->game_state[$order['result']['game_state']];
                $gasCardOrder->error_msg = $order['result']['err_msg'];
                $gasCardOrder->isUpdate()->save();
            }
        } catch (\Exception $e) {
            $gasCardOrder->game_state = '充值失败';
            $gasCardOrder->error_msg = $e->getMessage();
            $gasCardOrder->isUpdate()->save();
        }
        return true;
    }

    protected function originOrderDetail($order_no) {
        $url = 'sordersta';
        $data = [
            'key' => $this->appKey,
            'orderid' => $order_no
        ];
        $url = $this->url($url, $data);
        $res = CurlServer::init($url, $this)->curl();
        if ($res['error_code'] == 10014) throw new \Exception('聚合api系统内部异常');
        return $res;
    }

    public function originOrderCallback($post) {
        try {
            $appkey = $this->appKey; //您申请的数据的APIKey
            $sporder_id = addslashes($post['sporder_id']); //聚合订单号
            $orderid = addslashes($post['orderid']); //商户的单号
            $sta = addslashes($post['sta']); //充值状态
            $sign = addslashes($post['sign']); //校验值
            $local_sign = md5($appkey . $sporder_id . $orderid); //本地sign校验值
            if ($local_sign != $sign) {
                throw new \Exception('校验未通过');
            }
            try {
                $gasCardOrder = \app\api\model\GasCardOrder::get(['order_no' => $post['orderid']]);
                Db::name('test')->insert(['param' => '聚合订单回调:' . json_encode($post)]);
                if ($post['sta'] == 9) {
                    $gasCardOrder->game_state = '充值失败';
                    $gasCardOrder->error_msg = $post['err_msg'];
                    $gasCardOrder->isUpdate()->save();
                    return;
                }
                $gasCardOrder->game_state = '充值成功';
                $gasCardOrder->error_msg = '充值成功';
                $gasCardOrder->isUpdate()->save();
                //短信提醒
                $config = Db::name("config")->field('sms_aliyun,sms_alidayu,choose')->find();
                $data = json_decode($config['sms_aliyun'], true);
                send_sms_aliyun3($gasCardOrder['gasCardTel'], $gasCardOrder['game_userid'], $gasCardOrder['money'], $data);
                if ($gasCardOrder->gxz > 0) {
                    $lirun = $gasCardOrder->gxz - ConfigNew::gasCardOrder($gasCardOrder->gxz) - ConfigNew::cashFlow($gasCardOrder->gxz);
                    RightsService::giveReferralAward($lirun, $gasCardOrder->uid, $gasCardOrder->order_no);//直推分佣
                    RightsService::giveTeamAward($lirun, $gasCardOrder->uid, $gasCardOrder->order_no);//团队发佣
                    RightsService::giveOcAward($gasCardOrder->gxz, $gasCardOrder->uid, $gasCardOrder->order_no, 0,4,'油卡充值');//运营中心
                    RightsService::giveGxz($gasCardOrder->uid, $gasCardOrder->gxz, '油卡充值', 1, $gasCardOrder->order_no);
                    //升级检测
                    $upgrade = UpgradeService::objectInit();
                    $flag = $upgrade->userUpgradeCheck($gasCardOrder->uid);
                }
                return true;
            } catch (\ErrorException $e) {
                throw new \Exception($e->getMessage());
            }
        } catch (\Exception $e) {
            Db::name('test')->insert(['param' => '聚合回调:订单号:' . $post['order_id'] . ',错误信息:' . $e->getMessage(), 'time' => date('Y-m-d H:i:s')]);
        }
    }

    public function verification($post) {
        $valid = new \think\Validate(
            [
                'money' => 'require|in:100,200,500,1000',
                'game_userid' => ['regex' => '/^(100011\d{13}|90\d{14})$/'],
                'gasCardTel' => 'require|length:11',
            ],
            [
                'money.require' => '金额是必须的',
                'money.in' => '金额只能是:(100,200,500,1000)',
                'game_userid' => '油卡号格式不正确',
                'gasCardTel' => '手机号码不正确',
            ]
        );
        if (!$valid->check($post)) {
            throw new \Exception($valid->getError());
        }
        return true;
    }

    public function GasCardRecharge($post) {
        $this->verification($post);
        $uid = TokenService::getCurrentUid();
        $data = [
            'uid' => $uid,
            'money' => $post['money'],
            'game_userid' => $post['game_userid'],
            'gasCardTel' => $post['gasCardTel'],
            'gasCardName' => Db::name('members')->where('id', $uid)->value('realname'),
        ];
        $model = new GasCardOrder();
        try {
            $model->startTrans();
            if ($model->save($data)) {
                $info = OrderService::pay($uid, $model->id, $model->order_no, $model->money, $post['pay_type'], '留莲忘返-油卡充值', 8);
                $model->commit();
                return $info;
            }
            throw new \Exception('订单创建失败!');
        } catch (\Exception $e) {
            $model->rollback();
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * 获取价格配置
     * @return mixed
     */
    public function priceList() {
        return ConfigNew::gasCardPriceList();
    }

    /**
     * 失败的订单
     */
    public function failOrder() {
        $gasOrder = new GasCardOrder();
        return $gasOrder->where('uid', TokenService::getCurrentUid())->whereCV('game_state', '充值失败')->order('id', 'desc')->find();
    }

    public function orderList($post) {
        $valid = new \think\Validate([
            'order_status' => 'in:已支付,未支付'
        ], [
            'order_status' => '订单状态不正确'
        ]);
        if (!$valid->check($post)) throw new \Exception($valid->getError());

        $gasCardOrder = new GasCardOrder();
        $gasCardOrder->where('uid', TokenService::getCurrentUid());
        if (!empty($post['order_status'])) {
            $gasCardOrder->where('order_status', $post['order_status']);
        }
        return $gasCardOrder->limit(limit($post['start'], 10), 10)->order('id', 'desc')->select()->toArray();
    }

    public function orderDetail($post) {
        if (empty($post['order_no'])) throw new \Exception('订单号不能为空');
        return GasCardOrder::get(['order_no' => $post['order_no'], 'uid' => TokenService::getCurrentUid()]);
    }

    public function orderUpdate(array $post) {
        $valid = new \think\Validate([
            'game_userid' => ['regex' => '/^(100011\d{13}|90\d{14})$/'],
            'gasCardTel' => 'require|length:11',
            'order_no' => 'require'
        ], [
            'game_userid' => '油卡号格式不正确',
            'gasCardTel' => '手机号码不正确',
            'order_no' => '订单编号不能为空'
        ]);
        if (!$valid->check($post)) throw new \Exception($valid->getError());
        $gasCardOrder = GasCardOrder::get(['order_no' => $post['order_no'], 'uid' => TokenService::getCurrentUid()]);
        if (!$gasCardOrder) throw new \Exception('未找到该订单!');
        if ($gasCardOrder->game_state != '充值失败' && $gasCardOrder->order_status == '已支付') throw new \Exception('只有失败的订单才可以更新');
        $gasCardOrder->game_state = '充值中';
        $gasCardOrder->game_userid = $post['game_userid'];
        $gasCardOrder->gasCardTel = $post['gasCardTel'];
        $gasCardOrder->order_no = createOrderNo('JHYK');
        $gasCardOrder->isUpdate()->save();
        Db::name('pay_info')->where('order_no', $post['order_no'])->update(['order_no' => $gasCardOrder->order_no]);
        return $this->originGasCardRecharge($gasCardOrder);
    }

    public function adminOrderList(array $post) {
        $db = new GasCardOrder();
        if (!empty($post['order_no'])) {
            $db->where('order_no', $post['order_no']);
        }
        if (!empty($post['order_status'])) {
            $db->whereCV('order_status', $post['order_status']);
        }
        if (!empty($post['gasCardTel'])) {
            $db->where('gasCardTel', $post['gasCardTel']);
        }
        if (!empty($post['game_state'])) {
            $db->whereCV('game_state', $post['game_state']);
        }
        if (!empty($post['start_time'])) {
            $db->where('create_time', '>', strtotime($post['start_time']));
        }
        if (!empty($post['end_time'])) {
            $db->where('create_time', '<', strtotime($post['end_time']));
        }
        return $db->order('id', 'desc')->paginate(10, false, ['page' => $post['start']]);
    }
}
