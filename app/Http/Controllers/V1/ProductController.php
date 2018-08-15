<?php

namespace App\Http\Controllers\V1;

use App\Models\Goods;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function getSpecValue($goods_id) {
        $img_spec_id = DB::table('goods')->where('id', $goods_id)->value('img_spec_id');
        $products = DB::table('products as pro')
            ->leftJoin('promotion as p', function($join) use ($goods_id) {
                $join->on('p.condition', '=', 'pro.goods_id')
                    ->where([
                        ['type', 1],
                        ['is_close', 0],
                        ['end_time', '>=',  date('Y-m-d H:i:s')],
                        ['start_time', '<=',  date('Y-m-d H:i:s')],
                    ]);
            })
            ->where('pro.goods_id', $goods_id)->select('pro.spec_array', 'pro.id', 'pro.sell_price', 'pro.store_nums', 'p.award_value')->get();

        $spec = $products->pluck('spec_array');

        $specArr = [];
        foreach ($spec as $k => $v){
            $temp = json_decode($v,true);

            foreach ($temp as $kk => $vv){
                $specArr[$k.'_'.$kk]['id'] = $vv['id'];
                $specArr[$k.'_'.$kk]['name'] = $vv['name'];
                $specArr[$k.'_'.$kk]['type'] = $vv['type'];
                $specArr[$k.'_'.$kk]['value'] = $vv['value'];
            }
        }
        $specArr = collect($specArr)->groupBy('id');
        $t = [];
        foreach ($specArr as $k => $v){
            $value = [];
            foreach ($v as $kk => $vv){
                $id = $vv['id'];
                $name = $vv['name'];
                $type = $vv['type'];
                if($id  == $img_spec_id){
                    $va = DB::table('spec_img')->where([
                        ['spec_id', $id],
                        ['spec_value', $vv['value']],
                    ])->value('img');
                    if($va){
                        $value[] = getImgDir($va);
                    }else{
                        $value[] = $vv['value'];
                    }
                }else{
                    $value[] = $vv['value'];
                }
            }
            $t[$k]['id'] = $id;
            $t[$k]['name'] = $name;
            $t[$k]['type'] = $type;
            $t[$k]['value'] = collect(array_unique($value))->values();
        }

        $t = collect($t)->values();


        $quota = Goods::getQuotaRowBygoodsId($goods_id);
        $quota_arr = [];
        if($quota) {
            $quota_arr = json_decode($quota->product_detail, true);
        }

        $products = $products->map(function($i)use ($quota_arr, $img_spec_id){
            $temp['goodsID'] = $i->id;
            $temp['sell_price'] = $i->award_value ? $i->award_value : $i->sell_price;

            if(count($quota_arr) === 0) {
                $temp['store_nums'] = $i->store_nums;
            }else{
                $quota_info = $quota_arr[$i->id];

                if($quota_info['is_quota'] == 1) {
                    $temp['store_nums'] = $i->store_nums;
                }else{
                    $temp['store_nums'] = 0;
                }
            }

            $temp['goodsInfo'] = collect(json_decode($i->spec_array, true))->map(function($ii)use ($img_spec_id){
                if((int)$ii['id'] === (int)$img_spec_id){
                    $va = DB::table('spec_img')->where([
                        ['spec_id', $ii['id']],
                        ['spec_value', $ii['value']],
                    ])->value('img');
                    if($va){
                        $ii['value'] = getImgDir($va);
                    }
                }
                unset($ii['type']);
                return $ii;
            });

            return $temp;
        });

        return  [
            'attributes'=> $t,
            'stockGoods'=> $products,
        ];

    }

}
