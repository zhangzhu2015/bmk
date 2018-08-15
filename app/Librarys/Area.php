<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/7/25 0025
 * Time: 17:39
 */

namespace App\Librarys;


use Illuminate\Support\Facades\DB;

class Area
{
    public function name()
    {
        $result     = array();
        $paramArray = func_get_args();
        $areaData   = DB::table('areas')
            ->whereIn('area_id', trim(join(",",$paramArray),","))
            ->get();
        $areaData = array_map('get_object_vars', $areaData);
        $areaData2  = array_reverse($areaData);//按国外的地址顺序输出
        foreach($areaData2 as $key => $value)
        {
            $result[$value['area_id']] = $value['area_name'];
        }
        return $result;
    }
}