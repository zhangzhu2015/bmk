<?php
namespace App\Http\Controllers\V1;
 
use App\Htpp\Traits\ApiResponse;
use App\Models\Goods;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;//引入自带接数据库
use Illuminate\Support\Facades\DB;//引入DB
use Validator;
 
class QuotaController extends Controller
{
    use ApiResponse;
    public $successStatus = 200;


    /***
     * @param $user_id 用户id
     * @param $status 状态 4 所有 1需要分享的 2成功的 3，过期的
     * @param $page 页码
     * @param $pageSize 每页条目
     * @param $quota_code 拼单号
     * author: wangding
     * create_time: 2018/5/8 0008
     * description: 用户中心拼单列表
     */
    public function quotaList(Request $request){
        $quota_code = $request->quota_code;
        $status = $request->status; // 4 所有 1需要分享的 2成功的 3，过期的,5,已经失败的
        $page = $request->page ? $request->page : 1;
        $pageSize = $request->pageSize ? $request->pageSize : 10;
        $user_id = Auth::id();
        $quota_orders = DB::table('quota_orders_detail as d')
            ->leftJoin('goods as g', 'g.id', '=', 'd.goods_id')
            ->leftJoin('seller as s', 's.id', '=', 'g.seller_id')
            ->leftJoin('quota_orders as qo', 'qo.id', '=', 'd.quota_orders_id')
            ->leftJoin('quota_goods as qg', 'qg.id', '=', 'qo.quota_goods_id')
            ->where('user_id', $user_id)
            ->where(function ($query_status) use ($status){
                if($status && $status != 4){
                    if($status == 3){
                        $query_status->where('d.code', 3)->orWhere('d.code', 4);
                    }else{
                        $query_status->where('d.code', $status);
                    }
                }
            })->where(function ($query_code) use ($quota_code){
                if($quota_code){
                    $query_code->where('d.quota_code', 'like', '%'.$quota_code.'%');
                }
            })->orderBy('d.created_time','desc')
            ->skip(($page-1)*$pageSize)->take($pageSize)
            ->select('d.id', 'd.quota_orders_id', 'd.quota_code', 'd.goods_id', 'g.name', 'g.img', 's.true_name', 's.selleruin', 'd.created_time', 'd.goods_nums', 'd.quota_price', 'd.real_amount', 'd.order_amount', 'd.real_freight', 'd.code', 'd.product_id', 'd.goods_array', 's.id as seller_id')
            ->get();

        $quota_orders = $quota_orders->map(function ($value) {
            return (array)$value;
        })->toArray();
        foreach($quota_orders as $k => $v){
            $row = DB::table('order')->where('quota_code', $v['quota_code'])->first();
            $row = collect($row)->toArray();
            $quota_orders[$k]['order_no'] = $row ? $row['order_no'] : '';
            $quota_orders[$k]['order_id'] = $row ? $row['id'] : '';
            $quota_orders[$k]['end_time'] = date('Y-m-d H:i:s', strtotime($v['created_time']) + 86400);
            if($v['goods_array']){
                $quota_orders[$k]['goods_array'] = json_decode($v['goods_array'], true);
            }else{
                unset($quota_orders[$k]);
                continue;
            }
            $quota_orders[$k]['quota_price'] = showPrice($v['quota_price']);
            $quota_orders[$k]['active_id'] = '';
            $quota_orders[$k]['promo'] = '';

            if($res = Goods::getnewPromotionRowById($v['goods_id'])){
                $quota_orders[$k]['active_id'] = $res->id;
                $quota_orders[$k]['promo'] = 'time';
            }

            if($quotaRow = Goods::getQuotaRowBygoodsId($v['goods_id'])){
                $quota_orders[$k]['active_id'] = $quotaRow->quota_activity_id;
                $quota_orders[$k]['promo'] = 'quota';
            }
            $quota_orders[$k]['code'] = $quota_orders[$k]['code'] == 4 ? 3 : $quota_orders[$k]['code'];
        }
        return $this->success(array_values($quota_orders));
    }

