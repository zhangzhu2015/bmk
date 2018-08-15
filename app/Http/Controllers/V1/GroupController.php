<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/7/24 0024
 * Time: 15:25
 */

namespace App\Http\Controllers\V1;


use App\Htpp\Traits\ApiResponse;
use App\Http\Controllers\Controller;
use App\Librarys\Area;
use App\Librarys\CloudSearch;
use App\Librarys\Delivery;
use App\Librarys\Redisbmk;
use App\Models\Seller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\Goods;

class GroupController extends Controller
{
    use ApiResponse;

    /**
     * author: guoDing
     * createTime: 2018/7/24 0024 15:27
     * description: 发起/加入拼单校验;提交拼单,生成拼单数据
     */
    public function createGroup(Request $request)
    {
        $this->validate($request, [
            'goods_id' => 'required',
            'quota_goods_id' => 'required',
            'num' => 'required',
        ]);

        $goods_id       = $request->input('goods_id');//商品id
        $products_id    = $request->input('products_id', 0);//货品id
        $quota_goods_id = $request->input('quota_goods_id');//拼单商品id（不是商品id）
        $num            = $request->input('num', 1);//购买数量
        $user_id        = Auth::id();//买家用户id
        $type           = $request->input('type');//1：加入拼单 2：发起拼单
        $address_id     = $request->input('address_id');//收货地址id
        $device         = $request->input('device');//1web,2wap,3ios,4andriod
        $pay_type       = $request->input('pay_type');//支付方式id
        $delivery_id    = $request->input('delivery_id', 1);//配送方式id
        $quota_orders_id = $request->input('quota_orders_id');//拼单id  被邀请时存在
        $now = date('Y-m-d H:i:s',time());

        //主动加人其他拼单和被邀请加人拼单
        if ($type == 1){
            if (!$quota_orders_id){
                return $this->error(400, "Parameter error");
            }

            $quota_orders_row = DB::table('quota_orders')->where('id', '=', $quota_orders_id)->first();
            if (!$quota_orders_row){
                return $this->error(400, "Parameter error");
            }

            //该拼单已经完成
            if ($quota_orders_row->is_success == 1 || $quota_orders_row->people == $quota_orders_row->join_people){
                return $this->error(400, "This group buy has been completed");
            }

            //该拼单已经超过12小时
            if ($quota_orders_row && (strtotime($now) - strtotime($quota_orders_row->created_time)) > 24*60*60){
                return $this->error(400, "This group has expired, kindly make another group or simply join an existing one.");
            }
        }

        if ($type == 1 || $type == 2){
            if (!$address_id || !$pay_type){
                return $this->error(400, "Parameter error");
            }
            $payment = DB::table('payment')->where('id', '=', $pay_type)->first();
            if (!$payment){
                return $this->error(400, "Parameter error");
            }
            //校验收货地址信息
            $address_info = DB::table('address')->where('id', '=', $address_id)->where('user_id', '=', $user_id)->first();
            if (!$address_info){
                return $this->error(400, "Parameter error");
            }

            if (!$device){
                return $this->error(400, "Parameter error");
            }
        }

        $goods_info = DB::table('goods')->where('id', '=', $goods_id)->where('is_del', '=', 0)->first();
        if (!$goods_info){
            return $this->error(400, "Parameter error");
        }
        if ($goods_info->spec_array && !$products_id){
            return $this->error(400, "Parameter error");
        }
        if ($products_id){
            $products_info = DB::table('products')->where('id', '=', $products_id)->where('goods_id', '=', $goods_id)->first();
            if (!$products_info){
                return $this->error(400, "Parameter error");
            }
        }

        //发起拼单 开始===============
        //商品拼单不正常
        $quota_goods = DB::table('quota_goods')
            ->where('id', '=', $quota_goods_id)
            ->where('goods_id', '=', $goods_id)
            ->where('is_check', '=', 1)
            ->where('status', '=', 1)
            ->first();
        if (!$quota_goods){
            return $this->error(400, "Sorry, this Group Buy Product is sold out.");
        }

        //拼单商品库存
        if (!$products_id && !$quota_goods->quota_number) {
            return $this->error(400, "Sorry, this Group Buy Product is sold out.");
        }

        //拼单货品库存
        if ($products_id){
            $quota_product_detail = json_decode($quota_goods->product_detail,true);
            if (!$quota_product_detail[$products_id]['quota_number']){
                return $this->error(400, "Sorry, this Group Buy Product is sold out.");
            }

            //货品拼单被删
            if ($quota_product_detail[$products_id]['is_quota'] != 1){
                return $this->error(400, "Sorry,this variation was Sold Out.");
            }
        }

        //每账号 在一个活动中只能对同一个产品拼单一次
        $qodetail = DB::table('quota_orders_detail as qod')
            ->leftJoin('quota_orders as qo', 'qo.id', '=', 'qod.quota_orders_id')
            ->leftJoin('quota_goods as qg', 'qo.id', '=', 'qo.quota_goods_id')
            ->select('qg.quota_activity_id as q_id')
            ->where('qod.goods_id', '=', $goods_id)
            ->where('qod.user_id', '=', $user_id)
            ->first();
        if ($qodetail && $qodetail->q_id == $quota_goods->quota_activity_id){
            return $this->error(400, "Sorry, you cannot buy twice with the group buy product.");
        }

        if ($type == 1 || $type == 2) {
            //自提支付 完全不限制地区
            //校验拼单地区 Cavite, Laguna,Batangas, Rizal&Bulacan ,马尼拉
            if ($quota_goods->area == 3 && $pay_type != 17) {
                $area = array(421, 434, 410, 458, 314,175917007);
                if (!in_array($address_info->province, $area)) {
                    return $this->error(400, "Sorry,This group buy Product Only for GMA Areas.");
                }
            }
            if ($quota_goods->area == 1 && $pay_type != 17) { //仅限马尼拉下单  测试服175961201和正式服175917007 马尼拉id不同
                if (env('REDIS_PREFIX') == "dev"){
                    $manila = 175961201;
                }else{
                    $manila = 175917007;
                }

                if ($address_info->province != $manila) {
                    return $this->error(400, "Sorry,This group buy Product Only for Metro Manila Areas.");
                }
            }
        }

        //校验购买数量
        if ($num > $quota_goods->max){
            return $this->error(400, "More than the maximum purchase quantity.");
        }

        if ($products_id){
            if (!$quota_goods->product_detail){
                return $this->error(400, "Parameter error.");
            }
        }

        $quota_activity = DB::table('quota_activity')
            ->where('id', '=', $quota_goods->quota_activity_id)
            ->where('status', '=', 1)
            ->first();
        if (!$quota_activity){
            return $this->error(400, "Parameter error.");
        }

        //活动未开始
        if (strtotime($now) - strtotime($quota_activity->user_start_time) < 0){
            return $this->error(400, "We will refresh the page after 10 seconds to show you group buy product.");
        }
        //活动结束
        if (strtotime($now) - strtotime($quota_activity->user_end_time) > 0){
            return $this->error(400, "Sorry,Group buy Activity was over.");
        }

        //用户已参加此商品其他拼单(且未完成)
        $quota_orders = DB::table('quota_orders as qo')
            ->leftJoin('quota_orders_detail as qod', 'qo.id', '=', 'qod.quota_orders_id')
            ->select('qo.*','qod.id as qod_id')
            ->where('qod.user_id', '=', $user_id)
            ->where('qo.is_success', '=', 0)
            ->where('qod.goods_id', '=', $goods_id)
            ->where('qod.code', '=', 1)
            ->first();
        if ($quota_orders){
            return $this->error(400, "Sorry,you had joined in this group buy.");
        }

        if ($type == 1 || $type == 2) {
            DB::beginTransaction(); //事务开始
            $quota_orders_rs = 1;
            //发起拼单
            if ($type == 2) {
                $data = array(
                    'people' => $quota_goods->people,
                    'join_people' => 1,
                    'is_success' => 0,
                    'status' => 1,
                    'quota_goods_id' => $quota_goods_id,
                    'goods_id' => $goods_id,
                    'lead_user_id' => $user_id,
                    'created_time' => $now,
                    'updated_time' => $now
                );
                $quota_orders_id = DB::table('quota_orders')->insertGetId($data);
            }

            //加入拼单
            if ($type == 1) {
                $data3 = array(
                    'join_people' => $quota_orders_row->join_people + 1,
                    'updated_time' => $now
                );
                $quota_orders_rs = DB::table('quota_orders')->where('id', '=', $quota_orders_id)->update($data3);
            }

            //拼单商品表增加拼单销量
            /*$quota_sale = $quota_goods['quota_sale'] + $num;
            if ($products_id) {
                $quota_product_detail[$products_id]['quota_sale'] = $quota_product_detail[$products_id]['quota_sale'] + $num;
            }
            $quota_goods_rs = M('quota_goods')->where(array('id' => $quota_goods_id))->save(array('quota_sale' => $quota_sale, 'product_detail' => json_encode($quota_product_detail)));*/

            //写入拼单明细
            if ($products_id) {
                $goods_price = $products_info->sell_price;
                $quota_price = $quota_product_detail[$products_id]['quota_price'];
                $goods_weight = $products_info->weight;
                $package_size = $products_info->package_size;

                $spec_array = json_decode($products_info->spec_array, true);
                $values = array();
                foreach ($spec_array as $key => $value) {
                    $values[] = $value['name'] . ":" . $value['value'];
                }
                $goods_array = json_encode(array('name' => $goods_info->name, 'goodsno' => $products_info->products_no, 'value' => implode(",", $values)));
            } else {
                $goods_price = $goods_info->sell_price;
                $quota_price = $quota_goods->quota_price;
                $goods_weight = $goods_info->weight;
                $package_size = $goods_info->package_size;
                $goods_array = json_encode(array('name' => $goods_info->name, 'goodsno' => $goods_info->goods_no, 'value' => ''));
            }

            if($pay_type == 17) {
                $deliveryPrice = 0;
            }else{
                $deliveryDB = new Delivery();
                $delivery = $deliveryDB->getDelivery($address_info->province, $delivery_id, $goods_id, $products_id, $num, $user_id);
                $deliveryPrice = $delivery['price'];
            }

            $data2 = array(
                'quota_code' => date('YmdHis') . rand(100000, 999999),
                'quota_orders_id' => $quota_orders_id,
                'user_id' => $user_id,
                'goods_id' => $goods_id,
                'img' => $goods_info->img,
                'product_id' => $products_id,
                'goods_price' => $goods_price,
                'quota_price' => $quota_price,
                'real_price' => 0,
                'goods_nums' => $num,
                'goods_weight' => $goods_weight,
                'package_size' => $package_size,
                'goods_array' => $goods_array,
                'seller_id' => $goods_info->seller_id,
                'pay_type' => $pay_type,
                'distribution' => $delivery_id,
                'accept_name' => $address_info->accept_name,
                'postcode' => $address_info->zip,
                'telphone' => $address_info->telphone,
                'mobile' => $address_info->mobile,
                'province' => $address_info->province,
                'city' => $address_info->city,
                'area' => $address_info->area,
                'address' => $address_info->address,
                'payable_amount' => $goods_price * $num,
                'real_amount' => $quota_price * $num,
                'payable_freight' => $deliveryPrice,
                'real_freight' => $deliveryPrice,
                'insured' => 0,
                'taxes' => 0,
                'promotions' => 0,
                'discount' => 0,
                'voucher_id' => 0,
                'order_amount' => $deliveryPrice + $quota_price * $num,
                'invoice' => $request->input('taxes', 0),
                'invoice_title' => $request->input('tax_title', 0),
                'prop' => '',
                'accept_time' => $request->input('accept_time', 'At will'),
                'is_self' => $request->input('is_self', 0),
                'message' => $request->input('message', 0),
                'order_from' => $request->input('device', 1),
                'code' => 1,
                'status' => 1,
                'created_time' => date('Y-m-d H:i:s'),
                'updated_time' => date('Y-m-d H:i:s'),

            );
            $quota_orders_detail_id = DB::table('quota_orders_detail')->insertGetId($data2);

            $flag = 0;
            $quota_orders_row = DB::table('quota_orders')->where('id', '=', $quota_orders_id)->first();
            $flag = $quota_orders_row->people - $quota_orders_row->join_people;
            if ($type == 1){//加入拼单
                //参与拼单用户是该拼团最后一个成员
                if ($flag == 0){
                    $data4 = array(
                        'is_success' => 1,
                        'updated_time' => $now
                    );
                    DB::table('quota_orders')->where('id', '=', $quota_orders_id)->update($data4);
                }
            }

            if ($quota_orders_id && $quota_orders_rs && $quota_orders_detail_id) {
                DB::commit();

                $quota_orders_detail_user = DB::table('quota_orders_detail as qod')
                    ->leftJoin('user as u', 'qod.user_id', '=', 'u.id')
                    ->select('qod.id as qod_id','u.head_ico as user_head_ico')
                    ->where('quota_orders_id', '=', $quota_orders_id)
                    ->get();

                //参与拼单用户是该拼团最后一个成员
                if ($flag == 0 && $type == 1){
                    $quotaInfo['quota_orders_id'] = $quota_orders_id;
                    $quotaInfo['quota_orders_detail_id'] = $quota_orders_detail_id;
                    $quotaInfo['pay_type']= $payment->name;
                    $quotaInfo['message1'] = 'Orders submitted successfully';
                    $quotaInfo['price'] = sprintf('%.2f',$data2['order_amount']);
                    $quotaInfo['people'] = $quota_goods->people;
                    $quotaInfo['end_time'] = '';
                    $quotaInfo['message2'] = '';
                }else{//参与拼单用户不是该拼团最后一个成员
                    $quotaInfo['quota_orders_id'] = $quota_orders_id;
                    $quotaInfo['quota_orders_detail_id'] = $quota_orders_detail_id;
                    $quotaInfo['pay_type']= $payment->name;
                    $quotaInfo['message1'] = 'Quota has Submitted，but Quota is not successfully';
                    $quotaInfo['price'] = sprintf('%.2f',$data2['order_amount']);
                    $quotaInfo['people'] = $quota_goods->people;
                    $quotaInfo['end_time'] = date('Y-m-d H:i:s',strtotime($quota_orders_row->created_time) + 86400);
                    $quotaInfo['message2'] = "Need ".$flag." person join in this Group buy to make it successful";
                    $quotaInfo['nowtime'] = $now;
                }
                $quotaInfo['info'] = $quota_orders_detail_user;
                return $this->success($quotaInfo);

            } else {
                DB::rollback();
                return $this->error(400, "Network error,Please check the network is normal.");
            }

        }

        return $this->success("success");
    }

