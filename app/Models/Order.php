<?php

namespace App\Models;

use App\Librarys\Delivery;
use App\Librarys\Redisbmk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use function PHPSTORM_META\type;

class Order extends Model
{
    //
    protected $table = 'order';

    /**
     * @brief 是否允许退款申请
     * @param array $orderRow 订单表的数据结构
     * @param array $orderGoodsIds 订单与商品关系表ID数组
     * @return boolean true or false
     */
    static public function isRefundmentApply($orderRow, $orderGoodsIds = array())
    {

        if (!is_array($orderGoodsIds)) {
            return "Refund goods ID data type error";
        }
        //要退款的orderGoodsId关联信息
        if ($orderGoodsIds) {
            $order_id = $orderRow['id'];

            foreach ($orderGoodsIds as $key => $val) {
                $goodsOrderRow = DB::table('order_goods')->whereRaw('id = ' . $val . ' and order_id = ' . $order_id)->first();
                if ($goodsOrderRow && $goodsOrderRow->is_send == 2) {
                    return "Goods have been done Refund";
                }

                if (DB::table('refundment_doc')->whereRaw('if_del = 0 and pay_status = 0 and FIND_IN_SET(' . $val . ',order_goods_id)')->first()) {
                    return "You have submitted a refund request for this product, please be patient";
                }
            }

            //判断是否已经生成了结算申请或者已经结算了
            $billRow = DB::table('bill')->whereRaw('FIND_IN_SET(' . $order_id . ',order_ids)')->first();
            if ($billRow) {
                return 'This order has been completed merchant settlement amount, please contact the merchant refunds';
            }
            return true;
        } else {
            if (in_array($orderRow['order_status'], array(3, 11, 12))) {
                return false;
            }
            //已经付款
            if ($orderRow['pay_status'] == 1 && $orderRow['status'] != 6 && $orderRow['status'] != 5) {
                return true;
            }
//            //支付待审核
//            if($orderRow['pay_status'] == 4){
//                return true;
//            }
            return false;
        }
    }


