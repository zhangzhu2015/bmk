<?php

namespace App\Http\Controllers\V1;

use App\Htpp\Traits\ApiResponse;
use App\Librarys\CountSum;
use App\Librarys\Delivery;
use App\Librarys\ProRule;
use App\Librarys\Redisbmk;
use App\Models\Goods;
use App\Models\Order;
use App\Models\Seller;
use App\Librarys\CloudSearch;
use App\Models\User;
use App\Services\OSS;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Validator;
use Illuminate\Support\Facades\DB;

class CommonController extends Controller
{
    use ApiResponse;

    /***
     * @param $seller_id 商家id
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/17 0017
     * description: 店铺信息
     */
    public function sellerInfo(Request $request)
    {
        //字段验证
        Validator::make($request->all(), [
            'seller_id' => 'required',
        ])->validate();

        $userId = Auth::id();
        $sellerId = request('seller_id');

        //卖家信息
        $sellerInfo = Seller::select('is_del', 'is_lock', 'img', 'true_name', 'active_time', 'sale', 'comments',
            'create_time', 'contacts', 'mobile', 'is_shipping', 'is_cashondelivery', 'selleruin')
            ->where('id', $sellerId)
            ->first();

        if ($sellerInfo['is_del'] != 0 || $sellerInfo['is_lock'] != 0) {
            $this->error(400, '卖家信息不存在');   //t_01
        }
        $data['img'] = $sellerInfo['img'] ? getImgDir($sellerInfo['img']) : ''; //卖家头像
        $data['true_name'] = $sellerInfo['true_name']; //店铺名称
        $data['active_time'] = $sellerInfo['active_time'];        //店铺最后活动时间
        $data['sale'] = $sellerInfo['sale'];        //店铺总销量

        $redis = new Redisbmk();
        //用户每日访问量 增加
        $redis->hIncrBy('_seller_visi:seller_id_' . $sellerId, date('Y-m-d'), 1);

        //查询卖家的评论回复数
        $comment_num = DB::table('comment')
            ->leftJoin('goods', 'goods.id', '=', 'comment.goods_id')
            ->where([
                ['goods.seller_id', '=', $sellerId],
                ['comment.status', '=', 1],
                ['comment.recomment_time', '>', 0]
            ])
            ->count();

        //查询卖家总评论数
        $comment_total = DB::table('comment')
            ->leftJoin('goods', 'goods.id', '=', 'comment.goods_id')
            ->where([
                ['goods.seller_id', '=', $sellerId],
                ['comment.status', '=', 1],
            ])
            ->count();

        //评论回复率 = 卖家的评论回复数 / 查询卖家总评论数
        $data['response_rate'] = ceil((($comment_num) / $comment_total) * 100);
        $data['comments'] = $sellerInfo['comments'];
        //获取商品总数
        $goods_num = DB::table('goods')
            ->where([
                ['is_del', '=', 0],
                ['seller_id', '=', $sellerId],
            ])
            ->count();
        $data['products'] = $goods_num;

        //是否支持免运费
        $data['is_shipping'] = $sellerInfo['is_shipping'];        //是否支持免运费
        $data['is_cashondelivery'] = $sellerInfo['is_cashondelivery'];        //是否支持货到付款
        $data['create_time'] = $sellerInfo['create_time'];  //创建时间
        $data['contacts'] = $sellerInfo['contacts'];//联系方式
        $point = Seller::getPointOfSeller($sellerId);

        $data['ratting'] = $point['ratting'];
        $data['service_ratting'] = $point['service_ratting'];
        $data['delivery_speed_ratting'] = $point['delivery_speed_ratting'];
        $data['following_num'] = DB::table('seller_user_fav')->where('seller_id', '=', $sellerId)->count();
        $data['shop_link'] = env('SITE_URL') . '/' . $sellerInfo['true_name'];

        //是否关注
        if ($userId) {
            $result = DB::table('seller_user_fav')
                ->where([
                    ['user_id', '=', $userId],
                    ['seller_id', '=', $sellerId],
                ])->first();
            if ($result) {
                $data['is_follow'] = 1; //关注
            } else {
                $data['is_follow'] = 2; //没关注
            }
        }
        //店铺联系人
        $data['contacts'] = showMobile($sellerInfo['mobile']);

        //获取店铺banner图
        $bannerList = DB::table('seller_slide')->where('seller_id', $sellerId)->select('img')->get();
        $data['bannerList'] = $bannerList ? $bannerList : [];
        $data['selleruin'] = $sellerInfo['selleruin'];

        //获取店铺优惠券
        $data['coupon'] = seller::getInfocount($sellerId);

        $proRuleObj = new ProRule(999999999999, $sellerId);
        $proRuleObj->isGiftOnce = false;
        $proRuleObj->isCashOnce = false;
        $data['proRules'] = $proRuleObj->getInfo();
        return $this->success($data);
    }

