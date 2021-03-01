<?php
namespace app\api\service;

use think\Db;

//京东联盟
class JdService 
{

	private static $baseUrl = "http://api.josapi.net";
	

	static function getConfig()
	{
		$config = Db::name("config")->where("id",1)->find();
		return $config();
	}
	
	static function getConfigCollect()
	{
		$config = Db::name("config")->where("id",1)->find();
		$collect = $config['collect'];
		$config = json_decode($collect,true);
		$config = array_map(function($v){
			return trim($v);
		}, $config);
		return $config;
	}
	
	
	static function searchGoods($keywords,$page=1)
	{
		
		$config = self::getConfigCollect();
		
		$authkey = $config['jd_sq_key'];//京东联盟授权的key (需全局配置)
		$unionid = $config['unionid'];//联盟id            (需全局配置)
		$siteid  = $config['jd_appid'];//appid              (需全局配置)
		
		
		//$url = "http://jdapi.vephp.com/iteminfo?authkey={$authkey}&unionid={$unionid}&pidname={$pidname}&type=2&siteid={$siteid}";
		$url = "http://jdapi.vephp.com/conponitems?keyword={$keywords}&page={$page}&pagesize=100";
		$res = curl_post($url);
		
		//$res = '{"error":0,"msg":"获取成功！","data":{"resultList":{"467":1585184679,"789":1585237489},"siteId":1508685015,"type":2,"unionId":1001058994}}';
		$res = json_decode($res, true);
		
		if($res['error'] == 0 && $res['total'] > 0)
		{
			print_r($res['data']);die;
		}
		
	}/**
	 * 批量创建推广位
	 * @param array $param
	 * @param 
	 * 每次最多创建50个
	 */
	static function createPid($pidname)
	{
		$config = self::getConfigCollect();
		
		$authkey = $config['jd_sq_key'];//京东联盟授权的key (需全局配置)
		$unionid = $config['unionid'];//联盟id            (需全局配置)
		$siteid  = $config['jd_appid'];//appid              (需全局配置)
		
		//$url = "http://jdapi.vephp.com/addpid?authkey=ece3b6ab1c8b87a7e562df1aa835b361710341676aa3f5e17508b2ae1fd371ba2b2110857100b434&unionid=1001058994&pidname=467,789&type=2&siteid=1508685015";
		
		$url = self::$baseUrl."/createpid?key=".$authkey."&unionId=".$unionid."&spaceNameList=".$pidname."&type=2&siteId=".$siteid;
		
		$res = curl_post2($url);
		
		//$res = '{"error":0,"msg":"获取成功！","data":{"resultList":{"467":1585184679,"789":1585237489},"siteId":1508685015,"type":2,"unionId":1001058994}}';
		$res = json_decode($res, true);
		$arr = $res['data']['resultList'];
		return $arr;
	}
	
	
	/**
	 * 批量查询推广位
	 * @return mixed
	 */
	static function queryPid($page=1)
	{
		$config = self::getConfigCollect();
		
		$authkey = $config['jd_sq_key'];//京东联盟授权的key (需全局配置)
		$unionid = $config['unionid'];//联盟id            (需全局配置)
		$siteid  = $config['jd_appid'];//appid              (需全局配置)
		
		$url = "http://api.josapi.net/querypid?authkey=".$authkey."&unionid=".$unionid."&uniontype=1&page={$page}&pagesize=100";
		
		$res = curl_post($url);
		
		$res = json_decode($res, true);
		if(isset($res['data']['result']) && count($res['data']['result'])>0)
		{
			return $res['data']['result'];
		}		
	}

	
	
