<?php
/**
 * CloudSearch.php
 * ==============================================
 * Copy right 2017 http://www.bigmk.ph/
 * ----------------------------------------------
 * This is not a free software, without any authorization is not allowed to use and spread.
 * ==============================================
 * @desc: 亚马逊搜索引擎
 * @author: guoding
 * @date: 2017年9月30日
 */
namespace App\Librarys;

use App\Librarys\Redisbmk;
use App\Models\Category;
use App\Models\ShopCategory;
use Illuminate\Support\Facades\DB;

class CloudSearch{
    
    public function search($paramsearch){
        $page               = isset($paramsearch['page']) ? $paramsearch['page'] : '';
        $size               = isset($paramsearch['size']) ? $paramsearch['size'] : 10;
        $order_by           = isset($paramsearch['order_by']) ? $paramsearch['order_by'] : '';
        $order_type         = isset($paramsearch['order_type']) ? $paramsearch['order_type'] : '';
        $cate_id            = isset($paramsearch['cate_id']) ? $paramsearch['cate_id'] : '';
        $search             = isset($paramsearch['search']) ? $paramsearch['search'] : '';
        $b_id               = isset($paramsearch['b_id']) ? $paramsearch['b_id'] : '';
        $seller_id          = isset($paramsearch['seller_id']) ? $paramsearch['seller_id'] : '';
        $seller_category_id = isset($paramsearch['seller_category_id']) ? $paramsearch['seller_category_id'] : '';
        $min_price          = isset($paramsearch['min_price']) ? $paramsearch['min_price'] : '';
        $max_price          = isset($paramsearch['max_price']) ? $paramsearch['max_price'] : '';
        $instock            = isset($paramsearch['instock']) ? $paramsearch['instock'] : '';//是否有库存
        $is_shipping        = isset($paramsearch['is_shipping']) ? $paramsearch['is_shipping'] : '';
        $type               = isset($paramsearch['type']) ? $paramsearch['type'] : '';//专有用途类别

        $params = [];
        if (!$search) {
            $params['q.parser'] = "structured";
            $search = 'matchall';
        }else{
            $params['q.parser'] = "dismax";
            $params['q.options'] = "{defaultOperator: 'and',fields:['name^30']}";
        }

        if ($type == 'goodsSimilar') {
            $params['q.parser'] = "dismax";
            $params['q.options'] = "{defaultOperator: 'or',phraseFields:['name^5']}";
        }
        $params['size'] = $size;

        if ($page) {
            $params['start'] = ($page - 1)*$size;
        }else{
            $params['start'] = 0;
        } 
        // 权重排序
        $params['expr.score_sale'] = "_score+sale";
        // 分类筛选
        //$params['facet.category_id'] = "{sort:\"bucket\", size:10}";
        
        $fq = 0;
        //分类
        if ($cate_id) {
            $categoryDB = new Category();
            $childId = $categoryDB->catChild($cate_id);
            if (strpos($childId,",") !== false) {
                $childId = explode(",",$childId);
            }
            if (is_array($childId)) {
                $center = '';
                foreach ($childId as $key => $value) {
                    $center .= "(term field=category_id '".$value."')";
                }   
                $fq_category_id = "(or ".$center." )";
            } else {
                $fq_category_id = "(term field=category_id '".$childId."')";
            }
            $fq++;
        }
        //品牌
        if ($b_id) {
            $fq_brand_id = "(term field=brand_id '".$b_id."')";
            $fq++;
        } else {
            $fq_brand_id = '';
        }
        //卖家
        if ($seller_id){
            $fq_seller_id = "(term field=seller_id '".$seller_id."')";
            $fq++;
        } else {
            $fq_seller_id = '';
        }
        if ($seller_category_id){
            $categoryDB = new ShopCategory();
            $childId = $categoryDB->catChild($cate_id);
            if (strpos($childId,",") !== false) {
                $childId = explode(",",$childId);
            }
            if (is_array($childId)) {
                $center = '';
                foreach ($childId as $key => $value) {
                    $center .= "(term field=seller_category '".$value."')";
                }
                $fq_seller_category_id = "(or ".$center." )";
            } else {
                $fq_seller_category_id = "(term field=seller_category '".$childId."')";
            }
            $fq++;
        } else {
            $fq_seller_category_id = '';
        }
        //价格区间
        $fq_rang = '';
        if ($min_price && !$max_price) {
            $fq_rang = "(range field=sell_price [".$min_price.",})";
            $fq++;
        }
        if (!$min_price && $max_price) {
            $fq_rang = "(range field=sell_price {,".$max_price."])";
            $fq++;
        }
        if ($min_price && $max_price) {
            $fq_rang = "(range field=sell_price [".$min_price.",".$max_price."])";
            $fq++;
        }
        //库存
        $fq_instock = '';
        if ($instock) {
            $fq_instock = "(range field=store_nums [1,})";
            $fq++;
        }
        $fq_is_shipping = '';
        if ($is_shipping) {
            $fq_is_shipping = "(term field=is_shipping '1')";
            $fq++;
        }
        

        if ($fq == 1) {
            $params['fq'] = $fq_category_id.$fq_brand_id.$fq_seller_id.$fq_seller_category_id.$fq_rang.$fq_instock.$fq_is_shipping;
        } elseif ($fq > 1) {
            $params['fq'] = "( and ".$fq_category_id.$fq_brand_id.$fq_seller_id.$fq_seller_category_id.$fq_rang.$fq_instock.$fq_is_shipping." )";
        }
        //$params['fq'] = "(and(or(term field=category_id '571')(term field=category_id '569'))(term field=seller_id '858'))";//测试通过 查询分类id为571、569并且卖家id为858的所有商品
        //排序
        //获取配置信息
        $redis = new Redisbmk();
        $site_config = $redis->get('_site_config');
        $site_config   = unserialize($site_config);
        $order         = isset($site_config['order_by']) ? $site_config['order_by'] :'score_sale';
        $by            = isset($site_config['order_type']) ? $site_config['order_type'] :'desc';
        $shop_setting = [];
        if ($seller_id){
            $shop_setting = DB::table('shop_setting')->first('seller_id='.$seller_id);
        }
        if ($search == "matchall" && !$order_by) {//关键词为空
            if ($seller_id && $shop_setting->order_by) {
                $order_by = $shop_setting->order_by;
            }else{
                $order_by = $order;
            }
        }

        if ($search == "matchall" && !$order_type) {
            if ($seller_id && $shop_setting->order_type) {
                $order_type = $shop_setting->order_type;
            }else{
                $order_type = $by;
            }
        }
        if ($search !== "matchall" && !$order_by) {
            $order_by = 'score_sale';
            //$order_by = 'sale';
        }
        if ($search !== "matchall" && !$order_type) {
            $order_type = 'desc';
        }
        if ($order_by == 'cpoint'){
            $order_by = 'score_sale';
        }
        if ($order_by == 'price'){
            $order_by = 'sell_price';
        }
        if ($order_by == 'new'){
            $order_by = 'up_time';
        }
        $params['sort'] = $order_by.' '.$order_type;
        //$params['fq'] = "(term field=seller_id '21')";
        //$params['size'] = "10";
        //$params['start'] = "0";
        //$params['highlight.name'] = "{format:'text',max_phrases:3,pre_tag:'<b>',post_tag:'</b>'}";
        //$params['sort'] = "_score desc";
        //返回字段
        $params['return'] = "_all_fields,_score";
        $cloudsearch = new awsCloudSearch();
        $rs = $cloudsearch->search($search, $params);
        $goodsRow = json_decode($rs,true);

        $goods = [];
        if (isset($goodsRow['hits']) && $goodsRow['hits']['found'] > 0) {
            foreach ($goodsRow['hits']['hit'] as $key => $value) {
                $value['fields']['id'] = $value['id'];
                /* if ($params['q.parser'] !== "structured") {
                    $value['fields']['name'] = str_replace('"','&quot;',$value['highlights']['name']);
                } */
                $value['fields']['sell_price'] = $this->showPrice($value['fields']['sell_price']);
                $goods[] = $value['fields'];
            }
        }
        return $goods;
    }