    /***
     * author: zhangzhu
     * create_time: 2018/8/9 0009
     * description:获取购物车数量
     */
    public function getCartNum()
    {
        $user_id = auth()->id();
        $content = DB::table('goods_car')->where('user_id', $user_id)->value('content');
        $content = json_decode(str_replace(array('&','$'),array('"',','), $content));
        $productNums = 0;
        if(collect($content->product)->count() > 0){
            $ids = collect($content->product)->keys();
            $productNums = DB::table('products')->whereIn('id',$ids)->count();
        }
        $count = collect($content->goods)->count() + $productNums;
        return $this->success(array('count'=>$count));
    }

    /***
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/17 0017
     * description:获取用户收藏商品总数
     */
    public function getUserFavoritesNum()
    {
        $count = count($this->getFavorites());
        return $this->success(['num' => $count]);
    }

    //获取用户收藏商品
    private function getFavorites()
    {
        $user_id = auth()->user()->id;
        $items = DB::table('favorite')->where("user_id", $user_id)->get()->map(function ($value) {
            return (array)$value;
        })->toArray();
        foreach ($items as $val) {
            $goodsIdArray[] = $val['rid'];
        }

        //商品数据
        if (!empty($goodsIdArray)) {
            $goodsList = DB::table('goods')->whereIn('id', $goodsIdArray)->select('id', 'name', 'sell_price')->get()->map(function ($value) {
                return (array)$value;
            })->toArray();
        }

        foreach ($items as $key => $val) {
            foreach ($goodsList as $gkey => $goods) {
                if ($goods['id'] == $val['rid']) {
                    $items[$key]['goods_info'] = $goods;

                    //效率考虑,让goodsList循环次数减少
                    unset($goodsList[$gkey]);
                }
            }

            //如果相应的商品或者货品已经被删除了，
            if (!isset($items[$key]['goods_info'])) {
                DB::table('favorite')->delete($val['id']);
                unset($items[$key]);
            }
        }
        return $items;
    }

    /***
     * @param Request $request province 省id ，jsonData，
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/18 0018
     * description: 获取运费
     */
    public function getShippingFee(Request $request)
    {
        //字段验证
        Validator::make($request->all(), [
            'jsonData' => 'required',
            'province' => 'required',
        ])->validate();
        $user_id = auth()->user()->id;
        $jsonData = $request->jsonData;
        $result = json_decode($jsonData, true);
        if (!analyJson($jsonData)) {
            return $this->error(400, 'json格式不正确'); // t_02
        }

        $result = json_decode($jsonData, true);
        $keys = array_keys($result);
        $arr = ['product_id', 'goods_id', 'buy_num'];

        if (sort($keys) != sort($arr)) {
            return $this->error(400, 'json格式不正确'); // t_02
        }
        $deliveryDB = new Delivery();
        $result = $deliveryDB->getDelivery($request->province, 1, $result['goods_id'], $result['product_id'], $result['buy_num'], $user_id);
        foreach ($result['shippingcost'] as $k => $v) {
            $shippingcost[$k]['seller_id'] = $k;
            $shippingcost[$k]['sum_cost'] = $v;
        }
        $result['shippingcost'] = array_values($shippingcost);
        return $this->success($result);
    }

    /***
     * @param is_cashondelivery 是否支持货到付款 0不支持 1支持
     * @param is_banktobank    是否支持bank to bank 0不支持 1支持
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/18 0018
     * description: 获取支付方式
     */
    public function getPaymentList(Request $request)
    {
        //字段验证
        Validator::make($request->all(), [
            'is_cashondelivery' => 'required',
            'is_banktobank' => 'required',
        ])->validate();

        $user_id = $request->user_id;
        $is_cashondelivery = $request->is_cashondelivery;
        $is_banktobank = $request->is_banktobank;

        $where[] = ['status', '=', 0];
        $query = DB::table('payment');
        if (!$user_id) {
            $where[] = ['class_name', '<>', 'balance'];
        }
        if ($is_cashondelivery == 0) {
            $where[] = ['type', '=', 1];
        }
        if ($is_banktobank === '0') {
            $query = $query->whereNotIn('id', [14])->whereIn('client_type', [1, 3]);
        }
        $lst = $query->where($where)->select('id','name','description','logo','note')->orderBy("order", "asc")->get()->map(function ($value) {
            return (array)$value;
        })->toArray();

        foreach ($lst as $k => $v) {
            $lst[$k]['name'] = $v['name'] == 'Cash on delivery' ? 'Cash on Delivery' : $v['name'];
        }
        return $this->success($lst);
    }

