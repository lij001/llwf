<?php


namespace app\api\service;


use app\BaseService;
use think\Db;

class GoodsCommentService extends BaseService {
    public function addComment($param,$uid=null,$ajax=true)
    {
        $post = $param;
        try {
            if($uid===null){
                $uid = TokenService::getCurrentUid();
            }
            if (empty($post['order_no'])) throw new \Exception('订单编号不能为空!');
            $headSn=substr($post['order_no'], 0, 2);
            if ($headSn == 'ME' || $headSn=='MS') {
                $b = Db::name('ali_order')->where('uid', $uid)->where('order_no', $post['order_no'])->find();
            } else {
                $b = Db::name('order_detail')->where('order_no', $post['order_no'])->find();
            }
            if (!$b) throw new \Exception('未找到订单');
            $data = array_key_exists('goods_id', $b) ? ['gid' => $b['goods_id'], 'spid' => $b['spid']] : ['gid' => $b['feedId'], 'spid' => 0];
            if(!empty(Db::name('goods_comment_v2')->where(['uid'=>$uid,'gid'=>$data['gid'],'order_no'=>$post['order_no']])->find()))throw new \Exception('该商品评价过了.');
            if (empty($post['info'])) throw new \Exception('评价信息不能为空!');
            if (empty($post['degree_of_match_star']) || empty($post['logistics_service_star']) || empty($post['service_attitude_star'])) throw new \Exception('评分不能为空!');
            $star = round(($post['degree_of_match_star'] + $post['logistics_service_star'] + $post['service_attitude_star']) / 3, 1);
            if(!empty($post['info_img'])){
                $post['info_img']=htmlspecialchars_decode($post['info_img']);
            }
            $data = array_merge($data, [
                'uid' => $uid,
                'attr_id' => '',
                'order_no' => $post['order_no'],
                'info' => $post['info'],
                'info_img' => is_array(json_decode($post['info_img'], true)) ? $post['info_img'] : '[]',
                'status' => 0,
                'create_time' => time(),
                'update_time' => time(),
                'star' => $star,
                'degree_of_match_star' => $post['degree_of_match_star'],
                'logistics_service_star' => $post['logistics_service_star'],
                'service_attitude_star' => $post['service_attitude_star']
            ]);
            if (!Db::name('goods_comment_v2')->insert($data))
                throw new \Exception('评分插入失败!');

            if ($headSn == 'ME' || $headSn=='MS') {
                Db::name('ali_order')->where('uid', $uid)->where('order_no', $post['order_no'])->update(['comment'=>1]);
            }else{
                Db::name('order_info')->where('order_no', $post['order_no'])->update(['comment'=>1]);
            }
            if(!$ajax)return ;
            return true;
        } catch (\Exception $e) {
            if(!$ajax)return ;
            throw new \Exception($e->getMessage());
        }
    }
}