    /*********************获取订单详情****************/
    static public function getOrderDetail($order_id, $user_id = 0, $seller_id = 0)
    {
        $where = "id = {$order_id}";
        if ($user_id){
            $where .= " and user_id = {$user_id}";
        }
        if ($seller_id) {
            $where .= " and seller_id = {$seller_id}";
        }
        $data = DB::table('order')->whereRaw($where)->first();
        if ($data) {
            $data->order_id = $order_id;
            //获取配送方式
            $delivery_info = DB::table('delivery')->find($data->distribution);
            if ($delivery_info) {
                $data->delivery = $delivery_info->name;
            }

            $areaData = get_address_name($data->province, $data->city, $data->area);
            if (isset($areaData[$data->province]) && isset($areaData[$data->city]) && isset($areaData[$data->area])) {
                $data->province_str = $areaData[$data->province];
                $data->city_str = $areaData[$data->city];
                $data->area_str = $areaData[$data->area];
            }
            //物流单号
            $delivery_info = DB::table('delivery_doc as dd')
                ->leftJoin('freight_company as fc', 'dd.freight_id', '=' , 'fc.id')
                ->select('dd.id', 'dd.delivery_code', 'fc.freight_name')
                ->whereRaw("order_id = $order_id")
                ->get();

            if ($delivery_info) {
                $temp = array('freight_name' => array(), 'delivery_code' => array(), 'delivery_id' => array());
                foreach ($delivery_info as $key => $val) {
                    $temp['delivery_id'][] = $val->id;
                    $temp['freight_name'][] = $val->freight_name;
                    $temp['delivery_code'][] = $val->delivery_code;
                }
                $data->freight = collect();
                $data->freight->id = current($temp['delivery_id']);
                $data->freight->freight_name = join(",", $temp['freight_name']);
                $data->freight->delivery_code = join(",", $temp['delivery_code']);
            }
            //获取支付方式
            $payment_info = DB::table('payment')->find($data->pay_type);
//            dd($payment_info);
            if ($payment_info) {
                $data->payment = $payment_info->name;
                $data->paynote = $payment_info->note;
            } else {
                $data->payment = '';
                $data->paynote = '';
            }
            //获取商品总重量和总金额
            $order_goods_info = DB::table('order_goods')->whereRaw('order_id=' . $order_id)->get();
            $data->goods_amount = 0;
            $data->goods_weight = 0;

            if ($order_goods_info) {
                foreach ($order_goods_info as $k => $value) {
                    $data->goods_amount += $value->real_price * $value->goods_nums;
                    $data->goods_weight += $value->goods_weight * $value->goods_nums;

                }
            }

            //获取用户信息
            $user_info = DB::table('user as u')
                ->leftJoin('member as m', 'm.user_id', '=', 'u.id')
                ->select('u.username', 'm.email', 'm.mobile', 'm.contact_addr', 'm.true_name')
                ->where('u.id', $data->user_id)
                ->first();

            if ($user_info) {
                $data->username = $user_info->username;
                $data->email = $user_info->email;
                $data->u_mobile = $user_info->mobile;
                $data->contact_addr = $user_info->contact_addr;
                $data->true_name = $user_info->true_name;
            }

            //已支付金额
            $collection_doc_data = DB::table('collection_doc')->whereRaw("order_id = $order_id and if_del = 0")->first();

            if ($collection_doc_data) {
                $data->amount = $collection_doc_data->amount;
            } else {
                $data->amount = null;
            }

            //获取商家通讯id
            $sellerInfo = DB::table('seller')->select('selleruin', 'true_name', 'mobile', 'pickup_address')->whereRaw('id=' . $data->seller_id)->first();

            $data->pickup_address = new \stdClass();
            if ($payment_info->id == 17) {
                // 自提 查出地址
                $pickup_address = json_decode($sellerInfo->pickup_address, true);
                $data['pickup_address'] = get_address_name($pickup_address['province'], $pickup_address['city'], $pickup_address['area']);
                $data['delivery'] = '--';
            }

            $data->selleruin = $sellerInfo->selleruin;
            $data->true_name = $sellerInfo->true_name;
            $data->seller_mobile = $sellerInfo->mobile;
            if ($data->quota_code) {
                $data->payable_amount = $data->real_amount;
            }
        }
        return json_encode($data);
    }


    /**
     * @brief 还原重置订单所使用的道具
     * @param int $order 订单ID
     */
    static function resetOrderProp($order_id)
    {
        $orderList = DB::table('order')->whereRaw('id in ( '.$order_id.' ) and pay_status = 0 and prop is not null')->get();
        foreach($orderList as $key => $orderRow)
        {
            if(isset($orderRow->prop) && $orderRow->prop)
            {
                DB::table('prop')->whereRaw('id = '.$orderRow->prop)->update(array('is_close' => 0));
            }
        }
    }