    /***
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/18 0018
     * description: 获取最近浏览记录   每个用户最多存储6个
     */
    public function getRecentlyGoods()
    {
        $user_id = auth()->user()->id;
        $redis = new Redisbmk();
        $count = $redis->listcount('_recently_viewed:user_id_9234');
        if (!$count) {
            return $this->success([]);
        } else {
            $goods_ids = $redis->lRange('_recently_viewed:user_id_' . $user_id, 0, $count - 1);
        }
        $goods_str = join(',', $goods_ids);
        $goodsList = DB::table('goods')
            ->select('id', 'name', 'sell_price', 'market_price', 'store_nums', 'img', 'sale', 'grade', 'comments',
                'favorite', 'seller_id', 'is_shipping', 'template_id', 'freight')
            ->whereIn('id', $goods_ids)
            ->orderByRaw("FIELD(id,?)", [$goods_str])
            ->get()
            ->map(function ($value) {
                return (array)$value;
            })
            ->toArray();
        foreach ($goodsList as $k => $v) {
            $goodsList[$k]['active_id'] = '';
            $goodsList[$k]['promo'] = '';
            $goodsList[$k]['start_time'] = '';
            $goodsList[$k]['end_time'] = '';
            $res = Goods::getnewPromotionRowById($v['id']);
            if ($res) {
                $goodsList[$k]['sell_price'] = showPrice($res->award_value);
                $goodsList[$k]['active_id'] = $res->id;
                $goodsList[$k]['promo'] = 'time';
                $goodsList[$k]['start_time'] = $res->start_time;
                $goodsList[$k]['end_time'] = $res->end_time;
            }
            $quotaRow = Goods::getQuotaRowBygoodsId($v['id']);

            if ($quotaRow) {
                $goodsList[$k]['active_id'] = $quotaRow->quota_activity_id;
                $goodsList[$k]['promo'] = 'quota';
                $goodsList[$k]['start_time'] = $quotaRow->activity_start_time;
                $goodsList[$k]['end_time'] = $quotaRow->activity_end_time;
            }
            $diff = ($goodsList[$k]['market_price'] - $goodsList[$k]['sell_price']) / $goodsList[$k]['market_price'];
            $goodsList[$k]['discount'] = $diff <= 0 ? '' : number_format($diff, 2) * 100;

            //获取商家信息
            $sellerInfo = DB::table('seller')->where('id', $v['seller_id'])->first();

            //免运费判断
            if ($v['template_id']) {
                $temp = DB::table('shipping_template')->where('id', $v['template_id'])->first();
                if ($temp->free_shipping == 1) {
                    $goodsList[$k]['is_shipping'] = 2;  //有运费
                } else {
                    $goodsList[$k]['is_shipping'] = 1;  //无运费
                }
            } else {
                if ($v['freight'] > 0) {
                    $goodsList[$k]['is_shipping'] = 2;  //有运费
                } else {
                    $goodsList[$k]['is_shipping'] = 1;
                }
            }
            $goodsList[$k]['is_cashondelivery'] = $sellerInfo->is_cashondelivery; //等于1 的时候支持货到付款
            $goodsList[$k]['img'] = getImgDir($goodsList[$k]['img'], 300, 300);
        }
        return $this->success($goodsList);
    }

    /***
     * @param $area_id 地区id
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/19 0019
     * description:  根据地区id 获取该地区下级地区
     */
    public function getChildrenByAreaId(Request $request)
    {
        $area_id = request('area_id', 0);
        $res = DB::select(" select a.*, (select count(*) from  iwebshop_areas as b where b.parent_id = a.area_id) as c from iwebshop_areas as a  where a.parent_id = ? group by a.area_id order by a.area_name asc ", [$area_id]);
        return $this->success($res);
    }

    /***
     * @param Request $request good_id  商品id
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/19 0019
     * description: 商品点赞
     */
    public function likeGoods(Request $request)
    {
        //字段验证
        Validator::make($request->all(), [
            'goods_id' => 'required',
        ])->validate();
        $user_id = auth()->id();
        $goods_id = $request->goods_id;
        $res = DB::table('goods_like')
            ->where([
                ['user_id', '=', $user_id],
                ['goods_id', '=', $goods_id],
            ])
            ->first();
        $res = collect($res)->toArray();
        if ($res) {
            return $this->error(400, '你已经点过赞了'); //t_03
        } else {
            $data = array('user_id' => $user_id, 'goods_id' => $goods_id);
            DB::table('goods_like')->insert($data);
            //获取点赞总数
            $num = DB::table('goods_like')->where('goods_id', $goods_id)->count();
            return $this->success(['num' => $num]);
        }
    }

