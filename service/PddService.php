<?php
namespace app\api\service;

use think\Db;

//拼多多
class PddService {
	
// 	//无权限调用此接口
// 	static function getGd()
// 	{
// 		$apiType = "pdd.goods.detail.get";
// 		$param   = [
// 				'goods_id'     => 3613073688,
// 				'access_token' => '74f85c207d3c4e1e8e18c4b2ed6994452fd0bd7d',
// 		];
		
// 		$res = PddService::getPddApi($apiType, $param);
// 		$res = json_decode($res, true);
// 		print_r($res);die;
// 	}
	
	
	/**
	 * 订单查询同步
	 */
	static function orderTb($start_time='', $end_time='',$cron=0)
	{
		$apiType = "pdd.ddk.order.list.increment.get";

		$param   = [
				'start_update_time'  => $start_time,
				'end_update_time'    => $end_time,
				'page_size'          => 10
		];
		
		$res = PddService::getPddApi($apiType, $param);
		$res = json_decode($res, true);
		
		$total_count = $res['order_list_get_response']['total_count'];
		if($total_count > 0)
		{
			$list = $res['order_list_get_response']['order_list'];
			$data = [];
			// dump($list);
			foreach($list as $k=>$v)
			{
				// dump($v);
				$order_no      = $v['order_sn'];
				$_order_status = intval($v['order_status']);
				$demo = [
						'order_no'           => $order_no,
						'goods_id'           => $v['goods_id'],
						'goods_name'         => $v['goods_name'],
						'goods_thumbnail_url'=> $v['goods_thumbnail_url'],
						'goods_quantity'     => $v['goods_quantity'],
						'goods_price'        => $v['goods_price']*0.01,
						'order_amount'       => $v['order_amount']*0.01,
						'p_id'               => $v['p_id'],
						'promotion_rate'     => $v['promotion_rate']*0.001,
						'promotion_amount'   => $v['promotion_amount'] * 0.01,
						'create_time'        => date('Y-m-d H:i:s', $v['order_create_time']),
						'pay_time'           => date('Y-m-d H:i:s', $v['order_pay_time']),
						'group_success_time' => date('Y-m-d H:i:s', $v['order_group_success_time']),
						'verify_time'        => date('Y-m-d H:i:s', $v['order_verify_time']),
						'modify_at_time'     => !empty($v['order_modify_at'])?date('Y-m-d H:i:s', $v['order_modify_at']):'',
						'order_settle_time'     => !empty($v['order_settle_time'])?date('Y-m-d H:i:s', $v['order_settle_time']):'',
				];
				 // -1 未支付; 0-已支付；1-已成团；2-确认收货；3-审核成功；4-审核失败（不可提现）；5-已经结算；8-非多多进宝商品（无佣金订单）
				if($_order_status === -1 || $_order_status === 4 || $_order_status === 8 )
				{
					$demo['order_status'] = 2;//订单失效
				}
				elseif($_order_status === 0 || $_order_status === 1 || $_order_status === 2)
				{
					$demo['order_status'] = 0;//订单付款
				}
				elseif($_order_status === 3 || $_order_status === 5)
				{
					$demo['order_status'] = 1;//订单结算
				}
				if ($demo['order_status'] == 2) {
					$demo['is_sx'] = 1;
					// 修改奖金纪录的状态
					Db::name("rebate_yg_demo")->where(['order_no'=>$order_no])->setField('is_sx',1);
					Db::name("rebate_demo")->where(['order_no'=>$order_no])->setField('is_sx',1);
				}
				$demo['cron_time'] = $cron;
				$obj = Db::name("pdd_order")->field("id")->where('order_no',$order_no)->find();
				if(!empty($obj))
				{
					Db::name("pdd_order")->where("id",$obj['id'])->update($demo);
					continue;
				}
				else
				{
					// $data[] = $demo;
					Db::name("pdd_order")->insert($demo);
				}
			}
			// dump($data);
			// Db::name("pdd_order")->insertAll($data);
		}
	}
	
	
	/**
	 * 获取商品优惠券链接  短链接
	 */
	public function getCouponUrl($goods_id, $pid)
	{
		$apiType = "pdd.ddk.goods.promotion.url.generate";
		$gid = json_encode([$goods_id]);
		$param   = [
				//推广位id
				'p_id'               => $pid,
				'goods_id_list'      => $gid,
				//'zs_duo_id'          =>2074024,
				//是否生成短链接
				//'generate_short_url' => 'true',
				//'multi_group'        => 'false',
		];
		
		$res = PddService::getPddApi($apiType, $param);
		$res = json_decode($res, true);
		// dump($res);
		$arr = $res['goods_promotion_url_generate_response']['goods_promotion_url_list'][0];
		
		//能唤起微信app的短链接
		return $arr['we_app_web_view_short_url'];
	}
	
	
	
	
	
	/**
	 * 创建推广位pid
	 * $number (范围1-100)
	 * @return array 返回的是创建的推广位数组
	 */
	static function createPid($number=1)
	{
		$apiType = "pdd.ddk.goods.pid.generate";
		$param    = [
				'number' => $number,
		];
		
		$res = PddService::getPddApi($apiType, $param);
		$res = json_decode($res, true);
		return $res['p_id_generate_response']['p_id_list'];
	}
	
	/**
	 * 获取已经生成的推广位pid信息
	 * page, 
	 * page_size(取值范围10-100)
	 */
	static function getPidList()
	{
		header("content-type:text/html;charset=utf-8");
		$apiType = "pdd.ddk.goods.pid.query";
		$param    = [
				'page'    => 1,
				'page_size'=>100,
		];
		
		$res = PddService::getPddApi($apiType, $param);
		$res = json_decode($res, true);
		print_r($res);
		die;
	}
	
