<?php
use App\Models\Config;

class Bootstrap extends \Phalcon\Mvc\Application {
    private $_di;
    private $_config;

    /**
     * 构建网站服务入口(Constructor)
     *
     * @param $di
     */
    public function __construct(\Phalcon\DiInterface $di) {
        $this->_di = $di;
        
        $loaders = array('loader', 'config', 'timezone', 'db', 'crypt', 'cache', 'language', 'url', 'router', /* 'siteNode', 'sysConfig', */);

        // 注册各载入服务(Register services)
		try {
			foreach ($loaders as $service) {
				$this->$service();
			}
		} catch (\Exception $e){
			exit('网站模块注册出错！请通知管理员尽快恢复正常运行，谢谢！');
		}
        
        // 注册服务模块(Register modules)
        $this->registerModules(array(
            'home' => array(
                'className' => 'App\Home\Module',
                'path'      => APP_PATH . '/home/Module.php'
            ),
            
            'admin' => array(
                'className' => 'App\Admin\Module',
                'path'      => APP_PATH . '/admin/Module.php'
            ),
            
            'backend' => array(
                'className' => 'App\Backend\Module',
                'path'      => APP_PATH . '/backend/Module.php'
            ),
        ));

        // 注册本类为应用服务(Register the app itself as a service)
        $this->_di->set('app', $this);

        // 调用父类注册入口(Sets the parent Di)
        parent::setDI($this->_di);
    }

    protected function loader() {
        //注册加载各类服务的模块(Register an autoloader),以及各种文件
        $loader = new \Phalcon\Loader();
        $loader->registerNamespaces(array(
            'App\Api'           => APP_PATH . '/common/api/',
            'App\Common'        => APP_PATH . '/common/',
            'App\Models'        => APP_PATH . '/common/models/',
            'App\Library'       => APP_PATH . '/common/library/',
            'App\Controllers'   => APP_PATH . '/common/controllers/',
            'App\Backend\Controllers'   => APP_PATH . '/backend/controllers/',
        ))->register();
        include_once(APP_PATH . '/common/library/functions.php');
    }

    protected function config() {
        // 配置文件(Create the new object)
        $config = new \Phalcon\Config\Adapter\Ini(APP_PATH . '/common/config/config.ini');
        
        // Store it in the Di container
        $this->_di->set('config', $config);
        $this->_config = $config;
        
        if (file_exists(APP_PATH . '/common/config/constants.php')) {
            require(APP_PATH . '/common/config/constants.php');
        }
    }

    protected function timezone() {
        //时区设置
        date_default_timezone_set($this->_config->site->timezone);
    }

    protected function crypt() {
        //加密服务
        $config = $this->_config;

        $this->_di->set('crypt', function() use ($config) {
            $crypt = new \Phalcon\Crypt();
            $crypt->setKey($config->crypt->key);
            return $crypt;
        });
    }
    
    protected function language() {
        $this->_di->set('language', function() {
            $language = new Baseapp\Library\Language(array(
                'locale'    => 'zh', 
                'directory' => APP_PATH . '/common/lang/', 
                'phpex'     => 'php'
            ));
            return $language;
        });
    }

    protected function db() {
        $config = $this->_config;
        $this->_di->set('profiler', function(){
            return new \Phalcon\Db\Profiler();
        }, true);
        
        //设置数据库连接(Set the database service)，当前支持四种sql数据库连接
        $di = $this->_di;
        $this->_di->set('db', function() use ($config, $di){
            $params = array(
                "host" => $config->database->host,
                "username" => $config->database->username,
                "password" => $config->database->password,
                "dbname" => $config->database->dbname
            );
            if (property_exists($config->database, 'port')) {      //配置端口
                $params['port'] = $config->database->port;
            }

            try {
                $eventsManager = new \Phalcon\Events\Manager();
                $profiler      = $di->getProfiler();
                $eventsManager->attach('db', function($event, $connection) use ($profiler) {
                    if ($event->getType() == 'beforeQuery') {
                        // file_put_contents(APP_PATH . '/../query.txt', $connection->getSQLStatement());
                        $profiler->startProfile($connection->getSQLStatement());
                    }
                    if ($event->getType() == 'afterQuery') {
                        $profiler->stopProfile();
                    }
                });
                switch ($config->database->dbtype) {
                    case 'mysql':
                        $charset = $config->database->chasrset;
                        $params['options'] = array(
                            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES '$charset'",
                            PDO::ATTR_CASE => PDO::CASE_LOWER
                        );    // 设置编码和强制列小写
                        if (property_exists($config->database, 'persistent')) {
                            $params['persistent'] = $config->database->persistent;
                        }
                        $connection = new \Phalcon\Db\Adapter\Pdo\Mysql($params);
                        break;
                    case 'postgresql':
                        if (property_exists($config->database,'schema')) {
                            $params['schema'] = $config->database->schema;
                        }
                        $connection = new \Phalcon\Db\Adapter\Pdo\Postgresql($params);
                        break;
                    case 'sqlite':
                        array_splice($params,0,3);          // 最后一个dbname参数是必须
                        $connection = new \Phalcon\Db\Adapter\Pdo\Sqlite($params);
                        break;
                    case 'oracle':
                        array_splice($params,0,1);          // 第一个host参数不是必须
                        $params['charset'] = $config->database->charset;
                        $connection = new \Phalcon\Db\Adapter\Pdo\Oracle($params);
                        break;
                }
                $connection->setEventsManager($eventsManager);
                return $connection;
            } catch (\Exception $e) {
                exit('数据库连接失败！请通知管理员尽快恢复正常运行，谢谢！');
            }
        });
    }

