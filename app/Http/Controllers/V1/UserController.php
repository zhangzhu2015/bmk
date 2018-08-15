<?php

namespace App\Http\Controllers\V1;

use App\Htpp\Traits\ApiResponse;
use App\Http\Requests\V1\UnFollowRequest;
use App\Http\Requests\V1\RestPasswordRequest;
use App\Librarys\ProRule;
use App\Librarys\Redisbmk;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Promotion;
use App\Models\User;
use App\Models\Voucher;
use Validator;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    use ApiResponse;

    /**
     * @return mixed
     * CreateTime: 2018/7/17 10:26
     * Description: 获取用户信息
     */
    public function userInfo()
    {
        $data['user_info'] = DB::table('user as u')
            ->leftJoin('member as m', 'm.user_id', '=', 'u.id')
            ->select('u.id', 'u.username', 'u.email', 'u.head_ico', 'u.useruin', 'm.mobile', 'm.sex', 'm.birthday')
            ->find(Auth::id());

        //收藏数
        $data['favorite_count'] = count(Goods::getFavorites(Auth::id()));
        //关注数
        $data['seller_user_fav_count'] = DB::table('seller_user_fav')->where('user_id', Auth::id())->count();
        //订单数
        $order_count = DB::table('order')->where('user_id', Auth::id())->select(DB::raw("count(*) as c"), "order_status", 'pay_type')->groupBy('order_status', 'pay_type')->get();

        $undelivered_count = 0;
        $in_transit_count = 0;
        $pick_up_count = 0;
        foreach ($order_count as $k => $v) {
            if ($v->order_status == 1) {
                $undelivered_count += $v->c;
            } else if ($v->order_status == 3) {
                $in_transit_count += $v->c;
            }

            if ($v->pay_type == 17) {
                $pick_up_count += $v->c;
            }
        }
        $data['undelivered_count'] = $undelivered_count;
        $data['in_transit_count'] = $in_transit_count;
        $data['pick_up_count'] = $pick_up_count;
        $data['comment_count'] = DB::table('comment')->where('user_id', Auth::id())->distinct('order_no')->count();
        $data['quota_count'] = DB::table('quota_orders_detail')->where('user_id', Auth::id())->count();
        $data['voucher_count'] = DB::table('voucher_str as vs')
            ->join('voucher as v', 'v.id', '=', 'vs.voucher_id')
            ->where('vs.user_id', Auth::id())
            ->where('v.end_time', '>', date("Y-m-d H:i:s"))
            ->where('v.status', '<', 5)
            ->count();

        //信息数
        $member = DB::table('member')->where('user_id', Auth::id())->first();
        $message_ids = $member->message_ids;
        $tempIds = ',' . trim($message_ids, ',') . ',';
        preg_match_all('|,\d+|', $tempIds, $result);
        $count = count(current($result));
        if ($count) {
            $data['message_num'] = $count;
        } else {
            $data['message_num'] = 0;
        }

        return $this->success($data, 200);
    }


    /**
     * @param \App\Http\Requests\V1\RestPasswordRequest $request
     * @return mixed
     * CreateTime: 2018/7/17 10:55
     * Description: 修改密码
     */
    public function restPassword(RestPasswordRequest $request)
    {
        $ck = DB::table('user')->where('id', Auth::id())->update([
            'password' => md5($request->password)
        ]);

        if ($ck)
            return $this->success(['msg' => 'Succeed'], 200);
        return $this->error(400, 'Failed');
    }

    /**
     * @return mixed
     * CreateTime: 2018/7/17 11:58
     * Description: 获取我的地址
     */
    public function myAddress()
    {
        $prefix = env('DB_PREFIX', 'iwebshop_');
        $address = DB::table('address as a')
            ->where('user_id', Auth::id())
            ->select('a.id', 'a.mobile', 'a.accept_name', 'a.is_default', 'a.address', 'a.province', 'a.city', 'a.zip', 'a.telphone', 'a.area', DB::raw("(select GROUP_CONCAT(area_id,'_',area_name) from " . $prefix . 'areas' . " where area_id = " . $prefix . 'a.province' . " or area_id = " . $prefix . 'a.city' . " or area_id = " . $prefix . 'a.area' . ") as area_name"))
            ->get();
        $address = $address->map(function ($i) {
            // 处理area_name
            $area_name = explode(',', $i->area_name);
            foreach ($area_name as $k => $v) {
                $temp = explode('_', $v);
                $arrs = ['province', 'city', 'area'];
                $arrs_id = ['province_id', 'city_id', 'area_id'];
                foreach ($arrs as $kk => $arr) {
                    if ($temp[0] == $i->$arr) {
                        $i->$arr = $temp[1];
                        $i->{$arrs_id[$kk]} = $temp[0];
                    }
                }
            }
            $i = collect($i)->forget('area_name');
            return $i;
        });
        return $this->success($address, 200);
    }


    /**
     * @param \Illuminate\Http\Request $request
     * @return mixed
     * CreateTime: 2018/7/17 14:41
     * Description: 更新用户信息
     */
    public function updateUserInfo(Request $request)
    {
        $data = $request->only('mobile', 'sex', 'birthday');
        if (count($data) > 0) {
            DB::table('member')->where('user_id', Auth::id())->update($data);
            return $this->success(['msg' => 'Succeed'], 200);
        }
        return $this->success(['msg' => 'No changed'], 200);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return mixed
     * CreateTime: 2018/7/17 16:46
     * Description: 获取店铺列表
     */
    public function followList(Request $request)
    {
        $page = $request->page ?: 1;
        $size = $request->size ?: 6;
        $prefix = env('DB_PREFIX', 'iwebshop_');
        $list = DB::table('seller_user_fav as uf')
            ->where('user_id', Auth::id())
            ->join('seller as s', 's.id', '=', 'uf.seller_id')
            ->where('s.is_del', 0)
            ->where('s.is_lock', 0)
            ->select('s.id', 's.true_name', 's.img', DB::raw("(select count(*) from " . $prefix . 'goods' . " where seller_id = " . $prefix . 'uf' . ".seller_id and is_del <> 1) as products_count"))
            ->forPage($page, $size)
            ->get();
        return $this->success($list, 200);
    }

    /**
     * @param \App\Http\Requests\V1\UnFollowRequest $request
     * @return mixed
     * CreateTime: 2018/7/18 9:19
     * Description: 取消/关注
     */
    public function follow(UnFollowRequest $request)
    {
        if ($request->type == 1) {
            // 关注
            DB::table('seller_user_fav')->where('user_id', Auth::id())->where('seller_id', $request->seller_id)->delete();
            DB::table('seller_user_fav')->insert([
                'user_id' => Auth::id(),
                'seller_id' => $request->seller_id
            ]);
            return $this->success(['msg' => 'Succeed'], 200);
        } elseif ($request->type == 2) {
            // 取消关注
            DB::table('seller_user_fav')->where('user_id', Auth::id())->where('seller_id', $request->seller_id)->delete();
            return $this->success(['msg' => 'Succeed'], 200);
        }
        return $this->error(301, ['msg' => 'Incorrect parameter']);
    }


    /**
     * @param \Illuminate\Http\Request $request
     * @return mixed
     * CreateTime: 2018/7/19 9:50
     * Description: 获取订单列表
     */
    public function orderList(Request $request)
    {
        $page = $request->page ?: 1;
        $size = $request->size ?: 6;
        $sta = $request->sta ?: 0;

        $orders = DB::table('order as o')
            ->where('o.user_id', Auth::id())
            ->where('o.if_del', 0)
            // 几种分类筛选
            ->when($sta > 0, function ($q) use ($sta) {
                switch ($sta) {
                    case 1:
                        // Undelivery
                        $q->where(function ($q){
                            $q->where('o.order_status', 1)->orWhere('o.order_status', 4);
                        });
                        break;
                    case 2:
                        // In transit
                        $q->where(function ($q){
                            $q->where('o.order_status', 3)->orWhere('o.order_status', 11);
                        });
                        break;
                    case 3:
                        // pick up
                        $q->where('o.pay_type', 17);
                        break;
                    case 4:
                        // comment
                        $q->join('comment as c', 'c.order_no', '=', 'o.order_no');
                        break;
                }
            })
            ->select('o.id', 'o.order_no', 'o.order_status', 'o.create_time', 'o.pay_time', 'o.pay_status', 'o.status')
            ->forPage($page, $size)
            ->latest('o.create_time')
            ->get();
        // 获取商品
        $goods_id = DB::table('order_goods')->whereIn('order_id', $orders->pluck('id')->toArray())->select('goods_id', 'order_id', 'product_id', 'goods_array', 'img', 'goods_nums', 'goods_price', 'real_price', 'seller_id')->get();
        $goods_id = $goods_id->map(function ($i) {
            $i->goods_array = json_decode($i->goods_array, true);
            if ($i->product_id > 0) {
                $product = DB::table('products')->where('id', $i->product_id)->value('spec_array');
                $tempArray = json_decode($product, true);
                foreach ($tempArray as $ke => $va) {
                    if ($va["type"] == 2) {
                        $tempArray[$ke]['value'] = getImgDir($tempArray[$ke]['value'], 20, 20);
                    }
                }
                $i->goods_array["value"] = $tempArray;
            }
            $i = collect($i);
            return $i;
        })->groupBy('order_id');
        $orders = $orders->map(function ($i) use ($goods_id) {
            $i->order_goods = $goods_id[$i->id];
            //取消按钮
            $i->buttons = [];
            //发货提醒
            if(($i->order_status == 1 && (time()-strtotime($i->create_time))>24*3600) || ($i->order_status == 4 && (time()-strtotime($i->pay_time))>24*3600)){
                $i->buttons[] = 2;
            }
            //支付按钮
            if($i->order_status == 2 && $i->pay_status == 0){
                $i->buttons[] = 3; // 'Pay now';
            }
            //确认收货
            if(in_array($i->order_status ,array(11,3,10,17))){
                $i->buttons[] = 4; // 'Confirm receipt';
            }
            //退款申请
            if(Order::isRefundmentApply(collect($i))){
                $i->buttons[] = 5; // 'Application refund';
            }
            //评价订单
            $order_no = DB::table('comment')->whereRaw('user_id = '.Auth::id().' and status = 0')->pluck('order_no')->toArray();
            $order_no = array_unique($order_no);
            if(in_array($i->order_no, $order_no)){
                $i->buttons[] = 6;
            }
            $i->order_status_text = getNewOrderStatusText($i->order_status);
            return $i;
        });
        return $this->success($orders);
    }


    /**
     * @param $order_id
     * @return mixed
     * CreateTime: 2018/7/19 15:25
     * Description: 订单详情
     */
    public function orderDetail($order_id)
    {
        $data = Order::getOrderDetail($order_id, Auth::id());
        $data = json_decode($data, true);
        if (isset($data)) {
            //获取地区
            $order_goods = DB::table('order_goods')->whereRaw("order_id = $order_id")->get();

            $order_goods = $order_goods->map(function ($i) {
                if ($i->img) {
                    $i->img = getImgDir($i->img, 100, 100);
                }
                $i->goods_array = json_decode($i->goods_array, true);

                if ($i->product_id > 0) {
                    $product = DB::table('products')->find($i->product_id);
                    $tempArray = json_decode($product->spec_array, true);
                    foreach ($tempArray as $ke => $va) {
                        if ($va["type"] == 2) {
                            $tempArray[$ke]['value'] = getImgDir($tempArray[$ke]['value'], 100, 100);
                        }
                    }
                    $i->goods_array['value'] = $tempArray;

                    //查询订单商品是否有参加限时抢购活动
                    $res = Goods::getPromotionRowBygoodsId($i->goods_id);
                    if ($res) {
                        $i->promo = 'time';
                        $i->active_id = $res->id;
                    }
                    $quotaRow = Goods::getQuotaRowBygoodsId($i->goods_id);
                    if ($quotaRow) {
                        $i->active_id = $quotaRow->quota_activity_id;
                        $i->promo = 'quota';
                    }
                }

                return $i;
            });

            $data['order_goods'] = $order_goods;
            $create_time = $data['create_time'];
            $pay_time = $data['pay_time'];
            //取消按钮
            $buttons = [];
            if (in_array($data['order_status'], array(1, 2, 11))) {
                $buttons[] = 1;//'Cancel_order';
            }
            //发货提醒
            if (($data['order_status'] == 1 && (time() - strtotime($create_time)) > 24 * 3600) || ($data['order_status'] == 4 && (time() - strtotime($pay_time)) > 24 * 3600)) {
                $buttons[] = 2;//'Reminder_slip';
            }
            //支付按钮
            if ($data['order_status'] == 2 && $data->pay_status == 0) {
                $buttons[] = 3;//'Pay now';
            }
            //确认收货
            if (in_array($data['order_status'], array(11,3,10,17))) {
                $buttons[] = 4;//'Confirm receipt';
            }
            //退款申请
            if (Order::isRefundmentApply($data)) {
                $buttons[] = 5;//'Application refund';
            }
            //评价订单
            $order_no = DB::table('comment')->whereRaw('user_id = ' . Auth::id() . ' and status = 0')->pluck('order_no')->toArray();
            if (in_array($data['order_no'], $order_no)) {
                $buttons[] = 6;
            }

            $data['quota_order_detail_id'] = '';
            if ($data['quota_code']) {
                $buttons[] = 7;
                $qod = DB::table('quota_orders_detail')->whereRaw('quota_code="' . $data['quota_code'] . '"')->first();
                if ($qod) {
                    $data['quota_order_detail_id'] = $qod->id;
                }
            }
            $data['buttons'] = $buttons;
            if ($data['active_id']) {
                $promotionRow = DB::table('promotion')->find($data['active_id']);
                if ($promotionRow->type != 1) {
                    $data->active_id = 0;
                }
            }
            $data['order_status_text'] = getNewOrderStatusText($data['order_status']);
            $data['mobile'] = showMobile($data['mobile']);
            $data['seller_mobile'] = showMobile($data['seller_mobile']);
            $data['promotions'] = (int)$data['promotions'] >= (int)$data['payable_amount'] ? $data['payable_amount'] : $data['promotions'];
            return $this->success($data);
        } else {
            return $this->error(301, "this order does not exits");
        }
    }


    /**
     * @param \Illuminate\Http\Request $request
     * @param                          $order_id
     * @return mixed
     * CreateTime: 2018/7/19 16:07
     * Description: 取消订单
     */
    public function cancelOrder(Request $request, $order_id)
    {
        $user_id = Auth::id();
        //取消理由
        $cancel_reason = $request->cancel_reason;
        $order = DB::table('order')->find($order_id);

        if ($order->cancel_reason_by_user && $order->cancel_time) {
            return $this->success([]);
        }

        $sellerRow = DB::table('seller')->whereRaw('id =' . $order->seller_id)->first();

        //店家手机号
        $mobile = $sellerRow->mobile;
        $orderStatus = $order->order_status;
        $dataArray = array(
            'status' => 3,
            'cancel_reason_by_user' => $cancel_reason,
            'cancel_time' => date('Y-m-d H:i:s'),
        );
        //更新成功  库存回滚
        if (DB::table('order')->whereRaw('id=' . $order_id)->update($dataArray)) {
            Order::resetOrderProp($order_id);

            //更新新的订单状态
            Order::newOrderStatus($order_id);

            //增加库存量
            $orderGoodsList = DB::table('order_goods')->whereRaw('order_id = ' . $order_id)->get();
            $orderGoodsListId = array();
            foreach ($orderGoodsList as $key => $val) {
                $orderGoodsListId[] = $val->id;
            }
            Order::updateStore($orderGoodsListId, 'add');

            $redis = new Redisbmk();

            //限时抢购商品 销售数量
            if ($order->type == 2) {
                $ogoods = DB::table('order_goods')->whereRaw('order_id = ' . $order->id)->first();
                $num = $ogoods->goods_nums;
                $promoRow = DB::table('promotion')->find($order->active_id);
                $updateNum = intval($promoRow['sold_num']) - intval($num);
                $res = DB::table('promotion')->whereRaw('id=' . $order->active_id)->update(array('sold_num' => $updateNum));

                if ($res) {
                    for ($i = 0; $i < $num; $i++) {
                        $redis->addRlist('_promotion_max_num:id_' . $order->active_id, 1);
                    }
                }
            }

            //短信通知卖家
            if ($orderStatus == 11) {
                $content = "Dear seller, your order: {$order->order_no},has been cancelled by customer, which is being shipped";
            } else {
                $content = "Dear seller, your order: {$order->order_no}, has been cancelled by customer";
            }
            //todo sendMessage
//            sendMessage($mobile,$content);

            $userRow = DB::table('user')->find($user_id);
            $memberRow = DB::table('member')->whereRaw('user_id=' . $user_id)->first();
            //发消息给买家
            $postdata = array(
                'username' => Auth::user()->username,
                'order_id' => $order_id,
                'order_no' => $order->order_no,
                'create_time' => $order->create_time,
                'pay_type' => $order->pay_type,
                'seller_id' => $order->seller_id,
                'real_amount' => $order->real_amount,
                'real_freight' => $order->real_freight,
                'accept_name' => $order->accept_name,//'收货人姓名'
                'mobile' => $order->mobile, //'联系电话'
                'address' => $order->address,//收货地址
                'cancel_reason' => $cancel_reason,
                'cancel_time' => date('Y-m-d H:i:s'),
                'cancel_user' => $userRow->username,
            );

            $redis->addRlist('_order_sendemail', json_encode(array('email' => $memberRow['email'], 'title' => 'Your order has been cancelled', 'contenturl' => '/sendemail/order_cancel_user', 'contenttxt' => $postdata)));

//            $url = BIGMK_URL.'/sendemail/order_cancel_seller';
//            $sellercontent = send_http($url,$postdata);
            $redis->addRlist('_order_sendemail', json_encode(array('email' => $sellerRow['email'], 'title' => 'Your order has been cancelled', 'contenturl' => '/sendemail/order_cancel_seller', 'contenttxt' => $postdata)));

//            send_email($sellerRow['email'],"Your order has been cancelled",$sellercontent);
            return $this->success([]);
        }
    }


    /**
     * @param $order_id
     * @return mixed
     * CreateTime: 2018/7/19 16:33
     * Description: 确认收货
     */
    public function confirmReceipt($order_id)
    {
        $user_id = Auth::id();
        $time = date('Y-m-d H:i:s');
        $dataArray = array('status' => 5, 'completion_time' => $time);

        //查询是否有确认过
        $row = DB::table('order')->whereRaw("id = " . $order_id . " and distribution_status = 1 and user_id = " . $user_id . ' and completion_time >"0000-00-00 00:00:00"')->first();

        if ($row) {
            return $this->error(400, 'Has been confirmed');
        }

        DB::beginTransaction();
        try {
            $updateOrder = DB::table('order')
                ->where('id', $order_id)
                ->where('distribution_status', 1)
                ->where('user_id', $user_id)
                ->orWhere('pay_type', 17)
                ->update($dataArray);

            if ($updateOrder) {
                $orderRow = DB::table('order')->find($order_id);
                $sellerRow = DB::table('seller')->whereRaw('id =' . $orderRow->seller_id)->first();
                //确认收货后进行支付
                Order::updateOrderStatus($orderRow->order_no);

                //更新新的订单状态
                Order::newOrderStatus($order_id);

                //增加用户评论商品机会
                Order::addGoodsCommentChange($order_id);

                //发生邮件提醒告诉卖家已经确认收货
                $postdata = array(
                    'username' => Auth::user()->username,
                    'order_id' => $order_id,
                    'order_no' => $orderRow->order_no,
                    'seller_id' => $orderRow->seller_id,
                    'accept_name' => $orderRow->accept_name,//'收货人姓名'
                    'completion_time' => $time,
                );

                $redis = new Redisbmk();
                $redis->addRlist('_order_sendemail', json_encode(array('email' => $sellerRow->email, 'title' => 'You have a new order', 'contenturl' => '/sendemail/order_success_delivered', 'contenttxt' => $postdata)));
                DB::commit();
                return $this->success(['order_no' => $orderRow->order_no]);
            } else {
                return $this->error(400, 'Has been confirmed');
            }

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error(400, $e->getMessage());
        }

    }

    /**
     * @param $order_id
     * @return mixed
     * CreateTime: 2018/7/19 16:41
     * Description: 发货提醒
     */
    public function reminderSlip($order_id)
    {
        //订单id
        //查找订单记录并判断其上次提醒的时间是否有超过6个小时
        $orderRow = DB::table('order')->whereRaw('id = ' . $order_id)->first();
        //提醒时间
        $reminder_time = $orderRow->reminder_slip_time;

        if ($reminder_time !== null) {
            if ((time() - strtotime($reminder_time)) < 6 * 3600) {
                return $this->error(400, 'Dear customer, it is not yet more than 6 hours since the last shipment reminder, you can remind shipment later.！');
            } else {
                $result = 'Success';
                if ($result == 'Success') {
                    //更新提醒时间
                    $dataArray = (array('reminder_slip_time' => date('Y-m-d H:i:s')));
                    DB::table('order')->whereRaw('id =' . $order_id)->update($dataArray);
                    return $this->success([]);
                } else {
                    return $this->error(400, $result);
                }
            }
        } else {
            $result = 'Success';
            if ($result == 'Success') {
                //更新提醒时间
                $dataArray = (array('reminder_slip_time' => date('Y-m-d H:i:s')));
                DB::table('order')->whereRaw('id =' . $order_id)->update($dataArray);
                return $this->success([]);
            } else {
                return $this->error(400, $result);
            }
        }
    }


    //获取最近浏览记录   每个用户最多存储6个
    public function getRecentlyGoods()
    {
        $user_id = Auth::id();
        $redis = new Redisbmk();
        $count = $redis->listcount('_recently_viewed:user_id_' . $user_id);
        if (!$count) {
            return $this->success([]);
        } else {
            $goods_ids = $redis->lRange('_recently_viewed:user_id_' . $user_id, 0, $count - 1);
        }
        $goods_str = join(',', $goods_ids);
        $goodsList = DB::table('goods as go')
            ->select('go.id', 'go.name', 'go.sell_price', 'go.market_price', 'go.store_nums', 'go.img', 'go.sale', 'go.grade', 'go.comments', 'go.favorite', 'go.seller_id', 'go.is_shipping', 'go.commodity_security', 'go.template_id', 'go.freight')
            ->whereIn('id', $goods_ids)
            ->orderBy(DB::raw("FIELD(id,{$goods_str})"))
            ->get();

        $goodsList = $goodsList->map(function ($i) {
            $i->active_id = '';
            $i->promo = '';
            $i->start_time = '';
            $i->end_time = '';
            if ($res = Goods::getPromotionRowBygoodsId($i->id)) {
                $i->sell_price = showPrice($res->award_value);
                $i->active_id = $res->id;
                $i->promo = 'time';
                $i->start_time = $res->start_time;
                $i->end_time = $res->end_time;
            }

            if ($quotaRow = Goods::getQuotaRowBygoodsId($i->id)) {
                $i->active_id = $quotaRow->quota_activity_id;
                $i->promo = 'quota';
                $i->start_time = $quotaRow->activity_start_time;
                $i->end_time = $quotaRow->activity_end_time;
            }

            $diff = $i->market_price == 0 ? 0 : ($i->market_price - $i->sell_price) / $i->market_price;
            $i->discount = $diff <= 0 ? '' : number_format($diff, 2) * 100;
            $sellerInfo = DB::table('seller')->find($i->seller_id);

            //免运费判断
            if ($i->template_id) {
                $temp = DB::table('shipping_template')->find($i->template_id);
                if ($temp->free_shipping == 1) {
                    $i->is_shipping = 2;  //有运费
                } else {
                    $i->is_shipping = 1;  //无运费
                }
            } else {
                if ($i->freight > 0) {
                    $i->is_shipping = 2;  //有运费
                } else {
                    $i->is_shipping = 1;
                }
            }
            $i->is_cashondelivery = $sellerInfo->is_cashondelivery; //等于1 的时候支持货到付款
            $i->img = getImgDir($i->img, 300, 300);
            return $i;
        });
        return $this->success($goodsList);
    }

    /***
     * @param Request $request
     * @return mixed
     * Description:商品收藏列表
     */
    public function favoriteList(Request $request)
    {
        //$info=DB::select("select a.user_id,b.id,b.name,b.sell_price,b.img from iwebshop_favorite as a left join iwebshop_goods as b on a.rid=b.id where a.user_id=$userId order by a.time desc;");

        $userId = Auth::id();

        $info = DB::table('favorite as a')
            ->select('a.user_id', 'b.id', 'b.name', 'b.sell_price', 'b.img')
            ->leftJoin('goods as b', 'a.rid', '=', 'b.id')
            ->where('a.user_id', '=', $userId)
            ->orderBy('a.time', 'DESC')
            ->get();

        return $this->success($info);
    }

    /**
     * @param Request $request
     * @return mixed
     * Description:商品添加收藏&取消收藏
     */
    public function addOrCancel(Request $request)
    {
        $userId = $request->input('user_id');
        $goodsId = $request->input('goods_id');
        $type = $request->input('type');

        $num = DB::table('favorite')
            ->where('rid', $goodsId)
            ->get()
            ->toArray();;
        $res = array_map('get_object_vars', $num);

        switch ($type) {
            case 1:
                //添加收藏
                $catId = DB::table('category_extend')
                    ->select('category_id')
                    ->where('goods_id', '=', $goodsId)
                    ->get()
                    ->toArray();
                $res = array_map('get_object_vars', $catId);

                $info = DB::table('favorite')->insert([
                    'user_id' => $userId,
                    'rid' => $goodsId,
                    'time' => date('Y-m-d H:i:s', time()),
                    'cat_id' => $res[0]['category_id'],
                ]);

                return $this->success($info);

                break;
            case 2:
                //取消收藏(收藏列表页&商品详情页)
                if (count($res) >= 1) {
                    $info = DB::table('favorite')
                        ->where('rid', $goodsId)
                        ->delete();

                    return $this->success($info);
                } else {
                    return $this->error('400', "Id can't be empty!");
                }

                break;

            default:
                return $this->error('400', "Type is error!");
        }
    }

    /**
     * @param Request $request
     * @return mixed
     * Description:上传头像
     */
    public function updateAvatar(Request $request)
    {
        $userId = Auth::id();
        $aStr = $request->input('head_ico');

        $info = DB::table('user')
            ->where('id', $userId)
            ->update([
                'head_ico' => $aStr
            ]);

        return $this->success($info);
    }

    /**
     * @param Request $request
     * @return mixed
     * Description:用户中心订单评论
     */
    public function comments(Request $request)
    {
        $content = $request->input('content');
        $user_id   = Auth::id();

        if(!$content){
            $this->error(400,"Comment can't be empty!");
        }
        $service_point  = $request->service_point;
        $delivery_point = $request->delivery_point;
        $order_no = $request->order_no;
        $content  = json_decode($content,true);
        $seller_id = 0;

        foreach($content as $k=>$v)
        {
            $data = [];
            if(!$v['contents']){
                $this->error(400,"Comment can't be empty!");
            }
            $data['contents'] = $v['contents'];
            $data['img']  = $v['img'];
            $data['status'] = 1;
            $data['comment_time'] = date('Y-m-d H:i:s',time());
            $data['point'] = $v['point'];

            $re = DB::table('comment')->where('order_no','=',$order_no)->where('goods_id','=',$v['goods_id'])->update($data);
            if($re) {
                $select = DB::table('comment')->where('order_no', '=', $order_no)->where('goods_id', '=', $v['goods_id'])->where('user_id', '=', $user_id)->first();
                $sellerId = $select->seller_id;

                //同步更新goods表,comments,grade
                $goodsDB = DB::table('goods');
                $goodsDB->where('id', '=', $select->goods_id)->increment('grade', $select->point);
                $goodsDB->where('id', '=', $select->goods_id)->increment('comments', 1);
                $sellerDB = DB::table('seller');
                $sellerDB->where('id', '=', $sellerId)->increment('comments', 1);
                $sellerDB->where('id', '=', $sellerId)->increment('service_grade', $service_point);
                $sellerDB->where('id', '=', $sellerId)->increment('delivery_speed_grade', $delivery_point);
                $sellerDB->where('id', '=', $sellerId)->increment('grade', 5);
            }
        }
        return $this->success(['seller_id'=>$sellerId]);
    }

    /**
     * @param Request $request
     * @return mixed
     * Description:消息分组列表
     */
    public function messGroupList(Request $request)
    {
        //id为-表示已读
        $userId = Auth::id();

        $messIds = DB::table('member')->select('message_ids')->where('user_id', $userId)->get()->toArray();
        $strIds = array_map('get_object_vars', $messIds);

        //所有id，未去掉-
        $getMessIdsN = substr($strIds[0]['message_ids'], 0, -1);
        $arrStrIds = explode(',', $getMessIdsN);
        $CountN = count($arrStrIds);

        $a = 0;
        foreach ($arrStrIds as $key => $value) {
            $value < 0 ? $a++ : 0;
        }

        //所有id，去掉-
        $getMessIdsY = str_replace('-', '', trim($strIds[0]['message_ids'], ','));
        $getMessIds = str_replace(',', "','", $getMessIdsY);
        $CountY = $a;

        if ($getMessIds) {

            $allMess = DB::select("select * from iwebshop_message where (type=1 or type=2) and id in ('$getMessIds') order by id desc");
            $res = array_map('get_object_vars', $allMess);
            $lastRes = reset($res);

        } else {
            $lastRes = [];
        }

        //System Messages组
        $info[] = [
            'GroupName' => 'System Messages',
            'LastTitle' => $lastRes ? $lastRes['title'] : '',
            'LastTime' => $lastRes ? date('M d,Y', strtotime($lastRes['time'])) : '',
            'NotReadCount' => $CountN - $a
        ];

        //Order Notification
        if ($getMessIds) {
            $NoAllMess = DB::select("select * from iwebshop_message where type=3 and id in ('$getMessIds') order by id desc");
            $NoRes = array_map('get_object_vars', $NoAllMess);
            $NoLastRes = reset($NoRes);
        }

        $info[] = [
            'GroupName' => 'Order Notification',
            'LastTitle' => $NoLastRes ? $NoLastRes['title'] : '',
            'LastTime' => $NoLastRes ? date('M d,Y', strtotime($NoLastRes['time'])) : '',
            'NotReadCount' => count($NoRes),
        ];

        return $this->success($info);
    }

    /**
     * @param Request $request
     * @return mixed
     * Description:系统消息列表
     */
    public function messSMList(Request $request)
    {
        $user_id = Auth::id();

        if ($user_id) {
            $messIds = DB::table('member')->select('message_ids')->where('user_id', $user_id)->get()->toArray();
            $member = array_map('get_object_vars', $messIds);

            $message_ids = str_replace('-', '', trim($member[0]['message_ids'], ','));
            //转数组
            $strArr=explode(',',$message_ids);

            $obj = DB::table('message')->whereIn('id',$strArr)->where(function($query){
                $query->where('type','=','1')
                      ->orWhere('type','=','2');
             })->orderBy('id', 'desc')->get()->toArray();
            $messages = array_map('get_object_vars', $obj);

            foreach ($messages as $k => $v) {
                if (strpos(',' . trim($member[0]['message_ids'], ',') . ',', ',-' . $v['id'] . ',') === false) {
                    $messages[$k]['is_read'] = 0;
                    $messages[$k]['is_id'] = $v['id'];
                } else {
                    $messages[$k]['is_read'] = 1;
                    $messages[$k]['is_id'] = '-'.$v['id'];
                }
                $messages[$k]['time'] = date('M d,Y', strtotime($v['time']));

            }
            return $this->success($messages);
        }
    }

    /***
     * @param Request $request
     * @return mixed
     * Description:系统消息内容
     */
    public function messSysContent(Request $request)
    {
        $strId=$request->id;

        if(!$strId){
            return $this->error(400,"Message's id can't be empty");
        }

        $content=DB::table('message')->select('id','title','content','time')->where('id','=',$strId)->first();
        $res=[
            'id'=>$content->id,
            'title'=>$content->title,
            'content'=>$content->content,
            'time'=>$content->time
             ];

        return $this->success($res);
    }

    /**
     * @param Request $request
     * @return mixed
     * Description:订单通知列表
     */
    public function messONList(Request $request)
    {
        $user_id = Auth::id();

        $messIds = DB::table('member')->select('message_ids')->where('user_id', $user_id)->get()->toArray();
        $member = array_map('get_object_vars', $messIds);
        $message_ids = str_replace('-', '', trim($member[0]['message_ids'], ','));
        //转数组
        $strArr=explode(',',$message_ids);

        $obj = DB::table('message')->whereIn('id', $strArr)->where('type','=', 3)->orderBy('id', 'desc')->get()->toArray();
        $list = array_map('get_object_vars', $obj);

        foreach ($list as $k => $v) {
            $list[$k]['content'] = json_decode($v['content'], true);
            if (strpos(',' . trim($member[0]['message_ids'], ',') . ',', ',-' . $v['id'] . ',') === false) {
                $list[$k]['is_read'] = 0;
                $list[$k]['is_id'] = $v['id'];
            } else {
                $list[$k]['is_read'] = 1;
                $list[$k]['is_id'] = '-'.$v['id'];
            }
            $list[$k]['time'] = date('M d,Y H:i A', strtotime($v['time']));
        }

        return $this->success($list);
    }

    /***
     * @param Request $request
     * @return mixed
     * Description:消息是否已读
     */
    public function isRead(Request $request)
    {
        $user_id = Auth::id();
        $id = $request->id;

        if($id>0){
            return $this->error(400,'The message is read!');
        }

        $messIds = DB::table('member')->select('message_ids')->where('user_id', $user_id)->where('status','=',1)->first();
//        $messIdStr = str_replace('-', '', trim($messIds->message_ids, ','));

        //查询系统消息
//        $sysMess=DB::table('message')->select('id')->whereIn('id',explode(',',$messIdStr))->where(function($query){$query->where('type','=',1)->orWhere('type','=',2);})->orderBy('id', 'desc')->get()->toArray();
//        $sysMess=array_map('get_object_vars',$sysMess);
//        $sysIdVal=[];
//        foreach ($sysMess as $key=>$value){
//            $sysIdVal[$key]=$value['id'];
//        }

        //查询订单消息
//        $orderMess=DB::table('message')->select('id')->whereIn('id',explode(',',$messIdStr))->where('type','=',3)->get()->toArray();
//        $orderMess=array_map('get_object_vars',$orderMess);
//        $orderIdVal=[];
//        foreach ($orderMess as $k=>$v){
//            $orderIdVal[$k]=$v['id'];
//        }

        //消息id处理
        $allIds=explode(',',$messIds->message_ids);

        foreach ($allIds as $a=>$b){
            if(intval($b)==$id && $id<0){
                $allIds[$a]=strval(abs($id));
            }
        }

        $toStr=implode(',',$allIds);
        $res = DB::table('member')->where('user_id','=',$user_id)->update(['message_ids'=>$toStr]);
        if($res){
            return $this->success(['id'=>$id,'massage'=>'Reading success!']);
        }else{
            return $this->success(['user_id'=>$user_id,'massage'=>'此消息为已读消息！']);
        }

    }

    /**
     * @param Request $request
     * @return mixed
     * Description:优惠券列表
     */
    public function voucherList(Request $request)
    {
        $user_id = Auth::id();
        $status  = $request->input('status');
        $now  = date('Y-m-d', time());
        $time = date('Y-m-d H:i:s', time());

        $where = 'vs.user_id=' . $user_id . ' and v.status != 4 and vs.share_status = 0';
        if ($status == 1)
        {
            $where .= ' and "' . $now . '" <= v.end_time and vs.use_status=2';
        }
        else
        {
            $where .= ' and ("' . $now . '"> v.end_time or vs.use_status=1  or s.is_del !=0  or s.is_lock!=0)';
        }

        $total_s = DB::select("select count(*) as total from iwebshop_voucher_str as vs left join iwebshop_voucher as v on v.id=vs.voucher_id left join iwebshop_seller as s on s.id=v.seller_id where $where");
        $total = array_map('get_object_vars',$total_s)[0]['total'];

        $voucherstrRow_s = DB::select("
                    SELECT vs.voucher_id,vs.id AS voucher_str_id,v.freeship_value,v.type_way,vs.use_status,v.start_time,v.end_time,v.name AS voucher_name,v.value,v.type,s.true_name,s.is_lock,s.is_del,s.id AS seller_id,s.img,count(vs.voucher_id) as total 
                    FROM iwebshop_voucher_str AS vs 
                    LEFT JOIN iwebshop_voucher AS v 
                    ON v.id=vs.voucher_id 
                    LEFT JOIN iwebshop_seller AS s 
                    ON s.id=v.seller_id 
                    WHERE $where
                    ORDER BY vs.get_time DESC
                    ");

        $voucherstrRow=array_map('get_object_vars',$voucherstrRow_s);

        if($voucherstrRow[0]['voucher_id'] == null)
        {
            $voucherstrRow = [];
            return $this->success($voucherstrRow);
        }else{
            return $this->success($voucherstrRow);
        }
    }

    /**
     * @param Request $request
     * @return mixed
     * Description:优惠券详情
     */
    public function voucherDetail(Request $request)
    {
        $voucher_id = $request->voucher_str_id;

        $voucherstrInfo = DB::table('voucher_str')->where('id','=',$voucher_id)->get()->first();

        if($voucherstrInfo == null)
        {
            return $this->error("400",'不存在对应的ID');
        }

        $where['vs.id'] = array('eq', $voucher_id);
        $voucherstrInfo = DB::table('voucher_str as vs')
            ->select('vs.voucher_id', 'vs.id as voucher_str_id', 'v.name', 'vs.use_status', 'v.type_way', 'v.type_range', 'v.start_time', 'v.end_time', 'v.value', 'v.type', 'v.limit', 'v.goods_id', 'v.address_id', 's.true_name', 's.is_lock', 's.is_del', 's.id as seller_id', 's.img', 'v.freeship_value')
            ->leftJoin('voucher as v', 'v.id', '=', 'vs.voucher_id')
            ->leftJoin('seller as s', 's.id', '=', 'v.seller_id')
            ->where('vs.id', '=', $voucher_id)
            ->get()
            ->toArray();

        $voucherstrRow = array_map('get_object_vars', $voucherstrInfo);

        switch ($voucherstrRow[0]['type_way']) {
            case 1:
                $voucherstrRow[0]['value_text'] = '₱' . (int)$voucherstrRow[0]['value'];
                break;

            case 2:
                $voucherstrRow[0]['value_text'] = (int)$voucherstrRow[0]['value'] . '% off';
                break;

            case 3:
                $voucherstrRow[0]['value_text'] = (int)$voucherstrRow[0]['value'] === 0 ? 'Free Shipping' : 'Shipping Fee ₱' . (int)$voucherstrRow[0]['value'];
                break;
        }

        $voucherstrRow[0]['limit_text'] = (int)$voucherstrRow[0]['limit'] ? 'No Limit' : 'Your order of ₱' . (int)$voucherstrRow[0]['limit'] . ' or more';
        $voucherstrRow[0]['range_text'] = (int)$voucherstrRow[0]['type_range'] ? 'All products in shop' : 'Specific products in shop';
        $voucherstrRow[0]['instruction'] = 'Coupons are provided by sellers and can only be used in the specific shop that issued them.Limit one coupon per order.';

        if ($voucherstrRow[0]['type_way'] === 3 && $voucherstrRow[0]['address_id']) {
            $tmp_array = explode(',', $voucherstrRow[0]['address_id']);
            $address = DB::table('areas')->whereIn('area_id', $tmp_array)->get()->toArray();
            $addInfo = array_map('get_object_vars', $address);

            $tmp = [];
            foreach ($addInfo as $k => $v) {
                $tmp[$k] = $v['area_name'];
            }

            $voucherstrRow[0]['address'] = $tmp;
        } else {
            $voucherstrRow[0]['address'] = [];
        }

        return $this->success($voucherstrRow[0]);
    }

    /**
     * @param Request $request
     * @return mixed
     * Description:优惠券领取
     */
    public function voucherGet(Request $request)
    {
        $voucher_id = $request->input('voucher_id');
        $user_id = Auth::id();

        if (!$voucher_id || !$user_id || empty($voucher_id) || empty($user_id)) {
            return $this->error(400, '参数为空！');
        }

        $info = DB::table('voucher')->where('id', '=', $voucher_id)->where('status', '=', 2)->get()->toArray();
        $voucherInfo = array_map('get_object_vars', $info);

        if (!$voucherInfo) {
            return $this->error(400, "Coupon does't exist!");
        }

        if ($voucherInfo[0]['end_time'] < date("Y-m-d")) {
            DB::table('voucher')->where('id', '=', $voucher_id)->update(['status' => 4]);
            return $this->error(400, "Coupon expired!");
        }

        //判断是否能领取
        $voustrInfo = DB::table('voucher_str')->where('voucher_id', '=', $voucher_id)->where('user_id', '=', $user_id)->get()->toArray();
        $voucherstrInfo = array_map('get_object_vars', $voustrInfo);

        $count = DB::table('voucher_str')->where('voucher_id', '=', $voucher_id)->count();
        $surplus = $voucherInfo[0]['receive_number'] - $count;

        if ($surplus) {
            if ($voucherstrInfo) {
                if ((count($voucherstrInfo) + 1) > $voucherInfo[0]['receive_number']) {
                    return $this->error(400, 'The maximum number has been exceeded!');
                }
            }
        } else {
            return $this->error(400, 'Sold out!');
        }

        $str = date("Y-m-d H:i:s", time()) . 'user_id' . $user_id . 'id' . $voucher_id;


        $rs = DB::table('voucher_str')->insert([
            'voucher_id' => $voucher_id,
            'voucher_str' => authcode($str, 'ENCODE', 'voucher'),
            'user_id' => $user_id,
            'use_status' => 2,
            'get_time' => date("Y-m-d H:i:s")
        ]);

        if ($rs) {
            return $this->success(['message'=>'Receive success!']);
        } else {
            return $this->error(400, 'Receiving failure!');
        }
    }

    /***
     * @param Request $request
     * Date: 2018/8/6 0006 15:27
     * Description:生成赠送口令
     */
    public function createCode(Request $request) {
        $user_id = Auth::id();
        $voucherStr_id = $request->voucher_str_id;

        if(!$voucherStr_id){
            $this->error(400,'Parameter is incorrect');
        }

        $voucherStrRow = DB::table('voucher_str')->where('id','=',$voucherStr_id)->where('user_id','=',$user_id)->where('use_status','=',2)->first();

        $time = date('Y-m-d');
        $rs =DB::table('voucher_str as vs')
            ->leftJoin('voucher as v','v.id','=','vs.voucher_id')
            ->leftJoin('seller as s','s.id','=','v.seller_id')
            ->select('v.id as voucher_id','vs.id as voucherstr_id','vs.voucher_id','vs.user_id as user_id')
            ->where('vs.use_status','=',2)
            ->where('v.status','<>',4)
            ->where('s.is_del','=',0)
            ->where('s.is_lock','=',0)
            ->where('vs.id','=',$voucherStr_id)
            ->where('v.start_time','<=',$time)
            ->where('v.end_time','>=',$time)
            ->first();

        if (!$rs){
            $this->error(400,'This Angpao is no longer valid');
        }

        $voucherRow = DB::table('voucher')->where('id','=',$voucherStrRow->voucher_id)->first();

        $voucherStrsRow = DB::table('voucherstr_sharelink')->where('voucherStr_id','=',$voucherStr_id)->where('is_del','=',0)->first();

        if(is_null($voucherStrsRow)){
            return $this->error(400,"Coupon's id invalid!");
        }

        $redis = new Redisbmk();
        $expire = $redis->get("_angpaocodeexpire_".$voucherStrsRow->id);

        if ($voucherStrsRow && intval($expire) == 1){
            //已经赠送(分享)，且没过期
            $this->success(['code'=>$voucherStrsRow->sharetxt]);
        }else {
            //已经赠送(分享)，且过期
            DB::table('voucher_str')->where('id','=',$voucherStr_id)->update(['share_status'=>'']);//修改优惠券赠送(分享)状态
            DB::table('voucherstr_sharelink')->where('id','=',$voucherStr_id)->update(['is_del'=>1]);
        }

        $voucherText = "Kung Hei Fat Choi! Bigmk prepared a BIG Angpao for you! Click: ";
        /* if ($voucherRow['type'] == 4){
            $voucherText .= "Shipping fee - ₱".$voucherRow['freeship_value'];
        }elseif ($voucherRow['type'] == 3 || $voucherRow['type'] == 1){
            $voucherText .= $voucherRow['message'] = '₱'.showPrice($voucherRow['limit']).'-'.'₱'.showPrice($voucherRow['value']);
        }else {
            $voucherText .= $voucherRow['value']."% off. ";
        } */

        $code='';
        for ($i = 1; $i <= 10; $i++) {
            $code .= chr(rand(65,90));
        }

        $code .= $redis->incr("_angpaocodenum:".$voucherStr_id);
        $voucherText .= getenv('DB_HOST')."/site/index?angpao=".$code." to receive your Angpao,or simply copy this whole message |₱|".$code."|₱| and open the Bigmk Buyer app to get the angpao! #Bigmk angpao code#";
        $sharelink = array(
            'voucherStr_id' =>  $voucherStr_id,
            'code'          =>  $code,
            'sharetxt'      =>  $voucherText,
            'add_time'      =>  date('Y-m-d H:i:s'),
            'end_time'      =>  date('Y-m-d H:i:s',strtotime('+1 day'))
        );

        $voucherssl_id = $voucherstr_shareid = DB::table('voucherstr_sharelink')->insertGetId($sharelink);
        $redis->setex("_angpaocodeexpire_".$voucherstr_shareid, 86400, 1);//口令过期时间24小时

        //添加赠送(分享)记录
        $userInfo = DB::table('user')->where('id','=',$voucherStrRow->user_id)->first();
        $share = array(
            'voucherStr_id' =>  $voucherStrRow->id,
            'voucherssl_id' =>  $voucherssl_id,
            'voucher_id'    =>  $voucherStrRow->voucher_id,
            'from_id'       =>  $voucherStrRow->user_id,
            'from'          =>  $userInfo->username,
            'time'          =>  date('Y-m-d H:i:s'),
        );

        DB::table('voucherstr_share')->insertGetId($share);

        $data = array(
            'id'            => $voucherStr_id,
            'share_status'  => 1
        );

        DB::table('voucher_str')->where('id','=',$voucherStr_id)->update(['share_status'=>1]);//修改优惠券赠送(分享)状态
        return $this->success(['KouLing'=>$voucherText]);
    }

    /**
     * @param Request $request
     * Date: 2018/7/24 14:08
     * Description:拼单收货地址
     */
    public function operateDeliveryAddress(Request $request)
    {
        //获取收货地址
        $user_id = Auth::id();
        $area_type = $request->area_type;//1马尼拉, 2全国,3GMA(Cavite, Laguna,Batangas, Rizal & Bulacan)

        if(!$area_type){
            return $this->error(400,'Area_type can not be null');
        }

        switch ($area_type){
            case 1 :
                $area_id=DB::table('areas')->where('area_name','=','Metro manila')->pluck('area_id')->toArray();
                break;
            case 2 :
                $area_id = 'all';
                break;
            case 3 :
                //查询
                $area_id = DB::table('areas')->where('parent_id','=','0')->whereIn('area_name',["Cavite","Laguna","Batangas","Rizal","Bulacan","Metro manila"])->pluck('area_id')->toArray();
                break;
            default:
        }

        $addressList = array_map('get_object_vars',DB::table('address')->where('user_id','=',$user_id)->orderBy('is_default','desc')->get()->toArray());

        foreach ($addressList as $k=>$v)
        {

            $province = DB::table("areas")->where('area_id', '=', $v['province'])->first()->area_name;
            $city = DB::table("areas")->where('area_id', '=', $v['city'])->first()->area_name;
            $area = DB::table("areas")->where('area_id', '=', $v['area'])->first()->area_name;
            $addressList[$k]['provinceVal'] = $province;
            $addressList[$k]['cityVal'] = $city;
            $addressList[$k]['areaVal'] = $area;

        }

        $res = [];

        if(empty($addressList)){
            return $this->success(['can_select'=>[],'cannot_select'=>[]]);
        }else{
            foreach($addressList as $key=>$val){
                if($area_id == 'all'){
                    $res['can_select'][] = $val;
                }else{
                    if(in_array($val['province'],$area_id)){
                        $res['can_select'][] = $val;
                        $res['cannot_select'] = [];
                    }else{
                        $res['can_select'] = [];
                        $res['cannot_select'][] = $val;
                    }
                }
            }
            if($area_id == 'all'){
                $res['cannot_select'] = [];
            }
        }

        return $this->success($res);
    }

    /***
     * @param Request $request
     * Date: 2018/7/24 9:10
     * Description:下单成功页
     */
    public function orderSuccessPage(Request $request)
    {
        $id=$request->order_id;

        if($id){
            $idArray = explode(',',$id);
        }

        $orderInfo=DB::table('order')->select('id','real_amount','mobile','address','pay_type','province','city','area','accept_name','order_amount')->whereIn('id',$idArray)->get()->toArray();
        $infos=array_map('get_object_vars',$orderInfo);

        $price = array_column($infos,'order_amount');
        $real_amount = array_sum($price);

        $info=$infos[0];
        $info['province'] = DB::table("areas")->where('area_id', '=', $infos[0]['province'])->first()->area_name;
        $info['city'] = DB::table("areas")->where('area_id', '=', $infos[0]['city'])->first()->area_name;
        $info['area'] = DB::table("areas")->where('area_id', '=', $infos[0]['area'])->first()->area_name;
        $info['real_amount'] = sprintf('%.2f',$real_amount);
        $info['pay_type'] = DB::table('payment')->where('id','=',$infos[0]['pay_type'])->first()->name;

        return $this->success($info);
    }

    /***
     * @param Request $request
     * Date: 2018/7/26 0025 9:08
     * Description:下单可使用优惠券
     */
    public function orderVoucher(Request $request)
    {
        $user_id   = Auth::id();
        $seller_id = $request->seller_id;
        $order_info = $request->order_info;
        $province   = $request->province;

        if(!$seller_id || !$order_info || !$province)
        {
            return $this->error('400','The parameters you entered is incorrect!');
        }

        $order_info = json_decode(str_replace(array('&quot;'), array('"'), $order_info), true);

        // 检查是不是flash, 去除属于flash的商品 检查规格是不是失效 检查商品是不是被删除了
        $order_info = collect($order_info)->reject(function ($item) {
            return isFlash($item['goods_id']) || (isset($item['products_id']) && $item['products_id'] > 0 && !isProduct($item['products_id'])) || isDelGoods($item['goods_id']);
        })->values();

        // 查询在这家店铺的优惠卷
        $voucherM=new Voucher();
        $voucher = $voucherM->getUserVoucherBySeller($user_id, $seller_id);

        // 处理优惠券信息（排序，文字， 能否使用， 凑单）
        $voucher = $voucherM->setVoucherRank($voucher);
        $voucher = $voucherM->setVoucherText($voucher);
        $voucher = $voucherM->setVoucherCanBeUsed($voucher, $order_info, $province);
        $total   = $voucherM->getAllGoodsPrice($order_info);

        // 添加卖家的活动
        $proRuleObj = new ProRule($total, $seller_id);
        $proRuleObj->isGiftOnce = false;
        $proRuleObj->isCashOnce = false;
        $promotion = $proRuleObj->getInfo();
        if($promotion){
            // 计算价格
            $promotionInfo = new Promotion();
            $promotion = $promotionInfo->setPromotionPrice($promotion, $order_info, $province);
            $promotion = $promotionInfo->getMaxPrice($promotion);

            // 店铺优惠
            $p = new \stdClass();
            $p->seller_id = $seller_id;
            $p->vs_id = "-1";
            $p->value_text = $promotion['info'];
            $p->limit_text = "";
            $p->range_text = "";
            $p->valid_time = "";
            $p->can_be_used = true;
            $p->true_value = showPrice($promotion['award_value']);
            $voucher = $voucher->push($p);
        }

        if(count($voucher) > 0){
            $n = new \stdClass();
            $n->seller_id = $seller_id;
            $n->vs_id = "0";
            $n->value_text = 'No discount';
            $n->limit_text = "";
            $n->range_text = "";
            $n->valid_time = "";
            $n->can_be_used = true;
            $n->true_value = showPrice(0);
            $voucher = $voucher->push($n);
        }

        $voucher = $voucher->reject(function($item){
            return !$item->can_be_used;
        })->sortByDesc('true_value')->values();

        $voucher = $voucher->map(function($item){
            return hideFields($item, ['limit', 'value', 'type_way', 'type_range', 'start_time', 'end_time', 'goods_id', 'add_on']);
        });

        return $this->success($voucher);
    }

    public function commit_suggestion(Request $request){
        $user_id = auth()->id();
        //字段验证
        Validator::make($request->all(), [
            'type' => 'required',
            'content' => 'required',
        ])->validate();
        $type = request('type', '');
        $content = request('content', '');
        $images = request('images ', '');

        if(!in_array($type,[1,2,3,4])){
            return $this->error(400, 'Type 参数不对或者为空');
        }

        if(getStrLen($content) > 512 || getStrLen($content)<10){
            return $this->error(400, 'Please enter at least 10 characters and you can only enter up to 512 characters');
        }

        if($images){
            $images_array  = explode(',',$images);
            if(count($images_array) > 5 ){
                return $this->error(400, '反馈图片数量需要小于5');
            }
        }

        $res = DB::table('suggestion')->insertGetId([
            'user_id' => $user_id,
            'type' => $type,
            'content' => $content,
            'img' => $images,
            'time' => date('Y-m-d H:i:s'),
            're_status' => 5
        ]);
        if($res){
            return $this->success(['suggestion_id'=>$res]);
        }else{
            return $this->error(400, '添加失败');
        }
    }
}
