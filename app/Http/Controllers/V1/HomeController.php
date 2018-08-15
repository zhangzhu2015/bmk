<?php

namespace App\Http\Controllers\V1;

use App\Htpp\Traits\ApiResponse;
use App\Librarys\Redisbmk;
use App\Models\Goods;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    use ApiResponse;

    /**
     * author: guoDing
     * createTime: 2018/7/17 0017 14:29
     * description: 首页banner图
     */
    public function banner()
    {
        $client = new Client();
        $res = $client->request('GET', env('BMK_HOST').'bapi/bannerList');
        $data =  json_decode($res->getBody());
        return $this->success($data->data);
    }

    /**
     * author: guoDing
     * createTime: 2018/7/17 0017 15:58
     * description: 首页限时抢购版块
     */
    public function flashSale(Request $request)
    {
        $this->validate($request, [
            'limit' => 'required'
        ]);

        $shopping_festivalRow = DB::table('shopping_festival')->where('is_open',1)->first();
        $activeIds = [];
        if($shopping_festivalRow){
            $activeIds = DB::table('active')->where('shopping_festival_id',$shopping_festivalRow->id)->pluck('id');
        }

        $now = Carbon::now()->toDateTimeString();
        $promotionList = DB::table('promotion as p')
            ->leftjoin('goods as go', 'p.condition', '=', 'go.id')
            ->leftjoin('seller as s', 's.id', '=', 'go.seller_id')
            ->leftjoin('pro_speed_cate as psc', 'psc.id', '=', 'p.category_id')
            ->select('p.end_time','p.max_num','p.sold_num','go.name as goods_name','go.img as img','p.name as name','p.award_value as award_value','go.id as goods_id','p.id as p_id','p.start_time','p.end_time','go.sell_price','go.market_price','go.store_nums')
            ->where('p.type', '=', 1)
            ->where('p.is_close', '=', 0)
            ->where('p.is_show', '=', 1)
            ->where('go.is_del', '=', 0)
            ->whereNotNull('go.id')
            ->where('s.is_del', '=', 0)
            ->where('s.is_lock', '=', 0)
            ->where(function ($query) {
                $query->where('p.start_time', '>', NOW())
                    ->orwhere(function ($query) {
                        $query->where('p.start_time', '<', NOW())
                            ->where('p.end_time', '>', NOW());
                    });
            })
            ->where(function ($query) use ($activeIds) {
                if ($activeIds){
                    $query->whereNotIn('psc.active_id', $activeIds);
                }
            })
            ->orderBy('p.sort', 'asc')
            ->limit($request->limit)
            ->get()
            ->map(function ($v) use ($now) {
                $v->award_value   = showPrice($v->award_value);
                $v->img           =  getImgDir($v->img);
                $v->nowtime = $now;
                return $v;
            });
        return $this->success($promotionList);
    }

    /**
     * author: guoDing
     * createTime: 2018/7/19 0019 15:50
     * description: 首页限时抢购模块see all顶部分类
     */
    public function flashSaleCate()
    {
        $cate_ids = DB::table('promotion as p')
            ->leftjoin('goods as go', 'p.condition', '=', 'go.id')
            ->leftjoin('seller as s', 's.id', '=', 'go.seller_id')
            ->where('p.type', '=', 1)
            ->where('p.is_close', '=', 0)
            ->where('p.is_show', '=', 1)
            ->where('p.end_time', '>=', NOW())
            ->whereNotNull('go.id')
            ->where('s.is_del', '=', 0)
            ->where('s.is_lock', '=', 0)
            ->where('go.is_del', '=', 0)
            ->groupBy('p.category_id')
            ->pluck('p.category_id');

        if ($cate_ids) {
            $res = DB::table('pro_speed_cate')
                ->select('id','aliasname','start_time','end_time','sort','name')
                ->whereIn('id', $cate_ids)
                ->where('end_time', '>=', NOW())
                ->where('is_show', '=', 1)
                ->orderBy('sort', 'asc')
                ->get();
            $now = Carbon::now()->toDateTimeString();


            foreach ($res as $value) {
                $temp_one[] = Carbon::parse($value->start_time)->format('M j');
                foreach ($temp_one as $k => $v) {
                    $res[$k]->etime = $v;
                }
            }


            foreach ($res as $k => $v) {
                if ($now < $v->start_time) {
                    $res[$k]->is_open = 2; //未开启
                }
                if ($v->start_time <= $now && $now <= $v->end_time) {
                    $res[$k]->is_open = 1; //开启
                }
                $res[$k]->name = htmlspecialchars_decode($v->name);
            }

            $res = collect($res)->map(function ($v) use ($now) {
                $v->nowtime = $now;
                return $v;
            })->sortBy('start_time')->values()->all();
            $data = array_values($res);
            return $this->success($data);
        } else {
            return $this->success([]);
        }
    }
    /**
     * author: guoDing
     * createTime: 2018/7/19 0019 14:52
     * description: 首页限时抢购模块see all列表数据
     */
    public function flashSaleList(Request $request)
    {
        $this->validate($request, [
            'active_id' => 'required'
        ]);
        $page = $request->input('page', 1);
        $pageSize = $request->input('pageSize', 6);
        $active_id = $request->input('active_id');

        $pscRow = DB::table('pro_speed_cate')
            ->where('id', '=', $active_id)
            ->where('is_show', '=', 1)
            ->first();
        if (!$pscRow) {
            return $this->error(400,"活动不存在");
        }
        $data = [];
        $data['title'] = $pscRow->name;
        $start_time = $pscRow->start_time;
        $end_time = $pscRow->end_time;
        $now = date('Y-m-d H:i:s', time());

        $promotionList = DB::table('promotion as p')
            ->leftJoin('goods as go', 'p.condition', '=', 'go.id')
            ->leftJoin('seller as s', 's.id', '=', 'go.seller_id')
            ->select('p.end_time','go.name as goods_name','go.img as img','p.name as name','p.award_value as award_value','go.id as goods_id','p.id as p_id','start_time','end_time','sell_price','market_price','max_num','sold_num')
            ->where('p.is_show', '=', 1)
            ->where('p.type', '=', 1)
            ->where('p.is_close', '=', 0)
            ->where('s.is_del', '=', 0)
            ->where('s.is_lock', '=', 0)
            ->where('go.is_del', '=', 0)
            ->where('category_id', '=', $active_id)
            ->whereNotNull('go.id')
            ->orderBy('p.sort', 'asc')
            ->offset(($page- 1)*$pageSize)
            ->limit($pageSize)
            ->get();

        if ($promotionList) {
            if ($now < $start_time) {
                $data['is_open'] = 2;
            } else {
                $data['is_open'] = 1;
            }
            foreach ($promotionList as $k => $v) {
                $promotionList[$k]->award_value = showPrice($promotionList[$k]->award_value);
                $promotionList[$k]->sell_price = showPrice($promotionList[$k]->sell_price);
                $promotionList[$k]->market_price = showPrice($promotionList[$k]->market_price);
                $promotionList[$k]->img = getImgDir($promotionList[$k]->img);
            }
            $data['promotionList'] = $promotionList;
            $data['start_time'] = $start_time;
            $data['end_time'] = $end_time;
        } else {
            $array = [];
            $data = (object)$array;
        }

        return $this->success($data);
    }

    /**
     * author: guoDing
     * createTime: 2018/7/18 0018 10:06
     * description: 首页拼单版块
     */
    public function groupBuy()
    {
        $qaRow = DB::table('quota_activity')
            ->select('id','name_ch','name_en','user_start_time','user_end_time','activity_type','is_open','status')
            ->where('is_open', '=', 1)
            ->where('is_open_buyer', '=', 1)
            ->where('status', '=', 1)
            ->first();
        $now = Carbon::now()->toDateTimeString();

        $lists = [];
        if ($qaRow) {
            //判断活动的有效性
            if($qaRow->user_start_time > $now){
                $qaRow->open_status = 2;  //活动未开启
            }
            if($qaRow->user_start_time <= $now &&  $now<= $qaRow->user_end_time){
                $qaRow->open_status = 2;  //活动已开启
            }
            if($now > $qaRow->user_end_time){
                $qaRow->open_status = 3;  //活动已结束
            }

            if ($now <= $qaRow->user_end_time) {
                $cate_ids = DB::table('quota_category_relation')->where('quota_activity_id', '=', $qaRow->id)->pluck('id');

                $lists = DB::table('quota_goods as qg')
                    ->leftjoin('goods as g', 'g.id', '=', 'qg.goods_id')
                    ->leftjoin('seller as s', 's.id', '=', 'g.seller_id')
                    ->leftjoin('quota_orders as qo', 'qo.quota_goods_id', '=', 'qg.id')
                    ->select('qg.id as quota_goods_id','g.img','g.id as goods_id','qg.quota_activity_name_ch','qg.quota_activity_name_en','qo.join_people',DB::raw("SUM(iwebshop_qo.join_people) as joined_people"),'qg.quota_price','g.market_price','s.is_cashondelivery','g.is_shipping','qg.quota_activity_id','qg.quota_category_relation_id','g.name as goods_name','qg.quota_sale','qg.sort')
                    ->groupBy('quota_goods_id')
                    ->where('qg.quota_activity_id', '=', $qaRow->id)
                    ->where('g.is_del', '=', 0)
                    ->where('s.is_del', '=', 0)
                    ->where('s.is_lock', '=', 0)
                    ->where('qg.status', '=', 1)
                    ->where('qg.is_check', '=', 1)
                    ->where('qg.activity_end_time', '>=', $now)
                    ->where(function ($query) use ($cate_ids) {
                        if ($cate_ids) {
                            $query->whereIn('qg.quota_category_relation_id', $cate_ids);
                        }
                    })
                    ->inRandomOrder()
                    ->limit(20)
                    ->get()->toArray();
                foreach ($lists as $k => &$v) {
                    if(!$v->joined_people) {
                        $v->joined_people = '0';
                    }
                    $v->promo = 'quota';
                }
                $lists = array_map('get_object_vars', $lists);

                $sortByCols =  function ($list,$field){
                    $sort_arr=array();
                    $sort_rule='';
                    foreach($field as $sort_field=>$sort_way){
                        foreach($list as $key=>$val){
                            $sort_arr[$sort_field][$key]=$val[$sort_field];
                        }
                        $sort_rule .= '$sort_arr["' . $sort_field . '"],'.$sort_way.',';
                    }
                    if(empty($sort_arr)||empty($sort_rule)){ return $list; }
                    eval('array_multisort('.$sort_rule.' $list);');//array_multisort($sort_arr['parent'], 4, $sort_arr['value'], 3, $list);
                    return $list;
                };

                $lists = $sortByCols($lists, array(
                    'sort' => SORT_ASC,
                    'quota_sale' => SORT_DESC,
                ));
            }
            $qaRow->nowtime = $now;
            $qaRow->list = $lists;
            return $this->success($qaRow);
        } else {
            return $this->error(400,"暂无活动");
        }
    }

    /**
     * author: guoDing
     * createTime: 2018/7/19 0019 10:43
     * description: 首页拼单模块 See All
     */
    public function groupList(Request $request)
    {
        $page = $request->input('page', 1);
        $pageSize = $request->input('pageSize', 6);
        $category_id = $request->input('category_id');
        //查询活动是否关闭
        $row = DB::table('quota_activity')->where([
            ['is_open', '=', 1],
            ['is_open_buyer', '=', 1],
            ['status', '=', 1],
            ['user_end_time', '>', NOW()]
        ])->first();

        if(!$row){
            $this->error(400,'活动已结束');
        }
        $now = date('Y-m-d H:i:s');

        $lists = DB::table('quota_goods as qg')
            ->select('qg.id as quota_goods_id','g.img','g.id as goods_id','qg.quota_activity_name_ch','qg.quota_activity_name_en',DB::raw("SUM(iwebshop_qo.join_people) as joined_people"),'qg.quota_price','g.market_price','s.is_cashondelivery','g.is_shipping','qg.quota_activity_id','g.name as goods_name','qg.quota_sale','qg.sort')
            ->leftJoin('goods as g', 'g.id', '=', 'qg.goods_id')
            ->leftJoin('seller as s', 's.id', '=', 'g.seller_id')
            ->leftJoin('quota_orders as qo', 'qo.quota_goods_id', '=', 'qg.id')
            ->where('g.is_del', '=', 0)
            ->where('s.is_del', '=', 0)
            ->where('s.is_lock', '=', 0)
            ->where('qg.status', '=', 1)
            ->where('qg.is_check', '=', 1)
            ->where('qg.activity_end_time', '>=', $now)
            ->where('qg.quota_activity_id', '=', $row->id)
            ->where(function ($q) use ($category_id) {
                if ($category_id) {
                    $q->where('qg.quota_category_relation_id', '=', $category_id);
                }
            })
            ->groupBy('qg.id')
            ->orderBy('qg.quota_sale', 'desc')
            ->offset(($page- 1)*$pageSize)
            ->limit($pageSize)
            ->get()->toArray();

        foreach($lists as $k => &$list){
            if(!$list->joined_people){
                $list->joined_people  = '0';
            }
            $list->promo = 'quota';
        }
        return $this->success($lists);
    }

    /**
     * author: guoDing
     * createTime: 2018/7/19 0019 17:37
     * description: 首页拼单版块see all 拼单滚动拼单
     */
    public function groupTipsList(Request $request)
    {
        $goods_id = $request->input('goods_id');

        //查询活动是否开启
        $qcRow = DB::table('quota_activity')
            ->where([
                ['is_open', '=', 1],
                ['is_open_buyer', '=', 1],
                ['status', '=', 1],
            ])
            ->first();

        $data = [];
        if (!$qcRow){
            return $this->success($data);
        }

        $now = date('Y-m-d H:i:s');
        if($qcRow->user_start_time <= $now &&  $now<= $qcRow->user_end_time){
            $lists = DB::table('quota_orders_detail as qod')
                ->select('u.username','u.head_ico','qod.user_id as user_id','qo.lead_user_id as own_id','g.id as goods_id','g.name','g.img','qo.people','qo.join_people','qo.quota_goods_id','qg.quota_activity_id')
                ->leftJoin('quota_orders as qo', 'qo.id', '=', 'qod.quota_orders_id')
                ->leftJoin('goods as g', 'g.id', '=', 'qo.goods_id')
                ->leftJoin('quota_goods as qg', 'qo.quota_goods_id', '=', 'qg.id')
                ->leftJoin('user as u', 'qod.user_id', '=', 'u.id')
                ->leftJoin('seller as s', 's.id', '=', 'g.seller_id')
                ->where([
                    ['qo.is_success', '=', 0],
                    ['qg.status', '=', 1],
                    ['g.is_del', '=', 0],
                    ['s.is_del', '=', 0],
                    ['s.is_lock', '=', 0],
                    ['qod.code', '=', 1],
                ])
                ->where(function ($q) use ($goods_id) {
                    if ($goods_id) {
                        $q->where('g.id', '=', $goods_id);
                    }
                })
                ->get()
                ->map(function ($v) {
                    if($v->user_id == $v->own_id){
                        $v->action = 1;
                    }else{
                        $v->action = 2;
                    }
                    return $v;
                });

            return $this->success($lists);
        }else{
            return $this->success($data);
        }
    }
    /**
     * author: guoDing
     * createTime: 2018/7/18 0018 11:44
     * description: 首页分类版块
     */
    public function categoryBlock()
    {
        $minutes = Carbon::now()->addMinutes(24*60);
        $caRows = Cache::remember('cate_active', $minutes, function() {
            return DB::table('cate_active')->where('status', '=', 1)->orderBy('sort', 'asc')->get();
        });
        $now = date('Y-m-d H:i:s', time());
        if ($caRows) {
            foreach ($caRows as $k => &$v) {
                $pcRow = Cache::remember('pro_speed_cate:'.$v->pro_cate_id, $minutes, function() use ($v) {
                    return DB::table('pro_speed_cate')->where('pro_speed_cate.id', '=', $v->pro_cate_id)->first();
                });
                $caRows->start_time = $pcRow->start_time;
                $caRows->end_time = $pcRow->end_time;
                $caRows->pro_cate_name = $pcRow->aliasname;
                $activeRow = Cache::remember('active:'.$pcRow->active_id, $minutes, function() use ($pcRow) {
                    return DB::table('active')->where('active.id', '=', $pcRow->active_id)->first();
                });
                if ($activeRow) {
                    $caRows->active_number = $activeRow->number;
                } else {
                    $caRows->active_number = 0;
                }
                if ($pcRow->start_time <= $now && $pcRow->end_time >= $now) {
                    $caRows->is_open = 1;
                }
                if ($pcRow->start_time > $now || $pcRow->end_time < $now) {
                    $caRows->is_open = 2;
                }
            }
            return $this->success($caRows);
        }
        return $this->error(400,"模块未定义");
    }

    /**
     * author: guoDing
     * createTime: 2018/7/18 0018 15:12
     * description: 首页推荐商品版块
     */
    public function getRecom(Request $request)
    {
        $page = $request->input('page', 1);
        $size = $request->input('size', 6);

        $redis = new Redisbmk();
        $get_redis = $redis->get('api_home_get_recom');
        $get_redis = json_decode($get_redis, true);

        if(count($get_redis) > 0) {
            $lst = collect($get_redis)->forPage($page, $size)->values();
            return $this->success($lst);
        }

        $commend_goods = DB::table('commend_goods as co')
            ->join('goods as go', 'co.goods_id', '=', 'go.id')
            ->join('seller as s', 's.id', '=', 'go.seller_id')
            ->where([
                ['co.commend_id', 4],
                ['go.is_del', 0],
                ['go.is_show', 1],
                ['go.store_nums', '>', 0],
                ['s.is_del', 0],
                ['s.is_lock', 0],
            ])
            ->whereNotNull('go.id')
            ->take(50)
            ->select('go.img', 'go.sell_price', 'go.name', 'go.id', 'go.market_price', 'go.sale', 'go.store_nums', 'go.is_shipping', 'go.freight', 'go.template_id', 'go.is_show', 'go.seller_id')
            ->latest('co.id')->get();

        if (count($commend_goods) >= 50) {
            // 直接咋这里去
            $goods = $commend_goods->forPage($page, $size);
        } else {
            // 不满足
            $noIdsIn = $commend_goods->pluck('id')->toArray();
            $last_ids = 50 - count($noIdsIn) > 10 ? 10 : 50 - count($noIdsIn);

            $pv_keys = $redis->keys('_goods_pv:good_id_');

            $pvs = collect($pv_keys)->map(function ($i) use ($redis) {
                $ss = explode('_', $i);
                $i = [
                    'nums' => $redis->get($i),
                    'key' => $i,
                    'goods_id' => end($ss),
                ];
                return $i;
            })->sortByDesc('nums')->values()->reject(function ($i) use ($noIdsIn) {
                return in_array($i['goods_id'], $noIdsIn);
            })->forpage(1, $last_ids);

            $views_goods = DB::table('goods as g')
                ->join('seller as s', 's.id', '=', 'g.seller_id')
                ->where([
                    ['g.is_del', 0],
                    ['g.is_show', 1],
                    ['g.store_nums', '>', 0],
                    ['s.is_del', 0],
                    ['s.is_lock', 0],
                ])
                ->whereIn('g.id', $pvs->pluck('goods_id')->toArray())
                ->select('g.img', 'g.sell_price', 'g.name', 'g.id', 'g.market_price', 'g.sale', 'g.store_nums', 'g.is_shipping', 'g.freight', 'g.template_id', 'g.seller_id')
                ->get();

            // 目前的总ids
            $noIdsIn = $commend_goods->pluck('id')->merge($views_goods->pluck('id'));

            if (count($noIdsIn) >= 50) {
                $goods = $commend_goods->merge($views_goods)->forPage($page, $size);
            } else {
                $last_num = 50 - count($noIdsIn);
                $start_time = Carbon::now()->subDays(7)->toDateTimeString();
                $last_goods = DB::table('goods as g')
                    ->leftJoin('order_goods as og', 'og.goods_id', '=', 'g.id')
                    ->leftJoin('order as o', function ($j) use ($start_time) {
                        $j->on('o.id', '=', 'og.order_id')->whereDate('o.create_time', '>', $start_time);
                    })
                    ->where('g.is_del', 0)
                    ->where('g.is_show', 1)
                    ->where('g.store_nums', '>', 0)
                    ->whereNotIn('g.id', $noIdsIn)
                    ->select(DB::raw('count(iwebshop_o.id) as count'), 'g.img', 'g.sell_price', 'g.name', 'g.id', 'g.market_price', 'g.sale', 'g.store_nums', 'g.is_shipping', 'g.freight', 'g.template_id','g.seller_id')
                    ->groupBy('g.id')->latest('count')->take($last_num)->get();
                $goods = $commend_goods->merge($views_goods)->merge($last_goods)->forPage($page, $size);
            }
        }

        $lst = $goods->values()->all();

        foreach ($lst as $k => $v) {

            $lst[$k]->active_id = '';
            $lst[$k]->promo = '';
            $lst[$k]->start_time = '';
            $lst[$k]->end_time = '';
            $res = Goods::getnewPromotionRowById($v->id);
            if($res){
                $lst[$k]->sell_price = showPrice($res->award_value);
                $lst[$k]->active_id = $res->id;
                $lst[$k]->promo = 'time';
                $lst[$k]->start_time = $res->start_time;
                $lst[$k]->end_time = $res->end_time;
            }
            $quotaRow = Goods::getQuotaRowBygoodsId($v->id);
            if($quotaRow){
                $lst[$k]->active_id = $quotaRow->quota_activity_id;
                $lst[$k]->promo = 'quota';
                $lst[$k]->start_time = $quotaRow->activity_start_time;
                $lst[$k]->end_time = $quotaRow->activity_end_time;
            }

            //获取商家信息
            $minutes = Carbon::now()->addMinutes(24*60);
            $sellerInfo = Cache::remember('seller_info_'.$v->seller_id, $minutes, function() use ($v) {
                return DB::table('seller')->where('id', '=', $v->seller_id)->first();
            });
            if ($v->template_id) {
                $tmp = Cache::remember('seller_template_'.$v->template_id, $minutes, function() use ($v) {
                    return DB::table('shipping_template')->where('id', '=', $v->template_id)->first();
                });
                if ($tmp->free_shipping == 1) {
                    $lst[$k]->is_shipping = 2;  //有运费
                } else {
                    $lst[$k]->is_shipping = 1;  //无运费
                }
            } else {
                if ($v->freight > 0) {
                    $lst[$k]->is_shipping = 2;  //有运费
                } else {
                    $lst[$k]->is_shipping = 1;
                }
            }

            $lst[$k]->is_cashondelivery = $sellerInfo->is_cashondelivery; //等于1 的时候支持货到付款
            $diff = ($lst[$k]->market_price - $lst[$k]->sell_price) / $lst[$k]->market_price;
            $lst[$k]->discount = $diff <= 0 ? 0 : number_format($diff, 2) * 100;
            $lst[$k]->img = getImgDir($lst[$k]->img);
            $lst[$k]->name = htmlspecialchars_decode($lst[$k]->name);
        }
        return $this->success($lst);
    }

    /**
     * author: guoDing
     * createTime: 2018/7/18 0018 16:13
     * description: 首页推荐店铺模块
     */
    public function getShopList(Request $request)
    {
        $limit = $request->input('limit', 6);

        $seller = DB::table('seller as s')
            ->join('goods as g', 'g.seller_id', '=', 's.id')
            ->where([
                ['s.is_del', 0],
                ['s.is_lock', 0],
                ['g.store_nums', '>', 0],
                ['s.index_show', 1],
                ['g.is_del', 0],
            ])
            ->select('s.id', 's.true_name', 's.img', 's.id', 's.recommend_value', DB::raw('count(iwebshop_g.id) as g_count'))
            ->inRandomOrder()
            ->take($limit)
            ->groupBy('s.id')->having('g_count', '>=', 3)
            ->get();

        $seller = $seller->map(function ($i) {
            // 推荐商品
            switch ($i->recommend_value) {
                case 1 :  //按销量高到低排序
                    $orderBy = 'g.sale';
                    $sort = 'desc';
                    break;
                case 2 : //根据价格从高到低排序
                    $orderBy = 'g.sell_price';
                    $sort = 'desc';
                    break;
                case 3 : //根据价格从低到高排序
                    $orderBy = 'g.sell_price';
                    $sort = 'asc';
                    break;
                case 4 : //评论数从高到低排序
                    $orderBy = 'g.comments';
                    $sort = 'desc';
                    break;
                case 5 : //根据上架时间从新到旧排序
                    $orderBy = 'g.up_time';
                    $sort = 'desc';
                    break;
                default :
            }

            $recommand = DB::table('recommand as r')->where('r.seller_id', $i->id)
                ->leftJoin('goods as g', function ($q) {
                    $q->on('g.id', '=', 'r.good_id');
                })
                ->where('g.is_del', 0)
                ->select('g.id', 'g.img', 'g.name')
                ->take(3)->orderBy($orderBy, $sort)->oldest('g.id')->get();

            if (count($recommand) === 3) {
                $i->goods = $recommand;
                return $i;
            } else {
                $recommand_ids = $recommand->pluck('id');

                $last_num = 3 - count($recommand);
                // 取用户的周销量前三
                $start_time = Carbon::now()->subDays(7)->toDateTimeString();
                $goods = DB::table('goods as g')
                    ->leftJoin('order_goods as og', 'og.goods_id', '=', 'g.id')
                    ->leftJoin('order as o', 'o.id', '=', 'og.order_id')
                    ->where('g.seller_id', $i->id)
                    ->where('g.store_nums', '>', 0)
                    ->where('g.is_del', 0)
                    ->whereNotIn('g.id', $recommand_ids)
                    ->whereDate('o.create_time', '>', $start_time)
                    ->select(DB::raw('count(iwebshop_o.id) as count'), 'g.id', 'g.img', 'g.name')->groupBy('g.id')->latest('count')->take($last_num)->get();

                // 判断满足3个的条件
                $count_now = count($recommand_ids) + count($goods);

                if ($count_now === 3) {
                    $i->goods = $recommand->merge($goods);
                    return $i;
                } else {

                    $notInIds = $recommand_ids->merge($goods->pluck('id'));
                    $last_num = 3 - $count_now;

                    $goods2 = DB::table('goods')
                        ->where('is_del', 0)
                        ->where('seller_id', $i->id)
                        ->where('store_nums', '>', 0)
                        ->whereNotIn('id', $notInIds)
                        ->inRandomOrder()
                        ->select('id', 'img', 'name')
                        ->take($last_num)
                        ->get();

                    $i->goods = $recommand->merge($goods)->merge($goods2);
                    return $i;
                }
            }
        });

        $seller = $seller->map(function ($i) {
            $i->goods = $i->goods->map(function ($ii) {
                $temp = DB::table('promotion as p')
                    ->where('p.condition', $ii->id)
                    ->where('p.type', 1)
                    ->where('p.is_close', 0)
                    ->whereDate('p.end_time', '>=', DB::raw('NOW()'))
                    ->select('p.id as promotion_id', DB::raw("if(iwebshop_p.id = null, null, 'time') as promotion"))->first();
                if (!empty($temp)) {
                    $ii->promotion_id = $temp->promotion_id;
                    $ii->promotion = $temp->promotion;
                }
                return $ii;
            });
            return $i;
        });
        return $this->success($seller);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * author: guoDing
     * createTime: 2018/8/9 0009 09:54
     * description: 首页分类推荐品牌
     */
    public function getRecommendedBrand(Request $request)
    {
        $this->validate($request, [
            'category_id' => 'required|integer'
        ]);

        $limit = $request->input('limit', 15);

        $result = array();
        $cate = DB::table('brand_category')
            ->where('goods_category_id',$request->input('category_id'))
            ->get()
            ->toArray();
        $cate = array_map('get_object_vars', $cate);

        if (!$cate) {
            $this->error("没有数据",404);
        }
        foreach ($cate as $k => $v) {
            $list = DB::table('brand')->whereRaw("FIND_IN_SET(".$v['id'].",category_ids) and status=1 ")
                ->select('logo', 'name', 'url', 'id')
                ->orderBy('sort', 'asc')
                ->get()
                ->map(function ($v) {
                    $v->logo = getImgDir($v->logo);
                    return $v;
                })
                ->toArray();

            $list = array_map('get_object_vars', $list);

            $result = array_merge($result,$list);
            if(count($result) >= $limit)
            {
                $result = array_slice($result, 0, $limit);
                break;
            }
        }
        return $this->success($result);
    }

}
