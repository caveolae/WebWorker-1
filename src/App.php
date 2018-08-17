<?php
namespace WebWorker;

use Workerman\Connection\TcpConnection;
use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Autoloader;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\HttpCache;
use WebWorker\Libs\StatisticClient;

function microtime_float()
{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
}

class App extends Worker
{

    /**
     * 版本
     *
     * @var string
     */
    const VERSION = '0.3.0';

    private $conn = false;
    private $map = array();
    private $access_log = array();

    public  $autoload = array();
    public  $on404 ="";

    public $onAppStart = NULL;

    public $onAppReload = NULL;

    public $statistic_server = false;

    public $max_request = 10000;

    public function __construct($socket_name, $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
    }

    public function HandleFunc($url,callable $callback){
        if ( $url != "/" ){
            $url = strtolower(trim($url,"/"));
        }
        if ( is_callable($callback) ){
            if ( $callback instanceof \Closure ){
                $callback = \Closure::bind($callback, $this, get_class());
            }
        }else{
            throw new \Exception('can not HandleFunc');
        }
        $this->map[] = array($url,$callback,1);
    }

    public function AddFunc($url,callable $callback){
        if ( $url != "/" ){
            $url = strtolower(trim($url,"/"));
        }
        if ( is_callable($callback) ){
            if ( $callback instanceof \Closure ){
                $callback = \Closure::bind($callback, $this, get_class());
            }
        }else{
            throw new \Exception('can not HandleFunc');
        }
        $this->map[] = array($url,$callback,2);
    }

    private function show_404($connection){
        if ( $this->on404 ){
            $callback = \Closure::bind($this->on404, $this, get_class());
            call_user_func($callback);
        }else{
            Http::header("HTTP/1.1 404 Not Found");
            $html = '<html>
                <head><title>404 Not Found</title></head>
                <body bgcolor="white">
                <center><h1>404 Not Found</h1></center>
                <hr><center>Workerman</center>
                </body>
                </html>';
            $connection->send($html);
        }
    }

    private function auto_close($conn){
        if ( strtolower($_SERVER["SERVER_PROTOCOL"]) == "http/1.1" ){
            if ( isset($_SERVER["HTTP_CONNECTION"]) ){
                if ( strtolower($_SERVER["HTTP_CONNECTION"]) == "close" ){
                    $conn->close();
                }
            }
        }else{
            if ( $_SERVER["HTTP_CONNECTION"] == "keep-alive" ){

            }else{
                $conn->close();
            }
        }
        $this->access_log[7] = round(microtime_float() - $this->access_log[7],4);
        if(!@$_SESSION['isExport']){
            echo implode(" - ",$this->access_log)."\n";
        }
    }

    public function onClientMessage($connection,$data){
        if($_SERVER['REQUEST_METHOD'] == 'HEAD'){
            echo "slb ";
            $connection->close("Success");
            return ;
        }
        $this->access_log = [];
        $this->access_log[0] = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $_SERVER["REMOTE_ADDR"];
        $this->access_log[1] = date("Y-m-d H:i:s");
        $this->access_log[2] = $_SERVER['REQUEST_METHOD'];
        $this->access_log[3] = $_SERVER['REQUEST_URI'];
        $this->access_log[4] = $_SERVER['SERVER_PROTOCOL'];
        $this->access_log[5] = "NULL";
        $this->access_log[6] = 200;
        $this->access_log[7] = microtime_float();
        if ( empty($this->map) ){
            $str = <<<'EOD'
<div style="margin: 200px auto;width:600px;height:800px;text-align:left;">基于<a href="http://www.workerman.net/" target="_blank">Workerman</a>实现的自带http server的web开发框架.没有添加路由，请添加路由!
<pre>$app->HandleFunc("/",function($conn,$data) use($app){
    $conn->send("默认页");
});</pre>
</div>
EOD;
            $connection->send($str);
            return;
        }
        if ( $this->statistic_server ){
            require_once __DIR__ . '/Libs/StatisticClient.php';
            $statistic_address = $this->statistic_server;
        }
        $this->conn = $connection;
        $url= $_SERVER["REQUEST_URI"];
        $pos = stripos($url,"?");
        if ($pos != false) {
            $url = substr($url,0,$pos);
        }
        if ( $url != "/"){
            $url = strtolower(trim($url,"/"));
        }
        $this->access_log['url'] = $url;
        $url_arr = explode("/",$url);
        $class = empty($url_arr[0]) ? "_default" : $url_arr[0];
        $method = empty($url_arr[1]) ? "_default" : $url_arr[1];
        if ( $this->statistic_server ){
            StatisticClient::tick($class, $method);
        }
        $success = false;
        foreach($this->map as $route){
            if ( $route[2] == 1){//正常路由
                if ( $route[0] == $url ){
                    $callback[] = $route[1];
                    $success = true;
                }
            }else if ( $route[2] == 2 ){//中间件
                if ( $route[0] == "/" ){
                    $callback[] = $route[1];
                }else if ( stripos($url,$route[0]) === 0 ){
                    $callback[] = $route[1];
                }
            }
        }
        if ( isset($callback) && $success ){
            try {
                foreach($callback as $cl){
                    if ( call_user_func($cl) === true){
                        break;
                    }
                }
                if ( $this->statistic_server ){
                    StatisticClient::report($class, $method, 1, 0, '', $statistic_address);
                }
            }catch (\Exception $e) {
                // Jump_exit?
                if ($e->getMessage() != 'jump_exit' && $e->getMessage() != 'true') {
                    $this->access_log[5] = $e;
                }

                $code = $e->getCode() ? $e->getCode() : 500;
                $this->access_log[6] = 500;
                if($e->getMessage() == 'true'){
                    $code = 200;
                    $this->access_log[6] = 200;
                }
                if ( $this->statistic_server ){
                    StatisticClient::report($class, $method, $success, $code, $e, $statistic_address);
                }
            }
        }else{
            $this->show_404($connection);
            $code = 404;
            $msg = "class $class not found";
            if ( $this->statistic_server ){
                StatisticClient::report($class, $method, $success, $code, $msg, $statistic_address);
            }
        }
        $this->auto_close($connection);

        // 已经处理请求数
        static $request_count = 0;
        // 如果请求数达到1000
        if( ++$request_count >= $this->max_request && $this->max_request > 0 ){
            echo "WorkerId: {$this->id};  Reboot !!!".PHP_EOL;
            Worker::stopAll();
        }
        if(!@$_SESSION['isExport']){
            file_put_contents(APP_ROOT."/cache/tmp/WorkerId-{$this->id}.log", $this->access_log['url'] . PHP_EOL,FILE_APPEND);
            echo "WorkerId: {$this->id};  已经处理请求数:{$request_count}".PHP_EOL;
        }
    }

