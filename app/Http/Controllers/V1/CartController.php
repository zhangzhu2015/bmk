<?php
namespace App\Http\Controllers\V1;
 
use App\Htpp\Traits\ApiResponse;
use App\Librarys\Active;
use App\Librarys\CountSum;
use App\Librarys\Redisbmk;
use App\Models\Cart;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Seller;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;//引入自带接数据库
use Illuminate\Support\Facades\DB;//引入DB
use Validator;
 
class CartController extends Controller
{
    use ApiResponse;
    public $successStatus = 200;

    /**
     * 用户购物车列表
     * @param Request $request
     * @access public
     * @return mixed
     * @author wangding
     */
    public function list(){
        $userId   = Auth::id();
        $CountSum = new CountSum($userId,'cart_list');
        $result   = $CountSum->cart_count();
        if(is_string($result)){
            return $this->error(400,$result);
        }
        if($result['goodsList']){
            if($result['seller']){
                $res = [];
                foreach($result['seller'] as $k=>$v){
                    $sellerInfo = collect(DB::table('seller')->find($k))->toArray();
                    if($result['promotion']){
                        foreach($result['promotion'] as $k1=>$v1)
                        {
                            if($v1['seller_id'] == $k){
                                $res[$k]['promotion'][] = $v1;
                            }
                        }
                    }
                    $res[$k]['seller_id'] =  $sellerInfo['id'];
                    $res[$k]['shop_name'] =  $sellerInfo['true_name'];
                    $res[$k]['img'] =  $sellerInfo['img'];
                    $res[$k]['selleruin'] =  $sellerInfo['selleruin'];
                    $res[$k]['is_cashondelivery'] = $sellerInfo['is_cashondelivery'];
                    $res[$k]['is_banktobank'] = $sellerInfo['is_banktobank'];
                    $res[$k]['is_shipping'] = $sellerInfo['is_shipping'];
                    //检查商品所有店铺是否关闭
                    if(!is_valid_seller($sellerInfo['id'])){
                        $res[$k]['shop_status'] = '2'; //已关闭
                    }else{
                        $res[$k]['shop_status'] = '1'; //正常
                    }
                    //优惠券
                    $voucherRow = Seller::getInfobyseller($k, $userId);
                    $voucherCount = Voucher::getSellerVoucherCount($k, $userId);
                    $res[$k]['coupon'] = array_values($voucherRow);
                    $res[$k]['voucherCount'] = $voucherCount;

                    foreach($result['goodsList'] as $k1=>$v1){
                        if($v1['seller_id'] == $k){
                            //检查商品所有店铺是否关闭
                            if(!is_valid_seller($v1['seller_id']) || $v1['is_del']!=0){
                                $v1['shop_status'] = '2'; //已关闭
                            }else{
                                $v1['shop_status'] = '1'; //正常
                            }
                            if(isset($v1['spec_array'])){
                                $v1['spec_array'] = json_decode($v1['spec_array'],true);
                            }
                            $v1['img'] = getImgDir($v1['img'],80,80);
                            $v1['promo'] = '';
                            $v1['active_id'] = '';
                            if($rs = Goods::getPromotionRowBygoodsId($v1['goods_id'])){
                                $v1['promo'] = 'time';
                                $v1['active_id'] = $rs->id;
                            }
                            if($quotaRow = Goods::getQuotaRowBygoodsId($v1['goods_id'])){
                                $v1['promo'] = 'quota';
                                $v1['active_id'] = $quotaRow->quota_activity_id;
                            }
                            $res[$k]['goods_list'][] = $v1;
                        }
                    }
                }
            }
            $data = $result;
            unset($data['goodsList']);
            $data['cartList'] =   array_values($res);
            return $this->success($data);
        }
    }