    /**
     * author: guoDing
     * createTime: 2018/7/24 0024 17:21
     * description: 拼单详情 拼单商品页面中间部分，显示关于这个商品的拼单和控制按钮显示
     */
    public function groupInfo(Request $request)
    {
        $this->validate($request, [
            'goods_id' => 'required',
            'quota_goods_id' => 'required',
        ]);

        $goods_id       = $request->input('goods_id');//商品id
        $quota_goods_id = $request->input('quota_goods_id');//拼单商品id（不是商品id）
        $lead_user_id   = $request->input('lead_user_id');//购买数量
        $user_id = Auth::id();

        //校验参数是否有效
        //商品拼单不正常
        $quota_goods = DB::table('quota_goods')
            ->where('id', '=', $quota_goods_id)
            ->where('goods_id', '=', $goods_id)
            ->where('is_check', '=', 1)
            ->where('status', '=', 1)
            ->first();
        if (!$quota_goods){
            return $this->error(400, "Sorry, this Group Buy Product is sold out.");
        }
        $quotaInfo = array();

        //用户已参加此商品其他拼单(且未完成)
        if ($user_id){
            $quota_orders = DB::table('quota_orders as qo')
                ->leftJoin('quota_orders_detail as qod', 'qo.id', '=', 'qod.quota_orders_id')
                ->select('qo.*','qod.id as qod_id')
                ->where('qod.user_id', '=', $user_id)
                ->where('qo.is_success', '=', 0)
                ->where('qod.goods_id', '=', $goods_id)
                ->where('qod.code', '=', 1)
                ->first();
        }

        //用户此商品存在拼单 无论有没有邀请人
        if ($quota_orders){
            $quota_orders_detail_user = DB::table('quota_orders_detail as qod')
                ->leftJoin('user as u', 'qod.user_id', '=', 'u.id')
                ->select('qod.id as qod_id','u.head_ico as user_head_ico')
                ->where('quota_orders_id', '=', $quota_orders->id)
                ->get();

            $message2 = $quota_orders->join_people."/".$quota_orders->people;
            $message3 = "Need ".($quota_orders->people-$quota_orders->join_people)." person join in group buy";
            $quotaInfo['message1'] = 'You are already joined in this group buy!';
            $quotaInfo['message2'] = $message2;
            $quotaInfo['message3'] = $message3;
            $quotaInfo['quota_orders_id'] = $quota_orders->id;
            $quotaInfo['is_join_quota'] = 1;
            $quotaInfo['end_time'] = date('Y-m-d H:i:s',strtotime($quota_orders->created_time)+86400);
            $quotaInfo['info'] = $quota_orders_detail_user;
            $quotaInfo['button'] = array(
                'buy_alone'     =>  0,
                'create_quota'  =>  0,
                'chat_now'      =>  0,
                'join_quota'    =>  0,
                'invite_quota'  =>  1
            );
        }

        if ($lead_user_id){
            $quota_orders_lead = DB::table('quota_orders as qo')
                ->leftJoin('quota_orders_detail as qod', 'qo.id', '=', 'qod.quota_orders_id')
                ->select('qo.*','qod.id as qod_id')
                ->where('qod.lead_user_id', '=', $lead_user_id)
                ->where('qo.is_success', '=', 0)
                ->where('qod.goods_id', '=', $goods_id)
                ->where('qod.code', '=', 1)
                ->first();
            if (!$quota_orders_lead){
                $lead_user_id = '';
            }
        }

        //用户此商品不存在拼单 且有邀请人
        if ($lead_user_id && !$quota_orders){
            $quota_orders_detail_user = DB::table('quota_orders_detail as qod')
                ->leftJoin('user as u', 'qod.user_id', '=', 'u.id')
                ->select('qod.id as qod_id','u.head_ico as user_head_ico')
                ->where('quota_orders_id', '=', $quota_orders_lead->id)
                ->get();
            $message2 = $quota_orders_lead->join_people."/".$quota_orders_lead->people;
            $message3 = "Need ".($quota_orders_lead->people - $quota_orders_lead->join_people)." person join in group buy";
            $quotaInfo['message1'] = 'Your friend invited you to join in this group buy!';
            $quotaInfo['message2'] = $message2;
            $quotaInfo['message3'] = $message3;
            $quotaInfo['quota_orders_id'] = $quota_orders_lead->id;
            $quotaInfo['is_join_quota'] = 3;
            $quotaInfo['end_time'] = date('Y-m-d H:i:s',strtotime($quota_orders_lead->created_time) + 86400);
            $quotaInfo['info'] = $quota_orders_detail_user;
            $quotaInfo['button'] = array(
                'buy_alone'     =>  1,
                'create_quota'  =>  0,
                'chat_now'      =>  1,
                'join_quota'    =>  1,
                'invite_quota'  =>  0
            );
        }

        //用户此商品不存在拼单 且没有邀请人
        if (!$lead_user_id && !$quota_orders){
            $join_people = DB::table('quota_orders as qo')
                ->select(DB::raw("SUM(iwebshop_qo.join_people) as join_people"))
                ->where('quota_goods_id', '=', $quota_goods_id)
                ->get()->toArray();
            $join_people = array_map('get_object_vars', $join_people);

            $quota_orders_row = DB::table('quota_orders as qo')
                ->leftJoin('user as u', 'qo.lead_user_id', '=', 'u.id')
                ->select('qo.*','u.head_ico as user_head_ico','u.username as user_name')
                ->where('quota_goods_id', '=', $quota_goods_id)
                ->whereRaw('HOUR( timediff( now(), created_time) ) < 24')
                ->where('is_success', '=', 0)
                ->orderBy('join_people', 'desc')
                ->get();
            $list = array();
            $i = 0;
            foreach ($quota_orders_row as $k => $v){
                $list[$i]['quota_orders_id'] = $v->id;
                $list[$i]['user_head_ico'] = $v->user_head_ico;
                $list[$i]['user_name'] = $v->user_name;
                $list[$i]['people'] = $v->people;
                $list[$i]['message'] = "Need ".($v->people - $v->join_people)." person";
                $list[$i]['end_time'] = date('Y-m-d H:i:s',strtotime($v->created_time) + 86400);
                $quota_orders_detail_user = DB::table('quota_orders_detail as qod')
                    ->leftJoin('user as u', 'qod.user_id', '=', 'u.id')
                    ->select('qod.id as qod_id','u.head_ico as user_head_ico','u.username as user_name')
                    ->where('quota_orders_id', '=', $v->id)
                    ->get();
                $list[$i]['info'] = $quota_orders_detail_user;
                $i++;
            }
            $joinpeople = 0;
            if ($join_people[0]['join_people'] != 0){
                $joinpeople = $join_people[0]['join_people'];
            }
            $quotaInfo['message1'] = $joinpeople." person joined in this group buy!";
            $quotaInfo['message2'] = ">>more";
            $quotaInfo['message3'] = '';
            $quotaInfo['is_join_quota'] = 2;
            $quotaInfo['list'] = $list;
            $quotaInfo['button'] = array(
                'buy_alone'     =>  1,
                'create_quota'  =>  1,
                'chat_now'      =>  1,
                'join_quota'    =>  0,
                'invite_quota'  =>  0
            );
        }
        return $this->success($quotaInfo);
    }