	/**
	 * 获取商品详情
	 * 经测试可用
	 * @return Array
	 */
	static function getGoodsDetails($goods_id)
	{
		$apiType = 'pdd.ddk.goods.detail';
		//$arr = [3613073688];
		$str = json_encode([$goods_id]);
		//只返回有优惠券的商品
		$param   = [
				'goods_id_list'=> $str
		];
		$res     = self::getPddApi($apiType, $param);
		$res     = json_decode($res, true);
		return $res['goods_detail_response']['goods_details'][0];
	}
	
	
	/**
	 * 查询商品列表
	 * page_size 取值范围(10-100)
	 */
	static function getGoodsList()
	{
		
		$apiType = 'pdd.ddk.goods.search';
		//只返回有优惠券的商品
		$param   = [
				//商品关键字
				'keyword'     => '男装',
				'page'        => 1,
				'page_size'   => 10,
				//排序方式
			    'sort_type'   => 4,
				//是否有优惠券
				'with_coupon' => 'true',
				//商品类目id
				'cat_id'      =>69,
				
		];
		$res     = self::getPddApi($apiType, $param);
		$res     = json_decode($res, true);
		
		header("content-type:text/html;charset=utf-8");
		print_r($res);
		die;
		
	}
	
	
	/**
	 * 访问拼多多指定api
	 * @param unknown $apiType
	 * @param unknown $param
	 * @return mixed
	 */
	static function getPddApi($apiType, $param)
	{
		$config        = configSet()['collect'];
		$client_id     = trim($config['pdd_client_id']);
		$client_secret = trim($config['pdd_client_secret']);
		$url                = 'http://gw-api.pinduoduo.com/api/router';
		$param['client_id'] = $client_id;
		$param['type']      = $apiType;
		$param['data_type'] = 'JSON';
		$param['timestamp'] = self::getMillisecond();
		ksort($param);    //  排序
		$str = '';      //  拼接的字符串
		foreach ($param as $k => $v) $str .= $k . $v;
		$sign = strtoupper(md5($client_secret. $str . $client_secret));    //  生成签名    MD5加密转大写
		$param['sign'] = $sign;
	    return curl_post($url, $param);
	}
	
	
	/**
	 * 将拼多多商品分类保存到数据库
	 */
	static function setGoodsCate()
	{
		$apiType = 'pdd.goods.cats.get';
		$param   = ['parent_cat_id'=> 0];
		$res     = self::getPddApi($apiType, $param);
		$res     = json_decode($res, true);
		$arr     = $res['goods_cats_get_response']['goods_cats_list'];
		$_data = [];
		foreach($arr as $k=>$v)
		{
			$_demo = [];
			
			if($v['level'] == 1)
			{
				$_demo['cate_id'] = $v['cat_id'];
				$_demo['cate_name']=$v['cat_name'];
				$_data[] = $_demo;
			}
		}
		Db::query("truncate table xcx_pdd_goods_cate");
		Db::name("pdd_goods_cate")->insertAll($_data);
		
	}
	
	
	//  获取13位时间戳
	static function getMillisecond()
	{
		list($t1, $t2) = explode(' ', microtime());
		return sprintf('%.0f', (floatval($t1) + floatval($t2)) * 1000);
	}
	
	
	
	
	/**
	 * 获取并设置access_token
	 * 将access_token保存到config表中
	 * @return mixed
	 */
	static function get_and_set_access_token( $code )
	{
		$url = "http://open-api.pinduoduo.com/oauth/token";
		/*
		$param = [
				'client_id'     => 'a67ca30bf09249e9aa3a1e9f2b232cff',
				'client_secret' => '1b0581e9c0ff13b2b59a168d742ae66c35272534',
				'grant_type'    => 'authorization_code',
				'code'          => '9199ff2615c64efcb693f807106721f0ec4bb3b4',
				'redirect_uri'  => 'http://www.baidu.com',
		];
		*/
		$config        = configSet()['collect'];
		$client_id     = $config['pdd_client_id'];
		$client_secret = $config['pdd_client_secret'];
		
		$param = [
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => 'http://www.baidu.com',
		];
		
		$res = curl_post($url, $param);
		$arr = json_decode($res, true);
		
		if(isset($arr['access_token']) )
		{
			//将access_token保存到数据库
			Db::name("pdd_jtt")->data(['access_token'=>$arr['access_token'], 'refresh_token'=>$arr['refresh_token']])->where("type='pdd'")->update();
		}
		
	}
	
	
	/**
	 * 刷新access_token
	 */
	static function refresh_access_token()
	{
		$url = "http://open-api.pinduoduo.com/oauth/token";
		$config        = configSet()['collect'];
		
		$client_id     = trim($config['pdd_client_id']);
		$client_secret = trim($config['pdd_client_secret']);
		
		$_config = Db::name("pdd_jtt")->where("type='pdd'")->find();
		$refresh_token = $_config['refresh_token'];
		
		$param = [
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
		];
		
		$res = curl_post($url, $param);
		$arr = json_decode($res, true);
		print_r($arr);
		if(isset($arr['access_token']) )
		{
			//将access_token保存到数据库
			Db::name("pdd_jtt")->data(['access_token'=>$arr['access_token'], 'refresh_token'=>$arr['refresh_token']])->where("type='pdd'")->update();
		}
	}
	
	
}