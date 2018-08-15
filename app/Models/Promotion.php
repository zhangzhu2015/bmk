<?php
namespace App\Models;

use App\Librarys\Delivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use function PHPSTORM_META\type;

class Promotion extends Model{

    /**
     * @param array $promotion
     * @param       $order_info
     * @param int   $province
     * @return array
     * Author: chenglun
     * CreateTime: 2018/7/26 16:22
     * Description: 设置优惠的价格
     */
    public function setPromotionPrice(array $promotion, $order_info, $province = 0) {

        $total = new Voucher();
        $total = $total->getAllGoodsPrice($order_info);

        $delivery = new Delivery();
        $cartsInfo = collect($order_info)->map(function($item){
            !isset($item['products_id']) && $item['products_id'] = 0;
            return $item;
        });
        $delivery = $delivery->getDelivery($province, 1, $cartsInfo->pluck('goods_id')->all(), $cartsInfo->pluck('products_id')->all(),$cartsInfo->pluck('num')->all())['price'];
        foreach($promotion as &$v){
            $v['award_value'] = $this->getProProce($v, $total, $delivery);
        }
        unset($v);
        return $promotion;
    }

    /**
     * @param $promotion
     * @return mixed
     * Author: chenglun
     * CreateTime: 2018/2/28 16:22
     * Description: 找出最大的
     */
    public function getMaxPrice($promotion) {
        $p = collect($promotion)->max('award_value');
        foreach($promotion as $v){
            if($v['award_value'] === $p){
                return $v;
            }
        }
    }
}