	/*
	 //调用没权限，无法使用
	static function createPid2()
	{
		include_once EXTEND_PATH."jdsdk/JdSdk.php";
		include_once EXTEND_PATH."jdsdk/jd/request/ServicePromotionCreatePromotionSiteBatchRequest.php";
		$req = new \ServicePromotionCreatePromotionSiteBatchRequest();
		$c   = new \JdClient();
		$c->appKey      = '9c9300df386b4b9aa047214b11e78807';//需要全局配置
		$c->appSecret   = '206c0143af394ee5b5c632d1fc7e5f24';//需要全局配置
		$c->accessToken = '1057dafb-674d-419f-95a8-cd0d162cbb58';//需要全局配置
		$c->serverUrl   = 'https://api.jd.com/routerjson';
		
		//站长id
		$req->putOtherTextParam('unionId', 1001254565);//需要全局配置
		
		//联盟系统领取的key
		//在京东联盟里面可以看到
		$req->putOtherTextParam('key', 'ece3b6ab1c8b87a7fb01ac1d9188e9176d0792c129cbdb02c6017394882f8adbb55555d8bd696366');//需要全局配置
		
		//1表示cps推广
		$req->putOtherTextParam('unionType ', 1);
		
		//2表示app推广位
		$req->putOtherTextParam('type', 2);
		
		//siteId此刻填写的appid
		$req->putOtherTextParam('siteId', 1587348147);//需要全局配置
		
		//推广位名称
		$req->putOtherTextParam('spaceName ', '123');
		
		$resp = $c->execute($req, $c->accessToken);
		$resp = json_encode($resp, JSON_UNESCAPED_UNICODE);
		return json_decode($resp, true);
	}
	*/
	
	
	/*
	//access_token一年的有效期暂时不需要重新刷新
	static function refresh_token()
	{
		$url = "https://oauth.jd.com/oauth/token";
		$data = [
				'client_id'     => 'F55CCB1CBE528477D8CEAC90DD64E608',
				'client_secret' => 'd5e4e32c87b34cbd9ba3a9a481188603',
				'grant_type'    => 'refresh_token',
				'refresh_token' => '96410d71-3e85-407a-8c8c-90fbae8cc07f',
		];
		$res = curl_post($url, $data);
	}
	*/
	
	// 查询订单
	static function orderSearch($datetime, $page=1, $pagesize = 100,$cron=0)
	{
		// $config = self::getConfigCollect();
		// // $authkey = $config['jd_sq_key'];//京东联盟授权的key (需全局配置)
		// $authkey = 'ece3b6ab1c8b87a7fb01ac1d9188e917e989829e35717beea7ec1f42c3b9c843151dc70ee9ed4060';//京东联盟授权的key (需全局配置)
		// $url = "http://api.josapi.net/importorders?authkey=".$authkey."&time=".$datetime."&page=".$page."&pagesize=".$pagesize;
		// $res = curl_post($url);
		// dump($res);
		// $res = json_decode($res, true);

		// // dump($res['data']);
		// if(!empty($res['data']))
		// {
		// 	$data = $res['data'];
		// 	$_data = [];
		// 	foreach($data as $k=>$v)
		// 	{
				
		// 		$order_no = $v['orderId'];
				
		// 		$demo = [
		// 				'order_no'         => $v['orderId'],
		// 				'create_time'      => date("Y-m-d H:i:s",$v['orderTime']*0.001),
		// 				'finish_time'      => $v['finishTime']==0?'':date("Y-m-d H:i:s",$v['finishTime']),
		// 				'order_amount'     => $v['totalMoney'],
		// 				'cos_price'        => $v['cosPrice'],
		// 				'commission_money' => $v['commission'],
		// 				'itemid'  		   => $v['skus'][0]['skuId'],
		// 				'commission_rate'  => $v['skus'][0]['commissionRate'],
		// 				'goods_name'       => $v['skus'][0]['skuName'],
		// 				'goods_price'      => $v['skus'][0]['price'],
		// 				'goods_nums'       => $v['skus'][0]['skuNum'],
		// 				'pid'              => $v['positionId'],
		// 		];
		// 		if ($v['yn'] == 0) {
		// 			$demo['status'] = 2;
		// 		}else{
		// 			$demo['status'] = 0;

					
		// 			$validCode = $v['skus'][0]['validCode'];
				
		// 			// if($validCode>=-1 && $validCode<=15)
		// 			// {
		// 			// 	$demo['status'] = 2;//订单失效
		// 			// }
					
		// 			// if($validCode == 16 || $validCode == 17)
		// 			// {
		// 			// 	$demo['status'] = 0;//订单付款
		// 			// }
					
		// 			if($validCode == 18)
		// 			{
		// 				$demo['status'] = 1;//订单结算
		// 			}
		// 		}
				
		// 		$order_obj = Db::name("jd_order")->where("order_no='{$order_no}'")->find();
		// 		if ($demo['status'] == 2) {
		// 			$demo['is_sx'] = 1;
		// 			// 修改奖金纪录的状态
		// 			Db::name("rebate_yg_demo")->where(['order_no'=>$order_no])->setField('is_sx',1);
		// 		}
		// 		$demo['cron_time'] = $cron;
		// 		if(!empty($order_obj))
		// 		{
					
		// 			Db::name("jd_order")->where(['order_no'=>$order_no])->update($demo);
		// 		}
		// 		else
		// 		{
		// 			Db::name("jd_order")->data($demo)->insert();
		// 		}
		// 	}
		// }else{
		// 	return "并无订单";
		// }

		$collect  = self::getConfigCollect();
		// dump($config);
		// $collect = $config['collect'];
		$appid   = $collect['jtt_id'];
		$appkey  = $collect['jtt_key'];
		$unionid = $collect['unionid'];
		$token = $collect['jd_sq_key'];
		
		$url  = "http://japi.jingtuitui.com/api/get_order";
		//2019-04-17 11:57:54
		//$datetime = date("YmdH", strtotime("2019-04-11 08:40:17"));
		$data = [
				'appid' => $appid,
				'appkey' => $appkey,
				'unionid' => $unionid,
				'time'    => $datetime,
				'num'     => 100,
				'token'   => $token
		];
		
		$res = curl_post($url, $data);
		
		$arr = json_decode($res, true);
		//header("content-type:text/html;charset=utf-8");
		dump($arr);
		
		if(isset($arr['result']['data']) && count($arr['result']['data']) > 0 )
		{
			$list = $arr['result']['data'];
			if(count($list) > 0)
			{
				foreach($list as $k=>$v)
				{
					$validCode = $v['skuList'][0]['validCode'];
					$status = 0;
					if($validCode < 16)
					{
						$status = 2;//已失效
					}
					if($validCode == 16 || $validCode == 17)
					{
						$status = 0;//已付款
					}
					if($validCode == 18)
					{
						$status = 1;//已结算
					}
					
					
					$demo = [
							'order_no'    => $v['orderId'],
							'create_time' => date("Y-m-d H:i:s",$v['orderTime']*0.001),
							'finish_time' => $v['payMonth']==0?'':date("Y-m-d H:i:s",$v['payMonth']),
							'order_amount'=> $v['skuList'][0]['price']*$v['skuList'][0]['skuNum'],
							'cos_price'   => $v['skuList'][0]['estimateCosPrice'],
							'commission_money' => $v['skuList'][0]['estimateFee'],
							'itemid'           => $v['skuList'][0]['skuId'],
							'commission_rate'  => $v['skuList'][0]['commissionRate'],
							'goods_name'       => $v['skuList'][0]['skuName'],
							'goods_price'      => $v['skuList'][0]['price'],
							'goods_nums'       => $v['skuList'][0]['skuNum'],
							'pid'              => $v['skuList'][0]['positionId'],
							'status' => $status,
							
					];
					
					$order_no = $v['orderId'];
					$order_obj = Db::name("jd_order")->where("order_no='{$order_no}'")->find();
					if ($demo['status'] == 2) {
						$demo['is_sx'] = 1;
						// 修改奖金纪录的状态
						Db::name("rebate_yg_demo")->where(['order_no'=>$order_no])->setField('is_sx',1);
						Db::name("rebate_demo")->where(['order_no'=>$order_no])->setField('is_sx',1);
					}
					$demo['cron_time'] = $cron;
					if(!empty($order_obj))
					{
						
						Db::name("jd_order")->where(['order_no'=>$order_no])->update($demo);
					}
					else
					{
						Db::name("jd_order")->data($demo)->insert();
					}
				}
			}
		}else{
			return "并无订单";
		}
		
	}
	
	
	
