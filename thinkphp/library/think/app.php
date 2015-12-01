<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2015 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

/**
 * App 应用管理
 * @author  liu21st <liu21st@gmail.com>
 */
class App
{

    /**
     * 执行应用程序
     * @access public
     * @return void
     */
    public static function run(array $config = [])
    {
        if (version_compare(PHP_VERSION, '5.4.0', '<')) {
            throw new Exception('require PHP > 5.4.0 !');
        }

        // 初始化公共模块
        self::initModule(COMMON_MODULE, $config);

        // 读取扩展配置文件
        if ($config['extra_config_list']) {
            foreach ($config['extra_config_list'] as $file) {
                Config::load($file, $file);
            }
        }

        // 获取配置参数
        $config = Config::get();

        // 日志初始化
        Log::init($config['log']);
        // 缓存初始化
        Cache::connect($config['cache']);

        // 默认语言
        $lang = strtolower($config['default_lang']);
        Lang::range($lang);
        // 加载默认语言包
        Lang::load(THINK_PATH . 'Lang/' . $lang . EXT);

        // 监听app_init
        Hook::listen('app_init');

        // 启动session
        if (!IS_CLI && $config['use_session']) {
            Session::init($config['session']);
        }

        // 应用URL调度
        self::dispatch($config);

        // 监听app_run
        Hook::listen('app_run');

        // 执行操作
        if (!preg_match('/^[A-Za-z](\/|\.|\w)*$/', CONTROLLER_NAME)) {
            // 安全检测
            $instance = false;
        } elseif ($config['action_bind_class']) {
            $class    = self::bindActionClass($config['empty_controller']);
            $instance = new $class;
            // 操作绑定到类后 固定执行run入口
            $action = 'run';
        } else {
            $instance = Loader::controller(CONTROLLER_NAME, '', $config['empty_controller']);
            // 获取当前操作名
            $action = ACTION_NAME . $config['action_suffix'];
        }
        if (!$instance) {
            throw new Exception('[ ' . MODULE_NAME . '\\' . CONTROLLER_LAYER . '\\' . Loader::parseName(str_replace('.', '\\', CONTROLLER_NAME), 1) . ' ] not exists');
        }

        try {
            // 操作方法开始监听
            $call = [$instance, $action];
            Hook::listen('action_begin', $call);
            if (!preg_match('/^[A-Za-z](\w)*$/', $action)) {
                // 非法操作
                throw new \ReflectionException();
            }
            //执行当前操作
            $method = new \ReflectionMethod($instance, $action);
            if ($method->isPublic()) {
                // URL参数绑定检测
                if ($config['url_params_bind'] && $method->getNumberOfParameters() > 0) {
                    // 获取绑定参数
                    $args = self::getBindParams($method, $config['url_parmas_bind_type']);
                    // 全局过滤
                    array_walk_recursive($args, 'Input::filterExp');
                    $data = $method->invokeArgs($instance, $args);
                } else {
                    $data = $method->invoke($instance);
                }
                // 操作方法执行完成监听
                Hook::listen('action_end', $data);
                // 返回数据
                $data = Response::returnData($data, $config['default_return_type']);
                if ($config['response_exit']) {
                    exit($data);
                } else {
                    echo $data;
                }
            } else {
                // 操作方法不是Public 抛出异常
                throw new \ReflectionException();
            }
        } catch (\ReflectionException $e) {
            // 操作不存在
            if (method_exists($instance, '_empty')) {
                $method = new \ReflectionMethod($instance, '_empty');
                $method->invokeArgs($instance, [$action, '']);
            } else {
                throw new Exception('[ ' . (new \ReflectionClass($instance))->getName() . ':' . $action . ' ] not exists ', 404);
            }
        }
        return;
    }

    // 操作绑定到类：模块\controller\控制器\操作类
    private static function bindActionClass($emptyController)
    {
        if (is_dir(MODULE_PATH . CONTROLLER_LAYER . '/' . str_replace('.', '/', CONTROLLER_NAME))) {
            $namespace = MODULE_NAME . '\\' . CONTROLLER_LAYER . '\\' . str_replace('.', '\\', CONTROLLER_NAME) . '\\';
        } else {
            // 空控制器
            $namespace = MODULE_NAME . '\\' . CONTROLLER_LAYER . '\\' . $emptyController . '\\';
        }
        $actionName = strtolower(ACTION_NAME);
        if (class_exists($namespace . $actionName)) {
            $class = $namespace . $actionName;
        } elseif (class_exists($namespace . '_empty')) {
            // 空操作
            $class = $namespace . '_empty';
        } else {
            throw new Exception('_ERROR_ACTION_:' . ACTION_NAME);
        }
        return $class;
    }

