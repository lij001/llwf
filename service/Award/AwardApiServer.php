<?php


namespace app\api\service\Award;


use app\admin\model\ConfigModel;
use app\api\BaseService;
use app\api\model\award\Award;
use app\api\model\award\AwardGoods;
use app\api\model\award\AwardPond;
use app\api\model\award\AwardRecord;
use app\api\model\award\AwardOrder;
use app\api\model\award\AwardUser;
use app\api\model\Member;
use app\api\service\OrderService;
use think\Db;
use think\Validate;

class AwardApiServer extends BaseService {

    /**
     * 中奖列表
     * @param $param
     * @param int $limit
     * @return \think\Paginator
     * @throws \think\exception\DbException
     */
    public function getWinList($param, $limit = 20){
        $model = AwardOrder::objectInit();
        $page = !empty($param['page']) ? $param['page'] : 1;
        return $model->with('user')->order('id', 'desc')->page($page,$limit)->select();
    }


    /**
     * 获取抽奖商品
     * @return bool|false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getGoodsList($param){
        if(!is_numeric($param['position_type'])) throw new \Exception('显示位置错误');
        $now = time();
        $award = Award::objectInit()->whereCV(['start_time'=>['<',$now],'end_time'=>['>',$now],'status'=>'启用','position_type'=>$param['position_type']])->find();
        if(empty($award)) throw new \Exception('活动不存在');
        $list = AwardGoods::objectInit()->whereCV(['award_id'=>$award->id])->field('id,title,img')->select();
        if(empty($list))  throw new \Exception('活动商品不存在');
        return $list;
    }

    /**
     * 获得抽奖结果
     * @param $uid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getResult($uid){
        $now = time();
        $award = Award::objectInit()->whereCV(['start_time'=>['<',$now],'end_time'=>['>',$now],'status'=>'启用'])->find();
        if(empty($award)) throw new \Exception('活动不存在');
        $balance = Db::name('user_balance')->where('uid',$uid)->find();
        $recordCount = AwardRecord::objectInit()->whereCV(['members_id'=>$uid])->sum('num');
        if($recordCount > $award->max_count) throw new \Exception('达到抽奖最大次数');
        $this->isCanAward($award,$balance);
        $list = AwardGoods::objectInit()->whereCV(['award_id'=>$award->id])->select();
        if(empty($list))  throw new \Exception('活动商品不存在');
        $data = $this->getWardGoods($list,$uid,$award);
        return $data;
    }

    /**
     * 抽奖限制
     * @param $award
     * @param $uid
     * @param $play_count
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function isCanAward($award,$balance){
        if($balance['play_count'] <= 0) throw new \Exception('可抽奖次数已用完');
        if($award->time_type == 0){ //一天N次
            $awardRecord = AwardRecord::objectInit()->whereTime('create_time', 'today')->where('members_id',$balance['uid'])->find();
            if($awardRecord->num > $award->time_value['day_can']){
                throw new \Exception('今天抽奖次数已用完');
            }
        }
        if($award->time_type == 1){ //一人N次
            $num = AwardRecord::objectInit()->where('members_id',$balance['uid'])->sum('num');
            if($num > $award->time_value['day_can']){
                throw new \Exception('抽奖次数已用完');
            }
        }
        if($award->atype == 0){
            if($balance['calculator'] < $award->atype_value['reduce_score']){
                throw new \Exception('榴莲值不足');
            }
        }
        return true;
    }

    /**
     * 用户榴莲值按比例加入奖池 30%回收系统 70%加入奖池
     * @param $value
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function rate($award){
        if($award->atype != 0) return;
        $pond = AwardPond::objectInit()->where('id',1)->find();
        $sys = bcmul($award->atype_value['reduce_score'],bcdiv($pond->sys_rate,100,2),2);
        $pond = bcmul($award->atype_value['reduce_score'],bcdiv($pond->pond_rate,100,2),2);
        AwardPond::objectInit()->where('id',1)->setInc('money',$pond);
    }

    /**
     * 抽奖
     * @param $list
     * @param $uid
     * @param $award
     * @param $extra_count
     * @return array
     * @throws \think\Exception
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function getWardGoods($list,$uid,$award){
        if(!$list || !$uid || !$award) return [];
        $data = ['id'=>0,'msg'=>'谢谢参与'];
        $isImprove = $award->num >= $award->improve ? 1 : 0; //提高抽奖概率
        $award->setInc('num');
        //指定中奖人中奖
        $awardUser = $this->awardUser($award,$uid);
        if($awardUser !== false){
            return $awardUser;
        }
        if($isImprove){
            $awardGoods = $this->getRand($list);
            if($awardGoods){
                $data = $this->winning($award,$awardGoods,$uid);
            }
        }

        return $data;
    }

    /**
     * 增减贡献值并生成参与记录
     * @param $award
     * @param $uid
     */
    public function createLog($award,$uid,$isWin){
        $this->subGxz($uid,$award);
        $this->sendGxz($uid,$award,$isWin);
        $this->addAwardRecord($uid);
        $this->rate($award);
    }

