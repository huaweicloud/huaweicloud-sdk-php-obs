<?php
/**
 * 演示Demo
 * Created by PhpStorm.
 * User: Yanlongli
 * Date: 2019/1/23
 * Time: 11:15
 */
include '../vendor/autoload.php';

use Obs\ObsClient;

/**
 * 复制或修改引入的配置文件
 */
$config = require 'config-local.php';
// 创建ObsClient实例
$obsClient = new ObsClient([
    'key' => $config['key'], //ak
    'secret' => $config['secret'],//sk
    'endpoint' => $config['endpoint'],//endpoint
]);

// 使用访问OBS
$resp = $obsClient->putObject([
    'Bucket' => $config['bucket'],//桶名称对 固定
    'Key' => 'index.php',//储存文件名
    'SourceFile' => 'index.php'//本地文件名
]);
var_dump($resp);

// 关闭obsClient
$obsClient -> close();
