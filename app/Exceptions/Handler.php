<?php

namespace App\Exceptions;

use Aliyun\OSS\Exceptions\OSSException;
use App\Htpp\Traits\ApiResponse;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class Handler extends ExceptionHandler
{
    use ApiResponse;
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Exception  $exception
     * @return void
     */
    public function report(Exception $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $exception
     * @return \Illuminate\Http\Response
     */
    public function render($request, Exception $exception)
    {
        // 参数验证错误的异常，我们需要返回 400 的 http code 和一句错误信息
        if ($exception instanceof ValidationException) {
            return $this->error(425,array_first(array_collapse($exception->errors())));
        }

        if ($exception instanceof \InvalidArgumentException) {
            return $this->error(400,$exception->getMessage());
        }

        // 用户认证的异常，我们需要返回 401 的 http code 和错误信息
        if ($exception instanceof UnauthorizedHttpException) {
            return $this->error(401, $exception->getMessage());
        }

        if ($exception instanceof AuthenticationException) {
            return $this->error(401, $exception->getMessage());
        }

       // 数据库返回异常
        if ($exception instanceof QueryException) {
            if(env('APP_ENV') === 'release'){
                return $this->error(500, '服务器错误');
            }
        }
        // 阿里云上传图片出错，我们需要返回 401 的 http code 和错误信息
        if ($exception instanceof OSSException) {
            return $this->error(401, $exception->getMessage());
        }

        // 服务器出错
        if(env('APP_ENV') === 'release'){
            if($exception->getCode() === 0){
                return $this->error($exception->getMessage(), 500);
            }
        }

        return parent::render($request, $exception);
    }
}
