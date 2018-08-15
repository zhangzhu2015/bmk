<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    //
    protected $guarded = [];
    protected $table = 'category';

    public $timestamps = false;

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
            $temp = $this->whereRaw('parent_id = '.$id)->select();
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