    /**
     * 抽奖算法
     * @param $proArr
     * @return mixed|string
     */
    public function getRand($proArr){
        $result = '';
        $arr = [];
        $data = [];
        foreach($proArr as $v){
            $arr[$v['id']] = $v['rate'];
            $data[$v['id']] = $v;
        }
        //概率数组的总概率精度
        $total = array_sum($arr);
        //概率数组循环
        foreach ($arr as $key => $proCur) {
            $rand = rand(1, $total);
            if ($rand <= $proCur) {
                $result = $key;
                break;
            } else {
                $total -= $proCur;
            }
        }
        return !empty($data[$result]) ? $data[$result] : '';
    }

    /**
     * 中奖生成订单
     * @param $award
     * @param $awardGoods
     * @param $uid
     * @param $type
     * @return mixed
     * @throws \think\Exception
     */
    public function winning($award,$awardGoods,$uid){
        $data['id'] = $awardGoods->id;
        $data['img'] = $awardGoods->img;
        $data['give_type'] = $awardGoods->give_type;
        $data['order_no'] = '';
        $isWin = 1; //是否中奖 1未中 0中
        if($awardGoods->give_type == '谢谢参与'){
            $data['msg'] = '谢谢参与!';
        }else{
            $data['msg'] = '恭喜您中奖了！';
            $isWin = 0;
            //生成中奖订单
            $order = $this->addAwardOrder($uid,$awardGoods);
            //赠送余额或贡献值
            $this->gift($uid,$order);
            //扣除奖池积分
            $this->subPond($awardGoods->llz);
            $data['order_no'] = $order->order_no;
            $data['freight'] = $order->freight;
        }
        $this->createLog($award,$uid,$isWin);
        return $data;
    }

    public function subPond($value){
        return AwardPond::objectInit()->where('id',1)->setDec('money',$value);
    }

    /**
     * 指定中奖人
     * @param $award
     * @param $uid
     * @param $type
     * @return bool|mixed
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function awardUser($award,$uid){
        $awardUser = AwardUser::objectInit()->where(['uid'=>$uid,'status'=>0])->order('id desc')->find();
        if($awardUser === null) return false;
        $awardGoods = AwardGoods::objectInit()->where('id',$awardUser->award_goods_id)->find();
        if($awardGoods === null) return false;
        $awardUser->save(['status' => 1,'use_time' => time()]);
        return $this->winning($award,$awardGoods,$uid);
    }

    /**
     * 参与赠送贡献值
     * @param $uid
     * @param $award
     * @param int $type 0中奖 1未中奖
     * @return bool
     */
    public function sendGxz($uid,$award,$type){
        $member =  \app\api\service\MemberBalanceService::objectInit();
        if($award->give_type == 0){//赠送全部用户贡献值
            $member->chouJiang($uid, $award->give_value, '贡献值', 'add');
        }elseif($award->give_type == 1 && $type == 1){ //赠送给未中奖用户
            $member->chouJiang($uid, $award->give_value, '贡献值', 'add');
        }
        return true;
    }

    /**
     * 抽奖扣减贡献值
     * @param $uid
     * @param $award
     * @return bool
     */
    public function subGxz($uid,$award){
        $member =  \app\api\service\MemberBalanceService::objectInit();
        if($award->atype == 0){ //抽奖扣减贡献值
            $member->chouJiang($uid, $award->atype_value['reduce_score'], '贡献值', 'sub');
        }
        return true;
    }

    /**
     * 中奖记录
     * @param $uid
     * @param $award_goods_id
     * @param $award
     * @return bool
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function addAwardOrder($uid,$awardGoods){
        $data = [
            'order_no' => createOrderNo('CJ'),
            'members_id' => $uid,
            'award_goods_id' => $awardGoods->id,
            'award_id' => $awardGoods->award_id,
            'snapshot' => $awardGoods,
            'freight' => $awardGoods->freight?:0,
            'llz' => $awardGoods->llz?:0,
        ];
        return AwardOrder::create($data);
    }

    /**
     * 参与抽奖记录
     * @param int $uid
     * @return bool
     */
    public function addAwardRecord($uid){
        $model = AwardRecord::objectInit();
        $awardRecord = $model->whereTime('create_time', 'today')->where('members_id',$uid)->find();
        if($awardRecord){
            $model->where('id',$awardRecord->id)->setInc('num');
        }else{
            $model::create(['members_id'=>$uid,'num'=>1]);
        }
        return true;
    }


    /**
     * 下单付款赠送抽奖次数
     * @param $uid
     * @param $money
     * @return bool|string
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function addPlayCount($param){
        if(empty($param['order_id'])) throw new \Exception('订单ID不能为空');
        $order = Db::name('order_info')->where('id', $param['order_id'])->find();
        if(empty($order)) throw new \Exception('订单不存在');
        $now = time();
        $award = Award::objectInit()->whereCV(['start_time'=>['<',$now],'end_time'=>['>',$now],'status'=>'启用'])->find();
        if(empty($award)) throw new \Exception('活动不存在');
        $member = Member::get($order['uid']);
        if($award->member_type == 1){ //部分用户
            if($member->star < $award->member_value) throw new \Exception('不在用户等级范围内');
        }
        if($award->atype == 1){
            if($order['pay_amount'] >= $award->atype_value['min_money']){ //满多少赠送次数
                //$num = floor($order['pay_amount']/$award->atype_value['min_money']);
                Db::name('user_balance')->where('uid',$order['uid'])->setInc('play_count',$award->atype_value['use_count']);
            }
        }
        return true;
    }

    /**
     * 可抽奖次数
     * @param $uid
     * @return array
     */
    public function getPlayCount($uid){
        $playCount = Db::name('user_balance')->where('uid',$uid)->value('play_count');
        return ['play_count'=>$playCount];
    }

