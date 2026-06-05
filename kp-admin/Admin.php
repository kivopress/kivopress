<?php

declare(strict_types=1);

namespace Kivopress;

use Kivopress\Admin\AdminView;
use Kivopress\Admin\Controllers\AuthController;
use Kivopress\Admin\Controllers\ContentController;
use Kivopress\Admin\Controllers\DashboardController;
use Kivopress\Admin\Controllers\ErrorsController;
use Kivopress\Admin\Controllers\ExtensionsController;
use Kivopress\Admin\Controllers\MediaController;
use Kivopress\Admin\Controllers\MenusController;
use Kivopress\Admin\Controllers\RestController;
use Kivopress\Admin\Controllers\SettingsController;
use Kivopress\Admin\Controllers\TaxonomyController;
use Kivopress\Admin\Controllers\UsersController;

final class Admin
{
    private AdminView $view;
    private AuthController $authController;
    private DashboardController $dashboardController;
    private SettingsController $settingsController;
    private ExtensionsController $extensionsController;
    private ContentController $contentController;
    private TaxonomyController $taxonomyController;
    private MediaController $mediaController;
    private UsersController $usersController;
    private RestController $restController;
    private MenusController $menusController;
    private ErrorsController $errorsController;

    public function __construct(private App $app, private Auth $auth, private Content $content)
    {
        $this->view = new AdminView($app, $auth, $content);
        $this->authController = new AuthController($app, $auth, $content, $this->view);
        $this->dashboardController = new DashboardController($app, $auth, $content, $this->view);
        $this->settingsController = new SettingsController($app, $auth, $content, $this->view);
        $this->extensionsController = new ExtensionsController($app, $auth, $content, $this->view);
        $this->contentController = new ContentController($app, $auth, $content, $this->view);
        $this->taxonomyController = new TaxonomyController($app, $auth, $content, $this->view);
        $this->mediaController = new MediaController($app, $auth, $content, $this->view);
        $this->usersController = new UsersController($app, $auth, $content, $this->view);
        $this->restController = new RestController($app, $auth, $content, $this->view);
        $this->menusController = new MenusController($app, $auth, $content, $this->view);
        $this->errorsController = new ErrorsController($app, $auth, $content, $this->view);
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/admin/setup', fn (): Response => $this->authController->setup());
        $router->post('/admin/setup', fn (): Response => $this->authController->storeSetup());
        $router->get('/admin/login', fn (): Response => $this->authController->login());
        $router->post('/admin/login', fn (): Response => $this->authController->attemptLogin());
        $router->post('/admin/logout', fn (): Response => $this->authController->logout());

        $router->get('/admin', fn (): Response => $this->dashboardController->index());

        $router->get('/admin/settings', fn (): Response => $this->settingsController->index());
        $router->post('/admin/settings', fn (): Response => $this->settingsController->save());
        $router->get('/admin/settings/permalinks', fn (): Response => $this->settingsController->permalinks());
        $router->post('/admin/settings/permalinks', fn (): Response => $this->settingsController->savePermalinks());
        $router->get('/admin/api-tokens', fn (): Response => Response::redirect('/admin/settings'));
        $router->post('/admin/settings/api-tokens', fn (): Response => $this->settingsController->createApiToken());
        $router->post('/admin/settings/api-tokens/{id}/revoke', fn (string $id): Response => $this->settingsController->revokeApiToken((int) $id));
        $router->post('/admin/settings/api-tokens/{id}/rotate', fn (string $id): Response => $this->settingsController->rotateApiToken((int) $id));
        $router->post('/admin/api-tokens', fn (): Response => $this->settingsController->createApiToken());
        $router->post('/admin/api-tokens/{id}/revoke', fn (string $id): Response => $this->settingsController->revokeApiToken((int) $id));
        $router->post('/admin/api-tokens/{id}/rotate', fn (string $id): Response => $this->settingsController->rotateApiToken((int) $id));

        $router->get('/admin/users', fn (): Response => $this->usersController->index());
        $router->get('/admin/users/new', fn (): Response => $this->usersController->create());
        $router->post('/admin/users', fn (): Response => $this->usersController->store());
        $router->get('/admin/users/{id}/edit', fn (string $id): Response => $this->usersController->edit((int) $id));
        $router->post('/admin/users/{id}', fn (string $id): Response => $this->usersController->update((int) $id));
        $router->post('/admin/users/{id}/delete', fn (string $id): Response => $this->usersController->delete((int) $id));

        $router->get('/admin/rest-api', fn (): Response => $this->restController->index());
        $router->get('/admin/tools', fn (): Response => Response::redirect('/admin/tools/errors'));
        $router->get('/admin/tools/errors', fn (): Response => $this->errorsController->index());
        $router->post('/admin/tools/errors/clear', fn (): Response => $this->errorsController->clear());

        $router->get('/admin/media', fn (): Response => $this->mediaController->index());
        $router->post('/admin/media/upload', fn (): Response => $this->mediaController->upload());
        $router->get('/admin/media/{id}/edit', fn (string $id): Response => $this->mediaController->edit((int) $id));
        $router->post('/admin/media/{id}', fn (string $id): Response => $this->mediaController->update((int) $id));
        $router->post('/admin/media/{id}/delete', fn (string $id): Response => $this->mediaController->delete((int) $id));

        $router->get('/admin/themes', fn (): Response => $this->extensionsController->themes());
        $router->get('/admin/menus', fn (): Response => $this->menusController->index());
        $router->post('/admin/menus', fn (): Response => $this->menusController->save());
        $router->post('/admin/themes/upload', fn (): Response => $this->extensionsController->uploadTheme());
        $router->post('/admin/themes/{slug}/activate', fn (string $slug): Response => $this->extensionsController->activateTheme($slug));
        $router->get('/admin/plugins', fn (): Response => $this->extensionsController->plugins());
        $router->post('/admin/plugins/upload', fn (): Response => $this->extensionsController->uploadPlugin());
        $router->post('/admin/plugins/{slug}/activate', fn (string $slug): Response => $this->extensionsController->activatePlugin($slug));
        $router->post('/admin/plugins/{slug}/deactivate', fn (string $slug): Response => $this->extensionsController->deactivatePlugin($slug));

        $router->get('/admin/taxonomies/{taxonomy}', fn (string $taxonomy): Response => $this->taxonomyController->index($taxonomy));
        $router->post('/admin/taxonomies/{taxonomy}', fn (string $taxonomy): Response => $this->taxonomyController->store($taxonomy));
        $router->get('/admin/taxonomies/{taxonomy}/{id}/edit', fn (string $taxonomy, string $id): Response => $this->taxonomyController->edit($taxonomy, (int) $id));
        $router->post('/admin/taxonomies/{taxonomy}/{id}', fn (string $taxonomy, string $id): Response => $this->taxonomyController->update($taxonomy, (int) $id));
        $router->post('/admin/taxonomies/{taxonomy}/{id}/delete', fn (string $taxonomy, string $id): Response => $this->taxonomyController->delete($taxonomy, (int) $id));

        $router->get('/admin/content/{type}', fn (string $type): Response => $this->contentController->index($type));
        $router->get('/admin/content/{type}/new', fn (string $type): Response => $this->contentController->form($type));
        $router->post('/admin/content/{type}', fn (string $type): Response => $this->contentController->store($type));
        $router->post('/admin/content/{type}/bulk', fn (string $type): Response => $this->contentController->bulk($type));
        $router->get('/admin/content/{type}/{id}/edit', fn (string $type, string $id): Response => $this->contentController->form($type, (int) $id));
        $router->post('/admin/content/{type}/{id}', fn (string $type, string $id): Response => $this->contentController->update($type, (int) $id));
        $router->post('/admin/content/{type}/{id}/delete', fn (string $type, string $id): Response => $this->contentController->delete($type, (int) $id));
        $router->get('/admin/{path*}', fn (string $path): Response => $this->menuCallbackPage($path));
    }