    static function newOrderStatus($order_id){
        $order = DB::table('order as o')->leftJoin('refundment_doc as rd','rd.order_id','=','o.id')
            ->where('o.id',$order_id)
            ->select('o.status','o.pay_status','o.pay_type','o.id','o.distribution_status','o.order_no','rd.id as rd_id')
            ->first();
        $refundRow = DB::table('refundment_doc')->where('order_no','=',$order->order_no)->first();
        $new_status = 0;
        if($order->status == 1)
        {
            //选择货到付款的支付款式
            if($order->pay_type == 16)
            {
                if($order->distribution_status == 0)
                {
                    $new_status = 1;  //1:未付款等待发货(货到付款)
                }
                elseif($order->distribution_status == 1)
                {
                    $new_status = 11; //11:已发货(未付款)
                }
                elseif($order->distribution_status == 2)
                {
                    $new_status = 8;  //8:部分发货(不需要付款)
                }
            }
            //自提订单
            elseif($order->pay_type == 17){
                if($order->pay_status == 0 ){
                    $new_status = 17;  //17 等待自提
                }elseif($order->pay_status == 0){
                    $new_status = 18;  //18: 已经自提
                }
            }
            //选择在线支付
            else
            {
                if($order->pay_status == 0)
                    $new_status = 2;   //2:等待付款(线上支付)
            }
        }
        //2,已经付款
        elseif($order->status == 2)
        {
            if($refundRow){
                if($refundRow->pay_status === 0)
                {
                    $new_status = 12;  //12:退款申请中
                }elseif ($refundRow->pay_status == 1) {
                    $new_status = 15;  //12:退款申请失败
                }elseif ($refundRow->pay_status == 2) {
                    $new_status = 16;  //12:退款申请成功
                }
            }

            if($order->distribution_status == 0 && !$refundRow)
            {
                $new_status = 4;   //4:已付款等待发货
            }
            elseif($order->distribution_status == 1)
            {
                $new_status = 3;   //3:已发货(已付款)
            }
            elseif($order->distribution_status == 2)
            {
                $new_status = 8;   //8:部分发货(不需要付款)
            }
        }
        //3,取消或者作废订单
        elseif($order->status == 3 || $order->status == 4)
        {
            $new_status = 5;    //5:已取消
        }
        //4,完成订单
        elseif($order->status == 5)
        {
            $new_status = 6;    //6:已完成(已付款,已收货)
        }
        //5,退款
        elseif($order->status == 6)
        {
            $new_status = 7;    //7:已退款
        }
        //6,部分退款
        elseif($order->status == 7)
        {
            //发货
            if($order->distribution_status == 1)
            {
                $new_status = 10;  //10:部分退款(已发货)
            }
            //未发货
            else
            {
                $new_status = 9;   //9:部分退款(未发货+部分发货)
            }
        }
        elseif($order->status == 8)
        {
            $new_status = 13;  //13:订单申请取消中
        }
        //刚生成订单,支付审核状态
        if($order->pay_status==4){
            if($refundRow){
                $new_status = 12;  //12:退款申请中
            }else{
                $new_status = 14;  //14:已经支付待审核
            }
        }

        DB::table('order')->where('id',$order_id)->update(['order_status' => $new_status]);
    }


    /**
     * 订单商品数量更新操作[公共]
     * @param array $orderGoodsId ID数据
     * @param string $type 增加或者减少 add 或者 reduce
     */
    static function updateStore($orderGoodsId,$type = 'add')
    {
        if(!is_array($orderGoodsId))
        {
            $orderGoodsId = array($orderGoodsId);
        }

        $newStoreNums  = 0;
        $updateGoodsId = array();
//        $goodsObj      = M('goods');
//        $productObj    = M('products');
        $goodsList     = DB::table('order_goods')->whereRaw('id in('.join(",",$orderGoodsId).') and is_send = 0')->select('goods_id', 'product_id' ,'goods_nums', 'seller_id')->get();

        foreach($goodsList as $key => $val)
        {
            //货品库存更新
            if($val->product_id != 0)
            {
                $productsRow = DB::table('products')->whereRaw('id = '.$val->product_id)->select('store_nums')->first();
                if(!$productsRow)
                {
                    continue;
                }
                $localStoreNums = $productsRow->store_nums;

                //同步更新所属商品的库存量
                if(in_array($val->goods_id, $updateGoodsId) == false)
                {
                    $updateGoodsId[] = $val->goods_id;
                }

                $newStoreNums = ($type == 'add') ? $localStoreNums + $val->goods_nums : $localStoreNums - $val->goods_nums;
                $newStoreNums = $newStoreNums > 0 ? $newStoreNums : 0;
                DB::table('products')->whereRaw('id = '.$val->product_id)->update(array('store_nums' => $newStoreNums));
            }
            //商品库存更新
            else
            {
                $goodsRow = DB::table('goods')->whereRaw('id = '.$val->goods_id)->select('store_nums')->first();
                if(!$goodsRow)
                {
                    continue;
                }
                $localStoreNums = $goodsRow->store_nums;

                $newStoreNums = ($type == 'add') ? $localStoreNums + $val->goods_nums : $localStoreNums - $val->goods_nums;
                $newStoreNums = $newStoreNums > 0 ? $newStoreNums : 0;
                DB::table('goods')->where('id', $val->goods_id)->update(array('store_nums' => $newStoreNums));
            }
            //库存减少销售量增加，两者成反比
            $saleData = ($type == 'add') ? 0 : $val->goods_nums;
            //更新goods商品销售量sale字段
            DB::table('goods')->whereRaw('id = '.$val->goods_id)->increment('sale',$saleData);
            //更新seller商家销售量sale字段
            DB::table('seller')->whereRaw('id = '.$val->seller_id)->increment('sale',$saleData);
        }

        //更新统计goods的库存
        if($updateGoodsId)
        {
            foreach($updateGoodsId as $val)
            {
                $store_nums = DB::table('products')->whereRaw('goods_id = '.$val)->sum('store_nums');
                DB::table('goods')->whereRaw('id = '.$val)->update(array('store_nums' => $store_nums));
            }
        }
    }