    /***
     * @param $user_id 用户id
     * @param $status 状态 4 所有 1需要分享的 2成功的 3，过期的
     * @param $page 页码
     * @param $pageSize 每页条目
     * @param $quota_code 拼单号
     * author: wangding
     * create_time: 2018/5/8 0008
     * description: 用户中心拼单详情
     */
    public function quotaDetail($id){
        //拼单信息
        $quotaOrderRow = DB::table('quota_orders_detail')->select('quota_orders_id', 'id' , 'img', 'quota_code', 'code', 'province', 'city', 'area', 'address', 'mobile', 'accept_name', 'goods_array', 'quota_price', 'goods_nums', 'pay_type', 'order_amount', 'real_freight', 'real_amount', 'seller_id', 'goods_id', 'distribution')->find($id);
        if(!$id || !$quotaOrderRow ){
            $this->error(400,'Group buy does\'t exist');
        }
        $quotaOrderRow = collect($quotaOrderRow)->toArray();
        $quotaRow =  DB::table('quota_orders')->find($quotaOrderRow['quota_orders_id']);
        //所有拼单参与人
        $user_array = DB::table('quota_orders_detail as d')
            ->leftJoin('user as u', 'u.id', '=', 'd.user_id')
            ->where('quota_orders_id', $quotaRow->id)
            ->select('u.username', 'u.id', 'u.head_ico')
            ->get();
        $user_array = $user_array->map(function ($value) {
            return (array)$value;
        })->toArray();
        foreach($user_array as &$user){
            if($user['id'] == $quotaRow->lead_user_id ){
                $user['is_owner'] = 1;   //
            }else{
                $user['is_owner'] = 2;
            }
        }

        $quotaOrderRow['users'] = $user_array;
        $quotaOrderRow['people'] = $quotaRow->people;
        $quotaOrderRow['joined_people'] = $quotaRow->join_people;
        $quotaOrderRow['quota_price'] = showPrice($quotaOrderRow['quota_price']);

        //收货地址
        $quotaOrderRow['area_addr'] = join(',', Order::name($quotaOrderRow['province'], $quotaOrderRow['city'], $quotaOrderRow['area']));
        $quotaOrderRow['goods_info'] = json_decode($quotaOrderRow['goods_array'],true);

        unset($quotaOrderRow['goods_array']);
        //支付方式
        $payment_info = DB::table('payment')->find($quotaOrderRow['pay_type']);
        $payment_info = collect($payment_info)->toArray();
        if($payment_info)
        {
            $quotaOrderRow['payment'] = $payment_info['name'] == 'Cash on delivery' ? 'Cash on Delivery' : $payment_info['name'];
            $quotaOrderRow['paynote'] = $payment_info['note'];
        }
        else{
            $quotaOrderRow['payment'] = '';
            $quotaOrderRow['paynote'] = '';
        }
        //获取配送方式
        $delivery_info = DB::table('delivery')->find($quotaOrderRow['distribution']);
        $delivery_info = collect($delivery_info)->toArray();
        if($delivery_info)
        {
            $quotaOrderRow['delivery'] = $delivery_info['name'];
        }else{
            $quotaOrderRow['delivery'] = '';
        }
        $quotaOrderRow['end_time'] = date('Y-m-d H:i:s',strtotime($quotaRow->created_time)+86400);
        $quotaOrderRow['created_time'] = date('M d,Y H:i A',strtotime($quotaRow->created_time));

        //查询是否有订单
        if($quotaOrderRow['code'] == 2){
            $orderInfo = DB::table('order')->where('quota_code', $quotaOrderRow['quota_code'])->first();
            $orderInfo = collect($orderInfo)->toArray();
            $quotaOrderRow['order_no'] = $orderInfo['order_no'];
            $quotaOrderRow['order_id'] = $orderInfo['id'];
        }else{
            $quotaOrderRow['order_no'] = '';
            $quotaOrderRow['order_id'] = '';
        }
        //查询店铺信息
        $seller_info = DB::table('seller')->find($quotaOrderRow['seller_id']);
        $quotaOrderRow['pickup_address'] = new \stdClass();
        if($quotaOrderRow['pay_type'] == 17) {
            // 自提 查出地址
            $pickup_address = json_decode($seller_info->pickup_address, true);
            $pickup_address['province'] = DB::table('areas')->where('area_id', $pickup_address['province'])->value('area_name');
            $pickup_address['city'] = DB::table('areas')->where('area_id', $pickup_address['city'])->value('area_name');
            $pickup_address['area'] = DB::table('areas')->where('area_id', $pickup_address['area'])->value('area_name');
            $quotaOrderRow['pickup_address'] = $pickup_address;
            //配送方式改成 -- --
            $quotaOrderRow['delivery'] = '--';
        }

        $quotaOrderRow['selleruin'] = $seller_info->selleruin;
        $quotaOrderRow['seller_mobile'] = $seller_info->mobile;
        $quotaOrderRow['shop_name'] = $seller_info->true_name;

        $quotaOrderRow['active_id'] = '';
        $quotaOrderRow['promo'] = '';
        if($res = Goods::getnewPromotionRowById($quotaOrderRow['goods_id'])){
            $quotaOrderRow['active_id'] = $res['id'];
            $quotaOrderRow['promo'] = 'time';
        }

        if($quotaRow = Goods::getQuotaRowBygoodsId($quotaOrderRow['goods_id'])){
            $quotaOrderRow['active_id'] = $quotaRow->quota_activity_id;
            $quotaOrderRow['promo'] = 'quota';
        }
        $quotaOrderRow['code'] = $quotaOrderRow['code'] == 4 ? 3 : $quotaOrderRow['code'];
        return $this->success($quotaOrderRow);
    }