    /**
     * 加减购物车商品数量
     * @param Request $request
     * @access public
     * @return mixed
     * @author wangding
     */
    public function changeCartNum(Request $request)
    {
        Validator::make($request->all(), [
            'id' => 'required|integer',
            'type' => 'required|string',
            'num' => 'required|integer',
        ])->validate();
        $id = $request->id;
        $type = $request->type;
        $num = $request->num;
        $userId = Auth::id();
        $user_cart = DB::table('goods_car')->where('user_id', $userId)->first();
        $content = $user_cart->content;
        $content = Cart::decode($content);
        foreach($content as $k=>$v){
            if(isset($content[$type][$id]) && in_array($id, array_keys($v))){
                $content[$type][$id] = $num;
                if($content[$type][$id] < 1){
                    return $this->error(400, 'Your qty must be greater than 0');
                }
            }
        }
        $goodsArray  = isset($content['goods']) ? $content['goods'] : [];
        $productArray= isset($content['product']) ? $content['product'] : [];
        $cart_model = new Cart();

        $addResult = $cart_model->cartAdd($id,$num,$type);
        if($addResult == 1){
            $countSumObj  = new CountSum($userId);
            $result = $countSumObj->goodsCount($cart_model->cartFormat(array("goods" => $goodsArray,"product" => $productArray)));
            if($result['goodsList']){
                if($result['seller']){
                    $res = [];
                    foreach($result['seller'] as $k=>$v){
                        $sellerInfo = Seller::find($k);
                        $res[$k]['seller_id']         =  $sellerInfo['id'];
                        $res[$k]['shop_name']         =  $sellerInfo['true_name'];
                        $res[$k]['is_cashondelivery'] = $sellerInfo['is_cashondelivery'];
                        $res[$k]['is_shipping']       = $sellerInfo['is_shipping'];
                        foreach($result['goodsList'] as $k1=>$v1){
                            if($v1['seller_id'] == $k){
                                if(isset($v1['spec_array'])){
                                    $v1['spec_array'] = json_decode($v1['spec_array'],true);
                                }
                                $v1['img'] = getImgDir($v1['img'],80,80);
                                $res[$k]['goods_list'][] = $v1;
                            }
                        }
                    }
                }
                $data = $result;
                unset($data['goodsList']);
                $data['cartList'] = array_values($res);
                return $this->success($data);
            }
        }else{
            $this->error(400, $addResult['error']);
        }
    }

    /**
     * 删除购物车商品
     * @param Request $request
     * @access public
     * @return mixed
     * @author wangding
     */
    public function removeCart(Request $request)
    {
        Validator::make($request->all(), [
            'id' => 'required|integer',
            'type' => 'required|string',
        ])->validate();
        $id   = $request->id;
        $type = $request->type;
        $cart_model = new Cart();
        $flag = $cart_model->removeCart($id, $type);
        if($flag){
            return $this->success([]);
        }else{
            return $this->error(400, 'Delete failed');
        }
    }

    /**
     * 购物车店铺优惠券
     * @param Request $request
     * @access public
     * @return mixed
     * @author wangding
     */
    public function cartsVoucher(Request $request) {
        Validator::make($request->all(), [
            'seller_id' => 'required|integer',
            'carts_info' => 'required',
        ])->validate();
        $carts_info = json_decode(str_replace(array('&quot;'), array('"'), $request->carts_info), true);
        $userId = Auth::id();
        // 检查是不是flash, 去除属于flash的商品 检查规格是不是失效 检查商品是不是被删除了
        $carts_info = collect($carts_info)->reject(function ($item) {
            return isFlash($item['goods_id']) || (isset($item['products_id']) && $item['products_id'] > 0 && !isProduct($item['products_id'])) || isDelGoods($item['goods_id']);
        })->values();
        // 查询在这家店铺的优惠卷
        $voucher_model = new Voucher();
        $voucher = $voucher_model->getUserVoucherBySeller($request->seller_id, $userId);
        // 处理优惠券信息（排序，文字， 能否使用， 凑单）
        $voucher = $voucher_model->setVoucherRank($voucher);
        $voucher = $voucher_model->setVoucherText($voucher);
        $voucher = $voucher_model->setVoucherCanBeUsed($voucher, $carts_info);

        return $this->success($voucher);
    }