    /***
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/19 0019
     * description: 获取网站分类
     */
    public function getclist()
    {
        $redis = new Redisbmk();
        $clist = $redis->get('_category');
        if (!$clist) {
            return $this->error(400, 'error');
        }
        $cate = json_decode($clist, true);

        return $this->success(array_values($cate));
    }

    /***
     * @param Request $request accept_name =>收货人， province=> 省id，  city=> 市id ， are=> 区id , address=>详细地址
     *                         zip=>邮政编码， telphone=>座机号码 ，mobile=>手机号，is_default=>是否作为默认地址  ,email=>邮箱
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/19 0019
     * description: 添加/编辑收货地址
     */
    public function editAddress(Request $request)
    {
        //字段验证
        Validator::make($request->all(), [
            'accept_name' => 'required',
            'province' => 'required',
            'city' => 'required',
            'area' => 'required',
            'address' => 'required',
            'zip' => 'required',
            'telphone' => 'required',
            'mobile' => ['required', 'regex:!^9[0-9]\d{8}$!'],
            'is_default' => 'required',
            'email' => 'required|regex:/^\w+([-+.]\w+)*@\w+([-.]\w+)+$/i',
        ])->validate();

        $user_id = auth()->id();

        $data = $request->all();
        $default = $data['is_default'] != 1 ? 0 : 1;
        //如果设置为首选地址则把其余的都取消首选
        if ($default == 1) {
            $dataArray = array('is_default' => 0);
            DB::table('address')->where("user_id", $user_id)->update($dataArray);
        }

        if (!isset($data['id'])) {
            $data['user_id'] = $user_id;
            DB::table('address')->insertGetId($data);
            return $this->apiSuccess([]);
        } else {
            DB::table('address')->where('id', $data['id'])->update($data);
            return $this->apiSuccess([]);
        }
    }

    /***
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/19 0019
     * description:消息未读总数
     */
    public function countMess()
    {
        $user_id = auth()->id();
        $member = DB::table('member')->where('user_id', $user_id)->first();
        $message_ids = $member->message_ids;
        $tempIds = ',' . trim($message_ids, ',') . ',';
        preg_match_all('|,\d+|', $tempIds, $result);
        $count = count(current($result));
        return $this->success(array('count' => $count));
    }

    /***
     * @param Request $request
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/19 0019
     * description: 商品搜索
     */
    public function goodsList(Request $request)
    {
        $paramsearch = [];
        $paramsearch['page'] = request('page', env('PAGE', 1)); //页码
        $paramsearch['size'] = request('pageSize', env('PAGESIZE', 6)); //分页条数
        $paramsearch['cate_id'] = request('cate_id', ''); //分类搜索
        $paramsearch['search'] = request('search', ''); //商品名或商品分词搜索
        $paramsearch['min_price'] = request('min_price', ''); //价格下限
        $paramsearch['max_price'] = request('max_price', ''); //价格上限
        $paramsearch['b_id'] = request('b_id', ''); //品牌id
        $paramsearch['seller_id'] = request('seller_id', ''); //商家id
        $paramsearch['seller_category_id'] = request('seller_category_id', ''); //商家分类id
        $paramsearch['is_shipping'] = request('is_shipping', ''); //是否免运费 1免
        $paramsearch['order_by'] = request('order_by', ''); //分类字段
        $paramsearch['order_type'] = request('order_type', ''); //分类类型
        $paramsearch['instock'] = request('instock', ''); //库存
        $paramsearch['type'] = request('type', ''); //分类类型

        $cloudsearch = new CloudSearch();
        $goodsList = $cloudsearch->search($paramsearch);
        foreach ($goodsList as $k => $v) {
            $goodsList[$k]['active_id'] = '';
            $goodsList[$k]['promo'] = '';
            $goodsList[$k]['start_time'] = '';
            $goodsList[$k]['end_time'] = '';

            if ($res = Goods::getPromotionRowBygoodsId($v['id'])) {
                $goodsList[$k]['sell_price'] = showPrice($res->award_value);
                $goodsList[$k]['active_id'] = $res->id;
                $goodsList[$k]['promo'] = 'time';
                $goodsList[$k]['start_time'] = $res->start_time;
                $goodsList[$k]['end_time'] = $res->end_time;
            }

            if ($quotaRow = Goods::getQuotaRowBygoodsId($v['id'])) {
                $goodsList[$k]['active_id'] = $quotaRow->quota_activity_id;
                $goodsList[$k]['promo'] = 'quota';
                $goodsList[$k]['start_time'] = $quotaRow->activity_start_time;
                $goodsList[$k]['end_time'] = $quotaRow->activity_end_time;
            }

            $diff = ($goodsList[$k]['market_price'] - $goodsList[$k]['sell_price']) / $goodsList[$k]['market_price'];
            $goodsList[$k]['discount'] = $diff <= 0 ? '' : number_format($diff, 2) * 100;
            if (!$goodsList[$k]['is_cashondelivery']) {
                $goodsList[$k]['is_cashondelivery'] = "1";
            }
            $goodsList[$k]['img'] = getImgDir($goodsList[$k]['img'], 300, 300);
            //免运费判断
            $goodsRow = Goods::select('template_id', 'freight')->first($v['id']);
            if ($goodsRow->template_id) {
                $temp = DB::table('shipping_template')->where('id', $goodsRow->template_id)->first();
                if ($temp->free_shipping == 1) {
                    $goodsList[$k]['is_shipping'] = 0;  //有运费
                } else {
                    $goodsList[$k]['is_shipping'] = 1;  //无运费
                }
            } else {
                if ($goodsRow->freight > 0) {
                    $goodsList[$k]['is_shipping'] = 0;  //有运费
                } else {
                    $goodsList[$k]['is_shipping'] = 1;
                }
            }
        }
        $dataArray = array(
            'goodsList' => $goodsList,
            'condition' => [],
        );
        return $this->Success($dataArray);
    }