    /**
     * author: guoDing
     * createTime: 2018/7/24 0024 17:22
     * description: 也许喜欢
     */
    public function youLike(Request $request)
    {
        $this->validate($request, [
            'goods_id' => 'required',
            'goods_name' => 'required',
        ]);
        $goods_id = $request->input('goods_id');
        $goods_name = $request->input('goods_name');

        $page = $request->input('page', 1);
        $pageSize = $request->input('pageSize', 6);
        $cate_info = DB::table('category_extend')
            ->where('goods_id', '=', $goods_id)
            ->first();
        if ($cate_info->category_id){
            $list = DB::table('quota_goods as qg')
                ->leftJoin('category_extend as ce', 'qg.goods_id', '=', 'ce.goods_id')
                ->select('qg.goods_id as goods_id')
                ->where('ce.category_id', '=', $cate_info->category_id)
                ->where('qg.status', '=', 1)
                ->where('qg.is_check', '=', 1)
                ->take($pageSize)
                ->get()->toArray();
            $list = array_map('get_object_vars', $list);
        }

        $goodsLists = [];
        $count = 0;
        if ($list){
            $count = count($list);
            $goodsLists = DB::table('goods')
                ->whereIn('id',$list)
                ->get()->toArray();
            $goodsLists = array_map('get_object_vars', $goodsLists);
        }
        $sum = $count - 0;

        $paramsearch = [];
        $paramsearch['cate_id']    = $cate_info->category_id;
        $paramsearch['search']     = $goods_name;
        $paramsearch['size']       = $pageSize;
        $paramsearch['page']       = $page;
        $paramsearch['type']       = 'goodsSimilar';


        $goodsList = [];
        $cloudsearch = new CloudSearch();
        $goodsList = $cloudsearch->search($paramsearch);
        if (!$goodsList) {
            $dataArray = array(
                'goodsList' => $goodsList,
                'condition' => [],
            );

            return $this->success($dataArray);
        }

        foreach($goodsList as $k=>$v){
            $goodsList[$k]['active_id'] = '';
            $goodsList[$k]['promo'] = '';
            $goodsList[$k]['start_time'] = '';
            $goodsList[$k]['end_time'] = '';
            $res = Goods::getnewPromotionRowById($v['id']);
            if($res){
                $goodsList[$k]['sell_price'] = showPrice($res->award_value);
                $goodsList[$k]['active_id'] = $res->id;
                $goodsList[$k]['promo'] = 'time';
                $goodsList[$k]['start_time'] = $res->start_time;
                $goodsList[$k]['end_time'] = $res->end_time;
            }
            $quotaRow = Goods::getQuotaRowBygoodsId($v['id']);
            if($quotaRow){
                $goodsList[$k]['active_id'] = $quotaRow->quota_activity_id;
                $goodsList[$k]['promo'] = 'quota';
                $goodsList[$k]['start_time'] = $quotaRow->activity_start_time;
                $goodsList[$k]['end_time'] = $quotaRow->activity_end_time;
            }

            $diff = ($goodsList[$k]['market_price'] - $goodsList[$k]['sell_price'])/$goodsList[$k]['market_price'];
            $goodsList[$k]['discount'] = $diff <= 0 ? '' : number_format($diff,2)*100;
            if (!$goodsList[$k]['is_cashondelivery']) {
                $goodsList[$k]['is_cashondelivery'] = "1";
            }
            $goodsList[$k]['img'] = getImgDir($goodsList[$k]['img'],300,300);
            $goodsList[$k]['is_shipping'] = $goodsList[$k]['seller_is_shipping'];
        }
        foreach($goodsList as $k => $v){
            array_push($goodsLists,$v);
        }

        $dataArray = array(
            'goodsList' => $goodsList,
            'condition' => [],
        );

        return $this->success($dataArray);
    }

