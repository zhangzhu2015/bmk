<?php

namespace App\Http\Controllers\V1;

use App\Htpp\Traits\ApiResponse;
use App\Librarys\Redisbmk;
use App\Models\Goods;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\QuotaActivity;

class TestController extends Controller
{
    use ApiResponse;

    /**
     * author: guoDing
     * createTime: 2018/7/17 0017 14:29
     * description: 首页banner图
     */
    public function banner()
    {
        $client = new Client();
        $res = $client->request('GET', env('BMK_HOST').'bapi/bannerList');
        $data =  json_decode($res->getBody());
        return $this->success($data->data);
    }

    /**
     * author: guoDing
     * createTime: 2018/7/18 0018 10:06
     * description: 首页拼单版块
     */
    public function groupBuy()
    {DB::connection()->enableQueryLog();
		//查找活动
		$qaRow = $this->getQuotaActivity();
        
		//获取当前时间
        $now = Carbon::now()->toDateTimeString();
        $lists = [];
        if ($qaRow) {
            //判断活动的有效性
            if($qaRow->user_start_time > $now){
                $qaRow->open_status = 2;  //活动未开启
            }
            if($qaRow->user_start_time <= $now &&  $now<= $qaRow->user_end_time){
                $qaRow->open_status = 2;  //活动已开启
            }
            if($now > $qaRow->user_end_time){
                $qaRow->open_status = 3;  //活动已结束
            }

            if ($now <= $qaRow->user_end_time) {
                $cate_ids = DB::table('quota_category_relation')->where('quota_activity_id', $qaRow->id)->pluck('id');
                $lists = $this->getGroupList($qaRow->id, $cate_ids, 1);
            }
            $qaRow->nowtime = $now;
            $qaRow->list = $lists;
            return $this->success($qaRow);
        } else {
            return $this->error(400,"暂无活动");
        }
    }
	
    /**
     * author: guoDing
     * createTime: 2018/7/19 0019 10:43
     * description: 首页拼单模块 See All
     */
    public function groupList(Request $request)
    {DB::connection()->enableQueryLog();
        $page = intval($request->input('page', 1));
        $pageSize = intval($request->input('pageSize', 6));
        $category_id = intval($request->input('category_id'));
        //查询活动是否关闭
		$now = Carbon::now()->toDateTimeString();
        $row = $this->getQuotaActivity($now);

        if(!$row){
            $this->error(400,'活动已结束');
        }
        $lists = $this->getGroupList($row->id, $category_id, 2, $pageSize, $page);
        return $this->success($lists);
    }
	
	//查询活动
	 public function getQuotaActivity($date = '') {
		 $result = DB::table('quota_activity') -> select('id','user_start_time','user_end_time')-> where('is_open_buyer', 1)-> where('is_open', 1)-> where('status',1)
		 		 ->where(function ($query) use ($date) {
                        if ($date) {
                            $query->where('user_end_time', '>', NOW());
                        }
                    }) 
				-> first();
		 return $result;
     }
	 
