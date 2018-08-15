<?php

namespace App\Http\Controllers\V1;

use App\Htpp\Traits\ApiResponse;
use App\Models\Help;
use App\Models\HelpCategory;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class HelpController extends Controller
{
    //
    use ApiResponse;

    /**
     * @return mixed
     * Author: LF
     * CreateTime: 2018/4/27 9:47
     * Description: 获取帮助中心头部信息
     */
    public function header_info()
    {
        $logo = config('bmk.logo_url');
        $link = config('bmk.link');

        return $this->success([
            'logo' => $logo,
            'link' => $link,
        ]);
    }

    /**
     * @return mixed
     * Author: LF
     * CreateTime: 2018/4/27 19:38
     * Description: 获取帮助中心底部信息
     */
    public function footer() {
        $text = config('bmk.foot_text');
        $tel = config('bmk.foot_tel');

        return $this->success([
            'text' => $text,
            'tel' => $tel,
        ]);
    }

    /**
     * @param $type
     * @return mixed
     * Author: LF
     * CreateTime: 2018/4/27 9:47
     * Description:  获取帮助中心常见问题
     */
    public function hot_questions($type) {
        if(!isset(config('bmk.help_type')[$type])){
            return $this->error(400, '请输入正确的参数');
        }
        $res = Help::where('type', config('bmk.help_type')[$type])->select('id', 'name')->oldest('like', 'sort')->get()->take(10);
        return $this->success($res);
    }


    /**
     * @param $type
     * @return mixed
     * Author: LF
     * CreateTime: 2018/4/27 10:04
     * Description: 获取帮助列表的信息
     */
    public function categories($type) {
        if(!isset(config('bmk.help_type')[$type])){
            return $this->error(400, '请输入正确的参数');
        }
        $cate = HelpCategory::where('type', config('bmk.help_type')[$type])->select('id', 'name', 'font_class')->latest('sort')->get();
        return $this->success($cate);
    }

    /**
     * @param     $type
     * @param int $cate_id
     * @return mixed
     * Author: LF
     * CreateTime: 2018/4/27 10:08
     * Description: 获取帮助列表 ?page=1&size=10
     */
    public function lists(Request $request, $type, $cate_id = 0) {
        if(!isset(config('bmk.help_type')[$type])){
            return $this->error(400, '请输入正确的参数');
        }

        $page = $request->get('page') ? : 1;
        $size = $request->get('size') ? : 10;

        $when = Help::where('type', config('bmk.help_type')[$type])->when($cate_id > 0 , function ($q) use ($cate_id) {
            $q->where('cat_id', $cate_id);
        });

        $count = $when->count();

        $lists = $when->forpage($page, $size)->select('id', 'name')->oldest('sort')->get();

        return $this->success([
            'lists'=> $lists,
            'count'=> $count,
        ]);
    }

    /**
     * @param $type
     * @param $keyword
     * @return mixed
     * Author: LF
     * CreateTime: 2018/4/27 19:37
     * Description: 搜索帮助列表
     */
    public function search(Request $request,$type, $keyword) {
        if(!isset(config('bmk.help_type')[$type])){
            return $this->error(400, '请输入正确的参数');
        }

        $page = $request->get('page') ? : 1;
        $size = $request->get('size') ? : 10;

        $when = Help::where('type', config('bmk.help_type')[$type])->where('name', 'like', "%{$keyword}%");

        $count = $when->count();
        $res = $when->select('id', 'name')->oldest('sort')->forpage($page, $size)->get();

        return $this->success([
            'res'=> $res,
            'count'=> $count,
            'keyword'=> $keyword,
        ]);
    }

    /**
     * @param $id
     * @return mixed
     * Author: LF
     * CreateTime: 2018/4/27 19:38
     * Description: 获取帮助详情
     */
    public function detail($id) {
        $help = Help::select('id', 'name', 'content', 'dateline')->findOrFail($id);
        return $this->success($help);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param                          $id
     * @return mixed
     * Author: LF
     * CreateTime: 2018/4/27 19:38
     * Description: 点赞or点down
     */
    public function update(Request $request, $id) {
        if(Help::where('id', $id)->increment($request->name))
            return $this->success([], 201);
        return $this->error(400, '操作失败');
    }
}