    /**
     * 支付成功后修改订单状态
     * @param $orderNo  string 订单编号
     * @param $admin_id int    管理员ID
     * @param $note     string 收款的备注
     * @return false or int order_id
     */
    static function updateOrderStatus($orderNo,$admin_id = '',$note = '')
    {
        //获取订单信息
        $orderRow  = DB::table('order')->whereRaw('order_no = "'.$orderNo.'"')->first();

        if(empty($orderRow))
        {
            return false;
        }

        if($orderRow->pay_status == 1)
        {
            return $orderRow->id;
        }
        else if($orderRow->pay_status == 0)
        {
            $dataArray = array(
                'status'     => ($orderRow->status == 5) ? 5 : 2,
                'pay_time'   => date('Y-m-d H:i:s'),
                'pay_status' => 1,
            );

            $is_success = DB::table('order')->whereRaw('order_no = "'.$orderNo.'"')->update($dataArray);
            if($is_success == '')
            {
                return false;
            }
            //插入收款单
            $collectionData   = array(
                'order_id'   => $orderRow->id,
                'user_id'    => $orderRow->user_id,
                'amount'     => $orderRow->order_amount,
                'time'       => date('Y-m-d H:i:s'),
                'payment_id' => $orderRow->pay_type,
                'pay_status' => 1,
                'if_del'     => 0,
                'note'       => $note,
                'admin_id'   => $admin_id ? $admin_id : 0
            );

            DB::table('collection_doc')->insert($collectionData);

            return $orderRow->id;
        }
        else
        {
            return false;
        }
    }


    /**
     * 添加评论商品的机会
     * @param $order_id 订单ID
     */
    static function addGoodsCommentChange($order_id)
    {
        //获取订单对象
        $orderRow = DB::table('order')->find($order_id);

        //获取此订单中的商品种类
        $orderList = DB::table('order_goods')->whereRaw('order_id = '.$order_id)
            ->groupBy('goods_id')
            ->get();

        //对每类商品进行评论开启
        foreach($orderList as $val)
        {
            $issetGoods = DB::table('goods')->whereRaw('id = '.$val->goods_id)->first();
            if($issetGoods)
            {
                $attr = array(
                    'goods_id' => $val->goods_id,
                    'order_no' => $orderRow->order_no,
                    'user_id'  => $orderRow->user_id,
                    'time'     => date('Y-m-d H:i:s'),
                    'seller_id'=> $val->seller_id,
                    'comment_time'=> '0000-00-00 00:00:00',
                    'recomment_time'=> '0000-00-00 00:00:00',
                );
                DB::table('comment')->insert($attr);
            }
        }
    }

