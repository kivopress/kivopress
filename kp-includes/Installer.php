<?php

declare(strict_types=1);

namespace Kivopress;

use PDO;

final class Installer
{
    public function __construct(private string $rootPath, private array $config)
    {
    }

    public function registerRoutes(Router $router): void
    {
        $router->get('/', fn (): Response => Response::redirect('/setup'));
        $router->get('/setup', fn (): Response => $this->form());
        $router->post('/setup', fn (): Response => $this->install());
        $router->get('/admin', fn (): Response => Response::redirect('/setup'));
        $router->get('/admin/setup', fn (): Response => Response::redirect('/setup'));
        $router->get('/api', fn (): Response => Response::json(['error' => 'Kivopress is not installed yet.'], 503));
    }

    private function form(array $old = [], ?string $error = null): Response
    {
        $old = array_merge($this->formDefaults(), $old);

        $driver = (string) $old['driver'];
        $errorHtml = $error ? '<div class="notice error">' . \e($error) . '</div>' : '';
        $databaseHelp = $this->databaseHelp();

        return $this->layout('Setup Kivopress', $errorHtml . '
            <form method="post" action="/setup">
                <input type="hidden" name="_csrf" value="' . \e($this->csrfToken()) . '">
                <fieldset>
                    <legend>Site</legend>
                    <label>Site Name<input name="site_name" value="' . \e((string) $old['site_name']) . '" required autofocus></label>
                </fieldset>

                <fieldset>
                    <legend>Database</legend>
                    <label>Storage
                        <select name="driver">
                            <option value="mysql" ' . ($driver === 'mysql' ? 'selected' : '') . '>MySQL</option>
                            <option value="file" ' . ($driver === 'file' ? 'selected' : '') . '>Local JSON file</option>
                        </select>
                    </label>
                    <div class="mysql-grid">
                        <label>Database Name<input name="database" value="' . \e((string) $old['database']) . '"></label>
                        <label>Username<input name="username" value="' . \e((string) $old['username']) . '"></label>
                        <label>Password<input type="password" name="password" value=""></label>
                        <label>Host<input name="host" value="' . \e((string) $old['host']) . '"></label>
                        <label>Port<input name="port" value="' . \e((string) $old['port']) . '"></label>
                        <label>Charset<input name="charset" value="' . \e((string) $old['charset']) . '"></label>
                    </div>
                    ' . $databaseHelp . '
                    <label class="check"><input type="checkbox" name="create_database" value="1" ' . (!empty($old['create_database']) ? 'checked' : '') . '> Create the database if it does not exist</label>
                </fieldset>

                <fieldset>
                    <legend>Admin User</legend>
                    <label>Name<input name="admin_name" value="' . \e((string) $old['admin_name']) . '" required></label>
                    <label>Email<input type="email" name="admin_email" value="' . \e((string) $old['admin_email']) . '" required></label>
                    <label>Password<input type="password" name="admin_password" required minlength="8"></label>
                </fieldset>

                <button>Install Kivopress</button>
            </form>
        ');
    }

    private function install(): Response
    {
        $input = $this->input();

        try {
            if (!$this->validCsrf($_POST['_csrf'] ?? null)) {
                throw new \InvalidArgumentException('Your setup session expired. Refresh and try again.');
            }

            $this->validate($input);
            $databaseConfig = $this->databaseConfig($input);

            if ($databaseConfig['driver'] === 'mysql') {
                $this->prepareMysqlDatabase($databaseConfig, !empty($input['create_database']));
            }

            $this->ensureLocalConfigWritable();

            $database = new Database($databaseConfig);
            $database->migrate();
            $database->setOption('site_name', $input['site_name']);
            $database->setOption('admin_email', $input['admin_email']);

            $auth = new Auth($database);
            $auth->createAdmin($input['admin_name'], $input['admin_email'], $input['admin_password']);

            $this->writeLocalConfig([
                'name' => $input['site_name'],
                'database' => $databaseConfig,
                'theme' => 'default',
                'api' => ['cors' => true],
            ]);

            $auth->flash('notice', 'Kivopress installed.');

            return Response::redirect('/admin');
        } catch (\Throwable $exception) {
            return $this->form($input, $this->friendlyInstallError($exception, $input));
        }
    }

    private function formDefaults(): array
    {
        $database = $this->config['database'] ?? [];
        $account = $this->cpanelAccountName();
        $isCpanel = $account !== null;
        $configuredName = trim((string) ($database['name'] ?? 'kivopress'));
        $configuredUser = trim((string) ($database['user'] ?? 'root'));
        $configuredHost = trim((string) ($database['host'] ?? '127.0.0.1'));

        return [
            'site_name' => (string) ($this->config['name'] ?? 'Kivopress'),
            'driver' => 'mysql',
            'host' => $isCpanel && in_array($configuredHost, ['', '127.0.0.1'], true) ? 'localhost' : ($configuredHost ?: '127.0.0.1'),
            'port' => trim((string) ($database['port'] ?? '3306')) ?: '3306',
            'database' => $isCpanel && in_array($configuredName, ['', 'kivopress'], true) ? $account . '_kivopress' : ($configuredName ?: 'kivopress'),
            'username' => $isCpanel && in_array($configuredUser, ['', 'root'], true) ? $account . '_kivopress' : ($configuredUser ?: 'root'),
            'charset' => trim((string) ($database['charset'] ?? 'utf8mb4')) ?: 'utf8mb4',
            'create_database' => $isCpanel ? '' : '1',
            'admin_name' => '',
            'admin_email' => '',
        ];
    }

    private function databaseHelp(): string
    {
        $account = $this->cpanelAccountName();
        $prefix = $account ? $account . '_' : 'account_';

        return '<p class="kp-install-help">On cPanel, create the MySQL database and user in cPanel first, assign the user to the database, and enter the full prefixed names such as <code>' . \e($prefix) . 'kivopress</code>. The host is usually <code>localhost</code>; PHP cannot auto-detect the database password.</p>';
    }

    private function friendlyInstallError(\Throwable $exception, array $input): string
    {
        $message = $exception->getMessage();
        $lower = strtolower($message);

        if ($exception instanceof \PDOException || str_contains($lower, 'sqlstate')) {
            if (str_contains($lower, 'access denied')) {
                if (($input['username'] ?? '') === 'root' && ($input['password'] ?? '') === '') {
                    return 'MySQL rejected root without a password. On cPanel, use the MySQL database user you created in cPanel, not root. The full username often looks like account_user.';
                }

                if (!empty($input['create_database'])) {
                    return 'MySQL rejected the database operation. On cPanel, create the database in cPanel, assign the user to it, then retry with "Create the database" unchecked.';
                }

                return 'MySQL rejected those database credentials. Check the full cPanel database name, database username, password, and user privileges.';
            }

            if (str_contains($lower, 'unknown database')) {
                return 'MySQL could not find that database. Create it in cPanel first, or enable database creation only when your MySQL user has CREATE DATABASE permission.';
            }

            if (str_contains($lower, 'connection refused') || str_contains($lower, 'getaddrinfo') || str_contains($lower, 'no such file or directory')) {
                return 'Kivopress could not reach MySQL. On cPanel, the host is usually localhost and the port is usually 3306.';
            }
        }

        return $message;
    }

    private function validate(array $input): void
    {
        foreach (['site_name', 'driver', 'admin_name', 'admin_email', 'admin_password'] as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                throw new \InvalidArgumentException('Please fill all required fields.');
            }
        }

        if (!filter_var($input['admin_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid admin email address.');
        }

        if (strlen((string) $input['admin_password']) < 8) {
            throw new \InvalidArgumentException('Admin password must be at least 8 characters.');
        }

        if (!in_array($input['driver'], ['mysql', 'file'], true)) {
            throw new \InvalidArgumentException('Choose a supported storage driver.');
        }

        if ($input['driver'] === 'mysql' && !preg_match('/^[a-zA-Z0-9_]+$/', (string) ($input['database'] ?? ''))) {
            throw new \InvalidArgumentException('Database name may contain only letters, numbers, and underscores.');
        }
    }

    private function databaseConfig(array $input): array
    {
        if ($input['driver'] === 'file') {
            return [
                'driver' => 'file',
                'path' => $this->rootPath . '/kp-content/data/kivopress.sqlite',
                'charset' => 'utf8mb4',
                'prefix' => (string) ($this->config['database']['prefix'] ?? 'kp_'),
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => trim((string) $input['host']),
            'port' => trim((string) $input['port']) ?: '3306',
            'name' => trim((string) $input['database']),
            'user' => trim((string) $input['username']),
            'password' => (string) ($input['password'] ?? ''),
            'charset' => trim((string) $input['charset']) ?: 'utf8mb4',
            'prefix' => (string) ($this->config['database']['prefix'] ?? 'kp_'),
        ];
    }

    private function cpanelAccountName(): ?string
    {
        foreach ($this->serverPaths() as $path) {
            $path = str_replace('\\', '/', $path);

            if (preg_match('#/(?:home|home\d+)/([^/]+)/(?:public_html|www)(?:/|$)#', $path, $match)) {
                return $this->validAccountName($match[1]);
            }
        }

        $cpanelUsername = getenv('CPANEL_USERNAME');

        if (is_string($cpanelUsername) && ($account = $this->validAccountName($cpanelUsername))) {
            return $account;
        }

        $serverSoftware = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));

        if (str_contains($serverSoftware, 'cpanel')) {
            foreach (['USER', 'LOGNAME'] as $key) {
                $value = getenv($key);

                if (is_string($value) && ($account = $this->validAccountName($value))) {
                    return $account;
                }
            }
        }

        return null;
    }

