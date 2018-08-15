<?php

namespace App\Models;

use App\Librarys\Delivery;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Voucher extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /**
     * @param $seller_id
     * @return mixed
     */
    static public function getSellerVoucherCount($seller_id, $user_id){
        //查询用户在这个店铺的所有优惠券
        $now = date('Y-m-d');
        $count = DB::table('voucher_str as vs')
            ->leftJoin('voucher as v', 'v.id', '=', 'vs.voucher_id')
            ->leftJoin('seller as s', 's.id', '=', 'v.seller_id')
            ->where('vs.user_id', $user_id)->whereNotNull('v.id')->where('v.end_time', '>=', $now)
            ->where('vs.use_status', 2)
            ->where('v.seller_id', $seller_id)
            ->count();
        return $count;
    }

    /**
     * @param array $voucher
     * @return array
     * Author: wangding
     * CreateTime: 2018/2/22 15:04
     * Description: 排序优惠卷
     *              商品券（门槛从低到高）（同门槛价值从高到低）>折扣券（门槛从低到高）（折扣率从从大到小）>邮费券（门槛从低到高）（free shipping>shipping fee(从大到小)）
     * type_way:    1满减优惠 2打折优惠 3物流减免
     * type_range:  1全店通用 2部分商品
     */
    public function setVoucherRank($voucher) {
        $voucher = $voucher->groupBy('type_way');
        $voucher[1] = empty($voucher[1]) ? collect([]) : $voucher[1];
        $voucher[2] = empty($voucher[2]) ? collect([]) : $voucher[2];
        $voucher[3] = empty($voucher[3]) ? collect([]) : $voucher[3];
        return $voucher[1]->merge($voucher[2])->merge($voucher[3]);
    }

    /**
     * @param $user_id
     * @param $seller_id
     * @return \Illuminate\Support\Collection
     * Author: wangding
     * CreateTime: 2018/2/22 17:49
     * Description: 查询在这家店铺的优惠卷
     */
    public function getUserVoucherBySeller($seller_id, $user_id) {
        return DB::table('voucher_str as vs')
            ->where([
                ['user_id', $user_id],
                ['share_status', 0],
                ['use_status', 2],
            ])->join('voucher as v', function($join) use ($seller_id) {
                $join->on('vs.voucher_id', 'v.id')->where([ ['v.end_time', '>', DB::raw('now()')], ['v.seller_id', $seller_id] ]);
            })->select('v.id', 'v.limit', 'v.value', 'v.type_way', 'v.type_range', 'v.start_time', 'v.end_time', 'v.goods_id', 'v.seller_id', 'vs.id as vs_id')
            ->distinct('vs.voucher_id')->orderBy('v.limit', 'asc')->orderBy('v.value', 'desc')->get();
    }

    /**
     * @param array $voucher
     * @return mixed
     * Author: wangding
     * CreateTime: 2018/2/22 16:18
     * Description: 设置优惠券的显示信息
     * type_way:    1满减优惠 2打折优惠 3物流减免
     * type_range:  1全店通用 2部分商品
     */
    public function setVoucherText($voucher) {
        return collect($voucher)->each(function ($item) {
            switch((int)$item->type_way){
                case 1:
                    $item->value_text = '₱'.(int)$item->value;
                    break;
                case 2:
                    $item->value_text = (int)$item->value.'% off';
                    break;
                case 3:
                    $item->value_text = (int)$item->value === 0 ? 'Free Shipping' : 'Shipping Fee ₱'.(int)$item->value;
            }
            $item->limit_text = (int)$item->limit === 0 ? 'No Limit' : 'Your order of ₱'.(int)$item->limit.' or more';
            $item->range_text = (int)$item->type_range === 1 ? 'All products in shop' : 'Specific products in shop';
            $item->valid_time = Carbon::parse($item->start_time)->format('M j,Y') .' - '. Carbon::parse($item->end_time)->format('M j,Y');
        });
    }

    /**
     * @param array $voucher        优惠券详情
     * @param array $carts_info     购物车详情  $arr = [ ['goods_id'=>2000, 'num'=>2, 'seller_price'=>3888, 'products_id'=>11], ['goods_id'=>2814, 'num'=>5, 'seller_price'=>6666, 'products_id'=>12] ]
     * @param int $province
     * Author: wangding
     * CreateTime: 2018/2/22 17:49
     * Description: 判断优购物车优惠卷是否能使用以及凑单
     *              type_way:     1满减优惠 2打折优惠 3物流减免
     *              type_range:   1全店通用 2部分商品
     *              3、Add-On显示逻辑：
     *              1)不够优惠券使用条件显示“Add-On”
     *              2)满足优惠券使用条件的显示“Add-On”
     *              3)特殊：邮费券永远不显示“Add-On”
     *              点击跳转：跳转到该优惠券可使用的商品列表
     *              4、Can be used显示逻辑：购物车中的商品满足了该优惠券的使用条件（只计算正常状态的商品）
     *              5、购物车优惠券不可使用原因类型：
     *              1)优惠券只能购买一个商品
     *              2)订单金额不满XXX,点击“Add-On”凑足金额即可使用该优惠券
     *              3)指定商品金额不满XXX，点击“Add-On”凑足金额即可使用该优惠券
     *              4)使用时间未到
     */
    public function setVoucherCanBeUsed($voucher, $carts_info, $province = 0) {
        // 计算购物车的总价
        $total =  $this->getAllGoodsPrice($carts_info);

        //判断优惠券的使用条件
        $voucher->each(function($item) use ($province, $carts_info, $total) {
            switch((int)$item->type_range){
                case 1:
                    switch((int)$item->type_way){
                        case 1:
                            if($total < (int)$item->limit){
                                $item->add_on = true;
                                // 需要凑单的 不满足使用条件
                                $item->can_be_used = false;
                            }else{
                                $item->add_on = false;
                                // 判断时间
                                $item->can_be_used = $this->getCanBeUsedByTime($item->start_time);
                            }
                            $province !== 0 && $item->true_value = $this->getVoucherTrueValue($item, $total, $province);
                            break;
                        case 2;
                            if($total < (int)$item->limit){
                                $item->add_on = true;
                                $item->can_be_used = false;
                            }else{
                                $item->add_on = false;
                                // 判断时间
                                $item->can_be_used = $this->getCanBeUsedByTime($item->start_time);
                            }
                            $province !== 0 && $item->true_value = $this->getVoucherTrueValue($item, $total, $province);
                            break;
                        case 3;
                            $item->add_on = false;
                            // todo
                            $item->can_be_used = $this->getCanBeUsedByTime($item->start_time);
                            $province !== 0 && $item->true_value = $this->getVoucherTrueValue($item, $total, $province);
                            break;
                    }
                    break;
                case 2;
                    switch((int)$item->type_way){
                        case 1:
                            $total2 = $this->getSomeGoodsPrice(explode(',', $item->goods_id), $carts_info);
                            if((int)$total2 === 0 || $total2 < (int)$item->limit ){  // 总价小于最低限制 或则 总价为0
                                $item->add_on = true;
                                $item->can_be_used = false;
                            }else{
                                $item->add_on = false;
                                // 判断时间
                                $item->can_be_used = $this->getCanBeUsedByTime($item->start_time);
                            }
                            $province !== 0 && $item->true_value = $this->getVoucherTrueValue($item, $total, $province, $carts_info);
                            break;
                        case 2;
                            $total2 = $this->getSomeGoodsPrice(explode(',', $item->goods_id), $carts_info);
                            if((int)$total2 === 0 || $total2 < (int)$item->limit){
                                $item->add_on = true;
                                $item->can_be_used = false;
                            }else{
                                $item->add_on = false;
                                // 判断时间
                                $item->can_be_used = $this->getCanBeUsedByTime($item->start_time);
                            }
                            $province !== 0 && $item->true_value = $this->getVoucherTrueValue($item, $total, $province, $carts_info);
                            break;
                        case 3;
                            $item->add_on = false;
                            //
                            $item->can_be_used = $this->getCanBeUsedByTime($item->start_time);
                            $province !== 0 && $item->true_value = $this->getVoucherTrueValue($item, $total, $province, $carts_info);
                            break;
                    }
                    break;
            }
        });
        return $voucher;
    }


    /**
     * @param array    $goods_id
     * @param array     $carts_info
     * Author: wangding
     * CreateTime: 2018/2/23 15:11
     * Description:     获取购物车 部分商品的价格总和
     */
    public function getSomeGoodsPrice($goods_id, $carts_info) {
        $total =  collect($carts_info)->map(function($item) use ($goods_id) {
            if(in_array($item['goods_id'], $goods_id))
                return (isset($item['num']) ? $item['num'] : $item['count']) * (isset($item['seller_price']) ? $item['seller_price'] : $item['sell_price']);
            return 0;
        })->sum();
        return $total;
    }


    /**
     * @param array $carts_info
     * @return mixed
     * Author: wangding
     * CreateTime: 2018/2/28 14:42
     * Description: 获取购物车 价格总和
     */
    public function getAllGoodsPrice($carts_info) {
        return collect($carts_info)->map(function($item){
            return (isset($item['num']) ? $item['num'] : $item['count']) * (isset($item['seller_price']) ? $item['seller_price'] : $item['sell_price']);
        })->sum();
    }


    /**
     * @param $time
     * @return bool
     * Author: wangding
     * CreateTime: 2018/2/23 18:02
     * Description: 通过时间判断优惠券能不能使用
     */
    public function getCanBeUsedByTime($time) {
        if(strtotime($time) <= time())
            return true;
        else
            return false;
    }

    /**
     * @param     $voucher
     * @param     $total
     * @param     $province
     * @param     $carts_info  购物车详情  $arr = [ ['goods_id'=>2000, 'num'=>2, 'seller_price'=>3888, 'products_id'=>11], ['goods_id'=>2814, 'num'=>5, 'seller_price'=>6666, 'products_id'=>12] ]
     * Author: wangding
     * CreateTime: 2018/2/24 15:52
     * Description:
     */
    public function getVoucherTrueValue($voucher, $total, $province, $carts_info = []) {
        $delivery = new Delivery();
        $cartsInfo = collect($carts_info)->map(function($item){
            !isset($item['products_id']) && $item['products_id'] = 0;
            return $item;
        });
        $user_id = Auth::id();
        $delivery = $delivery->getDelivery($province, 1, $cartsInfo->pluck('goods_id')->all(), $cartsInfo->pluck('products_id')->all(),$cartsInfo->pluck('num')->all(), $user_id)['price'];
        switch((int)$voucher->type_range){
            case 1:
                switch((int)$voucher->type_way){
                    case 1:
                        return $voucher->value > $total ? showPrice($total) : showPrice($voucher->value);
                        break;
                    case 2;
                        return showPrice($voucher->value * $total / 100);
                        break;
                    case 3;
                        $p = $voucher->value > $total ? showPrice($total) : showPrice($voucher->value);
                        return $p > $delivery ? $delivery : $p;
                        break;
                }
                break;
            case 2;
                $total2 = $this->getSomeGoodsPrice(explode(',', $voucher->goods_id), $carts_info);
                switch((int)$voucher->type_way){
                    case 1:
                        return $voucher->value > $total2 ? showPrice($total2) : showPrice($voucher->value);
                        break;
                    case 2;
                        return showPrice($voucher->value * $total2 / 100);
                        break;
                    case 3;
                        $v = (int)$voucher->value === 0 ? $delivery : $voucher->value;
                        $p = $v > $total ? showPrice($total) : showPrice($v);
                        return $p > $delivery ? $delivery : $p;
                        break;
                }
                break;
        }

    }

}

?>