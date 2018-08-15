<?php
namespace App\Htpp\Traits;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\Response as FoundationResponse;


trait ApiResponse
{
    /**
     * @var int
     */
    protected $statusCode = FoundationResponse::HTTP_OK;

    /**
     * @return mixed
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @param $statusCode
     * @return $this
     */
    public function setStatusCode($statusCode)
    {

        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * @param $data
     * @param array $header
     * @return mixed
     */
    public function respond($data, $header = [])
    {

        return Response::json($data,$this->getStatusCode(),$header);
    }

    /**
     * @param $data
     * @param string $status
     * @return mixed
     */
    public function success($data, $code = Foundationresponse::HTTP_OK){
        $this->setStatusCode($code);
        return $this->setData(compact('data'));
    }


    public function error($code = Foundationresponse::HTTP_INTERNAL_SERVER_ERROR,$message){
        $this->setStatusCode($code);
        return $this->failed($message);
    }


    /**
     * @param $status
     * @param array $data
     * @param null $code
     * @return mixed
     */
    public function setData(array $data){

        $status = [
            'code' => $this->statusCode,
            'msg'=> 'Success',
        ];

        $data = array_merge($data, $status);
        return $this->respond($data);

    }

    /**
     * @param $msg_code
     * @return mixed
     */
    public function failed($message) {

        $data = [
            'code'=> $this->statusCode,
            'msg'=> $message,
        ];
        return $this->respond($data);
    }

}