    /**
     * @brief 根据传入的地域ID获取地域名称，获取的名称是根据ID依次获取的
     * @param int 地域ID 匿名参数可以多个id
     * @return array
     */
    public static function name()
    {
        $result     = array();
        $paramArray = func_get_args();
        $areaData   = DB::table('areas')->whereIn('area_id', $paramArray)->get();

        foreach($areaData as $key => $value)
        {
            $result[$value->area_id] = $value->area_name;
        }
        return $result;
    }

    /**
     * 订单提交页面计算可用代金券  选出满足条件的最优优惠券
     * $seller_value array(187=>748,188=>6666)
     */
    public static function voucher($info,$user_id, $province){
        $nowtime = date('Y-m-d');
        $save_total = 0;
        foreach($info['cartList'] as $k=> $v){
            //获取用户收货地址
            if($province){
                $address_id = $province;
            }else{
                $address = DB::table('address')->where([
                    ['user_id', '=', $user_id],
                    ['is_default', '=', 1],
                ])->first();
                $address_id = $address ? $address->province : 0;
            }

            $address_id = $address_id ?: 0;

            //计算运费
            $deliveryDB = new Delivery();
            $deliveryList = $deliveryDB->getDelivery($address_id , 1, array_column($v['goods_list'],'goods_id'), array_column($v['goods_list'],'product_id'), array_column($v['goods_list'],'count'), $user_id);
            $info['delivery'][$v['seller_id']] = $deliveryList['price'];

            //获取用户 所有优惠券
            if(!$info['reduce']){
                $sellerVouchers = DB::table('voucher_str as vs')
                    ->select('vs.id as voucher_str_id', 'v.*')
                    ->leftJoin('voucher as v', 'vs.voucher_id', '=', 'v.id')
                    ->where([
                        ['vs.share_status', '=', 0],
                        ['vs.use_status', '=', 2],
                        ['vs.user_id', '=', $user_id],
                        ['v.seller_id', '=', $v['seller_id']],
                        ['v.start_time', '>=', $nowtime],
                        ['v.end_time', '<=', $nowtime],
                    ])
                    ->get();

            }else{
                $sellerVouchers = [];
            }
            $sellerVouchersNew = [];

            if(count($sellerVouchers) && !$info['reduce']){

                //计算优惠券的减免情况

                $sellerVouchers = collect($sellerVouchers)->map(function($item){
                    return  arrayToCollect($item);
                });
                $voucherDB = new Voucher();

                $sellerVouchers =$voucherDB->setVoucherCanBeUsed($sellerVouchers, collect($v['goods_list']), $address_id);

                $sellerVouchers = $sellerVouchers->each(function($item){
                    return $item->voucher_value = $item->true_value;
                });
                $sellerVouchers = $voucherDB->setVoucherText($sellerVouchers);

                $promitionKey = -1;
                if(isset($v['promotion']) && $v['promotion']){
                    foreach($v['promotion'] as $k5=>&$v5){

                        if((int)$v5['award_type'] === 6){
                            $v5['award_value'] = $info['delivery'][$v5['seller_id']];
                        }
                    }
                    // 重新排序价格
                    array_multisort(array_column($v['promotion'],'award_value'),SORT_DESC, $v['promotion']);
                    $promitionKey = 0;
                }

                $tmp = new \stdClass();
                if($promitionKey != -1){
                    $tmp->voucher_str_id = '0';
                    $tmp->value_text = $v['promotion'][$promitionKey]['info'];
                    $tmp->voucher_value = $v['promotion'][$promitionKey]['award_type'] == 1  ? $v['promotion'][$promitionKey]['award_value'] :(string)($info['seller'][$v['seller_id']] * $v['promotion'][$promitionKey]['award_value']/100);
                    $tmp->seller_id = $v['promotion'][$promitionKey]['seller_id'];
                    $tmp->is_shippingFee = 2;
                    $tmp->limit_text = '';
                    $tmp->range_text = '';
                    $tmp->valid_time = '';
                    $tmp->can_be_used = true;
                    $sellerVouchers->push($tmp);
                }
                $sellerVouchersNew = $sellerVouchers->sortByDesc('voucher_value');
                $sellerVouchersNew = $sellerVouchersNew->reject(function($item){
                    return !$item->can_be_used;
                });

                if(count($sellerVouchersNew)){
                    $sellerVouchersNew = $sellerVouchersNew->map(function($item){
                        if($item->voucher_str_id != '0'){
                            $item->is_shippingFee = ((int)$item->type_way === 3 && (int)$item->true_value === 0) ? 2 : 1;
                            return hideFields($item, ['id', 'type_way', 'type_range', 'add_on','can_be_used', 'true_value', 'name', 'type', 'number', 'get_number', 'receive_number', 'limit', 'value',
                                'editv_time', 'start_time', 'end_time', 'status', 'created_at', 'goods_id', 'address_id', 'update_at', 'active_id', 'freeship_value', 'modify_time']);
                        }
                    })->values()->all();
                }
            }
            $tmp = [];
            if(count($sellerVouchersNew)){
                $tmp['voucher_str_id'] = '-1';
                $tmp['value_text'] = "Don't use coupon now";
                $tmp['voucher_value'] = 0;
                $tmp['seller_id'] = $v['seller_id'];
                $tmp['is_shippingFee'] = 2;
                $tmp['limit_text'] = '';
                $tmp['range_text'] = '';
                $tmp['valid_time'] = '';
                $sellerVouchersNew = collect($sellerVouchersNew)->prepend($tmp)->all();
            }
            $info['cartList'][$k]['coupon'] = $sellerVouchersNew;
            $info['cartList'][$k]['goods_sum'] = (float)$info['seller'][$v['seller_id']];

            if(count($sellerVouchersNew)){
                if($sellerVouchersNew[1]['is_shippingFee'] == 2){
                    if($sellerVouchersNew[1]['voucher_value'] >= (float)$info['seller'][$v['seller_id']] ){
                        $save_total += (float)$info['seller'][$v['seller_id']];
                        $info['cartList'][$k]['sum'] = (float)$info['seller'][$v['seller_id']] - (float)$info['seller'][$v['seller_id']];
                        $info['cartList'][$k]['save'] = (float)$info['seller'][$v['seller_id']];
                    }else{
                        $save_total += (float)$sellerVouchersNew[1]['voucher_value'];
                        $info['cartList'][$k]['sum'] = (float)$info['seller'][$v['seller_id']] - (float)$sellerVouchersNew[1]['voucher_value'];
                        $info['cartList'][$k]['save'] = $sellerVouchersNew[1]['voucher_value'];
                    }
                }else{
                    $save_total += (float)$sellerVouchersNew[1]['voucher_value'];
                    $info['cartList'][$k]['save'] = $sellerVouchersNew[1]['voucher_value'];
                    $info['cartList'][$k]['sum'] = (float)$info['seller'][$v['seller_id']] - (float)$sellerVouchersNew[1]['voucher_value'];
                }
            }else{
                $info['cartList'][$k]['save'] = '0.00';
                $info['cartList'][$k]['sum'] = (float)$info['seller'][$v['seller_id']];
            }
        }
        $info['save_total'] = $save_total;
        $info['final_sum'] = $info['sum'] + array_sum($info['delivery']) - $save_total;
        return $info;
    }