    /**
     * author: guoDing
     * createTime: 2018/7/24 0024 17:23
     * description: 邀请拼单 生成邀请代码
     */
    public function inviteGroup(Request $request)
    {
        $this->validate($request, [
            'quota_orders_id' => 'required'
        ]);
        $quota_orders_id = $request->input('quota_orders_id');
        $user_id = Auth::id();
        $now = date('Y-m-d H:i:s',time());

        $quota_orders_row = DB::table('quota_orders')->where('id', '=', $quota_orders_id)->first();
        if (!$quota_orders_row){
            return $this->error(400, "Parameter error");
        }

        //该拼单已经完成
        if ($quota_orders_row->is_success == 1 || $quota_orders_row->people == $quota_orders_row->join_people){
            return $this->error(400, "This Quota has been completed,You can join in other group buy");
        }

        //该拼单已经超过12小时
        if (strtotime($now) - strtotime($quota_orders_row->created_time) > 24*60*60){
            return $this->error(400, "This group has expired, kindly make another group or simply join an existing one.");
        }

        $quota_goods = DB::table('quota_goods')->where('id', '=', $quota_orders_row->quota_goods_id)
            ->where('status', '=', 1)
            ->first();
        if (!$quota_goods){
            return $this->error(400, "Parameter error");
        }
        $quota_activity = DB::table('quota_activity')
            ->where('id', '=', $quota_goods->quota_activity_id)
            ->where('status', '=', 1)
            ->first();
        //活动结束
        if (strtotime($now) - strtotime($quota_activity->user_end_time) > 0){
            return $this->error(400, "Sorry,6.18 Group Buy Activity was over!");
        }

        //用户已参加此商品其他拼单(且未完成)
        $quota_orders = DB::table('quota_orders as qo')
            ->leftJoin('quota_orders_detail as qod', 'qo.id', '=', 'qod.quota_orders_id')
            ->select('qo.*','qod.id as qod_id')
            ->where('qod.user_id', '=', $user_id)
            ->where('qo.is_success', '=', 0)
            ->where('qod.goods_id', '=', $quota_goods->goods_id)
            ->where('qod.code', '=', 1)
            ->get()->toArray();
        $quota_orders = array_map('get_object_vars', $quota_orders);
        if (count($quota_orders) >= 2){
            return $this->error(400, "Sorry,you had joined in this group buy!");
        }

        $goods_info = DB::table('goods')->where('id', '=', $quota_goods->goods_id)->where('is_del', '=', 0)->first();
        if (!$goods_info){
            return $this->error(400, "Parameter error");
        }
        $code = '';
        for ($i = 1; $i <= 10; $i++) {
            $code .= chr(rand(65,90));
        }
        $redis = new Redisbmk();
        $code .= $redis->incr("_quota:".$quota_orders_id);
        //$people = $quota_goods['people'] - $quota_goods['join_people'];
        $people = 1;
        $sharelink = array(
            'quota_orders_id'   =>  $quota_orders_id,
            'quota_activity_id' => $quota_goods->quota_activity_id,
            'lead_user_id'      =>  $quota_orders_row->lead_user_id,
            'quota_price'       =>  $quota_goods->quota_price,
            'goods_id'          =>  $quota_orders_row->goods_id,
            'user_id'           =>  $user_id,
            'title'             =>  'Join Bigmk Group buy | '.$goods_info->name,
            'description'       =>  'Help! I need '.$people.' more friend to buy this item at its FACTORY PRICE for only <₱'.$quota_goods->quota_price.'>',
            'goods_name'        =>  $goods_info->name,
            'goods_img'         =>  $goods_info->img,
            'add_time'          =>  date('Y-m-d H:i:s'),
        );
        $redis->set("_quotainvite_".$code, json_encode($sharelink));
        $data = array(
            //title' =>  'Hi friends! I found this <'.$goods_info['name'].'> on Bigmk Quota Sale and i just need  more friend to buy it with me so we can get it on its Factory Price <'.$quota_goods['quota_price'].'>',
            //'goods_name'        =>  $goods_info['name'],
            'title'             =>  'Join Bigmk Group buy | '.$goods_info->name,
            'goods_name'       =>  'Help! I need '.$people.' more friend to buy this item at its FACTORY PRICE for only <₱'.$quota_goods->quota_price.'>',
            'goods_img'        =>  $goods_info->img,
            'url'   =>  env('BMK_HOST')."site/getinvite?quota=".$code,
        );
        return $this->success($data);
    }