    private static function getBindParams($method, $paramsBindType)
    {
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
                $vars = array_merge($_GET, $_POST);
                break;
            case 'PUT':
                parse_str(file_get_contents('php://input'), $vars);
                break;
            default:
                $vars = $_GET;
        }
        $params = $method->getParameters();
        foreach ($params as $param) {
            $name = $param->getName();
            if (1 == $paramsBindType && !empty($vars)) {
                $args[] = array_shift($vars);
            }if (0 == $paramsBindType && isset($vars[$name])) {
                $args[] = $vars[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new Exception('_PARAM_ERROR_:' . $name);
            }
        }
        return $args;
    }
    /**
     * 初始化模块
     * @access private
     * @return void
     */
    private static function initModule($module, &$config)
    {
        // 加载初始化文件
        if (is_file(APP_PATH . $module . '/init' . EXT)) {
            include APP_PATH . $module . '/init' . EXT;
        } else {

            // 定位模块目录
            $module = COMMON_MODULE == $module ? '' : $module . '/';
            // 加载模块配置
            $config = Config::load($module . 'config');

            // 加载应用状态配置
            if ($config['app_status'] && is_file(APP_PATH . $module . $config['app_status'] . EXT)) {
                $config = Config::load($module . $config['app_status']);
            }

            // 加载别名文件
            if (is_file(APP_PATH . $module . 'alias' . EXT)) {
                Loader::addMap(include APP_PATH . $module . 'alias' . EXT);
            }

            // 加载行为扩展文件
            if (is_file(APP_PATH . $module . 'tags' . EXT)) {
                Hook::import(include APP_PATH . $module . 'tags' . EXT);
            }

            // 加载公共文件
            if (is_file(APP_PATH . $module . 'common' . EXT)) {
                include APP_PATH . $module . 'common' . EXT;
            }
        }
    }

    /**
     * URL调度
     * @access public
     * @return void
     */
    public static function dispatch($config)
    {
        if (isset($_GET[$config['var_pathinfo']])) {
            // 判断URL里面是否有兼容模式参数
            $_SERVER['PATH_INFO'] = $_GET[$config['var_pathinfo']];
            unset($_GET[$config['var_pathinfo']]);
        } elseif (IS_CLI) {
            // CLI模式下 index.php module/controller/action/params/...
            $_SERVER['PATH_INFO'] = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '';
        }

        // 检测域名部署
        if (!IS_CLI && !empty($config['domain_deploy'])) {
            Route::checkDomain();
        }

        // 监听path_info
        Hook::listen('path_info');
        // 分析PATHINFO信息
        if (!isset($_SERVER['PATH_INFO']) && $_SERVER['SCRIPT_NAME'] != $_SERVER['PHP_SELF']) {
            $types = explode(',', $config['pathinfo_fetch']);
            foreach ($types as $type) {
                if (0 === strpos($type, ':')) {
                    // 支持函数判断
                    $_SERVER['PATH_INFO'] = call_user_func(substr($type, 1));
                    break;
                } elseif (!empty($_SERVER[$type])) {
                    $_SERVER['PATH_INFO'] = (0 === strpos($_SERVER[$type], $_SERVER['SCRIPT_NAME'])) ?
                    substr($_SERVER[$type], strlen($_SERVER['SCRIPT_NAME'])) : $_SERVER[$type];
                    break;
                }
            }
        }

        $result = [null, null, null];
        if (empty($_SERVER['PATH_INFO'])) {
            $_SERVER['PATH_INFO'] = '';
            define('__INFO__', '');
            define('__EXT__', '');
        } else {
            $_SERVER['PATH_INFO'] = trim($_SERVER['PATH_INFO'], '/');
            define('__INFO__', $_SERVER['PATH_INFO']);
            // URL后缀
            define('__EXT__', strtolower(pathinfo($_SERVER['PATH_INFO'], PATHINFO_EXTENSION)));
            $_SERVER['PATH_INFO'] = __INFO__;
            if (__INFO__ && !defined('BIND_MODULE')) {
                if ($config['url_deny_suffix'] && preg_match('/\.(' . $config['url_deny_suffix'] . ')$/i', __INFO__)) {
                    throw new Exception('URL_SUFFIX_DENY');
                }
                // 路由检测
                if (!empty($config['url_route_on'])) {
                    // 开启路由 则检测路由配置
                    Route::register($config['route']);
                    $result = Route::check(__INFO__, $config['pathinfo_depr']);
                    if (false === $result) {
                        // 路由无效
                        if ($config['url_route_must']) {
                            throw new Exception('route not define ');
                        } else {
                            $result = Route::parseUrl(__INFO__);
                        }
                    }
                } else {
                    $result = Route::parseUrl(__INFO__);
                }
            }
            // 去除URL后缀
            $_SERVER['PATH_INFO'] = preg_replace($config['url_html_suffix'] ? '/\.(' . trim($config['url_html_suffix'], '.') . ')$/i' : '/\.' . __EXT__ . '$/i', '', $_SERVER['PATH_INFO']);
        }

        $module = strtolower($result[0] ?: $config['default_module']);
        if ($maps = $config['url_module_map']) {
            if (isset($maps[$module])) {
                // 记录当前别名
                define('MODULE_ALIAS', $module);
                // 获取实际的项目名
                $module = $maps[MODULE_ALIAS];
            } elseif (array_search($module, $maps)) {
                // 禁止访问原始项目
                $module = '';
            }
        }
        // 获取模块名称
        define('MODULE_NAME', defined('BIND_MODULE') ? BIND_MODULE : strip_tags($module));

        // 模块初始化
        if (MODULE_NAME && MODULE_NAME != $config['common_module'] && is_dir(APP_PATH . MODULE_NAME)) {
            Hook::listen('app_begin');
            define('MODULE_PATH', APP_PATH . MODULE_NAME . '/');
            define('VIEW_PATH', MODULE_PATH . VIEW_LAYER . '/');

            // 初始化模块
            self::initModule(MODULE_NAME, $config);
        } else {
            throw new Exception('module not exists :' . MODULE_NAME);
        }

        // 获取控制器名
        define('CONTROLLER_NAME', strip_tags(strtolower($result[1] ?: $config['default_controller'])));

        // 获取操作名
        define('ACTION_NAME', strip_tags(strtolower($result[2] ?: $config['default_action'])));
    }
}