    //获取优惠券面值
    /*
     * $voucher_str 优惠券的字符串
     * $province_id 送货地址
     * $sellerData 是总价
     * $proReduce 店铺优惠
     * $goods 商品信息
     */
    public static function get_voucher_value($voucher_str_id, $province_id,$sellerData, $proReduce, $goods) {
        $voucherstrInfo = DB::table('voucher_str')->where([
            ['id', '=', $voucher_str_id],
            ['use_status', '=', 2]
        ])->first();
        $voucherstrDB = new Voucher();
        if ($voucherstrInfo){
            $nowtime = date('Y-m-d');
            $voucherInfo =  DB::table('voucher')->where([
                ['id','=',$voucherstrInfo->voucher_id],
                ['start_time','<=',$nowtime],
                ['end_time','>=',$nowtime]
            ])->first();

            if ($voucherInfo){//未过期可以
                //更新优惠券使用状态
                $goods = collect($goods)->map(function($i){
                    $i['num'] = $i['count'];
                    $i['products_id'] = $i['product_id'];
                    return $i;
                })->all();
                $seller_voucher = $voucherstrDB->getVoucherTrueValue(arrayToCollect($voucherInfo), $sellerData, $province_id, $goods);
                return $seller_voucher;
            }else {
                return $seller_voucher = 0;
            }
        }else{
            if($voucher_str_id == '-1'){
                return 0;
            }else{
                return $seller_voucher = $proReduce;
            }
        }
    }