    /***
     * @param Request $request jsonData  {"goods":["8096"],"product":["14967 "]}
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/26 0026
     * description: 购物车检出
     */
    public function cartCheckOut(Request $request){
        //字段验证
        Validator::make($request->all(), [
            'jsonData' => 'required',
        ])->validate();

        $user_id = auth()->user()->id;

        $jsonData = $request->jsonData;  //{"goods":["8083"],"product":["7015"]}
        if (!analyJson($jsonData)) {
            return $this->error(400, 'json格式不正确');  //t_02
        }

        $result = json_decode($jsonData, true);

        $Id_str = [];
        foreach($result as $k=>$v){
            foreach($v as $k1=>$v1){
                $Id_str[] = $k.'_' .$v1;
            }
        }
        $Id_str = join(',',$Id_str);

        if($Id_str){
            $content = DB::table('goods_car')->where('user_id',$user_id)->value('content');
            $cartRow_array = json_decode(str_replace(array('&','$'),array('"',','),$content),true);
            foreach ($cartRow_array as $k=>$v){
                if ($v){
                    foreach ($v as $ks=>$vs){
                        if (strpos($Id_str,$k.'_'.$ks) === false){
                            unset($cartRow_array[$k][$ks]);
                        }
                    }
                }
            }
            $countSum = new Countsum($user_id);
            $user = new User();
            $result = $countSum->goodsCount($user->getMyCart($cartRow_array));

            //获取各个卖家支付方式
            $sellerinfo = Seller::getPayment($result['seller']);
            //免运费商家集合
            if($result['goodsList']){
                if($result['seller']){
                    $res = [];
                    foreach($result['seller'] as $k=>$v){
                        if($result['promotion']){
                            foreach($result['promotion'] as $k1=>$v1)
                            {
                                if($v1['seller_id'] == $k){
                                    $res[$k]['promotion'][] = $v1;
                                }
                            }
                        }
                        $sellerInfo = DB::table("seller")->find($k);

                        $res[$k]['seller_id'] =  $sellerInfo->id;
                        $res[$k]['shop_name'] =  $sellerInfo->true_name;
                        $res[$k]['is_cashondelivery'] = $sellerInfo->is_cashondelivery;
                        $res[$k]['is_shipping'] = ($sellerInfo->is_shipping == 1 || in_array($sellerInfo->id,$result['freeFreight'])) ? 1 : 0;
                        $res[$k]['payment'] = $sellerinfo[$sellerInfo->id];
                        foreach($result['goodsList'] as $k1=>$v1){
                            if($v1['seller_id'] == $k){
                                if(isset($v1['spec_array']) and $v1['spec_array']){
                                    $v1['spec_array'] = json_decode($v1['spec_array'],true);
                                }else{
                                    $v1['spec_array'] = [];
                                }
                                $v1['img'] = getImgDir($v1['img'],80,80);
                                $res[$k]['goods_list'][] = $v1;
                            }
                        }
                    }
                }
                $data = $result;
                unset($data['goodsList']);
                $data['cartList'] =   array_values($res);
            }
            //获取习惯方式
            $memberRow = DB::table('member')->where('user_id', $user_id)->first();
            if($memberRow && $memberRow->custom)
            {
                $custom = unserialize($memberRow->custom);
            }
            else
            {
                $custom = array(
                    'payment'  => '',
                    'delivery' => '',
                );
            }

            $dataArray = array(
                'goods_id' => '',
                'type' => '',
                'num' => '',
                'promo' => '',
                'active_id' => '',
                'final_sum' => $data['final_sum'],
                'promotion' => $data['promotion'],
                'proReduce' => $data['proReduce'],
                'sum' => $data['sum'] - $data['reduce'],
                'cartList' =>$data['cartList'],
                'count' => $data['count'],
                'reduce' =>$data['reduce'],
                'weight' =>$data['weight'],
                'seller' => $data['seller'],
                'goodsTax' =>$data['tax'],
                'sellerProReduce' =>$data['sellerProReduce'],
                'custom' => $custom
            );
            //优惠券
            $order_class = new Order();
            $dataArray = $order_class->voucher($dataArray,$user_id, request('province',null));
            return $this->success($dataArray);
        }
    }