    protected function cache() {
        $config = $this->_config;
        $this->_di->set('cache', function() use ($config) {
            // Get the parameters
            $frontCache = new \Phalcon\Cache\Frontend\Data(array('lifetime' => $config->cache->lifetime));
            $cache = new \Phalcon\Cache\Backend\File($frontCache, array('cacheDir' => APP_PATH . '/common/cache/'));
            return $cache;
        });
    }

    protected function url() {
        $config = $this->_config;
        $this->_di->set('url', function() use ($config) {
            $url = new \Phalcon\Mvc\Url();
            $url->setBaseUri($config->app->base_uri);
            $url->setStaticBaseUri($config->app->static_uri);
            return $url;
        });
    }

    protected function tags() {
        $this->_di->set('tag', function() {
            return new \App\Library\Tags();
        });
    }

    protected function router() {
        //Setting up the static router
        $this->_di->set('router', function() {
            $router = new \Phalcon\Mvc\Router(FALSE);
            $router->setDefaults(array('module' => 'home', 'controller' => 'Index', 'action' => 'index'));

            //前台
            $router->add('/', array('module' => 'home', 'controller' => 'Index', 'action' => 'index'));
            $router->add('/:controller[/]?', array('module' => 'home', 'controller' => 1, 'action' => 'index'));
            $router->add('/:controller/:action/:params', array('module' => 'home', 'controller' => 1, 'action' => 2, 'params' => 3));
            $router->add('/:int[/]?', array('module' => 'home', 'controller' => 'index', 'action' => 'index', 'node' =>1));
            $router->add('/:int/:controller[/]?', array('module' => 'home', 'controller' => 2, 'action' => 'index', 'node' =>1));
            $router->add('/:int/:controller/:action/:params', array('module' => 'home', 'controller' => 2, 'action' => 3, 'node' =>1, 'params' => 4));

            //后台
            $router->add('/admin[/]?', array('module' => 'admin', 'controller' => 'Index', 'action' => 'index'));
            $router->add('/admin/:controller[/]?', array('module' => 'admin', 'controller' => 1, 'action' => 'index'));
            $router->add('/admin/:controller/:action/:params', array('module' => 'admin', 'controller' => 1, 'action' => 2, 'params' => 3 ));
            $router->add('/:int/admin[/]?', array('module' => 'admin', 'controller' => 'Index', 'action' => 'index', 'node' =>1));
            $router->add('/:int/admin/:controller[/]?', array('module' => 'admin', 'controller' => 2, 'action' => 'index', 'node' =>1));
            $router->add('/:int/admin/:controller/:action/:params', array('module' => 'admin', 'controller' => 2, 'action' => 3, 'params' => 4, 'node' => 1));
            
            //任务
            $router->add('/backend[/]?', array('module' => 'backend', 'controller' => 'Index', 'action' => 'index'));
            $router->add('/backend/:controller[/]?', array('module' => 'backend', 'controller' => 1, 'action' => 'index'));
            $router->add('/backend/:controller/:action/:params', array('module' => 'backend', 'controller' => 1, 'action' => 2, 'params' => 3 ));
            $router->add('/:int/backend[/]?', array('module' => 'backend', 'controller' => 'Index', 'action' => 'index', 'node' =>1));
            $router->add('/:int/backend/:controller[/]?', array('module' => 'backend', 'controller' => 2, 'action' => 'index', 'node' =>1));
            $router->add('/:int/backend/:controller/:action/:params', array('module' => 'backend', 'controller' => 2, 'action' => 3, 'params' => 4, 'node' => 1));
            
            //404
            $router->notFound(array('controller' => 'index', 'action' => 'notFound'));

            return $router;
        });
    }

    public static function log(Exception $e) {        //错误日志记录
        if (\Phalcon\DI::getDefault()->getShared('config')->log->file) {
            $logger = new \Phalcon\Logger\Adapter\File(APP_PATH . '/common/logs/' . date('Ymd') . '.log', array('mode' => 'a+'));
            $logger->error(get_class($e) . '[' . $e->getCode() . ']: ' . $e->getMessage());
            $logger->info($e->getFile() . '[' . $e->getLine() . ']');
            $logger->debug("Trace: \n" . $e->getTraceAsString() . "\n");
            $logger->close();
        }
        if (\Phalcon\DI::getDefault()->getShared('config')->log->debug) {
            \App\Controllers\ControllerCommon::instance()->exception($e);
        } else {
            \App\Controllers\ControllerCommon::instance()->exception('系统发生了错误，请联系管理员进行修复，错误代码：' . $e->getCode());
        }
    }
}