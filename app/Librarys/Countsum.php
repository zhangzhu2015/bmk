<?php
namespace App\Librarys;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @copyright (c) 2011 aircheng.com
 * @file countsum.php
 * @brief 计算购物车中的商品价格
 * @author chendeshan
 * @date 2011-02-24
 * @version 0.6
 */
class CountSum
{
    protected $controller = '';
    //用户ID
    public $user_id = 0;

    //用户组ID
    public $group_id = '';

    //用户组折扣
    public $group_discount = '';

    //定义返回信息格式
    // status  返回状态， true 代表返回成功 ， false 代表返回失败
    // code    状态返回码，仅当失败的时候返回，用于指明返回的错误代码
    // msg     返回信息提示
    // data    返回的数据
    public $error = '';

    /**
     * 构造函数
     */
    public function __construct($user_id, $controller='')
    {
        //用户id必传
        if($user_id)
        {
            $this->user_id = $user_id;
        }

        if($controller){
            $this->controller = $controller;
        }
        //获取用户组ID及组的折扣率
        if($this->user_id)
        {
            $groupRow = DB::table('member as m')->select('g.*')->join('user_group as g', 'm.group_id', '=', 'g.id')->where('m.user_id',$this->user_id)->first();
            if($groupRow)
            {
                $this->group_id       = $groupRow->id;
                $this->group_discount = $groupRow->discount * 0.01;
            }
        }
    }

    /**
     * 获取会员组价格
     * @param $id   int    商品或货品ID
     * @param $type string goods:商品; product:货品
     * @return float 价格
     */
    public function getGroupPrice($id,$type = 'goods')
    {
        if(!$this->group_id)
        {
            return null;
        }

        //1,查询特定商品的组价格
        if($type == 'goods')
        {
            $discountRow = DB::table('group_price')
                ->where([
                    ['goods_id', '=', $id],
                    ['group_id', '=', $this->group_id],
                ])
                ->select('price')
                ->first();
        }
        else
        {
            $discountRow = DB::table('group_price')
                ->where([
                    ['product_id', '=', $id],
                    ['group_id', '=', $this->group_id],
                ])
                ->select('price')
                ->first();
        }

        if($discountRow)
        {
            return $discountRow->price;
        }

        //2,根据会员折扣率计算商品折扣
        if($this->group_discount)
        {
            if($type == 'goods')
            {
                $goodsRow = DB::table('goods')->where('id', '=', $id)->select('sell_price')->first();
                return $goodsRow ? round($goodsRow->sell_price * $this->group_discount,2) : null;
            }
            else
            {
                $productRow = DB::table('products')->where('id', '=', $id)->select('sell_price')->first();
                return $productRow ?round($productRow['sell_price'] * $this->group_discount,2) : null;
            }
        }
        return null;
    }

