<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/7/23 0023
 * Time: 16:12
 */
$router->get('discover/getList', 'DiscoverController@getList');
$router->post('discover/comment', 'DiscoverController@comment');
$router->post('discover/upvote', 'DiscoverController@upvote');
$router->get('discover/getComments', 'DiscoverController@getComments');