    /***
     * @param Request $request
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/26 0026
     * description:
     */
    public function createOrderBn(Request $request)
    {
        //字段验证
        Validator::make($request->all(), [
            'address_id' => 'required',
            'id' => 'required',
            'num' => 'required',
            'type' => 'required',
            'device' => 'required',
            'payment_id' => 'required', //{"188":14,"201":16}
        ])->validate();

        $address_id = $request->address_id; //地址id
        $delivery_id = 1; //目前只有一种配送方式
        $accept_time = request('accept_time','At will');  //配送时间

        $payment = request('payment_id'); //支付id // {"188":14,"201":16}
        $payment = str_replace("&quot","\"",$payment);
        $payment = str_replace(";","",$payment);
        $payment_array = json_decode($payment,true);

        $taxes         = request('taxes','');
        $tax_title     = request('tax_title','');

        if(is_array($payment_array)){
            $payment = $payment_array;
        }

        $postscripts = request('postscripts',[]); //留言

        if($postscripts){
            $postscripts = json_decode($_REQUEST['postscripts'],true);
        }

        $user_id = auth()->id();
        $id        = $request->id; //商品或货品id  单品 ，直接购买或者限时抢购
        $type      = $request->type; //商品类型 goods，product
        $buy_num   = $request->num;  //购买数量

        $active_id = request('active_id',''); //活动id
        $promo  = request('promo',''); //活动类型

        if(($active_id && !$promo) || (!$active_id && $promo)){
            return $this->error(400,'参数错误');  //t_06
        }

        $voucher_value = request('voucher_value', []);//优惠券  [{"seller_id":"188","voucher_str_id":"25","type"=>"1"},{"seller_id":"330","voucher_str_id":"0","type"=>"2"}]
        $takeself = request('takeself','');
        $order_type = 0;
        $device =   $request->device; //买家设备

        //查询商品是否加入限时抢购
        if(!$active_id || !$promo){
            $now = date('Y-m-d H:i:s',time());
            if($type == 'product'){
                $proRow = DB::table('products')->find($id);
                $g_id = $proRow->goods_id;
                $startRow = DB::table('promotion')->whereRaw("type = 1 and `condition` = {$g_id}  and is_close = 0 and ('".$now."' < start_time)")->first();
                $onGoingRow = DB::table('promotion')->whereRaw("type = 1 and `condition` = {$g_id}  and is_close = 0 and ('".$now."' between start_time and end_time)")->first();
            }else{
                $startRow = DB::table('promotion')->whereRaw("type = 1 and `condition` = {$id}  and is_close = 0 and ('".$now."' < start_time)")->first();
                $onGoingRow = DB::table('promotion')->whereRaw("type = 1 and `condition` = {$id}  and is_close = 0 and ('".$now."' between start_time and end_time)")->first();
            }
            if($startRow){
                return $this->error(400, 'Promo is not yet active,please try it later.');
            }
            if($onGoingRow){
                return $this->error(400, 'The product have been added to the flash sale,kindly refresh the page and try it again.');
            }
        }
        $countSum = new CountSum($user_id);
        $goodsResult = $countSum->cart_count($id,$type,$buy_num,$promo,$active_id);

        //处理收件地址
        $addressRow = DB::table('address')
            ->where([
                ['id', '=', $address_id],
                ['user_id', '=', $user_id],
            ])->first();

        if (!$addressRow) {
            return $this->error(400, '收获地址不存在');
        }
        $accept_name = $addressRow->accept_name;
        $province = $addressRow->province;
        $city = $addressRow->city;
        $area = $addressRow->area;
        $address = $addressRow->address;
        $mobile = $addressRow->mobile;
        $telphone = $addressRow->telphone;
        $zip = $addressRow->zip;
        $email = $addressRow->email;
        $order_class = new Order();
        $max_num = 0;
        if ($promo == 'time' && $active_id){ //限时抢购
            $redis = new Redisbmk();
            $count = 0;
            for ($i = 0; $i < $buy_num; $i++){
                $max_num = $redis->lpoplist('_promotion_max_num:id_'.$active_id);
                if ($max_num){
                    $count++;
                }else {
                    break;
                }
            }
            if(!$max_num){
                return $this->error(400, 'The inventory is not enough for the supply');
            }
            if (intval($count) !== intval($buy_num)){
                for ($i = 0; $i < intval($count); $i++){
                    $redis->addRlist('_promotion_max_num:id_'.$active_id, 1);
                }
                return $this->error(400, 'The inventory is not enough for the supply');
            }
        }

        //加入促销活动
        if($promo && $active_id)
        {
            $activeObject = new Active($promo,$active_id,$user_id,$id,$type,$buy_num);
            $order_type = $activeObject->getOrderType();
        }

        //最终订单金额计算
        $countSum = new Countsum($user_id);
        $orderData = $countSum->countOrderFee($goodsResult,$province,$delivery_id,$payment,0,0,$promo,$active_id,$voucher_value);
        //根据商品所属商家不同批量生成订单
        $orderIdArray  = array();
        $orderNumArray = array();
        $final_sum     = 0;

        DB::beginTransaction();
        try{
            foreach($orderData as $seller_id => $goodsResult)
            {
                //生成的订单数据
                $dataArray = array(
                    'order_no'            => $order_class->createOrderNum(),
                    'user_id'             => $user_id,
                    'accept_name'         => $accept_name,
                    'pay_type'            => is_array($payment) ? $payment[$seller_id] : $payment,
                    'distribution'        => $delivery_id,
                    'postcode'            => $zip,
                    'telphone'            => $telphone,
                    'province'            => $province,
                    'city'                => $city,
                    'area'                => $area,
                    'address'             => $address,
                    'mobile'              => $mobile,
                    'create_time'         => date('Y-m-d H:i:s'),
                    'postscript'          => $postscripts ? $postscripts[$seller_id] : '',
                    'accept_time'         => $accept_time,
                    'exp'                 => $goodsResult['exp'],
                    'point'               => $goodsResult['point'],
                    'type'                => $order_type,
                    'device'              => $device,

                    //商品价格
                    'payable_amount'      => $goodsResult['sum'],
                    'real_amount'         => $goodsResult['final_sum'],

                    //运费价格
                    'payable_freight'     => $goodsResult['deliveryOrigPrice'],
                    'real_freight'        => $goodsResult['deliveryPrice'],

                    //手续费
                    'pay_fee'             => $goodsResult['paymentPrice'],

                    //税金
                    'invoice'             => $taxes ? 1 : 0,
                    'invoice_title'       => $tax_title,
                    'taxes'               => $goodsResult['taxPrice'],

                    //优惠价格
                    'promotions'          => ($goodsResult['voucher_value']+$goodsResult['reduce']) - $goodsResult['sum'] > 0 ? $goodsResult['sum'] : ($goodsResult['voucher_value']+$goodsResult['reduce']),
                    'voucher_id'          => isset($goodsResult['voucher_id']) ? $goodsResult['voucher_id'] : '',

                    //订单应付总额
                    'order_amount'        => $goodsResult['orderAmountPrice'],

                    //订单保价
                    'insured'             => $goodsResult['insuredPrice'],

                    //自提点ID
                    'takeself'            => $takeself,

                    //促销活动ID
                    'active_id'           => $active_id,

                    //商家ID
                    'seller_id'           => $seller_id,

                    //ip地址
                    'ip'                  => get_client_ip(),
                    'note'                => ''
                );
                //促销规则
                if(isset($goodsResult['promotion']) && $goodsResult['promotion'])
                {
                    foreach($goodsResult['promotion'] as $key => $val)
                    {
                        $dataArray['note'] .= " 【".$val['info']."】 ";
                    }
                }

                $dataArray['order_amount'] = $dataArray['order_amount'] <= 0 ? 0 : $dataArray['order_amount'];

                //生成订单插入order表中
                $order_id = DB::table('order')->insertGetId($dataArray);
                //更新新的订单状态
                $order_class->newOrderStatus($order_id);

                /*将订单中的商品插入到order_goods表*/

                $order_class->insertOrderGoods($order_id,$goodsResult['goodsResult']);
                //减少库存量
                $orderGoodsList = DB::table('order_goods')->where('order_id',$order_id)->get()->map(function($v){return (array)$v;})->toArray();
                $orderGoodsListId = array();

                foreach($orderGoodsList as $key => $val)
                {
                    $orderGoodsListId[] = $val['id'];
                }
                //统计促销商品销售数量
                if($active_id && $promo){
                    $promoRow = DB::table('promotion')->find($active_id);
                    $updateNum = intval($promoRow->sold_num) + intval($buy_num);
                    DB::table('promotion')->where('id', $active_id)->update(array('sold_num' => $updateNum));
                }
                $order_class->updateStore($orderGoodsListId,'reduce');

                //订单金额小于等于0直接免单
                if($dataArray['order_amount'] <= 0)
                {
                    $order_class->updateOrderStatus($dataArray['order_no']);
                }
                else
                {
                    $orderIdArray[]  = $order_id;
                    $orderNumArray[] = $dataArray['order_no'];
                    $final_sum      += $dataArray['order_amount'];
                }

                $postdata = array(
                    'order_id'   => $order_id,
                    'order_no'   => $dataArray['order_no'],
                    'create_time'=> $dataArray['create_time'],
                    'pay_type'   => $dataArray['pay_type'],
                    'seller_id'  => $dataArray['seller_id'],
                    'real_amount'  => $dataArray['real_amount'],
                    'real_freight'  => $dataArray['real_freight'],
                    'accept_name'  => $dataArray['accept_name'],//'收货人姓名'
                    'mobile'  => $dataArray['mobile'], //'联系电话'
                    'address'  => $dataArray['address'],//收货地址
                );

                //给订单不同的商家发生订单邮件
                $sellerRow = DB::table('seller')->find($seller_id);
                $redis = new Redisbmk();
                $redis->addRlist('_order_sendemail', json_encode(array('email'=>$sellerRow->email,'title'=>'You have a new order','contenturl'=>'/sendemail/order_to_seller','contenttxt'=>$postdata)));

                //给买家发生订单邮件
                $memberRow =  DB::table('member')->where('user_id', $user_id)->first();
                $redis->addRlist('_order_sendemail', json_encode(array('email'=>($memberRow->email ? $memberRow->email : ($email ? $email :'')),'title'=>"Order Confirmation(".$dataArray['order_no'].")",'contenturl'=>'/sendemail/order_to_user','contenttxt'=>$postdata)));
                $order_class = new Order();

                //统计销售额
                $order_class->sellerAmount($seller_id,$order_id);

                //商家每日订单量
                $redis->hIncrByFloat('_seller_order:seller_id_'.$seller_id,date('Y-m-d'),1);


                //商家总订单量
                $seller_order_all = $redis->hGet('_seller_order_all',$seller_id);
                if(!$seller_order_all){
                    //获取卖家订单量和总销售金额
                    $order_num = DB::table('order')->where('seller_id', $seller_id)->count();
                    $redis->hSet('_seller_order_all',$seller_id,$order_num+1);
                }else{
                    $redis->hSet('_seller_order_all',$seller_id,$seller_order_all+1);
                }

                //销售统计
                $redis->hIncrByFloat('_sale_statistic_d', date('Y-m-d'), $dataArray['order_amount']);
                $redis->hIncrByFloat('_sale_statistic_m', date('Y-m'), $dataArray['order_amount']);

                //订单统计
                $redis->hIncrBy('_sale_order_d', date('Y-m-d'), 1);
                $redis->hIncrBy('_sale_order_m', date('Y-m'), 1);

                //订单类型
                if($device == 3){
                    $redis->hIncrBy('_sale_order_type_ios', date('Y-m'), 1);
                }else{
                    $redis->hIncrBy('_sale_order_type_android', date('Y-m'), 1);
                }

            }
            DB::commit();
        }catch (\Exception $e){
            DB::rollBack();
            echo $e->getMessage();exit;
            return $this->error(400, '订单创建失败'); //t_07
        }

        //数据渲染
        $res['order_id']    = join(",",$orderIdArray);

        return $this->success($res);
    }