    /**
     * @brief 计算商品价格
     * @param Array $buyInfo ,购物车格式
     * @promo string 活动类型 团购，抢购
     * @active_id int 活动ID
     * @return array or bool
     */
    public function goodsCount($buyInfo,$promo='',$active_id='')
    {

        $this->sum           = 0;       //原始总额(优惠前)
        $this->final_sum     = 0;       //应付总额(优惠后)
        $this->weight        = 0;       //总重量
        $this->reduce        = 0;       //减少总额
        $this->count         = 0;       //总数量
        $this->promotion     = array(); //促销活动规则文本
        $this->proReduce     = 0;       //促销活动规则优惠额
        $this->sellerProReduce     = [];       //促销活动规则优惠额
        $this->point         = 0;       //增加积分
        $this->exp           = 0;       //增加经验
        $this->freeFreight   = array(); //商家免运费
        $this->tax           = 0;       //商品税金
        $this->seller        = array(); //商家商品总额统计, 商家ID => 商品金额

        $user_id      = $this->user_id;
        $group_id     = $this->group_id;
        $goodsList    = array();
        $productList  = array();

        //活动购买情况
        if($promo && $active_id)
        {

            $ac_type    = isset($buyInfo['goods']) && $buyInfo['goods']['id'] ? "goods" : "product";
            $ac_id      = current($buyInfo[$ac_type]['id']);
            $ac_buy_num = $buyInfo[$ac_type]['data'][$ac_id]['count'];
            //开启促销活动
            $activeObject = new Active($promo,$active_id,$user_id,$ac_id,$ac_type,$ac_buy_num);
            $activeResult = $activeObject->checkValid();

            if($activeResult === true)
            {
                $typeRow  = $activeObject->originalGoodsInfo;
                $disPrice = $activeObject->activePrice;

                //设置优惠价格，如果不存在则优惠价等于商品原价
                $typeRow['reduce'] = $typeRow['sell_price'] - $disPrice;
                $typeRow['count']  = $ac_buy_num;
                $current_sum_all   = $typeRow['sell_price'] * $ac_buy_num;
                $current_reduce_all= $typeRow['reduce']     * $ac_buy_num;
                $typeRow['sum']    = $current_sum_all - $current_reduce_all;
                //判断商家的合法性（商品的合法性）
                if($this->controller != 'cart_list' && $this->controller != 'changeCartNum'){
                    if(!is_valid_seller($typeRow['seller_id'])){
                        $sellerRow = DB::table('seller')->select('true_name')->where('id', $typeRow['seller_id'])->first();
                        throw new \InvalidArgumentException('The '.$sellerRow->true_name.' store has been closed');
                    }
                }
                if(!isset($this->seller[$typeRow['seller_id']]))
                {
                    $this->seller[$typeRow['seller_id']] = 0;
                }
                $this->seller[$typeRow['seller_id']] += $typeRow['sum'];
                $this->sellerProReduce[$typeRow['seller_id']] = 0;
                //全局统计
                $this->weight += $typeRow['weight'] * $ac_buy_num;
                $this->point  += $typeRow['point']  * $ac_buy_num;
                $this->exp    += $typeRow['exp']    * $ac_buy_num;
                $this->sum    += $current_sum_all;
                $this->reduce += $current_reduce_all;
                $this->count  += $ac_buy_num;
                $this->tax    += self::getGoodsTax($typeRow['sum'],$typeRow['seller_id']);
                $typeRow == "goods" ? ($goodsList[] = $typeRow) : ($productList[] = $typeRow);
            }
        }
        else
        {
            /*开始计算goods和product的优惠信息 , 会根据条件分析出执行以下哪一种情况:
             *(1)查看此商品(货品)是否已经根据不同会员组设定了优惠价格;
             *(2)当前用户是否属于某个用户组中的成员，并且此用户组享受折扣率;
             *(3)优惠价等于商品(货品)原价;
             */
            //已加入购物车的产品，被设置为限时抢购 ，解决方案
            $redis = new Redisbmk();

            $promotionrows = [];
            $promotionrows_tmp =  $redis->hKeys('_promotion_version2');
            $promotionrows_tmps =  $redis->hVals('_promotion_version2');
            if ($promotionrows_tmp && $promotionrows_tmps){
                foreach ($promotionrows_tmp as $k => $v){
                    $promotionrows[$v] = json_decode($promotionrows_tmps[$k], true);
                }
            }
            $this->promotionrows = $promotionrows ? $promotionrows : array();

            //获取商品或货品数据
            /*Goods 拼装商品优惠价的数据*/
            if(isset($buyInfo['goods']['id']) && $buyInfo['goods']['id'])
            {
                //购物车中的商品数据

                $goodsList = DB::table('goods')
                    ->whereIn('id', $buyInfo['goods']['id'])
                    ->select('name', 'cost_price', 'id as goods_id', 'img', 'sell_price', 'point', 'weight', 'store_nums', 'exp', 'goods_no',DB::raw('0 as product_id'), 'seller_id', 'is_del')
                    ->get()
                    ->map(function ($value) {
                        return (array)$value;
                    })->toArray();
                //开始优惠情况判断
                foreach($goodsList as $key => $val)
                {
                    if($this->controller != 'cart_list' && $this->controller != 'changeCartNum'){
                        //检查商品所有店铺是否关闭
                        if(!is_valid_seller($val['seller_id'])){
                            $sellerRow = DB::table('seller')->select('true_name')->where('id', $val['seller_id'])->first();
                            throw new \InvalidArgumentException('The '.$sellerRow['true_name'].' store has been closed');
                        }
                    }
                    //检查库存
                    if($buyInfo['goods']['data'][$val['goods_id']]['count'] <= 0 || $buyInfo['goods']['data'][$val['goods_id']]['count'] > $val['store_nums'])
                    {
                        throw new \InvalidArgumentException("<Product: ".$val['name']."> Purchase quantity exceeds inventory, re-adjust the purchase quantity.");
                    }

                    $groupPrice                = $this->getGroupPrice($val['goods_id'],'goods');
                    $goodsList[$key]['reduce'] = $groupPrice === null ? 0 : $val['sell_price'] - $groupPrice;
                    $goodsList[$key]['count']  = $buyInfo['goods']['data'][$val['goods_id']]['count'];
                    $goodsList[$key]['oldcount']  = $buyInfo['goods']['data'][$val['goods_id']]['count'];
                    $current_sum_all           = $goodsList[$key]['sell_price'] * $goodsList[$key]['count'];
                    $current_reduce_all        = $goodsList[$key]['reduce']     * $goodsList[$key]['count'];
                    $goodsList[$key]['sum']    = $current_sum_all - $current_reduce_all;
                    if(!isset($this->seller[$val['seller_id']]))
                    {
                        $this->seller[$val['seller_id']] = 0;
                    }
                    $this->seller[$val['seller_id']] += $goodsList[$key]['sum'];

                    //全局统计
                    $this->weight += $val['weight'] * $goodsList[$key]['count'];
                    $this->point  += $val['point']  * $goodsList[$key]['count'];
                    $this->exp    += $val['exp']    * $goodsList[$key]['count'];
                    $this->sum    += $current_sum_all;
                    $this->reduce += $current_reduce_all;
                    $this->count  += $goodsList[$key]['count'];
                    $this->tax    += self::getGoodsTax($goodsList[$key]['sum'],$val['seller_id']);
                    
                    //总金额满足的促销规则   xiugai
                    if(!in_array($val['goods_id'], array_keys($this->promotionrows)))
                    {
                        //计算每个商家促销规则
                        foreach($this->seller as $seller_id => $sum)
                        {
                            $proObj = new ProRule($sum,$seller_id);
                            $proObj->setUserGroup($group_id);
                            if($proObj->isFreeFreight() == true)
                            {
                                $this->freeFreight[] = $seller_id;
                            }
                            $this->promotion = array_merge($proObj->getInfo(),$this->promotion);
                            $this->proReduce += $sum - $proObj->getSum();
                            $this->sellerProReduce[$seller_id] = $sum - $proObj->getSum();
                        }
                    }else{
                        foreach($this->seller as $seller_id => $sum)
                        {

                            $this->sellerProReduce[$seller_id] = 0;
                        }
                    }
                }
            }
            /*Product 拼装商品优惠价的数据*/
            if(isset($buyInfo['product']['id']) && $buyInfo['product']['id'])
            {
                //购物车中的货品数据
                $productList = DB::table('products as pro')
                    ->join('goods as go', 'go.id', '=', 'pro.goods_id')
                    ->whereIn('pro.id', $buyInfo['product']['id'])
                    ->select('pro.sell_price', 'pro.cost_price', 'pro.weight', 'pro.id as product_id','pro.spec_array', 'pro.goods_id',
                        'pro.store_nums','pro.products_no as goods_no','go.name', 'go.point', 'go.exp', 'go.img', 'go.seller_id', 'go.is_del')
                    ->get()
                    ->map(function ($value) {
                        return (array)$value;
                    })->toArray();
                //开始优惠情况判断
                foreach($productList as $key => $val)
                {
                    if($this->controller != 'cart_list' && $this->controller != 'changeCartNum'){
                        //检查商品所有店铺是否关闭
                        if(!is_valid_seller($val['seller_id'])){
                            $sellerRow = DB::table('seller')->select('true_name')->where('id', $val['seller_id'])->first();
                            throw new \InvalidArgumentException('The '.$sellerRow['true_name'].' store has been closed');
                        }
                    }

                    //检查库存
                    if($buyInfo['product']['data'][$val['product_id']]['count'] <= 0 || $buyInfo['product']['data'][$val['product_id']]['count'] > $val['store_nums'])
                    {
                        throw new \InvalidArgumentException("<Product: ".$val['name']."> Purchase quantity exceeds inventory, re-adjust the purchase quantity.");
                    }

                    $groupPrice                  = $this->getGroupPrice($val['product_id'],'product');
                    $productList[$key]['reduce'] = $groupPrice === null ? 0 : $val['sell_price'] - $groupPrice;
                    $productList[$key]['count']  = $buyInfo['product']['data'][$val['product_id']]['count'];
                    $productList[$key]['oldcount']  = $buyInfo['product']['data'][$val['product_id']]['count'];
                    $current_sum_all             = $productList[$key]['sell_price']  * $productList[$key]['count'];
                    $current_reduce_all          = $productList[$key]['reduce']      * $productList[$key]['count'];
                    $productList[$key]['sum']    = $current_sum_all - $current_reduce_all;
                    if(!isset($this->seller[$val['seller_id']]))
                    {
                        $this->seller[$val['seller_id']] = 0;
                    }
                    $this->seller[$val['seller_id']] += $productList[$key]['sum'];

                    //全局统计
                    $this->weight += $val['weight'] * $productList[$key]['count'];
                    $this->point  += $val['point']  * $productList[$key]['count'];
                    $this->exp    += $val['exp']    * $productList[$key]['count'];
                    $this->sum    += $current_sum_all;
                    $this->reduce += $current_reduce_all;
                    $this->count  += $productList[$key]['count'];
                    $this->tax    += self::getGoodsTax($productList[$key]['sum'],$val['seller_id']);

                    //总金额满足的促销规则   xiugai
                    if(!in_array($val['goods_id'], array_keys($this->promotionrows)))
                    {
                        //计算每个商家促销规则
                        foreach($this->seller as $seller_id => $sum)
                        {
                            $proObj = new ProRule($sum,$seller_id);
                            $proObj->setUserGroup($group_id);
                            if($proObj->isFreeFreight() == true)
                            {
                                $this->freeFreight[] = $seller_id;
                            }
                            $this->promotion = array_merge($proObj->getInfo(),$this->promotion);
                            $this->proReduce += $sum - $proObj->getSum();
                            $this->sellerProReduce[$seller_id] = $sum - $proObj->getSum();
                        }
                    }else{
                        foreach($this->seller as $seller_id => $sum)
                        {

                            $this->sellerProReduce[$seller_id] = 0;
                        }
                    }
                }
            }

        }

//        $this->final_sum = $this->sum - $this->reduce - $this->proReduce;
        $this->final_sum = $this->sum - $this->reduce;
        $resultList      = array_merge($goodsList,$productList);
        if(!$resultList)
        {
            throw new \InvalidArgumentException("Product information does not exsit.");
        }

        return array(
            'final_sum'  => $this->final_sum,
            'promotion'  => $this->promotion,
            'proReduce'  => $this->proReduce,
            'sum'        => $this->sum,
            'goodsList'  => $resultList,
            'count'      => $this->count,
            'reduce'     => $this->reduce,
            'weight'     => $this->weight,
            'point'      => $this->point,
            'exp'        => $this->exp,
            'tax'        => $this->tax,
            'seller'     => $this->seller,
            'freeFreight'=> $this->freeFreight,
            'sellerProReduce' => $this->sellerProReduce
        );
    }

