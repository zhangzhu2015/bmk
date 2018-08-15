<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopCategory extends Model
{
    //
    protected $guarded = [];
    protected $table = 'seller_category';

    public $timestamps = false;

    /**
     * @brief 获取子分类可以无限递归获取子分类
     * @param int $catId 分类ID
     * @param int $level 层级数
     * @return string 所有分类的ID拼接字符串
     */
    public function catChild($catId,$level = 1)
    {
        if($level == 0)
        {
            return $catId;
        }

        $temp   = array();
        $result = array($catId);

        while(true)
        {
            $id = current($result);
            if(!$id)
            {
                break;
            }
            $temp = $this->whereRaw('parent_id = '.$id)->get();
            foreach($temp as $key => $val)
            {
                if(!in_array($val['id'],$result))
                {
                    $result[] = $val['id'];
                }
            }
            next($result);
        }
        return join(',',$result);
    }
}