    /**
     * author: guoDing
     * createTime: 2018/7/24 0024 17:24
     * description: 拼单提交页面显示商品信息
     */
    public function groupOrderGoods(Request $request)
    {
        $this->validate($request, [
            'quota_goods_id' => 'required'
        ]);
        $quota_goods_id = $request->input('quota_goods_id');
        $products_id = $request->input('products_id');
        $num = $request->input('num');

        $quota_goods = DB::table('quota_goods')->where('id', '=', $quota_goods_id)->first();
        if ($quota_goods){
            if ($quota_goods->product_detail){
                $product_detail = json_decode($quota_goods->product_detail,true);
                $goods_price = $product_detail[$products_id]['quota_price'];
            }else{
                $goods_price = $quota_goods->quota_price;
            }

            $goods_info = DB::table('goods')->where('id', '=', $quota_goods->goods_id)->first();
            if ($goods_info){
                $seller_info = DB::table('seller')->where('id', '=', $goods_info->seller_id)->first();
            }
            if ($products_id){
                $products_info = DB::table('products')->where('id', '=', $products_id)->first();
            }
            $spec_array = '';
            $spec = '';
            if ($products_info){
                foreach (json_decode($products_info->spec_array,true) as $k => $v){
                    $spec_array[] = $v['name'].":".$v['value'];
                }
                $spec = implode(";",$spec_array);
            }
            $sellerinfo = Seller::getPayment(array($goods_info->seller_id => 1));
            $data = array(
                'goods_name' => $goods_info->name,
                'goods_price' => $goods_price,
                'goods_img' => $goods_info->img,
                'num'   => $num,
                'count' => $goods_price*$num,
                'spec_array' => $spec,
                'payment'=>$sellerinfo[$goods_info->seller_id],
                'area_type'=> $quota_goods->area,
            );
            return $this->success($data);
        }
        return $this->error(400, "Parameter error");
    }