    private function serverPaths(): array
    {
        return array_filter([
            $this->rootPath,
            $_SERVER['DOCUMENT_ROOT'] ?? null,
            $_SERVER['SCRIPT_FILENAME'] ?? null,
        ], 'is_string');
    }

    private function validAccountName(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{1,31}$/', $value) ? $value : null;
    }

    private function prepareMysqlDatabase(array $databaseConfig, bool $createDatabase): void
    {
        if (!class_exists(PDO::class) || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException('The pdo_mysql PHP extension is required for MySQL installs.');
        }

        $charset = $databaseConfig['charset'];
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=%s',
            $databaseConfig['host'],
            $databaseConfig['port'],
            $charset
        );

        $pdo = new PDO($dsn, $databaseConfig['user'], $databaseConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        if ($createDatabase) {
            $database = str_replace('`', '``', $databaseConfig['name']);
            $collation = $charset === 'utf8mb4' ? 'utf8mb4_unicode_ci' : $charset . '_general_ci';
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET {$charset} COLLATE {$collation}");
        }
    }

    private function writeLocalConfig(array $config): void
    {
        $path = $this->config['local_config_path'] ?? $this->rootPath . '/kp-content/config.php';
        $contents = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write kp-content/config.php.');
        }
    }

    private function ensureLocalConfigWritable(): void
    {
        $path = $this->config['local_config_path'] ?? $this->rootPath . '/kp-content/config.php';
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (!is_writable($dir)) {
            throw new \RuntimeException('The kp-content directory is not writable.');
        }
    }

    private function input(): array
    {
        return [
            'site_name' => trim((string) ($_POST['site_name'] ?? '')),
            'driver' => (string) ($_POST['driver'] ?? 'mysql'),
            'host' => trim((string) ($_POST['host'] ?? '127.0.0.1')),
            'port' => trim((string) ($_POST['port'] ?? '3306')),
            'database' => trim((string) ($_POST['database'] ?? 'kivopress')),
            'username' => trim((string) ($_POST['username'] ?? 'root')),
            'password' => (string) ($_POST['password'] ?? ''),
            'charset' => trim((string) ($_POST['charset'] ?? 'utf8mb4')),
            'create_database' => isset($_POST['create_database']) ? '1' : '',
            'admin_name' => trim((string) ($_POST['admin_name'] ?? '')),
            'admin_email' => trim((string) ($_POST['admin_email'] ?? '')),
            'admin_password' => (string) ($_POST['admin_password'] ?? ''),
        ];
    }

    private function layout(string $title, string $body): Response
    {
        return Response::html('<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . \e($title) . '</title>
<link rel="stylesheet" href="/kp-admin/assets/kivopress-ui.css">
<link rel="stylesheet" href="/kp-admin/assets/kivopress-shell.css">
</head>
<body class="kp-install">
<main class="kp-install-shell">
    <aside class="kp-install-side">
        <a class="kp-brand" href="/"><span>K</span>Kivopress</a>
        <h1>' . \e($title) . '</h1>
        <p>Database, admin account, then your clean dashboard. Tiny core first, everything else optional.</p>
        <div class="kp-install-steps"><span>1</span><span>2</span><span>3</span></div>
    </aside>
    <section class="kp-install-card">' . $body . '</section>
</main>
</body>
</html>');
    }

    private function csrfToken(): string
    {
        $this->session();

        if (empty($_SESSION['kivopress_setup_csrf'])) {
            $_SESSION['kivopress_setup_csrf'] = bin2hex(random_bytes(16));
        }

        return $_SESSION['kivopress_setup_csrf'];
    }

    private function validCsrf(?string $token): bool
    {
        $this->session();

        return is_string($token) && hash_equals($_SESSION['kivopress_setup_csrf'] ?? '', $token);
    }

    private function session(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
}