    /**
     * 程序输出并结束
     * @param $data
     */
    public function  ServerJson($data){
        Http::header("Content-type: application/json");
        $this->conn->send(json_encode($data));
        if(is_array($data)){
            $log_data['action'] = $_SERVER['REQUEST_URI'];     //动作
            $log_data['body'] = $GLOBALS['HTTP_RAW_POST_DATA'];    //提交参数
            $log_data['header'] = json_encode($_SERVER);    //报头
            $log_data['package'] = isset($_SESSION['package'])?$_SESSION['package']:'';    //包名
            $log_data['poortime'] = round(microtime_float() - $this->access_log[7],4);    //
            $log_data['referer'] = isset($_SESSION['referer'])?$_SESSION['referer']:'';    //来源
            $log_data['return_data'] = json_encode($data,320);    //
            $log_data['sql'] = isset($_SESSION['dumpList'])?json_encode($_SESSION['dumpList'],320):"";    // 打印出来的数据
            $log_data['token'] = isset($_SESSION['token'])? md5($_SESSION['token']) : ""; // 用户标示
            $log_data['uid'] = @$_SESSION['uid']>0 ? $_SESSION['uid']:"-1";            //用户uid
            $log_data['channel']= isset($_SESSION['channel'])?$_SESSION['channel']:"";      //渠道 iOS 为空
            $log_data['versioncode'] = isset($_SESSION['versioncode'])?$_SESSION['versioncode']:'';    //版本号
            #$log_data['input'] = $_GET['input'];    //加密参数
            $get_log_datas = $this->logSave->getData($log_data);  //获取数据
            $topic = "newSayu".CACHEIOSVER;         //__topic__
            $source = getuser_realip(); //来源 ip
            $this->logSave->save($topic,$source,$get_log_datas);   //写入日志
        }
        if(!@$_SESSION['isExport']){
            dump("返回数据",$data);
        }
        $this->end("true");
    }

    public function ServerHtmlJson($data){
        Http::header("Content-type: application/json");
        Http::header('Access-Control-Allow-Methods: GET, POST, PUT,OPTIONS');
        Http::header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept');
        Http::header('Access-Control-Allow-Origin:*');
        $this->conn->send(json_encode($data));
        $this->end("true");
    }

    public function  ServerHtml($data){
        dump('ServerHtml',$data);
        $this->conn->send($data);
        $this->end("true");
    }

    public function  Server404Html($data){
        dump('Server404Html',$data);
        $this->conn->send($data);
    }

    /**
     * 程序输出但不结束
     * @param $data
     */
    public function ConnSendJson($data){
        $this->conn->send(json_encode($data));
    }

    public function Header($str){
        Http::header($str);
    }

    public function end($msg){
        Http::end($msg);
    }

    public function Setcookie($name,$value = '',$maxage = 0,$path = '',$domain = '',$secure = false,$HTTPOnly = false){
        Http::setcookie($name,$value,$maxage,$path,$domain,$secure,$HTTPOnly);
    }

    public function run()
    {
        $this->reusePort = true;
        $this->onWorkerStart = $this->onAppStart;
        $this->onWorkerReload = $this->onAppReload;
        $this->onMessage = array($this, 'onClientMessage');
        parent::run();
    }

}

function autoload_dir($dir_arr){
    extract($GLOBALS);
    foreach($dir_arr as $dir ){
        foreach(glob($dir.'*.php') as $start_file)
        {
            require_once $start_file;
        }
    }
}