    //购物车计算
    public function cart_count($id = '',$type = '',$buy_num = 1,$promo='',$active_id='')
    {
        $buyInfo = [];
        //单品购买
        if($id && $type)
        {
            $type = ($type == "goods") ? "goods" : "product";

            //规格必填
            if($type == "goods")
            {
                $productsRow = DB::table('products')->where('goods_id', $id)->first();
                if($productsRow)
                {
                    throw new \InvalidArgumentException('Please select the product specifications.');
                }
            }

            $buyInfo = array(
                $type => array('id' => array($id), 'data' => array($id => array('count' => $buy_num)), 'count' => $buy_num)
            );
        }
        else
        {
            //获取购物车中的商品和货品信息
            $cartRow = DB::table('goods_car')->where('user_id', $this->user_id)->first();
            if($cartRow){
                $cartValue = json_decode(str_replace(array('&', '$'), array('"', ','),$cartRow->content), true);
                $user = new User();
                $buyInfo = $user->getMyCart($cartValue);
            }
        }
        return $this->goodsCount($buyInfo,$promo,$active_id);
    }

    /**
     * 计算订单信息,其中部分计算都是以商品原总价格计算的$goodsSum
     * @param $goodsResult array CountSum结果集
     * @param $province_id int 省份ID
     * @param $delievery_id int 配送方式ID
     * @param $payment_id int 支付ID
     * @param $is_invoice int 是否要发票
     * @param $discount float 订单的加价或者减价
     * @param $promo string 促销活动
     * @param $active_id int 促销活动ID
     * @param $goodsResult array 优惠券
     * @return $result 最终的返回数组
     */
    public function countOrderFee($goodsResult,$province_id,$delivery_id,$payment_id,$is_invoice,$discount = 0,$promo = '',$active_id = '',$seller_values = '')
    {
        //根据商家进行商品分组
        $sellerGoods = array();
        foreach($goodsResult['goodsList'] as $key => $val)
        {
            if(!isset($sellerGoods[$val['seller_id']]))
            {
                $sellerGoods[$val['seller_id']] = array();
            }
            $sellerGoods[$val['seller_id']][] = $val;
        }

        $cartObj = new Cart();
        foreach($sellerGoods as $seller_id => $item) {
            $seller_value = $seller_values;
            $num = array();
            $productID = array();
            $goodsID = array();
            $sell_price_array = array();
            $goodsArray = array();
            $productArray = array();
            foreach ($item as $key => $val) {
                $goodsID[] = $val['goods_id'];
                $productID[] = $val['product_id'];
                $num[] = $val['count'];
                $sell_price_array[] = $val['sell_price'];
                if ($val['product_id'] > 0) {
                    $productArray[$val['product_id']] = $val['count'];
                } else {
                    $goodsArray[$val['goods_id']] = $val['count'];
                }
            }
            $sellerData = $this->goodsCount($cartObj->cartFormat(array("goods" => $goodsArray, "product" => $productArray)), $promo, $active_id);


            //20170922 guoding
            if(is_array($payment_id)){
                $payment_id = $payment_id[$seller_id];
            }

            $deliveryDB = new Delivery();

            if($delivery_id == 17) {
                $deliveryList['org_price'] = 0;
                $deliveryList['price'] = 0;
                $deliveryList['protect_price'] = 0;
            }else{
                $deliveryList = $deliveryDB->getDelivery($province_id, $delivery_id, $goodsID, $productID, $num, $this->user_id);
            }

            if ($seller_value) {
                $seller_value = json_decode($seller_value,true);
                //订单优惠券计算
                if (is_array($seller_value) && $seller_value) {
                    $key = -1;
                    foreach ($seller_value as $k => $v) {
                        if ($v['seller_id'] == $seller_id) {
                            $key = $k;
                        }
                    }

                    if ($key != -1) {
                        $order_class = new Order();
                        $voucher_values = $order_class->get_voucher_value($seller_value[$key]['voucher_str_id'], $province_id, $sellerData['sum']-$sellerData['reduce'], $sellerData['proReduce'],$item);
                        $voucher_strRow  =  DB::table('voucher_str')->find($seller_value[$key]['voucher_str_id']);
                        $voucherRow =  DB::table('voucher')->find($voucher_strRow['voucher_id']);
                        $sellerData['voucher_id'] = $voucher_strRow['voucher_id'];
                        $sellerData['voucher_value'] = $voucher_values;
                        $sellerData['final_sum'] = -$voucher_values + $sellerData['final_sum'];
                        if($voucherRow['type'] != 4){
                            $sellerData['final_sum'] = $sellerData['final_sum'] < 0 ? 0 : $sellerData['final_sum'];
                        }
                    }
                }
            }else{
                $sellerData['voucher_value'] = 0;
            }

            $extendArray = array(
                'deliveryOrigPrice' => $deliveryList['org_price'],
                'deliveryPrice'     => $deliveryList['price'],
                'insuredPrice'      => $deliveryList['protect_price'],
                'taxPrice'          => $is_invoice == true ? $sellerData['tax'] : 0,
                'paymentPrice'      => ($payment_id != 0 || $payment_id != 17) ? self::getGoodsPaymentPrice($payment_id,$sellerData['final_sum'],$seller_id) : 0,
                'goodsResult'       => $sellerData,
                'orderAmountPrice'  => 0,
            );
            $orderAmountPrice = array_sum(array(
                $sellerData['final_sum'],
                $deliveryList['price'],
                $deliveryList['protect_price'],
                $extendArray['taxPrice'],
                $extendArray['paymentPrice'],
                $discount,
            ));

            $extendArray['orderAmountPrice'] = $orderAmountPrice <= 0 ? 0 : round($orderAmountPrice,2);
            $sellerGoods[$val['seller_id']]  = array_merge($sellerData,$extendArray);
        }
        return $sellerGoods;
    }