    /**
     * author: 
     * createTime: 2018/7/19 0019 10:43
     * description: 拼单列表
	 * $id intval 活动ID
	 * $category_id intval 类别id 默认为空
	 * $type intval 1首页2列表
	 * $pageSize intval 每页显示条数默认是6
	 * $page intval   页数默认1
     */
	public function getGroupList($id, $category_id = '', $type, $pageSize = 6, $page = 1)
	{
		//获取当前时间
		$now = Carbon::now()->toDateTimeString();		
		//拼接WHERE条件
		$wheres = '';
		
		if(!$id && intval($id) > 0) {
			$wheres .=  'AND `iwebshop_qg`.`quota_activity_id` = ' . $id;
		}
		
		//判断输出位置		
		if($type == 1){
			if(!$category_id){
				$wheres .= ' AND `iwebshop_qg`.`quota_category_relation_id` IN (' . $category_id . ')';
			}
			$wheres .= ' GROUP BY `quota_goods_id` ORDER BY `iwebshop_qg`.`sort` ASC, `iwebshop_qg`.`quota_sale` DESC, RAND() LIMIT 20';
		} else {
			$wheres .= ' GROUP BY `iwebshop_qg`.`id` ORDER BY `iwebshop_qg`.`quota_sale` DESC LIMIT ' . $pageSize . ' OFFSET '.($page - 1) * $pageSize;
		}
		
		$fileds = '`iwebshop_qg`.`id` AS `quota_goods_id`,`iwebshop_g`.`img`,`iwebshop_g`.`id` AS `goods_id`, SUM(iwebshop_qo.join_people) AS joined_people, `iwebshop_qg`.`quota_price`, `iwebshop_g`.`market_price`, `iwebshop_s`.`is_cashondelivery`,`iwebshop_g`.`is_shipping`,`iwebshop_qg`.`quota_activity_id`,`iwebshop_qg`.`quota_category_relation_id`,`iwebshop_g`.`name` AS `goods_name`,`iwebshop_qg`.`quota_sale`';
		//查询数据条数
		$lists = DB::select('SELECT ' . $fileds . ' FROM  `iwebshop_quota_goods` AS `iwebshop_qg` 
							LEFT JOIN `iwebshop_goods` AS `iwebshop_g` ON `iwebshop_g`.`id` = `iwebshop_qg`.`goods_id` 
							LEFT JOIN `iwebshop_seller` AS `iwebshop_s` ON `iwebshop_s`.`id` = `iwebshop_g`.`seller_id` 
							LEFT JOIN `iwebshop_quota_orders` AS `iwebshop_qo` ON `iwebshop_qo`.`quota_goods_id` = `iwebshop_qg`.`id` 
							WHERE `iwebshop_g`.`is_del` = 0 AND `iwebshop_s`.`is_del` = 0 AND `iwebshop_s`.`is_lock` = 0 AND `iwebshop_qg`.`status` = 1 AND `iwebshop_qg`.`is_check` = 1 AND `iwebshop_qg`.`activity_end_time` >= "'.$now.'" ' . $wheres);
		//遍历数据
        foreach($lists as $k => &$list){
            if(!$list->joined_people){
                $list->joined_people = '0';
            }
            $list->promo = 'quota';
        }
//		return $log = DB::getQueryLog();return $this->success($log);
		return $lists;
	}

    /**
     * author: guoDing
     * createTime: 2018/7/19 0019 17:37
     * description: 首页拼单版块see all 拼单滚动拼单
     */
    public function groupTipsList(Request $request)
    {
        $goods_id = $request->input('goods_id');

        //查询活动是否开启
        $qcRow = DB::table('quota_activity')
            ->where([
                ['is_open', '=', 1],
                ['is_open_buyer', '=', 1],
                ['status', '=', 1],
            ])
            ->first();

        $data = [];
        if (!$qcRow){
            return $this->success($data);
        }

        $now = date('Y-m-d H:i:s');
        if($qcRow->user_start_time <= $now &&  $now<= $qcRow->user_end_time){
            $lists = DB::table('quota_orders_detail as qod')
                ->select('u.username','u.head_ico','qod.user_id as user_id','qo.lead_user_id as own_id','g.id as goods_id','g.name','g.img','qo.people','qo.join_people','qo.quota_goods_id','qg.quota_activity_id')
                ->leftJoin('quota_orders as qo', 'qo.id', '=', 'qod.quota_orders_id')
                ->leftJoin('goods as g', 'g.id', '=', 'qo.goods_id')
                ->leftJoin('quota_goods as qg', 'qo.quota_goods_id', '=', 'qg.id')
                ->leftJoin('user as u', 'qod.user_id', '=', 'u.id')
                ->leftJoin('seller as s', 's.id', '=', 'g.seller_id')
                ->where([
                    ['qo.is_success', '=', 0],
                    ['qg.status', '=', 1],
                    ['g.is_del', '=', 0],
                    ['s.is_del', '=', 0],
                    ['s.is_lock', '=', 0],
                    ['qod.code', '=', 1],
                ])
                ->where(function ($q) use ($goods_id) {
                    if ($goods_id) {
                        $q->where('g.id', '=', $goods_id);
                    }
                })
                ->get();
            foreach($lists as $k => &$v){
                //拼单发起人
                if($v->user_id == $v->own_id){
                    $lists->action = 1;
                }else{
                    $lists->action = 2;
                }
            }
            return $this->success($lists);
        }else{
            return $this->success($data);
        }
    }
 
}

?>
