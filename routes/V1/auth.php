<?php
Route::post('login', 'V1\PassportController@login');//登录
Route::post('register', 'V1\PassportController@register');
Route::post('send_mobile_code', 'V1\PassportController@send_mobile_code');//发送手机验证码
Route::post('registerBymobile', 'V1\PassportController@registerBymobile');//手机注册第一步
Route::post('registerBymobile2', 'V1\PassportController@registerBymobile2');//手机注册第二步
Route::post('login_mobile', 'V1\PassportController@login_mobile');//手机号登录
Route::post('oauth_login', 'V1\PassportController@oauth_login');//第三方登录
Route::post('findPwdByMobile', 'V1\PassportController@findPwdByMobile');//手机号找回密码
Route::post('reset_password', 'V1\PassportController@reset_password');//重置密码