    /**
     * 获取商品的税金
     * @param $goodsSum float 商品总价格
     * @param $seller_id int 商家ID
     * @return $goodsTaxPrice float 商品的税金
     */
    public static function getGoodsTax($goodsSum,$seller_id = 0)
    {
        if($seller_id)
        {
            $sellerRow= DB::table('seller')->where('id', $seller_id)->first();
            $tax_per  = $sellerRow->tax;
        }
        else
        {
            $tax_per       = C('TAX') ? C('TAX') : 0;
        }
        $goodsTaxPrice = $goodsSum * ($tax_per * 0.01);
        return round($goodsTaxPrice,2);
    }

    /**
     * 获取商品金额的支付费用
     * @param $payment_id int 支付方式ID
     * @param $goodsSum float 商品总价格
     * @return $goodsPayPrice
     */
    public static function getGoodsPaymentPrice($payment_id,$goodsSum)
    {
        $paymentRow =  DB::table('payment')->where('id', $payment_id)->select('poundage', 'poundage_type')->first();

        if($paymentRow)
        {
            if($paymentRow->poundage_type == 1)
            {
                //按照百分比
                return $goodsSum * ($paymentRow->poundage * 0.01);
            }
            //按照固定金额
            return $paymentRow->poundage;
        }
        return 0;
    }

