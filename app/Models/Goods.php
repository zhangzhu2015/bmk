<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/7/18 0018
 * Time: 16:55
 */
namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Goods extends Model
{
    /**
     * 与模型关联的数据表
     *
     * @var string
     */
    protected $table = 'goods';

    static public function getnewPromotionRowById($goods_id){
        $now = Carbon::now();
        //商品页面根据ID限时抢购  没开始的也要查询
        $res = DB::table('promotion')
            ->select('id','award_value','start_time','end_time','user_group','condition','sold_num','max_num','people')
            ->where('type', '=', 1)
            ->where('condition', '=', $goods_id)
            ->where('is_close', '=', 0)
            ->where(function ($q) use ($now) {
                $q->where('start_time', '>', $now)
                    ->orwhere(function ($query) use ($now) {
                        $query->where('start_time', '<', $now)
                            ->where('end_time', '>', $now);
                    });
            })
            ->first();
        return $res;
    }

    static public function getQuotaRowBygoodsId($goods_id){
        $now = Carbon::now();

        //查询活动是否开启
        $row = DB::table('quota_activity')
            ->select('id','name_ch','name_en','user_start_time','user_end_time','activity_type','is_open','status')
            ->where('is_open', '=', 1)
            ->where('status', '=', 1)
            ->where('is_open_buyer', '=', 1)
            ->where('user_end_time', '>=', $now)
            ->first();
        if(!$row){
            return array();
        }

        $res = DB::table('quota_goods')
            ->select('id as quota_goods_id','quota_activity_id','quota_activity_name_en','quota_category_relation_id','activity_start_time','activity_end_time','people','max','area','seller_id','goods_id','quota_price','product_detail','quota_sale')
            ->where('status', '=', 1)
            ->where('is_check', '=', 1)
            ->where('goods_id', '=', $goods_id)
            ->where('quota_activity_id', '=', $row->id)
            ->where('activity_end_time', '>=', $now)
            ->first();

        if ($res){
            $res->user_start_time = $row->user_start_time;
            $res->user_end_time = $row->user_end_time;
        }
        return $res;
    }

    //根据商品获取限时抢购详情
    static public function getPromotionRowBygoodsId($goods_id)
    {
        $now = date('Y-m-d H:i:s',time());
        //根据商品ID限时抢购
        $res = DB::table('promotion')->select('id', 'award_value', 'condition', 'start_time', 'end_time')
            ->whereRaw("type = 1 and `condition` = {$goods_id}  and is_close = 0 and ('".$now."' < start_time OR '".$now."' between start_time and end_time)")
            ->first();
        return $res;
    }


    //统计收藏总数
    static function getFavorites($user_id)
    {
        $items = DB::table('favorite')->where("user_id", $user_id)->get();
        $goodsIdArray   = array();

        foreach($items as $val)
        {
            $goodsIdArray[] = $val->rid;
        }

        //商品数据
        if(!empty($goodsIdArray))
        {
            $goodsIdStr = join(',',$goodsIdArray);
            $goodsList  = DB::table('goods')->whereRaw('id in ('.$goodsIdStr.')')->select('id', 'name', 'sell_price')->get();
        }

        foreach($items as $key => $val)
        {
            foreach($goodsList as $gkey => $goods)
            {
                if($goods->id == $val->rid)
                {
                    $items[$key]->goods_info = $goods;

                    //效率考虑,让goodsList循环次数减少
                    unset($goodsList[$gkey]);
                }
            }

            //如果相应的商品或者货品已经被删除了，
            if(!isset($items[$key]->goods_info))
            {
                DB::table('favorite')->where('id', $val->id)->delete();
                unset($items[$key]);
            }
        }
        return $items;
    }
}