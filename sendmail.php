<?php

$time=date('Y-m-d H:i:s', time()+8*60*60);
//http://cs.yycq.pw/邮件发送/7/index1.php?from=admin%40cyyl.top&to=578136454%40qq.com&subject=%E6%B5%8B%E8%AF%95&body=12345789
// 处理 AJAX 请求
    $from = $_GET['from'];
    $to = $_GET['to'];
    $subject = $_GET['subject'];
    $body = $_GET['body'];
	$body = $body."<br>".$time;
	
// 启用错误报告，方便调试
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 初始化消息和进度变量
$message = '';
$progress = '';

// 函数：添加进度信息并立即输出
function addProgress($msg) {
    global $progress;
    $progress .= htmlspecialchars($msg) . "<br>";
    echo $msg . "\n";
    ob_flush();
    flush();
}



    // 处理附件
    $attachments = [];
    if (isset($_FILES['attachments'])) {
        foreach ($_FILES['attachments']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['attachments']['error'][$key] == UPLOAD_ERR_OK) {
                $attachments[] = [
                    'name' => $_FILES['attachments']['name'][$key],
                    'type' => $_FILES['attachments']['type'][$key],
                    'tmp_name' => $tmp_name,
                    'content' => file_get_contents($tmp_name)
                ];
            }
        }
    }

    try {
        sendEmail($from, $to, $subject, $body, $attachments);
        echo json_encode(['success' => true, 'message' => '邮件发送成功'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '发送邮件错误: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;


// 主要的邮件发送函数
function sendEmail($from, $to, $subject, $body, $attachments = []) {
    $from = trim($from, '<>');
    $to = trim($to, '<>');

    // 获取收件人域名的 MX 记录
    $domain = explode('@', $to)[1];
    //addProgress("\n正在查询 {$domain} 的 MX 记录...");
    $mxhosts = [];
    if (!getmxrr($domain, $mxhosts, $mxweights)) {
        //throw new Exception("\n无法找到 {$domain} 的 MX 记录");
    }
    
    // 显示找到的 MX 记录
    //addProgress("找到以下 MX 记录:");
    foreach ($mxhosts as $index => $mx) {
        $weight = $mxweights[$index];
        $ip = gethostbyname($mx);
        //addProgress("\n- {$mx} (权重: {$weight}, IP: {$ip})");
    }

    // 处理内嵌图片
    $body = preg_replace_callback(
        '/<img[^>]+src="(data:image\/[^;]+;base64,([^"]+))"/i',
        function($matches) use (&$attachments) {
            $imageData = base64_decode($matches[2]);
            $imageName = 'image_' . md5($matches[2]) . '.' . str_replace('image/', '', explode(';', $matches[1])[0]);
            $cid = md5($imageName) . '@' . gethostname();
            $attachments[] = [
                'name' => $imageName,
                'type' => explode(';', $matches[1])[0],
                'content' => $imageData,
                'cid' => $cid
            ];
            return '<img src="cid:' . $cid . '"';
        },
        $body
    );

    // 尝试连接每个 MX 主机
    $errors = [];
    foreach ($mxhosts as $mx) {
        try {
            //addProgress("\n正在尝试连接到 {$mx}...");
            checkNetworkConnection($mx);
            sendMailToMX($mx, $from, $to, $subject, $body, $attachments);
            return;
        } catch (Exception $e) {
            //$errors[] = "\n尝试 {$mx} 失败: " . $e->getMessage();
            //addProgress("\n连接失败: " . $e->getMessage());
        }
    }

    // 如果所有 MX 主机都失败，抛出异常
    //throw new Exception("\n无法发送邮件到 {$to}. 错误: " . implode("; ", $errors));
}

// 检查网络连接
function checkNetworkConnection($host) {
    $port = 25;
    $timeout = 5;

    //addProgress("\n正在检查与 {$host}:{$port} 的网络连接...");
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
        //throw new Exception("\n网络连接失败: 无法连接到 {$host}:{$port}. 错误: {$errstr} ({$errno})");
    }
    //addProgress("\n成功连接到 {$host}:{$port}");
    fclose($socket);
}

// 通过 MX 主机发送邮件
function sendMailToMX($mxHost, $from, $to, $subject, $body, $attachments = []) {
    //addProgress("\n正在建立 SMTP 连接到 {$mxHost}:25...");
    $socket = @stream_socket_client("tcp://{$mxHost}:25", $errno, $errstr, 30, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        //throw new Exception("\n连接到 {$mxHost} 失败: {$errstr} ({$errno})");
    }

    //addProgress("\nSMTP 连接已建立");
    stream_set_timeout($socket, 30);

    // 生成唯一的边界字符串
    $boundary = md5(time());
    $altBoundary = md5(time() . 'alt');

    // 修改 SMTP 命令序列，添加 HTML 内容和附件支持
    $commands = [
        ["", 220],
        ["EHLO " . gethostname(), 250],
        ["MAIL FROM:<{$from}> SMTPUTF8", 250],
        ["RCPT TO:<{$to}>", 250],
        ["DATA", [250, 354]],
        [
            "From: <{$from}>\r\n" .
            "To: <{$to}>\r\n" .
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" .
            "Date: " . date("r") . "\r\n" .
            "MIME-Version: 1.0\r\n" .
            "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n" .
            "\r\n" .
            "--{$boundary}\r\n" .
            "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n" .
            "\r\n" .
            "--{$altBoundary}\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: base64\r\n" .
            "\r\n" .
            chunk_split(base64_encode(strip_tags($body))) .
            "\r\n" .
            "--{$altBoundary}\r\n" .
            "Content-Type: text/html; charset=UTF-8\r\n" .
            "Content-Transfer-Encoding: base64\r\n" .
            "\r\n" .
            chunk_split(base64_encode($body)) .
            "\r\n" .
            "--{$altBoundary}--\r\n",
            false
        ],
    ];

    // 添加内嵌图片和附件
    foreach ($attachments as $attachment) {
        $commands[] = [
            "--{$boundary}\r\n" .
            "Content-Type: " . $attachment['type'] . "; name=\"" . $attachment['name'] . "\"\r\n" .
            "Content-Transfer-Encoding: base64\r\n" .
            (isset($attachment['cid']) ? "Content-ID: <" . $attachment['cid'] . ">\r\n" : "") .
            "Content-Disposition: " . (isset($attachment['cid']) ? "inline" : "attachment") . "; filename=\"" . $attachment['name'] . "\"\r\n" .
            "\r\n" .
            chunk_split(base64_encode($attachment['content'])) .
            "\r\n",
            false
        ];
    }

    // 添加结束边界和 QUIT 命令
    $commands[] = ["--{$boundary}--\r\n.\r\n", 250];
    $commands[] = ["QUIT", 221];

    // 执行 SMTP 命令序列
    foreach ($commands as $index => $command) {
        list($cmd, $expectedCode) = $command;
        if ($cmd !== "") {
            //addProgress("\n发送: " . (strlen($cmd) > 100 ? substr($cmd, 0, 100) . "..." : $cmd));
            $result = fwrite($socket, $cmd . "\r\n");
            if ($result === false) {
                //throw new Exception("\n写入套接字失败");
            }
        }
        if ($expectedCode !== false) {
            $response = fgets($socket, 515);
            if ($response === false) {
                //throw new Exception("\n读取响应失败，可能连接已关闭");
            }
            //addProgress("\n接收: " . rtrim($response));
            $responseCode = (int)substr($response, 0, 3);
            
            // 处理多行响应
            while (substr($response, 3, 1) === '-') {
                $response = fgets($socket, 515);
                //addProgress("\n接收: " . rtrim($response));
            }
            
            // 检查响应代码是否符合预期
            if (!is_array($expectedCode) && $responseCode !== $expectedCode) {
                //throw new Exception("\n意外的SMTP响应 (命令 {$index}): 期望 {$expectedCode}，实际 {$responseCode}. 完整响应: {$response}");
            } elseif (is_array($expectedCode) && !in_array($responseCode, $expectedCode)) {
                //throw new Exception("\n意外的SMTP响应 (命令 {$index}): 期望 " . implode(" 或 ", $expectedCode) . "，实际 {$responseCode}. 完整响应: {$response}");
            }
        }
    }

    fclose($socket);
    //addProgress("\nSMTP 连接已关闭");
}
?>

