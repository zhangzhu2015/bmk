<?php

namespace App\Http\Controllers\V1;

use App\Htpp\Traits\ApiResponse;
use App\Htpp\Traits\RedisFucntion;
use App\Http\Requests\V1\GetFeedBackRequest;
use App\Http\Requests\v1\GoodsInfoRequest;
use App\Http\Requests\V1\JoinCartRequest;
use App\Librarys\CountSum;
use App\Librarys\ProRule;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Seller;
use Carbon\Carbon;
use Validator;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GoodsController extends Controller
{
    //
    use ApiResponse;
    use RedisFucntion;

    /**
     * @param \App\Http\Requests\v1\GoodsInfoRequest $request
     * @return mixed
     * CreateTime: 2018/7/26 9:09
     * Description: 商品详情
     */
    public function goodsInfo(GoodsInfoRequest $request)
    {
        // 查询商品信息
        $goods_info = DB::table('goods as g')
            ->where('g.id', $request->id)
            ->where('g.is_del', 0)
            ->join('seller as s', function ($j) {
                $j->on('s.id', '=', 'g.seller_id')->where('s.is_del', 0)->where('s.is_lock', 0);
            })
            ->select('g.id', 'g.name', 'g.goods_no', 'g.sell_price', 'g.market_price', 'g.cost_price', 'g.store_nums', 'g.img', 'g.ad_img', 'g.content',
                'g.commodity_security', 'g.brand_id',
                'g.visit', 'g.favorite', 'g.spec_array', 'g.template_id', 'g.freight', 'g.seller_id', 's.true_name', 's.img as s_img', 's.delivery_address', 's.province', 'g.sale', 'g.comments')
            ->first();

        if (!$goods_info) {
            return $this->error(301, '商家已被关闭或者商家信息不存在');
        }

        //存在运费模板
        if ($goods_info->template_id) {
            $template_info = DB::table('shipping_template')->whereRaw('id = ' . $goods_info->template_id)->first();
            if ($template_info) {
                $goods_info->delivery_time = $template_info->delivery_time;
            } else {
                $goods_info->delivery_time = 0;
            }
        }
        if ($goods_info->freight) {
            $goods_info->delivery_time = 0;
        }

        $point = Seller::getPointOfSeller($goods_info->seller_id);

        $sellerInfo = new \stdClass();
        $sellerInfo->ratting = $point['ratting'];
        $sellerInfo->service_ratting = $point['service_ratting'];
        $sellerInfo->delivery_speed_ratting = $point['delivery_speed_ratting'];
        $sellerInfo->lead_time = Seller::getSpeedShip($goods_info->seller_id);
        $sellerInfo->unshipped_nums = Seller::getUnshippedNums($goods_info->seller_id);
        $goods_info->seller = $sellerInfo;

        // 查询发货地址  商品详情页shipping from读取地址的优先级为  “运费模板设置的地址>发货地址>店铺地址>Manila
        $delivery_address = json_decode($goods_info->delivery_address, true);
        $province_id = '';

        if (!$province_id) {
            if (isset($template_info)) {
                $delivery_address1 = explode(",", $template_info->delivery_address);
                $province_id = $delivery_address1[0];
            }
        }
        if (!$province_id) {
            if ($delivery_address['province']) {
                $province_id = $delivery_address['province'];
            }
        }
        if (!$province_id) {
            $province_id = $sellerInfo->province;
        }
        if (!$province_id) {
            $areaRow = DB::table('areas')->whereRaw('area_name = "Metro manila"')->first();
            $province_id = $areaRow->area_id;
        }
        $province = DB::table('areas')->where('area_id', $province_id)->first();
        $goods_info->seller->delivery_address_id = '';
        $goods_info->seller->delivery_address_name = '';

        if ($province) {
            $goods_info->seller->delivery_address_id = $province->area_id;
            $goods_info->seller->delivery_address_name = $province->area_name;
        }
        $proRuleObj = new ProRule(999999999999, $goods_info->seller_id);
        $proRuleObj->isGiftOnce = false;
        $proRuleObj->isCashOnce = false;
        $goods_info->proRules = $proRuleObj->getInfo();

        $goods_info->content = str_replace('src="/upload', 'src="' . config('bmk.img_host') . 'upload', $goods_info->content);
        $goods_info->commodity_security = explode(",", $goods_info->commodity_security);

        //如果登陆 判断用户对该商品是否收藏
        $goodsRow = null;

        if ($user = auth('api')->user()) {
            try {
                $this->setUserRecentlyViewed($user->id, $request->id);
            } catch (\Exception $e) {
            }
            $goodsRow = DB::table('favorite')->where('user_id', $user->id)->where('rid', $request->id)->first();
        }

        $goods_info->usrfavorite = $goodsRow ? 1 : 2;
        $goods_info->usrfavorite_id = $goodsRow ? $goodsRow->id : '';

        //品牌名称
        if ($goods_info->brand_id) {
            $brand_info = DB::table('brand')->select('id', 'logo', 'name')->find($goods_info->brand_id);
            if ($brand_info) {
                $brand_info->logo = getImgDir($brand_info->logo, 80, 80);
                $goods_info->brand = $brand_info;
            }
        }

        //获取商品分类
        $categoryList = DB::table('category_extend as ca')->leftJoin('category as c', 'ca.category_id', '=', 'c.id')
            ->where('ca.goods_id', $request->id)->select('c.id', 'c.name')->latest('ca.id')->get()->toArray();
        $categoryRow = null;
        if ($categoryList) {
            $categoryRow = current($categoryList);
        }
        $goods_info->category = $categoryRow ? $categoryRow->id : 0;


        //商品图片
        $goods_info->photo =  DB::table('goods_photo_relation as g')
            ->select('p.id AS photo_id', 'p.img')
            ->leftJoin('goods_photo as p', 'p.id', 'g.photo_id')
            ->where('g.goods_id', $request->id)
            ->get();


        foreach($goods_info->photo as $key => $val)
        {
            //对默认第一张图片位置进行前置
            if($val->img == $goods_info->img)
            {
                $temp = $goods_info->photo[0];
                $goods_info->photo[0] = $val;
                $goods_info->photo[$key] = $temp;
            }
        }

        foreach($goods_info->photo as $key=>$val){
            $goods_info->photo[$key]->img = getImgDir($goods_info->photo[$key]->img);
        }


        //商品规格
        if ($goods_info->spec_array) {
            $goods_info->type = 'product';
            $p = new ProductController();
            $goods_info->spec_array = $p->getSpecValue($request->id);
        } else {
            $goods_info->spec_array = new \stdClass();
            $goods_info->type = 'goods';
        }
        //商品是否参加促销活动(团购，抢购,拼团)
        $promotionRow = Goods::getnewPromotionRowById($request->id);
        $quotaInfomationRow = Goods::getQuotaRowBygoodsId($request->id);


        $goods_info->promo = '';
        $goods_info->active_id = '';
        $goods_info->promo_errorCode = 0;
        $goods_info->promotion = $promotionRow ? $promotionRow : new \stdClass();

        if ($promotionRow) {
            $goods_info->promo = 'time';
            $goods_info->active_id = $promotionRow->id;
        }

        if ($quotaInfomationRow) {
            $goods_info->promo = 'quota';
            $goods_info->active_id = $quotaInfomationRow->quota_activity_id;
        }

        if ($goods_info->promo) {
            switch ($goods_info->promo) {
                //抢购
                case 'time':
                    if ($promotionRow) {
                        $goods_info->promotion->Stime = Carbon::parse($goods_info->promotion->start_time)->format('M j g:i A');
                        $goods_info->promotion->Etime = Carbon::parse($goods_info->promotion->end_time)->format('M j g:i A');
                    }
                    if (isset($goods_info->regiment->goods_id) && $goods_info->promotion->condition != $request->id) {
                        $goods_info->promo_errorCode = 1;
                    }
                    if (isset($goods_info->regiment->award_value)) {
                        $goods_info->regiment->award_value = showPrice($goods_info->regiment->award_value);
                    }
                    break;
                //抢购
                case 'quota':
                    $quota_goods = $quotaInfomationRow;
                    if ($quota_goods) {
                        $goods_info->is_quota = 1;
                        $product_detail = new \stdClass();
                        if ($quota_goods->product_detail) {

                            $product_detail = json_decode($quota_goods->product_detail, true);
                        }
                        $quota_goods->product_detail = $product_detail;
                        if ($quota_goods->area == 1) {
                            $quota_goods->area_message = "Group buy Metro Manila only";
                        } elseif ($quota_goods->area == 3) {
                            $quota_goods->area_message = "Group buy GMA only";
                        } else {
                            $quota_goods->area_message = "";
                        }
                        $goods_info->quota = $quota_goods;
                    } else {
                        $goods_info->is_quota = 0;
                    }
                    break;
                default:
                    {
                        $goods_info->promo_errorCode = 2;
                    }
            }
        }

        //获得扩展属性
        $goods_info->attribute = DB::table('goods_attribute as g')->leftJoin('attribute as a', 'a.id', '=', 'g.attribute_id')
            ->select('a.name', 'g.attribute_value')
            ->where('goods_id', $request->id)
            ->where('attribute_id', '=', '')
            ->get();

        //购买记录
        $shop_info = DB::table('order_goods as og')
            ->leftJoin('order as o', 'o.id', '=', 'og.order_id')
            ->where('og.goods_id', $request->id)
            ->where('o.status', 5)
            ->select('o.status', DB::raw('count(*) as count'))
            ->groupBy('o.status')
            ->get();

        $buy_num = 0;
        $cancelled_num = 0;

        foreach ($shop_info as $k => $v) {
            if ($v->status == 5) {
                $buy_num += $v->count;
            }
            if ($v->status == 3 || $v->status == 4) {
                $cancelled_num += $v->count;
            }
        }
        $goods_info->buy_num = $buy_num;
        $goods_info->cancelled_num = $cancelled_num;

        //购买前咨询
        $refeer_info = DB::table('refer')->where('goods_id', $request->id)->count();
        $goods_info->refer = $refeer_info ?: 0;

        //网友讨论
        $discussion_info = DB::table('discussion')->where('goods_id', $request->id)->count();
        $goods_info->discussion = $discussion_info ?: 0;


        //获得商品的价格区间
        $product_info = DB::table('products')->where('goods_id', $request->id)
            ->select(DB::raw('max(sell_price) as maxsellprice ,min(sell_price) as minsellprice,max(market_price) as minmarketprice,min(market_price) as maxmarketprice'))->first();
        $goods_info->maxSellPrice = '';
        $goods_info->minSellPrice = '';
        $goods_info->minMarketPrice = '';
        $goods_info->maxMarketPrice = '';

        if ($product_info) {
            $goods_info->maxSellPrice = $product_info->maxsellprice;
            $goods_info->minSellPrice = $product_info->minsellprice;
            $goods_info->minMarketPrice = $product_info->minmarketprice;
            $goods_info->maxMarketPrice = $product_info->maxmarketprice;
        }

        if ($goods_info->minSellPrice) {
            $goods_info->sell_price = $goods_info->minSellPrice;
        }

        //免运费判断
        if ($goods_info->template_id) {
            $temp = DB::table('shipping_template')->find($goods_info->template_id);
            if ($temp->free_shipping == 1) {
                $goods_info->is_shipping = 0;  //有运费
            } else {
                $goods_info->is_shipping = 1;  //无运费
            }
        } else {
            if ($goods_info->freight > 0) {
                $goods_info->is_shipping = 0;  //有运费
            } else {
                $goods_info->is_shipping = 1;
            }
        }

        $goods_info->is_cashondelivery = 1;

        //新增PV （商品页）
        if ($user) {
            try {
                $this->addGoodsPvByGoodsInfo($request->id);
                $this->addSellerPv($goods_info->seller_id);
            } catch (\Exception $e) {
            }
        }

        //去掉content里面所有的a标签
        $goods_info->content = preg_replace("/<a[^>]*>/", "", $goods_info->content);
        $goods_info->content = preg_replace("/<\/a>/", "", $goods_info->content);
        $goods_info->name = htmlspecialchars_decode($goods_info->name);
        $goods_info->sell_price = showPrice($goods_info->sell_price);
        $goods_info->nowtime = Carbon::now()->toDateTimeString();

        return $this->success($goods_info);
    }

    /**
     * @param \App\Http\Requests\V1\GetFeedBackRequest $request
     * @return mixed
     * CreateTime: 2018/7/26 9:09
     * Description: 商品评论
     */
    public function getFeedback(GetFeedBackRequest $request)
    {

        $page = $request->page ?: 1; //页码
        $size = $request->size ?: 10; //每页显示多少条

        $data = DB::table('comment as c')
            ->leftJoin('goods as go', 'go.id', '=', 'c.goods_id')
            ->leftJoin('user as u', 'u.id', '=', 'c.user_id')
            ->select('u.head_ico', 'u.username', 'c.*')
            ->where('c.goods_id', $request->id)
            ->where('c.status', 1)
            ->where('go.is_del', 0)
            ->latest('c.id')
            ->forPage($page, $size)
            ->get();

        $data = $data->map(function ($i) {
            $i->comment_time = date('M d,Y', strtotime($i->comment_time));
            $i->head_ico = getImgDir($i->head_ico, 200, 200);
            if ($i->img) {
                $i->img = explode(",", $i->img);
                foreach ($i->img as $k1 => $v1) {
                    $i->img[$k1] = getImgDir($v1, 1000, 1000);
                }
            } else {
                $i->img = [];
            }
            return $i;
        });

        return $this->success($data);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return mixed
     * CreateTime: 2018/7/26 10:36
     * Description: 加入到购物车
     */
    public function joinCart(JoinCartRequest $request)
    {
        //规格必填
        if ($request->type == "product") {
            if (!DB::table('products')->where('id', $request->id)->exists()) {
                return $this->error(301, 'Please choose product specifications');
            }
        } else {
            if (!DB::table('goods')->where('id', $request->id)->exists()) {
                return $this->error(301, 'goods Bu cun zai');
            }
        }

        //更新购物车内容
        $gid = intval($request->id);
        $num = intval($request->num);
        $type = $request->type;
        $user_id = Auth::id();

        if ($type != 'goods') {
            $type = 'product';
        }
        //获取基本的商品数据
        $goodsRow = $this->getGoodInfo($gid, $type);
        if ($goodsRow) {
            //查询数据库 获取购物车是否有user的信息
            $cartRow = DB::table('goods_car')->where('user_id', $user_id)->first();
            if ($cartRow) {
                $res = decode($cartRow->content);
                //更新已有商品信息数据
                if ($goodsRow->store_nums < $num) {
                    return $this->error(400, 'The inventory is not enough for the supply');
                }
                if ($num <= 0) {
                    return $this->error(400, 'Purchase quantity must be greater than 0');
                }
                $res[$type][$gid] = $num;

                $res = encode($res);

                $dataArray = array('content' => $res, 'create_time' => date("Y-m-d H:i:s"));
                DB::table('goods_car')->where('user_id', $user_id)->update($dataArray);

            } else {
                $res[$type][$gid] = $num;
                $res = encode($res);
                $dataArray = array('content' => $res, 'user_id' => $user_id, 'create_time' => date("Y-m-d H:i:s"));
                DB::table('goods_car')->insert($dataArray);
            }
        } else {
            return $this->error(400, 'Unable to match the information of the product');
        }

        return $this->success([]);
    }

    /**
     * @param        $gid
     * @param string $type
     * @return \stdClass
     * CreateTime: 2018/7/26 10:37
     * Description: 获取商品详情
     */
    public function getGoodInfo($gid, $type = 'goods')
    {
        $dataArray = new \stdClass();

        //商品方式
        if ($type == 'goods') {
            $dataArray = DB::table('goods as go')
                ->where('id', $gid)
                ->where('is_del', 0)
                ->select('go.name', 'go.id as goods_id', 'go.img', 'go.sell_price', 'go.point', 'go.weight', 'go.store_nums', 'go.exp', 'go.goods_no', DB::raw('0 as product_id'), 'go.seller_id', 'go.is_shipping')->first();
            if ($dataArray) {
                $dataArray->id = $dataArray->goods_id;
            }
        } //货品方式
        else {
            $productRow = DB::table('products as pro')
                ->leftJoin('goods as go', 'pro.goods_id', '=', 'go.id')
                ->where('pro.id', $gid)
                ->where('go.is_del', 0)
                ->select('pro.sell_price', 'pro.weight', 'pro.id as product_id', 'pro.spec_array', 'pro.goods_id', 'pro.store_nums', 'pro.products_no as goods_no', 'go.name', 'go.point', 'go.exp', 'go.img', 'go.seller_id', 'go.is_shipping')
                ->get();
            if ($productRow) {
                $dataArray = $productRow[0];
            }
        }
        return $dataArray;
    }

    /***
     * @param Request $request
     * @param  $id  商品或者货品id
     * @param  $type  类型  goods商品  product 货品
     * @param  $num  购买数量
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/26 0026
     * description: 立即购买
     */
    public function buyNow(Request $request){
        //字段验证
        Validator::make($request->all(), [
            'id' => 'required',
            'type' => 'required',
            'num' => 'required',
        ])->validate();

        $user_id = auth()->id();
        $id        = $request->id; //商品或货品id  单品 ，直接购买或者限时抢购
        $type      = $request->type; //商品类型 goods，product
        $buy_num   = $request->num;  //购买数量

        $active_id = request('active_id','');
        $promo  = request('promo','');

        if(($active_id && !$promo) || (!$active_id && $promo)){
            return $this->error(400,'参数错误');  //t_06
        }

        //查看商品库存
        //为限时抢购 判断单个买家购买个数
        if($active_id && $promo){
            $tb_promotion = DB::table('promotion')->where('id', $active_id)
                ->select('people','start_time','end_time','max_num','sold_num')
                ->first();

            if($promo == 'time'){
                //查询买家订单
                $tb_order_list = DB::table('order as o')
                    ->leftJoin('order_goods as og', 'o.id', '=', 'og.order_id')
                    ->select('o.id as order_id', 'o.user_id', 'og.goods_id', 'og.product_id', 'og.goods_nums')
                    ->where([
                        ['o.user_id', '=', $user_id],
                        ['o.create_time', '>', $tb_promotion->start_time],
                        ['o.create_time', '<', $tb_promotion->end_time],
                    ])
                    ->whereIn('o.status', [3,4,8])
                    ->get()
                    ->map(function($v){
                        return (array)$v;
                    })
                    ->toArray();
                $num = $buy_num;
                $order_buy_num = 0;

                foreach ($tb_order_list as $k=>$v){
                    //如果 是货品，查询商品表，获取货品对应商品id
                    if($type == 'product'){
                        $productRow = DB::table('products')->find($id);
                        if($productRow->goods_id == $v['goods_id']){
                            $num += intval($v['goods_nums']);
                            $order_buy_num += intval($v['goods_nums']);
                        }
                    }else {
                        if ($id == $v['goods_id']) {
                            $num += intval($v['goods_nums']);
                            $order_buy_num += intval($v['goods_nums']);
                        }
                    }
                }

                if($num > $tb_promotion->people){
                    return $this->error(400,'The max order is '.$tb_promotion->people." only,you have bought".$order_buy_num.' items!');
                }

                if($buy_num > $tb_promotion->max_num - $tb_promotion->sold_num ){
                    return $this->error(400, ' The inventory is not enough for the supply');
                }

                if($tb_promotion->sold_num == $tb_promotion->max_num){
                    return $this->error(400, 'The inventory is not enough for the supply');
                }
            }
        }

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
        $result = $countSum->cart_count($id,$type,$buy_num,$promo,$active_id);
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
                            if(isset($v1['spec_array']) && $v1['spec_array']){
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
            'goods_id' => $id,
            'type' => $type,
            'num' => $buy_num,
            'promo' => $promo,
            'active_id' => $active_id,
            'final_sum' => $data['final_sum'],
            'promotion' => $data['promotion'],
            'proReduce' => $data['proReduce'],
            'sum' => $data['sum'] - $data['reduce'],
            'cartList' =>$data['cartList'],
            'count' => $data['count'],
            'reduce' =>$data['reduce'],
            'weight' =>$data['weight'],
            'seller' => $data['seller'],
//            'goodsTax' =>$data['tax'],
            'sellerProReduce' =>$data['sellerProReduce'],
//            'custom' => $custom
        );
        //优惠券
        $order_class = new Order();
        $dataArray = $order_class->voucher($dataArray,$user_id, request('province',null));
        return $this->success($dataArray);
    }
}
