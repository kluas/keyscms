<?php
error_reporting(E_ALL & ~E_NOTICE);
ini_set('default_charset', 'UTF-8');
try {
    defined('ROOT_PATH') || define('ROOT_PATH', dirname(__FILE__));
    defined('APP_PATH') || define('APP_PATH', ROOT_PATH . '/application');

    require_once APP_PATH . '/Bootstrap.php';

    $app = new \Bootstrap(new \Phalcon\DI\FactoryDefault());
    echo $app->handle()->getContent();
    
} catch (\Phalcon\Exception $e) {
    Bootstrap::log($e);
} catch (\PDOException $e) {
    Bootstrap::log($e);
} catch (\Exception $e) {
    Bootstrap::log($e);
}