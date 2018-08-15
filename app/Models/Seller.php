<?php

namespace App\Models;

use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Seller extends Authenticatable
{
    use Notifiable;

    protected $table = 'seller';
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $guarded = [];


    /***
     * @param $seller_id
     * @return array
     * author: zhangzhu
     * create_time: 2018/7/17 0017
     * description:获取店铺评分
     */
    static public function getPointOfSeller($seller_id)
    {
        //计算店铺的商品质量分
        $comments = DB::table('comment')
            ->select(DB::raw('count(*) as num'), 'point')
            ->where([
                ['seller_id', '=', $seller_id],
                ['status', '=', 1]
            ])
            ->groupBy('point')
            ->get()
            ->map(function ($value) {
                return (array)$value;
            })->toArray();

        $config = array(0 => 'none', 1 => 'bad', 2 => 'middle', 3 => 'middle', 4 => 'middle', 5 => 'good');
        $point_grade = array('none' => 0, 'good' => 0, 'middle' => 0, 'bad' => 0);
        $point_total = 0;
        foreach ($comments AS $value) {
            if ($value['point'] >= 0 && $value['point'] <= 5) {
                $point_total += $value['point'] * $value['num'];
                $point_grade[$config[$value['point']]] += $value['num'];
            }
        }
        $comment_total = array_sum($point_grade);
        $data = [];
        if ($point_total > 0) {
            $data['ratting'] = number_format($point_total / $comment_total, 1);
        } else {
            $data['ratting'] = '5.0';
        }
        //获取店铺评论的订单数
        $point_num = DB::table('comment')
            ->where([
                ['seller_id', '=', $seller_id],
                ['status', '=', 1],
            ])
            ->count();
        //获取店铺信息
        $seller = self::where('id', $seller_id)->first();
        //计算店铺服务分和配送速度
        if ($point_num > 0) {
            $data['service_ratting'] = (number_format($seller['service_grade'] / count($point_num), 1) > 5 ? number_format(5, 1) : number_format($seller['service_grade'] / count($point_num), 1));
            $data['delivery_speed_ratting'] = (number_format($seller['delivery_speed_grade'] / count($point_num), 1) > 5 ? number_format(5, 1) : number_format($seller['delivery_speed_grade'] / count($point_num), 1));
        } else {
            $data['service_ratting'] = '5.0';
            $data['delivery_speed_ratting'] = '5.0';
        }
        return $data;
    }


    /***
     * @param $seller_id 商家id
     * @param $user_id 用户id
     * @return array
     * author: zhangzhu
     * create_time: 2018/7/17 0017
     * description:获取优惠券列表
     */

    static public function getInfobyseller($seller_id, $user_id)
    {

        $nowtime = date("Y-m-d");
        //查询当前可以领取的店铺优惠券（包括已经领取的）
        $voucherRow = DB::table('voucher')->where([
            ['seller_id', '=', $seller_id],
            ['status', '=', 2],
            ['end_time', '=', $nowtime],
        ])->get()->map(function ($value) {
            return (array)$value;
        })->toArray();

        if ($voucherRow) {
            foreach ($voucherRow as $k => &$v) {
                //如果已经领取 判断 是否还可以领取(小于最大领取量 且 购物券还有剩余)
                $temp_count = DB::table('voucher_str')->where('voucher_id', $v['id'])->count();
                $shenyu = (int)$v['number'] - $temp_count;
                if ($shenyu) {
                    switch ($v['type']) {
                        case "1" :
                            $v['show_value'] = showPrice($v['value']);
                            $v['coupon_type'] = 'Shop coupon';
                            $v['coupons_conditions'] = 'Apply if Total Cart Value reaches ₱' . $v['value'] . '（not contain postage）';
                            break;
                        case "3" :
                            $v['show_value'] = showPrice($v['value']);
                            $v['coupon_type'] = 'Specific product';
                            $v['coupons_conditions'] = 'Apply if Total Cart Value reaches₱' . $v['value'];
                            break;
                        case "2" :
                            $v['show_value'] = intval($v['value']) . '%';
                            $v['coupon_type'] = 'Shop coupon';
                            $v['coupons_conditions'] = 'Apply if Total Cart Value reaches ₱' . $v['value'] . '（not contain postage）';
                            break;
                        case '4':
                            $v['show_value'] = 'Free shipping';
                            $v['coupon_type'] = 'Shop coupon';
                            if ($v['limit'] == 1) {
                                $v['coupons_conditions'] = 'Apply if Total Cart Value reaches ₱' . $v['value'];
                            } else {
                                $v['coupons_conditions'] = 'Orders are full of ' . $v['value'] . ' items to be used';
                            }
                            break;
                        default:
                            break;
                    }
                    $voucherstrInfo = DB::table('voucher_str')->where(array(['user_id', '=', $user_id], ['voucher_id', '=', $v['id']]))->get()->map(function ($value) {
                        return (array)$value;
                    })->toArray();
                    if ($voucherstrInfo) {
                        if (count($voucherstrInfo) < $v['receive_number']) {
                            $v['get_status'] = 1;  //已经领取，还能继续领取
                        } else {
                            $v['get_status'] = 2;  //已经领取,不能领取
                        }
                    } else {
                        $v['get_status'] = 3; //没领取过，可以领取
                    }
                } else {
                    unset($voucherRow[$k]);
                }
            }
        }
        return $voucherRow;
    }


    /***
     * @param $seller_id
     * @return int
     * author: zhangzhu
     * create_time: 2018/7/17 0017
     * description:获取店铺可以使用的优惠券 过滤掉已经抢光的
     */
    static public function getInfocount($seller_id)
    {
        return 0;
        $nowtime = date("Y-m-d");
        $where = array(['v.seller_id', '=', $seller_id], ['v.status', '=', 2], ['v.end_time', '>=', $nowtime]);
        $count = DB::table('voucher as v')
            ->select('v.id as id', DB::raw('count(*) as count'), 'v.number as number')
            ->leftJoin('voucher_str as vs', 'v.id', '=', 'vs.voucher_id')
            ->groupBy('v.id')
            ->where($where)
            ->having('count < number')
            ->count();
        return $count;
    }


    static public function getSpeedShip($seller_id)
    {
        //获取已经发货的订单
        $where = 'seller_id =' . $seller_id . ' and send_time is not null';
        $orders = DB::table('order')->whereRaw($where)->get();
        if (!$orders)
            return "";
        //统计订单发货时间
        $res = [];
        foreach ($orders as $k => $v) {
            $paymentlistofflineid = [16];
            //如果付款方式为货到付款
            if (in_array($v->pay_type, $paymentlistofflineid)) {
                $res[] = strtotime($v->send_time) - strtotime($v->create_time);
            } else {
                $res[] = strtotime($v->send_time) - strtotime($v->pay_time);
            }
        }
        $sum = array_sum($res);
        $num = count($res);
        $result = date('G:i:s', ceil($sum / $num));
        return $result;
    }

    static public function getUnshippedNums($seller_id)
    {
        $paymentlistofflineid = [16];

        $where = '(seller_id =' . $seller_id . ' and if_del = 0) and (status = 1 and pay_type in (' . join(',', $paymentlistofflineid) . ') and distribution_status = 0)';
        $orders = DB::table('order')->whereRaw($where)->get();

        $orders1 = DB::table('order')->whereRaw('seller_id =' . $seller_id . ' and if_del = 0 and status = 2 and distribution_status = 0')->get();
        $order_total = $orders->merge($orders1)->toArray();
        if (!$order_total) {
            return 0;
        }
        $ids = array_column($order_total, 'id');
        $count = DB::table('order as o')
            ->select('o.id as id')
            ->leftJoin('order_goods as og', 'og.order_id', '=', 'o.id')
            ->whereIn('o.id', $ids)
            ->whereNotNull('og.id')
            ->groupBy('o.id')
            ->get();

        //统计订单为发货数量
        return count($count);
    }

    static public function getPayment($sellerarray)
    {
        $payment = [];
        foreach ($sellerarray as $seller_id => $v) {
            $seller_info = DB::table('seller')->where('id', '=', $seller_id)->first();

            $payment[$seller_id] = DB::table('payment')
                ->where('status', '=', 0)
                ->when($seller_info->is_cashondelivery == 1, function ($query) {
                    $query->where('id', '=', 16);
                }, function ($query) {
                    $query->where('id', '<>', 16);
                })
                ->when($seller_info->is_pickup == 1, function ($query) {
                    $query->orwhere('id', '=', 17);
                }, function ($query) {
                    $query->where('id', '<>', 17);
                })
                ->orderBy('order', 'asc')
                ->get()
                ->map(function ($v) {
                    return (array)$v;
                })
                ->toArray();

            foreach ($payment[$seller_id] as $k => $v) {
                $payment[$seller_id][$k]['pickup_address'] = (object)array();
                if ($v['id'] == 17) {
                    $pickup_address = json_decode($seller_info->pickup_address, true);
                    $pickup_address['province'] = DB::table('areas')->where('area_id', '=', $pickup_address['province'])->value('area_name');
                    $pickup_address['city'] = DB::table('areas')->where('area_id', '=', $pickup_address['city'])->value('area_name');
                    $pickup_address['area'] = DB::table('areas')->where('area_id', '=', $pickup_address['area'])->value('area_name');
                    $payment[$seller_id][$k]['pickup_address'] = $pickup_address;
                }
            }
        }
        return $payment;
    }

    static function getSubCate($pid,$seller_id)
    {
        $array[] = $pid;
        do{
            $cate = array();
            $res = DB::table('seller_category')
                ->where('seller_id', '=', $seller_id)
                ->where('visibility', '=', 1)
                ->where('parent_id',1212)
                ->select('name', 'id')
                ->orderBy('sort', 'asc')
                ->get()
                ->map(function ($v) {
                    return (array)$v;
                })->toArray();
            if ($res) {
                foreach ($res as $k1 => $v1){
                    $cate[$v1['id']] = $v1['name'];
                    $array[] = $v1['id'];
                }
                $pid = join(",", array_keys($cate));
            }
        } while (!empty($res));

        return $array;
    }
}

?>