    /**
     * @brief 产生订单ID
     * @return string 订单ID
     */
    public function createOrderNum()
    {
        return date('YmdHis').rand(100000,999999);
    }


    /**
     * @brief  统计销售额
     * @param  array
     * @return array
     */
    public function sellerAmount($seller_id,$order_id)
    {
        $redis = new Redisbmk();

        $orderRow = DB::table('order')->find($order_id);
        //商家每日销售金额
        $redis->hIncrByFloat('_seller_deal:seller_id_'.$seller_id,date('Y-m-d'),$orderRow->order_amount);

        //商家总销售金额
        $seller_deal_all = $redis->hGet('_seller_deal_all',$seller_id);
        if(!$seller_deal_all){
            //获取卖家订单量和总销售金额
            $orders = DB::table('order')->select(DB::raw('count(*) as order_num'),DB::raw('sum(order_amount) as seller_amount'))
                ->where('seller_id', $seller_id)
                ->get()
                ->map(function($v){
                    return (array)$v;
                })
                ->toArray();
            $data = $orders[0];

            $goods =  DB::table("seller_visit")->select(DB::raw('sum(visit) as visit_num'))
                ->where('seller_id', $seller_id)
                ->get()
                ->map(function($v){
                    return (array)$v;
                })->toArray();

            $data['visit_num'] = $goods[0]['visit_num'];
            $redis->hSet('_seller_deal_all', $seller_id, $orderRow->order_amount + $data['seller_amount']);
        }else{
            $redis->hSet('_seller_deal_all', $seller_id, $seller_deal_all + $orderRow->order_amount);
        }
    }


    /**
     * @brief 把订单商品同步到order_goods表中
     * @param $order_id 订单ID
     * @param $goodsInfo 商品和货品信息（购物车数据结构,countSum 最终生成的格式）
     */
    public function insertOrderGoods($order_id,$goodsResult = array())
    {
        $goodsArray = array(
            'order_id' => $order_id
        );

        if(isset($goodsResult['goodsList']))
        {
            foreach($goodsResult['goodsList'] as $key => $val)
            {
                //拼接商品名称和规格数据
                $specArray = array('name' => $val['name'],'goodsno' => $val['goods_no'],'value' => '');

                if(isset($val['spec_array']))
                {
                    $spec = show_spec($val['spec_array']);
                    foreach($spec as $skey => $svalue)
                    {
                        $specArray['value'] .= $skey.':'.$svalue.',';
                    }
                    $specArray['value'] = trim($specArray['value'],',');
                }

                $goodsArray['product_id']  = $val['product_id'];
                $goodsArray['goods_id']    = $val['goods_id'];
                $goodsArray['img']         = $val['img'];
                $goodsArray['goods_price'] = $val['sell_price'];
                $goodsArray['real_price']  = $val['sell_price'] - $val['reduce'];
                $goodsArray['goods_nums']  = $val['count'];
                $goodsArray['goods_weight']= $val['weight'];
                $goodsArray['goods_array'] = json_encode($specArray);
                $goodsArray['seller_id']   = $val['seller_id'];
                DB::table('order_goods')->insert($goodsArray);
            }
        }
    }
}
