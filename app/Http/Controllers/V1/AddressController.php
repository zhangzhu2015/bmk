<?php

namespace App\Http\Controllers\V1;

use App\Htpp\Traits\ApiResponse;
use App\Http\Requests\V1\AddressRequest;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    //
    use ApiResponse;

    /**
     * @param $address_id
     * @return mixed
     * CreateTime: 2018/7/19 17:39
     * Description: 设置默认地址
     */
    public function setDefault($address_id) {

        $address = DB::table('address')->where('user_id', Auth::id())->where('id', $address_id)->first();
        if (!$address) {
            return $this->error(301, "Shipping address does't exist");
        }

        DB::beginTransaction();
        try {
            DB::table('address')->where('user_id', Auth::id())->update([
                'is_default'=> 0
            ]);
            DB::table('address')->where('user_id', Auth::id())->where('id', $address_id)->update([
                'is_default'=> 1
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
        }
        DB::commit();
        return $this->success([]);

    }

    /**
     * @param \App\Http\Requests\V1\AddressRequest $request
     * @return mixed
     * CreateTime: 2018/7/19 17:48
     * Description: 新增地址
     */
    public function store(AddressRequest $request) {
        $arr = [];
        $arr['user_id'] = Auth::id();
        foreach ($request->all() as $k => $v) {
            $arr[$k] = $v;
        }

        $address_id = DB::table('address')->insertGetId($arr);
        return $this->success(['address'=> DB::table('address')->find($address_id)]);
    }

    /**
     * @param \App\Http\Requests\V1\AddressRequest $request
     * @param                                      $address_id
     * @return mixed
     * CreateTime: 2018/7/19 17:54
     * Description: 编辑地址
     */
    public function update(AddressRequest $request, $address_id) {
        $address = DB::table('address')->where('user_id', Auth::id())->where('id', $address_id)->first();
        if (!$address) {
            return $this->error(301, "Shipping address does't exist");
        }

        $arr = [];

        $temp = ['accept_name', 'province', 'city', 'area', 'address', 'zip', 'telphone', 'mobile', 'is_default', 'email'];
        foreach ($request->all() as $k => $v) {
            if (in_array($k, $temp)) {
                $arr[$k] = $v;
            }
        }

        DB::table('address')->where('user_id', Auth::id())->where('id', $address_id)->update($arr);
        return $this->success(['address'=> DB::table('address')->find($address_id)]);
    }

    public function delete($address_id) {
        $a = DB::table('address')->where('user_id', Auth::id())->where('id', $address_id)->delete();
        if ($a)
            return $this->success([]);
        return $this->error(301, []);
    }

}