    /***
     * @param Request $request
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/26 0026
     * description:
     */
    public function createOrderCart(Request $request)
    {
        //字段验证
        Validator::make($request->all(), [
            'jsonData' => 'required',
        ])->validate();
        $user_id = auth()->id();

        //接受并验证字段开始
        $active_id = request('active_id',''); //活动id
        $promo  = request('promo',''); //活动类型

        //接受并处理购物车过来的数据
        $jsonData = $request->jsonData;  //{"goods":["8083"],"product":["7015"]}
        if (!analyJson($jsonData)) {
            return $this->error(400, 'json格式不正确'); //t_02
        }
        $result = json_decode($jsonData, true);
        $Id_str = [];
        foreach($result as $k=>$v){
            foreach($v as $k1=>$v1){
                $Id_str[] = $k.'_' .$v1;
            }
        }
        $Id_str = join(',',$Id_str);
        $cartRow = DB::table('goods_car')->where('user_id', $user_id)->select('content')->first();
        $cartRow_array = json_decode(str_replace(array('&', '$'), array('"', ','), $cartRow->content), true);

        foreach ($cartRow_array as $k => $v) {
            if ($v) {
                foreach ($v as $ks => $vs) {
                    if (strpos($Id_str, $k . '_' . $ks) === false) {
                        //保存购物车剩余商品
                        $cartData[$k][$ks] = $cartRow_array[$k][$ks];
                        unset($cartRow_array[$k][$ks]);
                    }
                }
            }
        }
        $address_id = $request->address_id; //地址id
        $delivery_id = 1; //目前只有一种配送方式
        $accept_time = request('accept_time','At will');  //配送时间
        $payment = request('payment_id'); //支付id // {"188":14,"201":16}
        $payment = str_replace("&quot","\"",$payment);
        $payment = str_replace(";","",$payment);
        $payment_array = json_decode($payment,true);
        $taxes         = request('taxes','');
        $tax_title     = request('tax_title','');
        if(is_array($payment_array)){
            $payment = $payment_array;
        }
        $postscripts = request('postscripts',[]); //留言
        if($postscripts){
            $postscripts = json_decode($_REQUEST['postscripts'],true);
        }
        $voucher_value = request('voucher_value', []);//优惠券  [{"seller_id":"188","voucher_str_id":"25","type"=>"1"},{"seller_id":"330","voucher_str_id":"0","type"=>"2"}]
        $takeself = request('takeself','');
        $order_type = 0;
        $device =   $request->device; //买家设备
        //处理收件地址
        $addressRow = DB::table('address')
            ->where([
                ['id', '=', $address_id],
                ['user_id', '=', $user_id],
            ])->first();
        if (!$addressRow) {
            return $this->error(400, '收获地址不存在'); //t_09
        }
        $accept_name = $addressRow->accept_name;
        $province = $addressRow->province;
        $city = $addressRow->city;
        $area = $addressRow->area;
        $address = $addressRow->address;
        $mobile = $addressRow->mobile;
        $telphone = $addressRow->telphone;
        $zip = $addressRow->zip;
        $email = $addressRow->email;
        //接受并验证字段结束

        $order_class = new Order();
        $countSum = new Countsum($user_id);
        $user = new User();
        $goodsResult = $countSum->goodsCount($user->getMyCart($cartRow_array));
        //最终订单金额计算
        $countSum = new Countsum($user_id);
        $orderData = $countSum->countOrderFee($goodsResult,$province,$delivery_id,$payment,0,0,$promo,$active_id,$voucher_value);
        //根据商品所属商家不同批量生成订单
        $orderIdArray  = array();
        $orderNumArray = array();
        $final_sum     = 0;

        DB::beginTransaction();
        try{
            foreach($orderData as $seller_id => $goodsResult)
            {
                //生成的订单数据
                $dataArray = array(
                    'order_no'            => $order_class->createOrderNum(),
                    'user_id'             => $user_id,
                    'accept_name'         => $accept_name,
                    'pay_type'            => is_array($payment) ? $payment[$seller_id] : $payment,
                    'distribution'        => $delivery_id,
                    'postcode'            => $zip,
                    'telphone'            => $telphone,
                    'province'            => $province,
                    'city'                => $city,
                    'area'                => $area,
                    'address'             => $address,
                    'mobile'              => $mobile,
                    'create_time'         => date('Y-m-d H:i:s'),
                    'postscript'          => $postscripts ? $postscripts[$seller_id] : '',
                    'accept_time'         => $accept_time,
                    'exp'                 => $goodsResult['exp'],
                    'point'               => $goodsResult['point'],
                    'type'                => $order_type,
                    'device'              => $device,

                    //商品价格
                    'payable_amount'      => $goodsResult['sum'],
                    'real_amount'         => $goodsResult['final_sum'],

                    //运费价格
                    'payable_freight'     => $goodsResult['deliveryOrigPrice'],
                    'real_freight'        => $goodsResult['deliveryPrice'],

                    //手续费
                    'pay_fee'             => $goodsResult['paymentPrice'],

                    //税金
                    'invoice'             => $taxes ? 1 : 0,
                    'invoice_title'       => $tax_title,
                    'taxes'               => $goodsResult['taxPrice'],

                    //优惠价格
                    'promotions'          => ($goodsResult['voucher_value']+$goodsResult['reduce']) - $goodsResult['sum'] > 0 ? $goodsResult['sum'] : ($goodsResult['voucher_value']+$goodsResult['reduce']),
                    'voucher_id'          => isset($goodsResult['voucher_id']) ? $goodsResult['voucher_id'] : '',

                    //订单应付总额
                    'order_amount'        => $goodsResult['orderAmountPrice'],

                    //订单保价
                    'insured'             => $goodsResult['insuredPrice'],

                    //自提点ID
                    'takeself'            => $takeself,

                    //促销活动ID
                    'active_id'           => $active_id,

                    //商家ID
                    'seller_id'           => $seller_id,

                    //ip地址
                    'ip'                  => get_client_ip(),
                    'note'                => '',
                );
                //促销规则
                if(isset($goodsResult['promotion']) && $goodsResult['promotion'])
                {
                    foreach($goodsResult['promotion'] as $key => $val)
                    {
                        $dataArray['note'] .= " 【".$val['info']."】 ";
                    }
                }

                $dataArray['order_amount'] = $dataArray['order_amount'] <= 0 ? 0 : $dataArray['order_amount'];

                //生成订单插入order表中
                $order_id = DB::table('order')->insertGetId($dataArray);
                //更新新的订单状态
                $order_class->newOrderStatus($order_id);

                /*将订单中的商品插入到order_goods表*/

                $order_class->insertOrderGoods($order_id,$goodsResult['goodsResult']);
                //减少库存量
                $orderGoodsList = DB::table('order_goods')->where('order_id',$order_id)->get()->map(function($v){return (array)$v;})->toArray();
                $orderGoodsListId = array();

                foreach($orderGoodsList as $key => $val)
                {
                    $orderGoodsListId[] = $val['id'];
                }
                //统计促销商品销售数量
                $order_class->updateStore($orderGoodsListId,'reduce');

                //订单金额小于等于0直接免单
                if($dataArray['order_amount'] <= 0)
                {
                    $order_class->updateOrderStatus($dataArray['order_no']);
                }
                else
                {
                    $orderIdArray[]  = $order_id;
                    $orderNumArray[] = $dataArray['order_no'];
                    $final_sum      += $dataArray['order_amount'];
                }

                $postdata = array(
                    'order_id'   => $order_id,
                    'order_no'   => $dataArray['order_no'],
                    'create_time'=> $dataArray['create_time'],
                    'pay_type'   => $dataArray['pay_type'],
                    'seller_id'  => $dataArray['seller_id'],
                    'real_amount'  => $dataArray['real_amount'],
                    'real_freight'  => $dataArray['real_freight'],
                    'accept_name'  => $dataArray['accept_name'],//'收货人姓名'
                    'mobile'  => $dataArray['mobile'], //'联系电话'
                    'address'  => $dataArray['address'],//收货地址
                );

                //给订单不同的商家发生订单邮件
                $sellerRow = DB::table('seller')->find($seller_id);
                $redis = new Redisbmk();
                $redis->addRlist('_order_sendemail', json_encode(array('email'=>$sellerRow->email,'title'=>'You have a new order','contenturl'=>'/sendemail/order_to_seller','contenttxt'=>$postdata)));

                //给买家发生订单邮件
                $memberRow =  DB::table('member')->where('user_id', $user_id)->first();
                $redis->addRlist('_order_sendemail', json_encode(array('email'=>($memberRow->email ? $memberRow->email : ($email ? $email :'')),'title'=>"Order Confirmation(".$dataArray['order_no'].")",'contenturl'=>'/sendemail/order_to_user','contenttxt'=>$postdata)));
                $order_class = new Order();

                //统计销售额
                $order_class->sellerAmount($seller_id,$order_id);

                //商家每日订单量
                $redis->hIncrByFloat('_seller_order:seller_id_'.$seller_id,date('Y-m-d'),1);


                //商家总订单量
                $seller_order_all = $redis->hGet('_seller_order_all',$seller_id);
                if(!$seller_order_all){
                    //获取卖家订单量和总销售金额
                    $order_num = DB::table('order')->where('seller_id', $seller_id)->count();
                    $redis->hSet('_seller_order_all',$seller_id,$order_num+1);
                }else{
                    $redis->hSet('_seller_order_all',$seller_id,$seller_order_all+1);
                }

                //销售统计
                $redis->hIncrByFloat('_sale_statistic_d', date('Y-m-d'), $dataArray['order_amount']);
                $redis->hIncrByFloat('_sale_statistic_m', date('Y-m'), $dataArray['order_amount']);

                //订单统计
                $redis->hIncrBy('_sale_order_d', date('Y-m-d'), 1);
                $redis->hIncrBy('_sale_order_m', date('Y-m'), 1);

                //订单类型
                if($device == 3){
                    $redis->hIncrBy('_sale_order_type_ios', date('Y-m'), 1);
                }else{
                    $redis->hIncrBy('_sale_order_type_android', date('Y-m'), 1);
                }

            }
            //清空购物车
            $cartData = str_replace(array('"',','),array('&','$'),json_encode($cartData));
            DB::table('goods_car')->where('user_id', $user_id)->update(array('content'=>$cartData));
            DB::commit();
        }catch (\Exception $e){
            DB::rollBack();
            echo $e->getMessage();exit;
            return $this->error(400, '订单创建失败');  //t_07
        }

        //数据渲染
        $res['order_id']    = join(",",$orderIdArray);

        return $this->success($res);
    }
}
