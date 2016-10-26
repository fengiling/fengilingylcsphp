<?php

class DaemonCommand
{
    private $info_dir = "/tmp";
    private $pid_file = "";
    private $terminate = false; // 是否中断
    private $workers_count = 0;
    private $gc_enabled = null;
    private $workers_max = 8; // 最多运行8个进程
    private $runtime = array(); // 进程号 进程名 上次更新时间 进行的步骤
    private $message_queue_file = '/home/fengiling/Daemonylcs.php'; // 消息使用
    private $message_queue = ''; // 消息使用
    private $micro_seconds = 10; // 无消息则等待的毫秒数
    private $upTime = 86400; // 24 * 60 * 60; // 更新$runtime的时间间隔
    private $killTime = 43200; // 12 * 60 * 60; // 单个进程最大空闲时间
    private $logFile = '/home/fengiling/logs'; // 日志文件名

    public function __construct($is_sington = false, $user = 'nobody', $output = "/dev/null")
    {
        $this->is_sington = $is_sington; // 是否单例运行，单例运行会在tmp目录下建立一个唯一的PID
        $this->user = $user; // 设置运行的用户 默认情况下nobody
        $this->output = $output; // 设置输出的地方
        $this->checkPcntl();
        // 初始化消息
        $message_queue_key = ftok($this->message_queue_file, 'a');
        $this->message_queue = msg_get_queue($message_queue_key, 0666);

        $this->childMax = 1;

        $this->secu = 0;
        $this->err = 0;
    }