    function showPrice($price)
    {
        return number_format($price,2,".","");
    }

    /***
     * 图片地址处理
     */
    function getImgDir($img,$height=216,$weight=216)
    {
        if($height && $weight){
            return HOST.$img.'?x-oss-process=image/resize,m_pad,h_'.$height.',w_'.$weight;
        }

        if(!$height || !$weight){
            return HOST.$img;
        }
    }

    function processNull($data){
        if(is_array($data)){
            foreach($data as $k=>&$v){
                $data[$k] = $this->processNull($v);
            }
            unset($v);
        }else{
            if(is_null($data)){
                $data = '';
            }
        }
        return $data;
    }
    
    /**
     * 删除指定标签
     *
     * @param array $tags     删除的标签  数组形式
     * @param string $str     html字符串
     * @param bool $content   true保留标签的内容text
     * @return mixed
     */
    function stripHtmlTags($tags, $str, $content = false)
    {
        $html = [];
        // 是否保留标签内的text字符
        $str = strip_tags($str);
        $str = addslashes($str);
        if($content){
            foreach ($tags as $tag) {
                $html[] = '/(<' . $tag . '.*?>(.|\n)*?<\/' . $tag . '>)/is';
            }
        }else{
            foreach ($tags as $tag) {
                $html[] = "/(<(?:\/" . $tag . "|" . $tag . ")[^>]*>)/is";
            }
        }

        $data = preg_replace($html, '', $str);
        $data = $this->utf8_string($data);
        return str_replace("\u0009","",$data);
    }

    function utf8_string($string){
        $string = mb_convert_encoding($string, "UTF-8");
        return preg_replace(
                array(
                        '/\x00/', '/\x01/', '/\x02/', '/\x03/', '/\x04/',
                        '/\x05/', '/\x06/', '/\x07/', '/\x08/', '/\x09/', '/\x0A/',
                        '/\x0B/','/\x0C/','/\x0D/', '/\x0E/', '/\x0F/', '/\x10/', '/\x11/',
                        '/\x12/','/\x13/','/\x14/','/\x15/', '/\x16/', '/\x17/', '/\x18/',
                        '/\x19/','/\x1A/','/\x1B/','/\x1C/','/\x1D/', '/\x1E/', '/\x1F/'
                ),
                array(
                        "\u0000", "\u0001", "\u0002", "\u0003", "\u0004",
                        "\u0005", "\u0006", "\u0007", "\u0008", "\u0009", "\u000A",
                        "\u000B", "\u000C", "\u000D", "\u000E", "\u000F", "\u0010", "\u0011",
                        "\u0012", "\u0013", "\u0014", "\u0015", "\u0016", "\u0017", "\u0018",
                        "\u0019", "\u001A", "\u001B", "\u001C", "\u001D", "\u001E", "\u001F"
                ),
                $string
                );
    }
}