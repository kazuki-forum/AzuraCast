<?php

declare(strict_types=1);

namespace App;

use App\Console\Application;
use App\Enums\SupportedLocales;
use App\Http\Factory\ResponseFactory;
use App\Http\Factory\ServerRequestFactory;
use DI;
use Invoker\Invoker;
use Invoker\ParameterResolver\AssociativeArrayResolver;
use Invoker\ParameterResolver\Container\TypeHintContainerResolver;
use Invoker\ParameterResolver\DefaultValueResolver;
use Invoker\ParameterResolver\ResolverChain;
use Monolog\Registry;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Slim\App;
use Slim\Factory\ServerRequestCreatorFactory;

use const E_COMPILE_ERROR;
use const E_CORE_ERROR;
use const E_ERROR;
use const E_PARSE;
use const E_USER_ERROR;

class AppFactory
{
    public static function createApp(
        array $appEnvironment = [],
        array $diDefinitions = []
    ): App {
        $di = self::buildContainer($appEnvironment, $diDefinitions);
        return self::buildAppFromContainer($di);
    }

    public static function createCli(
        array $appEnvironment = [],
        array $diDefinitions = []
    ): Application {
        $di = self::buildContainer($appEnvironment, $diDefinitions);
        self::buildAppFromContainer($di);

        $env = $di->get(Environment::class);

        SupportedLocales::createForCli($env);

        return $di->get(Application::class);
    }

    public static function buildAppFromContainer(DI\Container $container): App
    {
        ServerRequestCreatorFactory::setSlimHttpDecoratorsAutomaticDetection(false);
        ServerRequestCreatorFactory::setServerRequestCreator(new ServerRequestFactory());

        $app = new App(
            responseFactory: new ResponseFactory(),
            container: $container,
        );
        $container->set(App::class, $app);

        $routeCollector = $app->getRouteCollector();

        // Use the PHP-DI Bridge's action invocation helper.
        $resolvers = [
            // Inject parameters by name first
            new AssociativeArrayResolver(),
            // Then inject services by type-hints for those that weren't resolved
            new TypeHintContainerResolver($container),
            // Then fall back on parameters default values for optional route parameters
            new DefaultValueResolver(),
        ];

        $invoker = new Invoker(new ResolverChain($resolvers), $container);
        $controllerInvoker = new ControllerInvoker($invoker);

        $routeCollector->setDefaultInvocationStrategy($controllerInvoker);

        $environment = $container->get(Environment::class);
        if ($environment->isProduction()) {
            $routeCollector->setCacheFile($environment->getTempDirectory() . '/app_routes.cache.php');
        }

        $eventDispatcher = $container->get(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(new Event\BuildRoutes($app));

        return $app;
    }

    public static function buildContainer(
        array $appEnvironment = [],
        array $diDefinitions = []
    ): DI\Container {
        $environment = self::buildEnvironment($appEnvironment);
        Environment::setInstance($environment);

        self::applyPhpSettings($environment);

        // Override DI definitions for settings.
        $diDefinitions[Environment::class] = $environment;

        $plugins = new Plugins($environment->getBaseDirectory() . '/plugins');

        $diDefinitions[Plugins::class] = $plugins;
        $diDefinitions = $plugins->registerServices($diDefinitions);

        $containerBuilder = new DI\ContainerBuilder();
        $containerBuilder->useAutowiring(true);

        if ($environment->isProduction()) {
            $containerBuilder->enableCompilation($environment->getTempDirectory());
        }

        $containerBuilder->addDefinitions($diDefinitions);

        // Check for services.php file and include it if one exists.
        $config_dir = $environment->getConfigDirectory();
        if (file_exists($config_dir . '/services.php')) {
            $containerBuilder->addDefinitions($config_dir . '/services.php');
        }

        $di = $containerBuilder->build();

        $logger = $di->get(LoggerInterface::class);

        register_shutdown_function(
            static function (LoggerInterface $logger): void {
                $error = error_get_last();
                if (null === $error) {
                    return;
                }

                $errno = $error["type"];
                $errfile = $error["file"];
                $errline = $error["line"];
                $errstr = $error["message"];

                if ($errno &= E_PARSE | E_ERROR | E_USER_ERROR | E_CORE_ERROR | E_COMPILE_ERROR) {
                    $logger->critical(
                        sprintf(
                            'Fatal error: %s in %s on line %d',
                            $errstr,
                            $errfile,
                            $errline
                        )
                    );
                }
            },
            $logger
        );

        Registry::addLogger($logger, 'app', true);

        return $di;
    }

    /**
     * @param array<string, mixed> $environment
     */
    public static function buildEnvironment(array $environment = []): Environment
    {
        if (!isset($environment[Environment::BASE_DIR])) {
            throw new Exception\BootstrapException('No base directory specified!');
        }

        $environment[Environment::IS_DOCKER] = file_exists(
            dirname($environment[Environment::BASE_DIR]) . '/.docker'
        );

        $environment[Environment::TEMP_DIR] ??= dirname($environment[Environment::BASE_DIR]) . '/www_tmp';
        $environment[Environment::CONFIG_DIR] ??= $environment[Environment::BASE_DIR] . '/config';
        $environment[Environment::VIEWS_DIR] ??= $environment[Environment::BASE_DIR] . '/templates';
        $environment[Environment::UPLOADS_DIR] ??= dirname($environment[Environment::BASE_DIR]) . '/uploads';

        if (file_exists($environment[Environment::BASE_DIR] . '/env.ini')) {
            $envIni = parse_ini_file($environment[Environment::BASE_DIR] . '/env.ini');
            if (false !== $envIni) {
                $_ENV = array_merge($_ENV, $envIni);
            }
        } else {
            $_ENV = getenv();
        }

        $environment = array_merge(array_filter($_ENV), $environment);

        return new Environment($environment);
    }

    protected static function applyPhpSettings(Environment $environment): void
    {
        error_reporting(
            $environment->isProduction()
                ? E_ALL & ~E_NOTICE & ~E_WARNING & ~E_STRICT & ~E_DEPRECATED
                : E_ALL & ~E_NOTICE
        );

        $displayStartupErrors = (!$environment->isProduction() || $environment->isCli())
            ? '1'
            : '0';
        ini_set('display_startup_errors', $displayStartupErrors);
        ini_set('display_errors', $displayStartupErrors);

        ini_set('log_errors', '1');
        ini_set(
            'error_log',
            $environment->isDocker()
                ? '/dev/stderr'
                : $environment->getTempDirectory() . '/php_errors.log'
        );

        if (!headers_sent()) {
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_lifetime', '86400');
            ini_set('session.use_strict_mode', '1');

            session_cache_limiter('');
        }

        date_default_timezone_set('UTC');
    }
}