    /**
     * author: guoDing
     * createTime: 2018/7/24 0024 17:25
     * description: 拼单收货地址
     */
    public function groupAddressList(Request $request)
    {
        $this->validate($request, [
            'area_type' => 'required'
        ]);
        //获取收货地址
        $user_id = Auth::id();
        $area_type = $request->input('area_type'); //1马尼拉, 2全国,3GMA(Cavite, Laguna,Batangas, Rizal & Bulacan)

        switch ($area_type){
            case 1 :
                $area_id = DB::table('areas')->where('area_name', '=', 'Metro manila')->value('area_id');
                break;
            case 2 :
                $area_id = 'all';
                break;
            case 3 :
                //查询
                $area_id = DB::table('areas')->whereIn('area_name', array("Cavite","Laguna","Batangas","Rizal","Bulacan","Metro manila"))->where('parent_id', '=', 0)->value('area_id');
                break;
            default:
        }
        $addressList = DB::table('address')->where('user_id', '=', $user_id)->orderBy('is_default', 'desc')->get()->toArray();
        $addressList = array_map('get_object_vars', $addressList);
        $area = new Area();
        //更新$addressList数据
        foreach($addressList as $key => $val)
        {
            $addressList[$key]['mobile'] = showMobile($val['mobile']);
            $addressList[$key]['province_val'] = '';
            $addressList[$key]['city_val']     = '';
            $addressList[$key]['area_val']     = '';
            $temp = $area->name($val['province'],$val['city'],$val['area']);
            if(isset($temp[$val['province']]) && isset($temp[$val['city']]) && isset($temp[$val['area']]))
            {
                $addressList[$key]['province_val'] = $temp[$val['province']];
                $addressList[$key]['city_val']     = $temp[$val['city']];
                $addressList[$key]['area_val']     = $temp[$val['area']];
            }
        }
        $res = [];
        foreach($addressList as $key=>$val){
            if($area_id == 'all'){
                $res['can_select'][] = $val;
            }else{
                if(in_array($val['province'],$area_id)){
                    $res['can_select'][] = $val;
                }else{
                    $res['cannot_select'][] = $val;
                }
            }
        }
        if($area_id == 'all'){
            $res['cannot_select'] = [];
        }

        return $this->success($res);
    }
}