	/**
	 * 订单查询(应该会用到这个) (可用)
	 */
	static function orderSearch2($page=1)
	{
		include_once EXTEND_PATH."jdsdk/JdSdk.php";
		include_once EXTEND_PATH."jdsdk/jd/request/UnionServiceQueryOrderListRequest.php";
		$req = new \UnionServiceQueryOrderListRequest();
		$c   = new \JdClient();
		$c->appKey      = 'F55CCB1CBE528477D8CEAC90DD64E608';//需要全局配置
		$c->appSecret   = 'd5e4e32c87b34cbd9ba3a9a481188603';//需要全局配置
		$c->accessToken = '1057dafb-674d-419f-95a8-cd0d162cbb58';//需要全局配置
		$c->serverUrl   = 'https://api.jd.com/routerjson';
		
		//站长id
		$req->putOtherTextParam('unionId', 1001058994);//需要全局配置
		$req->putOtherTextParam('time', date('Y-m-d H:i:s'));
		$req->putOtherTextParam('pageIndex', $page);
		$req->putOtherTextParam('pageSize', 500);
		$resp = $c->execute($req, $c->accessToken);
		$resp = json_encode($resp, JSON_UNESCAPED_UNICODE);
		return json_decode($resp, true);
	}

	static function getGoodsObjImg($itemId, $uid=null)
	{
	    $url = "http://api.josapi.net/goodsquery?skuIds={$itemId}";
	    $res = curl_get($url);
	    $res = json_decode($res, true);
	    $data = $res['data'][0];
	    
	    $image= !empty($data['imageInfo']['imageList'][0]) ? $data['imageInfo']['imageList'][0]['url'] : '';
	    return [
	        'img'        => $image,//商品图片地址
	    ];
	}

	
	
	
}