    /***
     * @param Request $request images64 base64编码
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/20 0020
     * description: 图片上传
     */
    public function uploadImage(Request $request)
    {
        //字段验证
        Validator::make($request->all(), [
            'images64' => 'required',
        ])->validate();

        $base64 = $request->images64;
        $imagename = date('Ymdhis') . mt_rand(100, 9999) . '.jpg';
        $img = base64_decode(str_replace(" ", "+", $base64));
        //阿里云oss上传
        $bigmkoss = new OSS();
        $object = $bigmkoss->publicUploadContent($imagename, $img, ['ContentType' => 'image/jpeg']);
        return $this->success(['url' => $object]);
    }

    /***
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/24 0024
     * description: 启动图
     */
    public function getBuyerStartImg(){
        $row = DB::table('start_img')->where('name','b_start_img')->first();
        if($row && $row->img){
            return $this->success(['start_img'=>$row->img]);
        }else{
            return $this->success(['start_img'=>'']);
        }
    }

    /***
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/24 0024
     * description: 引导图
     */
    public function getBuyerGuideImg(){
        $rows = DB::table('app_guide')->get()->map(function($v){return (array)$v;})->toArray();
        return $this->success($rows);
    }

    /***
     * @param Request $request client 客户端
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/24 0024
     * description: 更新日志
     */
    public function getAppDialog(Request $request){
        //字段验证
        Validator::make($request->all(), [
            'client' => 'required',
        ])->validate();

        $client = $request->client;

        $arr = [1=>'buyer_android_dialog',2=>'buyer_ios_dialog',3=>'seller_android_dialog',4=>'seller_ios_dialog'];
        $client = $arr[$client];
        $row = DB::table('app_dialog')->where('client', $client)->first();
        if(!$row){
            $row['id'] = '';
            $row['client'] = $arr[$client];
            $row['content'] = '' ;
        }
        return $this->success($row);
    }

    //店铺列表
    public function shop_list(Request $request)
    {
        $page = $request->page ?: 1;
        $size = $request->size ?: 6;
        $search_word = $request->search_word ?: '';

        $sellerList = DB::table('seller')
            ->where([
                ['true_name', 'like', '%'.$search_word.'%'],
                ['is_del', '=', 0],
                ['is_lock', '=', 0],
            ])
            ->select('id','grade','true_name','comments','sale','img','active_time')
            ->orderBy('sale','desc')
            ->forPage($page, $size)
            ->get()
            ->map(function($v){
                return (array)$v;
            })->toArray();

        foreach($sellerList as $k=>$v){
            $sellerList[$k]['img'] = getImgDir( $sellerList[$k]['img']);
            $point = Seller::getPointOfSeller($sellerList[$k]['id']);
            $sellerList[$k]['ratting'] = $point['ratting'];
        }
        return $this->success($sellerList);
    }
}