    // 检查环境是否支持pcntl支持
    public function checkPcntl()
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            // PHP < 5.3 uses ticks to handle signals instead of pcntl_signal_dispatch
            // call sighandler only every 10 ticks
            declare(ticks = 10)
            ;
        }

        // Make sure PHP has support for pcntl
        if (!function_exists('pcntl_signal')) {
            $message = 'PHP does not appear to be compiled with the PCNTL extension.  This is neccesary for daemonization';
            $this->_log($message);
            throw new Exception($message);
        }
        // 信号处理
        pcntl_signal(SIGTERM, array(__CLASS__, "signalHandler"), false);
        pcntl_signal(SIGINT, array(__CLASS__, "signalHandler"), false);
        pcntl_signal(SIGQUIT, array(__CLASS__, "signalHandler"), false);

        // Enable PHP 5.3 garbage collection
        if (function_exists('gc_enable')) {
            gc_enable();
            $this->gc_enabled = gc_enabled();
        }
    }

    // daemon化程序
    public function daemonize()
    {
        global $stdin, $stdout, $stderr;
        global $argv;

        set_time_limit(0);

        // 只允许在cli下面运行
        if (php_sapi_name() != "cli") {
            die("only run in command line mode\n");
        }

        // 只能单例运行
        if ($this->is_sington == true) {

            $this->pid_file = $this->info_dir . "/" . __CLASS__ . "_" . substr(basename($argv[0]), 0, -4) . ".pid";
            $this->checkPidfile();
        }

        umask(0); // 把文件掩码清0
        if (pcntl_fork() != 0) { // 是父进程，父进程退出
            exit();
        }

        posix_setsid(); // 设置新会话组长，脱离终端

        if (pcntl_fork() != 0) { // 是第一子进程，结束第一子进程
            exit();
        }

        chdir("/"); // 改变工作目录

        $this->setUser($this->user) or die("cannot change owner");

        // 关闭打开的文件描述符
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $stdin = fopen($this->output, 'r');
        $stdout = fopen($this->output, 'a');
        $stderr = fopen($this->output, 'a');

        if ($this->is_sington == true) {
            $this->createPidfile();
        }
    }

    // --检测pid是否已经存在
    public function checkPidfile()
    {
        if (!file_exists($this->pid_file)) {
            return true;
        }
        $pid = file_get_contents($this->pid_file);
        $pid = intval($pid);
        if ($pid > 0 && posix_kill($pid, 0)) {
            $this->_log("the daemon process is already started:" . $pid);
            exit(1);
        } else {
            $this->_log("the daemon proces end abnormally, please check pidfile " . $this->pid_file . " :" . $pid);
        }
    }

    // ----创建pid
    public function createPidfile()
    {
        if (!is_dir($this->info_dir)) {
            mkdir($this->info_dir);
        }
        $fp = fopen($this->pid_file, 'w') or die("cannot create pid file");
        fwrite($fp, posix_getpid());
        fclose($fp);
        $this->_log("create pid file " . $this->pid_file);
    }

    // 设置运行的用户
    public function setUser($name)
    {
        $result = false;
        if (empty($name)) {
            return true;
        }
        $user = posix_getpwnam($name);
        $this->_log(print_r($user, true));
        if ($user) {
            $uid = $user['uid'];
            $gid = $user['gid'];
            // $result = posix_setuid( $uid );
            // posix_setgid( $gid );
        }
        return true;
    }

    // 信号处理函数
    public function signalHandler($signo)
    {
        switch ($signo) {

            // 用户自定义信号
            // case SIGUSR1 : // busy
            // if ($this->workers_count < $this->workers_max) {
            // $pid = pcntl_fork();
            // if ($pid > 0) {
            // $this->workers_count++;
            // }
            // }
            // break;
            // 子进程结束信号
            case SIGCHLD :
                while (($pid = pcntl_waitpid(-1, $status, WUNTRACED | WNOHANG)) > 0) {
                    // $this->workers_count--;
                    $this->_log('signalHandler kill pid:' . $pid);
                    unset($this->runtime['process'][$pid]);
                }
                break;
            // 中断进程
            case SIGTERM :
            case SIGHUP :
            case SIGQUIT :

                $this->terminate = true;
                break;
            default :
                return false;
        }
    }

    /**
     * 开始开启进程
     * $count 准备开启的进程数
     */
    public function start($count = 1, $childMax = 1)
    {
        $this->childMax = $childMax;

        $this->_log("daemon process is running now");
        pcntl_signal(SIGCHLD, array(__CLASS__, "signalHandler"), false); // if worker die, minus children num
        $startTime = time();
        while (true) {
            if (function_exists('pcntl_signal_dispatch')) {

                pcntl_signal_dispatch();
            }

            if ($this->terminate) {
                break;
            }
            $pid = -1;
            if ($this->workers_count < $count) {
                $pid = pcntl_fork();
            } else {
                break;
            }

            if ($pid > 0) {
                // $this->_log( 'runtime:' . print_r( $this->runtime, true ) );
                $this->workers_count++;
            } elseif ($pid == 0) {

                // 这个符号表示恢复系统对信号的默认处理
                pcntl_signal(SIGTERM, SIG_DFL);
                pcntl_signal(SIGCHLD, SIG_DFL);
                $this->_childRunning();
                return;
            } else {
                sleep(2);
            }
        }
//        $c_Running = true;
        $i=0;
        while ($i < $count) {
//            $c_Running = $this->_checkChildProcess();
            // 获取消息
            $a = msg_stat_queue($this->message_queue);
//            $this->_log('a:' . print_r($a, true));
            if ($a['msg_qnum'] == 0) {
                // 无消息 sleep 待下次获取消息
                usleep($this->micro_seconds);
                continue;
            }

            $i++;
            $this->_log('获取:' . $i.' 次');
            $ret = msg_receive($this->message_queue, 1, $message_type, 1024, $message, true, MSG_IPC_NOWAIT);
            $this->_log('child msg:' . $message);
            // 解析收到的消息 并保存的runingtime中
            $list = explode('|', $message);

            $this->secu += $list[0];
            $this->err += $list[1];
//            sleep(2);
        }
        $endTime = time();
//        $this->_log('$c_Running:' . $c_Running);
        $this->_log('结果:' . $this->secu . ' ' . $this->err);
        $this->_log('用时:' . ($endTime - $startTime));
        $this->_log('tps:' . $this->secu/($endTime - $startTime));


        $this->mainQuit();
        exit(0);
    }

    // 整个进程退出
    public function mainQuit()
    {
        if (file_exists($this->pid_file)) {
            unlink($this->pid_file);
            $this->_log("delete pid file " . $this->pid_file);
        }
        $this->_log("daemon process exit now");
        posix_kill(0, SIGKILL);
        msg_remove_queue($this->message_queue);
        exit(0);
    }

    // 添加工作实例，目前只支持单个job工作
    public function setJobs($jobs = array())
    {
        if (!isset($jobs['argv']) || empty($jobs['argv'])) {
            $jobs['argv'] = "";
        }
        if (!isset($jobs['runtime']) || empty($jobs['runtime'])) {

            $jobs['runtime'] = 1;
        }

        if (!isset($jobs['function']) || empty($jobs['function'])) {

            $this->log("你必须添加运行的函数！");
        }

        $this->jobs = $jobs;
    }

    // 日志处理
    private function _log($message)
    {
        $logfile = $this->logFile . '.' . date('Ymd') . '.log';
        $fp = fopen($logfile, 'a');
        fwrite($fp, date("c") . "\t" . posix_getpid() . "\t" . posix_getppid() . "\t" . $message . "\n");
        fclose($fp);
    }
    // 校验进程是否需要创建子进程
    // 返回 array(进程名,进程启动命令, 进程步骤)
    private function _checkChildProcess()
    {
        $cmd = "ps xu | grep -v \"grep\" | grep Daemonylcs | wc -l";
        // $this->_log( "cmd:" . $cmd );
        $last_line = exec($cmd);
        return $last_line > 1;

    }

    private function _childRunning()
    {
        $c_secu = 0;
        $c_err = 0;
        try {
            for ($i = 0; $i < $this->childMax; $i++) {

                $input_param = '报文内容';
                $curl_url = "http://fengiling.com";
                echo "PARTNER_REGISTER请求[" . $input_param . "]\n";

                $tuCurl = curl_init();
                curl_setopt($tuCurl, CURLOPT_URL, $curl_url);
                curl_setopt($tuCurl, CURLOPT_POST, 1);
                curl_setopt($tuCurl, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($tuCurl, CURLOPT_POSTFIELDS, $input_param);
                $tuData = curl_exec($tuCurl);
                $info = curl_getinfo($tuCurl);
                curl_close($tuCurl);

                echo "PARTNER_REGISTER应答[" . $tuData . "]\n";
                if (strpos($tuData, 'success')) {
                    $c_secu++;
                } else {
                    $c_err++;
                }
            }
        }catch (Exception $e) {
//            $this->errorInfo = iconv('GBK', 'UTF-8', $e->getMessage());
            $this->_log('ERROR' . $e->getMessage() . ' . _childRunning err.');
        }
        msg_send($this->message_queue, 1, $c_secu . "|" . $c_err,true, true,  $msg_err);
        $this->_log('child send:' . $c_secu . "|" . $c_err);
        return $c_secu . "|" . $c_err;
    }
}

// 调用方法1
$daemon = new DaemonCommand(true);
$daemon->daemonize();
$daemon->start(300, 20); // 开启2个子进程工作

?>