    /***
     * author: zhangzhu
     * create_time: 2018/5/7 0007
     * description:帮助列表
     */

  	public function help_list(){
        $category_ids = DB::table('help_category')->whereRaw('type=1 and category_type = 2 and application_type = 1')->pluck('id');
        if($category_ids){
            $list = DB::table('help')->whereIn('cat_id', $category_ids)->get()->map(function($v){return (array)$v;})->toArray();
            return $this->success($list);
        }else{
            return $this->error(400,'数据错误'); //t_04
        }
    }

    /***
     * author: zhangzhu
     * create_time: 2018/5/7 0007
     * description:帮助详情
     */
    public function help_detail(Request $request){
        //字段验证
        Validator::make($request->all(), [
            'help_id' => 'required',
        ])->validate();
        $help_id = $request->help_id;  //帮助id
        $help_row = DB::table('help')->find($help_id);
        if($help_row){
            return $this->success($help_row);
        }else{
            return $this->error(400,'没找到对应记录'); //t_05
        }
    }

    /***
     * author: zhangzhu
     * create_time: 2018/5/8 0008
     * description:拼单活动期间，展示该提示文字
     */
    public function order_tips(){
        return $this->success(['message'=>'Due to higher volume of orders per product, there might be a slight delay on the  delivery.','status'=>'1']);
    }

    /***
     * @param $active_id 活动id0
    author: zhangzhu
     * create_time: 2018/5/8 0008
     * description:
     */
    public function goods_categories(Request $request){
        //字段验证
        Validator::make($request->all(), [
            'active_id' => 'required',
        ])->validate();
        $active_id = $request->active_id;

        $cate_ids = DB::table('quota_category_relation')->where('quota_activity_id', $active_id)->pluck('id')->toArray();

        $now = date('Y-m-d H:i:s');

        $where = [
            ['qg.quota_activity_id','=',$active_id],
            ['g.is_del','=',0],
            ['s.is_del','=',0],
            ['s.is_lock','=',0],
            ['qg.status','=',1],
            ['qg.is_check','=',1],
            ['qg.activity_end_time','>=',$now],
        ];

        if($cate_ids){
            $where[] = [DB::raw('iwebshop_qg.quota_category_relation_id in ('.join(',',$cate_ids).')'), '>' , DB::raw(0)];
        }

        $ids = DB::table('quota_goods as qg')
            ->select('qg.quota_category_relation_id')
            ->leftJoin('goods as g','g.id','=','qg.goods_id')
            ->leftJoin('seller as s','s.id','=','g.seller_id')
            ->leftJoin('quota_orders as qo','qo.quota_goods_id','=','qg.id')
            ->where($where)
            ->groupBy('qg.quota_category_relation_id')
            ->pluck('qg.quota_category_relation_id');

        if($ids){
            $list = DB::table('quota_category_relation')->whereIn('id', $ids)->get()->map(function($v){return (array)$v;})->toArray();
        }else{
            $list = [];
        }
        return $this->success($list);
    }
}
