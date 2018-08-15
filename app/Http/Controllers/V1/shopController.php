<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/7/26 0026
 * Time: 15:13
 */

namespace App\Http\Controllers\V1;


use App\Htpp\Traits\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class shopController extends Controller
{
    use ApiResponse;

    /**
     * @param \Illuminate\Http\Request $request
     * author: guoDing
     * createTime: 2018/7/26 0026 16:21
     * description: 店铺限时抢购列表
     */
    public function shopFlashList(Request $request)
    {
        $this->validate($request, [
            'page' => 'required',
            'pageSize' => 'required',
            'seller_id' => 'required',
        ]);

        $page = $request->input('page');
        $pageSize = $request->input('pageSize');
        $seller_id = $request->input('seller_id');
        $now = date('Y-m-d H:i:s',time());

        $goods_list = DB::table('promotion as p')
            ->leftJoin('goods as go', 'go.id', '=', 'p.condition')
            ->select('p.end_time','go.img as img','p.name as name','p.award_value as award_value','go.id as goods_id','p.id as p_id','start_time','end_time','sell_price','market_price','go.seller_id','go.comments','go.favorite','go.commodity_security','go.grade','go.store_nums','go.sale','go.name as goods_name','go.is_shipping','go.freight','go.template_id')
            ->where('go.seller_id', '=', $seller_id)
            ->where('p.type', '=', 1)
            ->where('p.is_close', '=', 0)
            ->where('go.is_del', '=', 0)
            ->where(function ($q) use ($now) {
                $q->where('start_time', '>', $now)
                    ->orwhere(function ($query) use ($now) {
                        $query->where('start_time', '<', $now)
                            ->where('end_time', '>', $now);
                    });
            })
            ->offset($pageSize * ($page - 1))
            ->limit($pageSize)
            ->orderBy('p.sort', 'asc')
            ->get()
            ->map(function ($v) {
                $diff = ($v->market_price - $v->award_value)/$v->market_price;
                $v->discount = $diff <= 0 ? '' : number_format($diff,2)*100;
                $v->promo = 'time';
                $v->active_id = $v->p_id;
                //获取商家信息
                $sellerInfo = DB::table('seller')->find($v->seller_id);
                if($v->template_id){
                    $temp = DB::table('shipping_template')->find($v->template_id);
                    if($temp->free_shipping == 1){
                        $v->is_shipping = 2;  //有运费
                    }else{
                        $v->is_shipping = 1;  //无运费
                    }
                }else{
                    if($v->freight > 0 ){
                        $v->is_shipping = 2;  //有运费
                    }else{
                        $v->is_shipping = 1;
                    }
                }
                $v->is_cashondelivery = $sellerInfo->is_cashondelivery; //等于1 的时候支持货到付款
                $v->img = getImgDir($v->img,300,300);
                $v->award_value = showPrice($v->award_value);
                return $v;
            });

            return $this->success($goods_list);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * author: guoDing
     * createTime: 2018/7/26 0026 16:15
     * description: 店铺分类
     */
    public function shopCategories(Request $request)
    {
        $this->validate($request, [
            'seller_id' => 'required'
        ]);
        $seller_id = $request->input('seller_id');

        $categoryRow = DB::table('seller_category')
            ->whereRaw("visibility = 1 and seller_id = ".$seller_id." and parent_id = 0")
            ->select('id','parent_id as pid','name')
            ->get()
            ->map(function ($v) use ($seller_id) {
                $array= Seller::getSubCate($v->id,$seller_id);
                if($array){
                    $ids = '('.join(',',$array).')';
                    //统计商品数量
                    $v->goods_num = DB::table('goods')->whereRaw('is_del =0 and seller_id ='.$seller_id.' and seller_category in '.$ids)->count();
                }else{
                    $v->goods_num = 0;
                }
                return $v;
            });
        return $this->success($categoryRow);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * author: guoDing
     * createTime: 2018/7/26 0026 17:43
     * description: 店铺特价商品    卖家这个功能已经去掉了
     */
    public function shopSaleList(Request $request)
    {
        $this->validate($request, [
            'seller_id' => 'required'
        ]);
        $seller_id = $request->input('seller_id');

        $promoList = DB::table('promotion')
            ->whereRaw("is_close = 0 and award_type = 7 and seller_id = $seller_id")->orderBy('sort', 'asc')
            ->get();
dd($promoList);
        foreach($promoList as $key => $val)
        {
            $intro = json_decode($val['intro'],true);
            $intro = array_keys($intro);
            $intro = join(",",$intro);
            $promoList[$key]['goodsList'] = $goodsDB->where("id in (".$intro.") and is_del = 0")
                ->field("id,name,img,sell_price,market_price,seller_id,comments,favorite,commodity_security,grade,store_nums,sale,is_shipping,template_id,freight")
                ->order("sort asc")
                ->select();
            foreach($promoList[$key]['goodsList'] as $k=>$v){
                if($promoList[$key]['goodsList'][$k]['img']){
                    $promoList[$key]['goodsList'][$k]['img'] = getImgDir($promoList[$key]['goodsList'][$k]['img']);
                }
                $temp =$promoList[$key]['goodsList'][$k];
                $diff = ($temp['market_price']-$temp['seller_price'])/$temp['market_price'];
                $temp['discount'] = $diff <= 0 ? '' : number_format($diff,2)*100;
                $temp['promo'] = 'time';
                $temp['active_id'] = $val['id'];
                //获取商家信息
                $sellerInfo = D('seller')->getInfo($temp['seller_id']);

                if($v['template_id']){
                    $temp1 = M('shipping_template')->find($v['template_id']);
                    if($temp1['free_shipping'] == 1){
                        $temp['is_shipping'] = 2;  //有运费
                    }else{
                        $temp['is_shipping'] = 1;  //无运费
                    }
                }else{
                    if($v['freight'] > 0 ){
                        $temp['is_shipping'] = 2;  //有运费
                    }else{
                        $temp['is_shipping'] = 1;
                    }
                }
                $temp['is_cashondelivery'] = $sellerInfo['is_cashondelivery']; //等于1 的时候支持货到付款
                $goodsList[] = $temp;
            }
        }
        $this->apiSuccess($goodsList);
    }
}