<?php


namespace app\api\service\Award;


use app\admin\model\GoodsCateSelfModel;
use app\api\BaseService;
use app\api\model\award\Award;
use app\api\model\award\AwardGoods;
use app\api\model\award\AwardPond;
use app\api\model\award\AwardUser;
use app\api\model\award\AwardOrder;
use app\api\model\award\Goods;
use app\api\model\award\GoodsAttr;
use app\api\model\Member;
use think\Validate;

class AwardListServer extends BaseService {

    /**
     * 获取抽奖列表
     * @param $param
     * @param int $limit
     * @return \think\Paginator
     */
    public function awardList($param, $limit = 20) {
        $model = Award::objectInit();
        return $model->order('id', 'desc')->paginate($limit, false, ['page' => $param['start']]);
    }


    /**
     * 添加抽奖
     * @param $post
     * @throws \think\exception\PDOException
     */
    public function addAward($post) {
        try {
            $model = Award::objectInit();
            $data = [
                'atype_value' => ['reduce_score'=>$post['reduce_score'],'min_money'=>$post['min_money'],'use_count'=>$post['use_count']],
                'time_value' => ['day_can'=>$post['day_can'],'day_share'=>$post['day_share'],'ren_can'=>$post['ren_can'],'ren_share'=>$post['ren_share']],
                'give_type' => empty($post['give_type'][0]) ? 0 : 1 ,
            ];
            $data = array_merge($post,$data);
            if (!$model->allowField(true)->save($data)) throw new \Exception('添加失败,请重新尝试!');
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * 编辑抽奖活动
     * @param $post
     * @throws \think\exception\PDOException
     */
    public function editAward($post) {
        if(empty($post['id']))  throw new \Exception('id不能为空');
        try {
            $model = Award::objectInit();
            $info = $model::get($post['id']);
            if($info === null) throw new \Exception('信息不存在');
            $data = [
                'atype_value' => ['reduce_score'=>$post['reduce_score'],'min_money'=>$post['min_money'],'use_count'=>$post['use_count']],
                'time_value' => ['day_can'=>$post['day_can'],'day_share'=>$post['day_share'],'ren_can'=>$post['ren_can'],'ren_share'=>$post['ren_share']],
                'give_type' => empty($post['give_type'][0]) ? 0 : 1 ,
            ];
            $data = array_merge($post,$data);
            $info->allowField(true)->save($data);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * 获取抽奖信息
     * @param $post
     * @return Award
     * @throws \think\exception\DbException
     */
    public function infoAward($post) {
        if(!$post['id']) throw new \Exception('id不能为空');
        $model = Award::objectInit();
        $res =  $model::get(['id' => $post['id']]);
        $res->member_selected = $this->memberType($res->member_value);
        $res->position_selected = $this->positionType($res->position_type);
        if(!$res) throw new \Exception('信息不存在');
        return $res;
    }

    /**
     * 删除抽奖活动
     * @param $post
     * @return bool
     * @throws \Exception
     */
    public function delAward($post){
        if(!$post['ids']) throw new \Exception('ids不能为空');
        $model = Award::objectInit();
        $res = $model->whereIn('id',$post['ids'])->delete();
        if(!$res) throw new \Exception('删除失败');
        return true;
    }

    /**
     * 添加抽奖商品
     * @param $param
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * @throws \think\exception\PDOException
     */
    public function addAwardGoods($param) {
        if (empty($param['goods_id'])) throw new \Exception('商品id不能为空!');
        if (empty($param['attr_id'])) throw new \Exception('商品属性id不能为空!');
        if (empty($param['award_id'])) throw new \Exception('活动id不能为空!');
        $awardInfo = Award::get($param['award_id']);
        if (empty($awardInfo)) throw new \Exception('id不正确');
        $good = Goods::get($param['goods_id']);
        if (empty($good)) throw new \Exception('商品不存在!');
        $goodsAttr = GoodsAttr::get(['id'=>$param['attr_id'],'goods_id'=>$param['goods_id']]);
        if (empty($good)) throw new \Exception('商品不存在!');
        $param['img'] = $good->more['thumbnail'];
        $param['title'] = $good->goods_name.' '.$goodsAttr->field1_name.' '.$goodsAttr->field2_name;
        $find = AwardGoods::_whereCV(['award_id' => $param['award_id'], 'goods_id' => $param['goods_id'],'attr_id'=>$param['attr_id']])->find();
        if ($find) throw new \Exception('该商品已经在列表中!');
        try {
            AwardGoods::create($param, true);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    /**
     * 批量添加抽奖商品
     * @param $param
     * @return bool|void
     * @throws \Exception
     */
    public function addAllAwardGoods($param) {
        if (empty($param['goods_id'])) return;
        if (empty($param['award_id'])) throw new \Exception('活动id不能为空');
        foreach ($param['attr_id'] as $id) {
            try {
                $this->addAwardGoods(['award_id' => $param['award_id'], 'goods_id' => $param['goods_id'],'attr_id'=>$id]);
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
            }
        }
        return true;
    }

    /**
     * 获取抽奖商品
     * @param $param
     * @return array|bool|false|\PDOStatement|string|\think\Model
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function goodsAwardInfo($param){
        if (empty($param['id'])) throw new \Exception('抽奖商品id不能为空');
        $res = AwardGoods::objectInit()->with('goods,attrs')->whereCV('id',$param['id'])->find();
        if(empty($res)) throw new \Exception('抽奖商品id错误');
        return $res;
    }

    /**
     * 更新抽奖商品
     * @param $param
     * @return bool
     * @throws \think\exception\DbException
     */
    public function editAwardGoods($param){
        if (empty($param['id'])) throw new \Exception('抽奖商品id不能为空');
        $model = AwardGoods::get($param['id']);
        if(empty($model)) throw new \Exception('抽奖商品id错误');
        $sum = AwardGoods::_whereCV(['award_id'=>$param['award_id'],'id'=>['neq',$param['id']]])->sum('rate');
        if(($sum + $param['rate']) > 100) throw new \Exception('中奖概率不能超过100');
        $res = $model->allowField(true)->save($param);
        if($res === false) throw new \Exception('更新失败,请重新尝试!');
        return true;
    }

    /**
     * 删除抽奖商品
     * @param $param
     * @return bool
     * @throws \Exception
     */
    public function delAwardGoods($param){
        if(!$param['ids']) throw new \Exception('ids不能为空');
        $model = AwardGoods::objectInit();
        $res = $model->whereIn('id',$param['ids'])->delete();
        if(!$res) throw new \Exception('删除失败');
        return true;
    }

    /**
     * 抽奖商品列表
     * @return \think\Paginator
     * @throws \think\exception\DbException
     */
    public function goodsListPage($param){
        return AwardGoods::objectInit()->with('goods,attrs')->order('id', 'desc')->paginate(10, false, ['page' => $param['start']]);
    }

    /**
     * 获取中奖列表
     * @param $param
     * @param int $limit
     * @return \think\Paginator
     */
    public function awarnWinList($param, $limit = 20) {
        $where = [];
        if(!empty($param['order_no'])){
            $where['order_no'] = ['like','%'.trim($param['order_no']).'%'];
        }
        if(!empty(trim($param['nickname']))){
            $idArr = Member::where(['nickname'=>['like','%'.trim($param['nickname']).'%']])->column('id');
            $where['members_id'] = ['in',$idArr];
        }
        $list = AwardOrder::objectInit()->with(['user','awardGoods','award'])->where($where)->order('id', 'desc')->paginate($limit, false, ['page' => $param['start']]);
        //dump($list->toArray());die;
        return $list;
    }

    /**
     * 编辑中奖信息
     * @param $param
     * @return bool
     * @throws \think\exception\DbException
     */
    public function editAwardOrder($param){
        if (empty($param['id'])) throw new \Exception('中奖id不能为空');
        $model = AwardOrder::get($param['id']);
        if(empty($model)) throw new \Exception('中奖id错误');
        if($model->status == '已发货') throw new \Exception('已发货');
        $param['status'] = '已发货';
        $res = $model->allowField(true)->save($param);
        if($res === false) throw new \Exception('更新失败,请重新尝试!');
        if($model->AwardGoods->give_type == '商品') $this->subAttrStock($model->AwardGoods->attr_id);
        return true;
    }

    /**
     * 扣除商品属性库存
     * @param $attr_id
     * @return int|true
     * @throws \think\Exception
     * @throws \think\exception\DbException
     */
    public function subAttrStock($attr_id){
        $goodsAttr = GoodsAttr::get($attr_id);
        if(empty($goodsAttr))  throw new \Exception('商品属性不存在');
        return $goodsAttr->setDec('stock');
    }

    /**
     * 获取用户级别
     * @param int $id
     * @return string
     */
    public function memberType($id = 0){
        $arr = [1=>'掌柜',2=>'店主',3=>'经销商',4=>'区代',5=>'市代',6=>'省代'];
        $html = '';
        foreach($arr as $k=>$v){
            $selected = '';
            if($id == $k) $selected = 'selected';
            $html .="<option value={$k} {$selected}>{$v}</option>";
        }
        return $html;
    }

    /**
     * 位置
     * @param $id
     * @return string
     */
    public function positionType($id=0){
        $arr = [0=>'首页',1=>'点击跳转',2=>'分享'];
        $html = '';
        foreach($arr as $k=>$v){
            $selected = '';
            if($id == $k) $selected = 'selected';
            $html .="<option value={$k} {$selected}>{$v}</option>";
        }
        return $html;
    }

    /**
     * 商品列表
     * @param $param
     * @return \think\Paginator
     * @throws \think\exception\DbException
     */
    public function goodsIframe($param){
        $model = Goods::objectInit();
        $where = [];
        if(!empty($param['cateId'])){
            $where['cate_id'] = $param['cateId'];
        }
        if(!empty($param['keywords'])){
            $where['goods_name'] = ['like','%'.$param['keywords'].'%'];
        }
        if(!empty($param['id'])){
            $where['cate_id'] = $param['cateId'];
        }
        return $model->where($where)->order('id', 'desc')->paginate(10, false, ['page' => $param['start']]);
    }

    public function goodsCate($param){
        $GoodsCateSelfModel = new GoodsCateSelfModel();
        $id = !empty($param['cateId']) ? $param['cateId'] : 0;
        $categoriesTree = $GoodsCateSelfModel->adminCategoryTree($id); //分类树
        return $categoriesTree;
    }

    /**
     * 商品属性列表
     * @param $param
     * @return \think\Paginator
     * @throws \think\exception\DbException
     */
    public function goodsAttrIframe($param){
        $model = GoodsAttr::objectInit();
        $where = [];
        if(!empty($param['id'])){
            $where['goods_id'] = $param['id'];
        }
        return $model->with('goods')->where($where)->order('id', 'desc')->paginate(10, false, ['page' => $param['start']]);
    }


    /**
     * 余额、贡献值、谢谢参与
     * @param $param
     * @return bool
     * @throws \think\exception\DbException
     */
    public function addParamGoods($param) {
        if (empty($param['give_type'])) throw new \Exception('赠送类型不能为空!');
        if (empty($param['title'])) throw new \Exception('标题不能为空!');
        if (empty($param['award_id'])) throw new \Exception('活动id不能为空!');
        if (empty($param['img'])) throw new \Exception('图片不能为空!');
        if($param['give_type'] == '余额' || $param['give_type'] == '贡献值'){ //1余额 2贡献值
            if (empty($param['value'])) throw new \Exception($param['give_type'].'不能为空!');
        }
        $awardInfo = Award::get($param['award_id']);
        if (empty($awardInfo)) throw new \Exception('活动id不正确');
        try {
            AwardGoods::create($param, true);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return true;
    }

    /**
     *  指定中奖人列表
     * @param $param
     * @return \think\Paginator
     * @throws \think\exception\DbException
     */
    public function awardUser($param){
        return AwardUser::objectInit()->with('award,awardGoods,member')->order('id', 'desc')->paginate(10, false, ['page' => $param['start']]);
    }

    /**
     * 中奖信息
     * @param $param
     * @return AwardWin|null
     * @throws \think\exception\DbException
     */
    public function getAwardOrderInfo($param){
        if (empty($param['id'])) throw new \Exception('id不能为空!');
        $AwardOrder = AwardOrder::get($param['id']);
        if($AwardOrder) throw new \Exception('中奖信息不存在!');
        return $AwardOrder;
    }

    public function awardListSelect($id = 0){
        $arr = Award::objectInit()->all();
        $html = "<option value='0'>请选择</option>";
        foreach($arr as $k=>$v){
            $selected = '';
            if($id == $v->id) $selected = 'selected';
            $html .="<option value={$v->id} {$selected}>{$v->title}</option>";
        }
        return $html;
    }

    /**
     * 添加指定中奖人
     * @param $param
     * @return AwardUser
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function addAwardUser($param) {
        if (empty($param['uid'])) throw new \Exception('id不能为空');
        if (empty($param['award_id'])) throw new \Exception('抽奖活动id不能为空');
        if (empty($param['award_goods_id'])) throw new \Exception('抽奖商品id不能为空');
        $validate = new Validate([
            'uid' => 'require',
            'award_id' => 'require',
            'award_goods_id' => 'require',
        ], [
            'uid' => '会员id不能为空',
            'award_id' => '抽奖活动id不能为空',
            'award_goods_id' => '抽奖商品id不能为空',
        ]);
        if (!$validate->check($param)) throw new \Exception($validate->getError());
        $member = Member::objectInit()->where('id',$param['uid'])->find();
        if($member === null) throw new \Exception('会员不存在');
        $award = Award::objectInit()->where('id',$param['award_id'])->find();
        if($award === null) throw new \Exception('抽奖活动不存在');
        $awardGoods = AwardGoods::objectInit()->where('id',$param['award_goods_id'])->find();
        if($awardGoods === null) throw new \Exception('抽奖商品不存在');
        return AwardUser::create($param, true);
    }

    /**
     * 编辑指定中奖人
     * @param $param
     * @return bool
     * @throws \think\exception\DbException
     */
    public function editAwardUser($param){
        if (empty($param['id'])) throw new \Exception('id不能为空');
        $model = AwardUser::get($param['id']);
        $res = $model->allowField(true)->save($param);
        if($res === false) throw new \Exception('更新失败,请重新尝试!');
        return true;
    }

    /**
     * 删除指定人
     * @param $param
     * @return bool
     * @throws \Exception
     */
    public function deleteAwardUser($param){
        if(!$param['ids']) throw new \Exception('ids不能为空');
        $model = AwardUser::objectInit();
        $res = $model->whereIn('id',$param['ids'])->delete();
        if(!$res) throw new \Exception('删除失败');
        return true;
    }

    /**
     * 指定中奖人下拉选择活动获取抽奖商品
     * @param $param
     * @return bool|false|\PDOStatement|string|\think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getSelAwardGoods($param){
        if(!$param['award_id']) throw new \Exception('award_id不能为空');
        $res = AwardGoods::objectInit()->where('award_id',$param['award_id'])->field('id,title')->select();
        return $res;
    }

    /**
     * 指定中奖人信息
     * @param $param
     * @return array|bool|false|\PDOStatement|string|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAwardUserInfo($param){
        if(!$param['id']) throw new \Exception('id不能为空');
        $res = AwardUser::objectInit()->where('id',$param['id'])->find();
        $res->awardSelect = $this->getAwardSelect($res->award_id);
        $res->awardGoodsSelect = $this->getAwardGoodsSelect($res->award_id,$res->award_goods_id);
        return $res;
    }

    /**
     * 指定中奖人下拉抽奖活动
     * @param int $award_id
     * @return string
     * @throws \think\exception\DbException
     */
    public function getAwardSelect($award_id = 0){
        $arr = Award::objectInit()->all();
        $html = "<option value='0'>请选择</option>";
        foreach($arr as $k=>$v){
            $selected = '';
            if($award_id == $v->id) $selected = 'selected';
            $html .="<option value={$v->id} {$selected}>{$v->title}</option>";
        }
        return $html;
    }

    /**
     * 指定中奖人下拉抽奖商品
     * @param int $award_id
     * @param int $award_goods_id
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getAwardGoodsSelect($award_id = 0,$award_goods_id = 0){
        $arr = AwardGoods::objectInit()->where('award_id',$award_id)->select();
        $html = "<option value='0'>请选择</option>";
        foreach($arr as $k=>$v){
            $selected = '';
            if($award_goods_id == $v->id) $selected = 'selected';
            $html .="<option value={$v->id} {$selected}>{$v->title}</option>";
        }
        return $html;
    }

    public function addAwardPond($param){
        $awardPond = AwardPond::objectInit()->where('id',1)->find();
        return $awardPond->setInc('money',$param['money']);
    }

    public function getAwardPond(){
        $awardPond = AwardPond::objectInit()->where('id',1)->find();
        return $awardPond;
    }

    public function set($param){
        $awardPond = AwardPond::objectInit()->isUpdate(true)->where('id',1)->save($param);
        return $awardPond;
    }
}
