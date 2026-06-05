<?php

declare(strict_types=1);

namespace Kivopress;

final class App
{
    public const VERSION = '0.2.0';

    private Router $router;
    private Middleware $middleware;
    private Hooks $hooks;
    private Database $db;
    private Migrator $migrator;
    private TableManager $tables;
    private Content $content;
    private Auth $auth;
    private Media $media;
    private Rest $rest;
    private RestApi $restApi;
    private CoreRegistrar $core;
    private Logger $logger;
    private ErrorHandler $errors;
    private MenuManager $menus;
    private AdminMenu $adminMenu;
    private RateLimiter $rateLimiter;
    private Scheduler $scheduler;
    private PluginLoader $plugins;
    private Theme $theme;
    private Admin $admin;
    private Installer $installer;
    private ?Request $request = null;
    private bool $booted = false;

    public function __construct(private string $rootPath, private array $config)
    {
        $this->router = new Router();
        $this->middleware = new Middleware();
        $this->hooks = new Hooks();
        $this->db = new Database($config['database']);
        $this->migrator = new Migrator($this->db);
        $this->tables = new TableManager($this->db);
        $this->content = new Content($this->db);
        $this->auth = new Auth($this->db);
        $this->media = new Media($this->db, $rootPath);
        $this->rest = new Rest($this);
        $this->restApi = new RestApi($this);
        $this->logger = new Logger($rootPath);
        $this->errors = new ErrorHandler($this, $this->logger, (bool) ($config['debug'] ?? false));
        $this->menus = new MenuManager($this->db);
        $this->adminMenu = new AdminMenu($this);
        $this->rateLimiter = new RateLimiter($rootPath);
        $this->scheduler = new Scheduler($this);
        $this->core = new CoreRegistrar($this);
        $this->plugins = new PluginLoader($this, $rootPath);
        $this->theme = new Theme($this, $rootPath, $config['theme'] ?? 'default');
        $this->admin = new Admin($this, $this->auth, $this->content);
        $this->installer = new Installer($rootPath, $config);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        date_default_timezone_set($this->config('timezone', 'UTC'));
        $this->errors->register();
        $this->core->registerMiddleware();

        if (!$this->isInstalled()) {
            $this->installer->registerRoutes($this->router);
            $this->booted = true;

            return;
        }

        $this->db->migrate();
        $this->core->registerMigrations();
        $this->core->registerContentTypes();
        $this->core->registerMenus();
        $this->plugins->load();
        $this->hooks->doAction('migrations.registered', $this->migrator);
        $this->migrator->run();
        $this->hooks->doAction('rest_api_init', $this->rest);
        $this->registerRoutes();
        $this->hooks->doAction('app.booted', $this);
        $this->core->registerSchedules();
        $this->hooks->doAction('cron.registered', $this->scheduler);
        $this->scheduler->runDue();

        $this->booted = true;
    }

    public function handle(string $method, string $path): Response
    {
        $this->request = Request::capture($method, $path);
        $method = $this->request->method();
        $path = $this->normalizePath($this->request->path());

        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper((string) $_POST['_method']);
            $this->request = $this->request->withMethod($method);
        }

        try {
            if ($method === 'OPTIONS' && $this->isRestPath($path)) {
                return $this->withCors(Response::noContent());
            }

            $result = $this->router->dispatch($this->request, null, $this->middleware);
            $response = $this->toResponse($result);

            return $this->isRestPath($path) ? $this->withCors($response) : $response;
        } catch (\Throwable $exception) {
            $response = $this->errors->exceptionResponse($exception, $this->isRestPath($path));

            return $this->isRestPath($path) ? $this->withCors($response) : $response;
        }
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function middleware(): Middleware
    {
        return $this->middleware;
    }

    public function hooks(): Hooks
    {
        return $this->hooks;
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function migrator(): Migrator
    {
        return $this->migrator;
    }

    public function tables(): TableManager
    {
        return $this->tables;
    }

    public function content(): Content
    {
        return $this->content;
    }

    public function auth(): Auth
    {
        return $this->auth;
    }

    public function media(): Media
    {
        return $this->media;
    }

    public function rest(): Rest
    {
        return $this->rest;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function errors(): ErrorHandler
    {
        return $this->errors;
    }

    public function menus(): MenuManager
    {
        return $this->menus;
    }

    public function adminMenu(): AdminMenu
    {
        return $this->adminMenu;
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    public function scheduler(): Scheduler
    {
        return $this->scheduler;
    }

    public function request(): Request
    {
        return $this->request ?? Request::capture();
    }

    public function plugins(): PluginLoader
    {
        return $this->plugins;
    }

    public function theme(): Theme
    {
        return $this->theme;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function path(string $path = ''): string
    {
        return rtrim($this->rootPath . '/' . ltrim($path, '/'), '/');
    }

    public function isInstalled(): bool
    {
        return (bool) $this->config('installed', false);
    }

    private function registerRoutes(): void
    {
        $this->admin->registerRoutes($this->router);
        $this->router->get('/api', fn (): Response => $this->rest->index());
        $this->restApi->register();
        $this->rest->registerRoutes($this->router);

        $this->router->get('/media/{id}/{filename}', fn (string $id, string $filename): Response => $this->media->serve((int) $id, $filename));
        $this->router->get('/', fn (): Response => $this->theme->renderHome());
        $this->router->get('/{path*}', fn (string $path): Response => $this->theme->renderPath($path));
    }

    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        return Response::html('Not found.', 404);
    }

    private function withCors(Response $response): Response
    {
        if (!$this->config('api.cors', true)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Kivopress-Token')
            ->withHeader('Access-Control-Allow-Methods', 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS');
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');

        return $path === '//' ? '/' : $path;
    }

    private function isRestPath(string $path): bool
    {
        return str_starts_with($path, '/api');
    }
}