    /**
     * @brief 获取商户订单货款结算
     * @param int $seller_id 商户ID
     * @param datetime $start_time 订单开始时间
     * @param datetime $end_time 订单结束时间
     * @param string $is_checkout 是否已经结算 0:未结算; 1:已结算; null:不限
     * @param IQuery 结果集对象
     */
    public static function getSellerGoodsFeeQuery($seller_id = '',$start_time = '',$end_time = '',$is_checkout = '')
    {
        $where  = "status in (5,6,7) and pay_type != 0 and pay_status = 1 and distribution_status in (1,2)";
        $where .= $is_checkout !== '' ? " and is_checkout = ".$is_checkout : "";
        $where .= $seller_id          ? " and seller_id = ".$seller_id : "";
        $where .= $start_time         ? " and create_time >= '{$start_time}' " : "";
        $where .= $end_time           ? " and create_time <= '{$end_time}' "   : "";

        $orderGoodsDB = M('order')->where($where)->order('id desc')->select();
        return $orderGoodsDB;
    }

    /**
     * @brief 计算商户货款及其他费用
     * @param array $orderList 订单数据关联
     * @return array(
     * 'orderAmountPrice' => 订单金额（去掉pay_fee支付手续费）,'refundFee' => 退款金额, 'orgCountFee' => 原始结算金额,
     * 'countFee' => 实际结算金额, 'platformFee' => 平台促销活动金额(代金券等平台补贴给商家),'commission' => '手续费' ,'commissionPer' => '手续费比率',
     * 'orderNum' => 订单数量, 'order_ids' => 订单IDS,'orderNoList' => 订单编号
     * ),
     */
    public static function countSellerOrderFee($orderList)
    {
        $result = array(
            'orderAmountPrice' => 0,
            'refundFee'        => 0,
            'orgCountFee'      => 0,
            'countFee'         => 0,
            'platformFee'      => 0,
            'commission'       => 0,
            'commissionPer'    => 0,
            'orderNum'         => count($orderList),
            'order_ids'        => array(),
            'orderNoList'      => array(),
        );

        if($orderList && is_array($orderList))
        {
            $refundObj = new IModel("refundment_doc");
            $propObj   = new IModel("prop");
            foreach($orderList as $key => $item)
            {
                //检查平台促销活动
                //1,代金券
                if($item['prop'])
                {
                    $propRow = $propObj->getObj('id = '.$item['prop'].' and type = 0');
                    if($propRow && $propRow['seller_id'] == 0)
                    {
                        $propRow['value'] = min($item['real_amount'],$propRow['value']);
                        $result['platformFee'] += $propRow['value'];
                    }
                }

                $result['orderAmountPrice'] += $item['order_amount'] - $item['pay_fee'];
                $result['order_ids'][]       = $item['id'];
                $result['orderNoList'][]     = $item['order_no'];

                //是否存在退款
                $refundList = $refundObj->query("order_id = ".$item['id'].' and pay_status = 2');
                foreach($refundList as $k => $val)
                {
                    $result['refundFee'] += $val['amount'];
                }
            }
        }

        //应该结算金额
        $result['orgCountFee'] = $result['orderAmountPrice'] - $result['refundFee'] + $result['platformFee'];

        //获取结算手续费
        $siteConfigData = new Config('site_config');
        $result['commissionPer'] = $siteConfigData->commission ? $siteConfigData->commission : 0;
        $result['commission']    = round($result['orgCountFee'] * $result['commissionPer']/100,2);

        //最终结算金额
        $result['countFee'] = $result['orgCountFee'] - $result['commission'];

        return $result;
    }
}