    private function menuCallbackPage(string $path): Response
    {
        if ($redirect = $this->auth->requireAdmin()) {
            return $redirect;
        }

        $href = '/admin/' . trim($path, '/');
        $item = $this->app->adminMenu()->findByHref($href);
        $callback = $item['callback'] ?? null;

        if (!$item || $callback === null) {
            return $this->view->layout('Not Found', '<p>Admin page not found.</p>', true, 404);
        }

        $capability = (string) ($item['capability'] ?? 'read');

        if ($capability !== '' && !$this->auth->can($capability)) {
            return $this->view->layout('Forbidden', '<p>You do not have permission to access this area.</p>', true, 403);
        }

        $title = (string) ($item['page_title'] ?? $item['label'] ?? 'Admin');

        if (is_string($callback) && !is_callable($callback)) {
            return $this->view->layout($title, $this->view->template($callback, ['menuItem' => $item]));
        }

        if (!is_callable($callback)) {
            return $this->view->layout('Invalid Admin Page', '<p>This admin menu item does not have a callable screen.</p>', true, 500);
        }

        ob_start();

        try {
            $result = $this->callMenuCallback($callback, $item);
            $output = (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        if ($result instanceof Response) {
            return $result;
        }

        $body = $output . (is_string($result) ? $result : '');

        return $this->view->layout($title, $body);
    }

    private function callMenuCallback(callable $callback, array $item): mixed
    {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));
        $args = [$item, $this->view, $this->app];

        return $callback(...array_slice($args, 0, $reflection->getNumberOfParameters()));
    }
}