    /**
     * 分享额外赠送抽奖次数
     * @param $uid
     * @param $param
     * @return bool|int|string|true
     * @throws \think\Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function addShareCount($uid,$param){
        if(!empty($param['position_type']))  throw new \Exception('活动不存在');
        $now = time();
        $award = Award::objectInit()->whereCV(['start_time'=>['<',$now],'end_time'=>['>',$now],'status'=>'启用','position_type'=>$param['position_type']])->find();
        if(empty($award)) throw new \Exception('活动不存在');
        if($award->time_type == 0) {
            $playCount = Db::name('user_balance')->where('uid', $uid)->setInc('play_count',$award->time_value['day_share']);
        }else{
            $playCount = Db::name('user_balance')->where('uid', $uid)->setInc('play_count',$award->time_value['ren_share']);
        }
        return $playCount;
    }

    /**
     * 赠送贡献值或余额
     * @param $uid
     * @param $param
     * @throws \think\exception\DbException
     */
    public function gift($uid,$awardOrder){
        if(empty($awardOrder))  throw new \Exception('中奖信息不存在');
        $member = \app\api\service\MemberBalanceService::objectInit();
        switch($awardOrder->awardGoods->give_type){
            case '余额':
                $member->chouJiang($uid, $awardOrder->awardGoods->value, '余额', 'add');
                $awardOrder->save(['status'=>'已完成']);
                break;
            case '贡献值':
                $member->chouJiang($uid, $awardOrder->awardGoods->value, '贡献值', 'add');
                $awardOrder->save(['status'=>'已完成']);
                break;
            default:'';
        }
    }

    /**
     * 收货地址
     * @param $param
     * @return bool|false|int
     * @throws \think\exception\DbException
     */
    public function addAddress($param){
        if(empty($param['id'])) throw new \Exception('ID不能为空');
        if(empty($param['addr_id'])) throw new \Exception('收货地址ID不能为空');
        $address = Db::name('member_address')->where('id',$param['addr_id'])->find();
        if($address === null) throw new \Exception('快递信息不存在');
        $data = [
            'province' => $address['province'],
            'city' => $address['city'],
            'area' => $address['country'],
            'detail' => $address['detail'],
            'username' => $address['name'],
            'mobile' => $address['mobile'],
        ];
        $awardOrder = AwardOrder::get($param['id']);
        if($awardOrder === null) throw new \Exception('中奖信息不存在');
        return $awardOrder->save($data);
    }

    /**
     * 抽奖活动规则
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function prizeRule(){
        $config = (new ConfigModel())->where('id',1)->find();
        return ['prize_rule' => $config['prize_rule']];
    }

    /**
     * 支付运费
     * @param $param
     * @param $uid
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function pay($param,$uid){
        if(empty($param['order_no'])) throw new \Exception('订单号不能为空');
        if(!is_numeric($param['money'])) throw new \Exception('运费必须是数字');
        if(empty($param['pay_type'])) throw new \Exception('支付方式不能为空');
        $awardOrder = AwardOrder::objectInit()->where('order_no',$param['order_no'])->find();
        if($awardOrder === null) throw new \Exception('订单不存在');
        if($awardOrder->status > 0) throw new \Exception('已付款,不能重复支付');
        if($this->isAddress($awardOrder) === false) throw new \Exception('请先填写收货地址');
        if($awardOrder->freight != $param['money']) throw new \Exception('支付金额不一致');
        if($param['money'] == 0){
            $awardOrder->save(['status' => '待发货']);
            return ['info'=>''];
        }
        $data = OrderService::pay($uid, $awardOrder->id, $awardOrder->order_no, $awardOrder->freight, $param['pay_type'], '留莲忘返-中奖商品', 12);
        return $data;
    }

    /**
     * 是否填写收货地址
     * @param $awardOrder
     * @return bool
     */
    public function isAddress($awardOrder){
        if(!$awardOrder->username || !$awardOrder->mobile || !$awardOrder->province || !$awardOrder->city || !$awardOrder->area || !$awardOrder->detail) {
            return false;
        }
        return true;
    }

    /**
     * 支付回调更新状态
     * @param $order_no
     * @throws \Exception
     */
    public function payCallBack($order_no){
        if(empty($order_no)) throw new \Exception('订单号不能为空');
        try{
            AwardOrder::objectInit()->where('order_no',$order_no)->isUpdate(true)->save(['status' => '待发货']);
        }catch (\Exception $e){
            throw new \Exception($e->getMessage());
        }
    }
}
