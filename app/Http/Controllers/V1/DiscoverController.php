<?php

namespace App\Http\Controllers\V1;

use App\Htpp\Traits\ApiResponse;
use Validator;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class DiscoverController extends Controller
{
    //
    use ApiResponse;

    /***
     * @param Request $request page 页码 size 每页显示条目
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/23 0023
     * description: 动态列表
     */
    public function getList(Request $request)
    {
        //查询用户关注的列表
        $user_id = auth()->id();
        $page = $request->page ?: 1;
        $size = $request->size ?: 6;
        $sellerIdArray = DB::table('seller_user_fav')->whereRaw("user_id = ".$user_id)->pluck("seller_id");
        $list = DB::table('discover as d')
                    ->leftJoin('seller as s','d.seller_id','=','s.id')
                    ->whereIn('d.seller_id',$sellerIdArray)
                    ->select('d.*','s.true_name','s.img as shop_img')
                    ->forPage($page, $size)
                    ->get()
                    ->map(function($v){
                        return (array)$v;
                    })->toArray();
        if($list){
            foreach($list as $k=>$v){
                if($v['shop_img']){
                    $list[$k]['shop_img'] = getImgDir($v['shop_img'],'','');
                }
                $imgArray = [];
                if($v['img']){
                    $img = explode(',',$v['img']);
                    foreach($img as $k2=>$v2){
                        $imgArray[] = getImgDir($v2,'','');
                    }
                }
                $list[$k]['img'] = $imgArray;
                //查找所有评论
                $list[$k]['comment_num']= DB::table('discover_comment')->whereRaw('article_id='.$v['id'])->count();
                $list[$k]['upvote_num']= DB::table('discover_upvote')->whereRaw('discover_id ='.$v['id'])->count();
            }
        }
        return $this->success($list);
    }

    /***
     * @param Request $request discover_id 动态id  comment_id 评论id  contents 评论内容
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/23 0023
     * description: 评论动态
     */
    public function comment(Request $request){
        $user_id = auth()->id();

        //字段验证
        Validator::make($request->all(), [
            'contents' => 'required',
        ])->validate();

        $comment_id = request('comment_id', 0);
        $article_id = request('discover_id', 0);
        $contents = $request->contents;
        $parent = DB::table('discover_comment')->where('id', $comment_id)->first();
        $data = [
            'seller_id' => 0,
            'user_id' => $user_id,
            'comm_cont' => $contents,
            'comm_time' => date('Y-m-d H:i:s',time()),
        ];
        if($article_id){
            $data['article_id'] = $article_id;
            $data['parent_id'] = 0;
        }else{
            $data['article_id'] = $parent['article_id'];
            $data['parent_id'] = $comment_id;
        }
        $id = DB::table('discover_comment')->insertGetId($data);
        if($article_id && $id){
            $userRow = DB::table('user')->select('username','head_ico')->first($user_id);
            $data['username'] = $userRow->username;
            $data['user_img'] = getImgDir($userRow->head_ico,'','');
            $data['id'] = $id;
        }
        return $this->success($data);
    }


    /***
     * @param Request $request discover_id 动态id
     * @return mixed
     * author: zhangzhu
     * create_time: 2018/7/23 0023
     * description: 动态点赞
     */
    public function upvote(Request $request){
        $user_id = auth()->id();
        //字段验证
        Validator::make($request->all(), [
            'discover_id' => 'required',
        ])->validate();
        $discover_id = $request->discover_id;
        $res = DB::table('discover_upvote')->whereRaw('discover_id ='.$discover_id.' and user_id='.$user_id)->count();
        if($res){
            return $this->error(400,'你已经点赞过了!'); //t_03
        }else{
            $result = DB::table('discover_upvote')->insert([
                'discover_id' =>$discover_id, 'user_id' => $user_id
            ]);
            if($result){
                $count = DB::table('discover_upvote')->where('discover_id ='.$discover_id)->count();
                return $this->success(['count'=>$count]);
            }else{
                return $this->error(400,'点赞失败!'); //t_08
            }
        }
    }

    /***
     * @param Request $request
     * author: zhangzhu
     * create_time: 2018/7/23 0023
     * description: 获取评论（回复）
     */
    public function getComments(Request $request)
    {
        Validator::make($request->all(), [
            'discover_id' => 'required',
        ])->validate();
        $discover_id = $request->discover_id;
        $seller_id = DB::table('discover')->where('id', $discover_id)->value('seller_id');
        $sellerRow = DB::table('seller')->where('id', $seller_id)->first();
        $page = $request->page ?: 1;
        $size = $request->size ?: 6;

        //所有楼层
        $comments = DB::table('discover_comment')
                    ->where([
                        ['article_id', '=', $discover_id],
                        ['parent_id', '=', 0]
                    ])
                    ->orderBy('comm_time','asc')
                    ->forPage($page,$size)
                    ->get()
                    ->map(function($v){return (array)$v;})
                    ->toArray();

        $comms = DB::table('discover_comment')
                 ->where('article_id',$discover_id)
                 ->orderBy('comm_time', 'asc')
                 ->get()
                 ->map(function($v){return (array)$v;})
                 ->toArray();

        if($comments){
            foreach($comments as $k1=>$v1){
                if($v1['user_id']){
                    $userInfo = DB::table('user')->select('username','head_ico')->first($v1['user_id']);
                    $comments[$k1]['user_img'] = getImgDir($userInfo->head_ico,'','');;
                    $comments[$k1]['username'] = $userInfo->username;
                }
                //查询所有下级  （回复）
                $tmp = [];
                $children = $this->findChildren($v1['id'],$comms,$tmp);
                foreach($children as $k=>$v){
                    //查询父级
                    $parent = DB::table('discover_comment')->first($v['parent_id']);
                    //取出被回复的人的名字
                    if($parent->seller_id){
                        $parent_name = $sellerRow->true_name;
                    }else{
                        if($parent->user_id){
                            $userRow = DB::table('user')->select('username')->first($parent['user_id']);
                            $parent_name = $userRow->username;
                        }else{
                            $parent_name = $sellerRow->true_name;
                        }
                    }
                    //回复人的名字
                    if($v['seller_id']){
                        $children[$k]['reply_person'] = $sellerRow->true_name;
                        $children[$k]['returned_person'] = $parent_name;
                    }else{
                        $userRow = DB::table('user')->select('username')->first($parent['user_id']);
                        $children[$k]['reply_person'] = $userRow->username;
                        $children[$k]['returned_person'] = $parent_name;
                    }
                }
                $comments[$k1]['children'] = $children;
            }
        }
        return $this->success($comments);
    }

    //递归查找所有评论下的回复
    protected function findChildren($comment_id,$comments,&$res=[])
    {
        $res = [];
        foreach($comments as $k=>$v){
            if($v['parent_id'] == $comment_id){
                $res[] = $v;
                $this->findChildren($v['id'],$comments);
            }
        }
        return $res;
    }
}
