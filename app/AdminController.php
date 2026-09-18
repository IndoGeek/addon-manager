<?php

namespace Pterodactyl\Http\Controllers\Admin\Extensions\modpackinstaller;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Catalog\CatalogSort;
use Pterodactyl\BlueprintFramework\Extensions\modpackinstaller\Services\Management\InstallHistoryStore;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Helpers\SoftwareVersionService;

// Blueprint copies this file to app/Http/Controllers/Admin/Extensions/modpackinstaller/modpackinstallerExtensio...
class modpackinstallerExtensionController extends Controller
{
    // Settings keys persisted through Blueprint's extension library.
    private const SETTING_PREFIX = 'modpackinstaller:setting_';

    private const SETTING_API_KEY = self::SETTING_PREFIX . 'curseforge_api_key';

    private const SETTING_MAX_DOWNLOAD_MB = self::SETTING_PREFIX . 'max_download_mb';

    private const SETTING_CATALOG_CACHE_TTL = self::SETTING_PREFIX . 'catalog_cache_ttl';

    private const SETTING_SERVER_TARGET = self::SETTING_PREFIX . 'server_target';

    private const SETTING_DEFAULT_PROVIDER = self::SETTING_PREFIX . 'default_provider';

    private const SETTING_DEFAULT_SORT = self::SETTING_PREFIX . 'default_sort';

    private const SETTING_PAGE_SIZE = self::SETTING_PREFIX . 'page_size';

    private const SETTING_DISABLE_CURSEFORGE = self::SETTING_PREFIX . 'disable_curseforge';

    public function __construct(
        private \Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary $blueprint,
        private SoftwareVersionService $version,
        private ViewFactory $view,
    ) {
    }

    // Render the settings page the extensions list card opens.
    public function index(): View
    {
        return $this->view->make('admin.extensions.modpackinstaller.index', [
            'blueprint' => $this->blueprint,
            'version' => $this->version,
            'root' => '/admin/extensions/modpackinstaller',
            'settings' => $this->currentSettings(),
            'history' => $this->installHistory()->all(30),
            'envValues' => [
                'curseforge_api_key' => config('modpackinstaller.curseforge_api_key'),
                'max_download_mb' => config('modpackinstaller.max_download_mb'),
                'catalog_cache_ttl' => config('modpackinstaller.catalog_cache_ttl'),
                'server_target' => config('modpackinstaller.server_target', 'local'),
            ],
        ]);
    }

    // Persist the settings form (PATCH via the settings page form).
    public function update(): RedirectResponse
    {
        // The API key field is intentionally write-only from the browser's perspective: empty means "keep the saved key...
        if (request()->boolean('clear_curseforge_api_key')) {
            $this->blueprint->dbSet('modpackinstaller', 'setting_curseforge_api_key', '');
        } else {
            $apiKey = trim((string) request()->input('curseforge_api_key', ''));

            if ($apiKey !== '') {
                $this->blueprint->dbSet('modpackinstaller', 'setting_curseforge_api_key', $apiKey);
            }
        }

        $this->blueprint->dbSet(
            'modpackinstaller',
            'setting_max_download_mb',
            $this->positiveIntegerOrEmpty(request()->input('max_download_mb')),
        );

        $this->blueprint->dbSet(
            'modpackinstaller',
            'setting_catalog_cache_ttl',
            $this->positiveIntegerOrEmpty(request()->input('catalog_cache_ttl')),
        );

        $target = (string) request()->input('server_target', 'local');

        $this->blueprint->dbSet(
            'modpackinstaller',
            'setting_server_target',
            $target === 'wings' ? 'wings' : 'local',
        );

        // Default catalog provider: only accept known provider slugs.
        $provider = (string) request()->input('default_provider', '');

        $this->blueprint->dbSet(
            'modpackinstaller',
            'setting_default_provider',
            in_array($provider, ['modrinth', 'curseforge'], true)
                ? $provider
                : '',
        );

        // Default sort order: only accept values the catalog API supports.
        $sort = (string) request()->input('default_sort', '');
        $validSorts = array_map(
            static fn (CatalogSort $case): string => $case->value,
            CatalogSort::cases(),
        );

        $this->blueprint->dbSet(
            'modpackinstaller',
            'setting_default_sort',
            in_array($sort, $validSorts, true) ? $sort : '',
        );

        // Default results per page: 10/20/30 match the dashboard's Stack options; empty falls back to the API default (...
        $pageSize = (string) request()->input('page_size', '');

        $this->blueprint->dbSet(
            'modpackinstaller',
            'setting_page_size',
            in_array($pageSize, ['10', '20', '30'], true) ? $pageSize : '',
        );

        // Disabling CurseForge hides the provider everywhere and rejects its installs; Modrinth keeps working.
        $this->blueprint->dbSet(
            'modpackinstaller',
            'setting_disable_curseforge',
            request()->boolean('disable_curseforge') ? '1' : '0',
        );

        return redirect()->route('admin.extensions.modpackinstaller.index');
    }

    // Blueprint also routes POST/PUT here; keep them as aliases.
    public function post(): RedirectResponse
    {
        return $this->update();
    }

    public function put(): RedirectResponse
    {
        return $this->update();
    }

    private function currentSettings(): array
    {
        return [
            'curseforge_api_key' => (string) $this->blueprint->dbGet(
                'modpackinstaller',
                'setting_curseforge_api_key',
            ),
            'max_download_mb' => (string) $this->blueprint->dbGet(
                'modpackinstaller',
                'setting_max_download_mb',
            ),
            'catalog_cache_ttl' => (string) $this->blueprint->dbGet(
                'modpackinstaller',
                'setting_catalog_cache_ttl',
            ),
            'server_target' => (string) $this->blueprint->dbGet(
                'modpackinstaller',
                'setting_server_target',
            ),
            'default_provider' => (string) $this->blueprint->dbGet(
                'modpackinstaller',
                'setting_default_provider',
            ),
            'default_sort' => (string) $this->blueprint->dbGet(
                'modpackinstaller',
                'setting_default_sort',
            ),
            'page_size' => (string) $this->blueprint->dbGet(
                'modpackinstaller',
                'setting_page_size',
            ),
            'disable_curseforge' => (string) $this->blueprint->dbGet(
                'modpackinstaller',
                'setting_disable_curseforge',
            ),
        ];
    }

    private function installHistory(): InstallHistoryStore
    {
        return new InstallHistoryStore($this->historyDirectory());
    }

    private function historyDirectory(): string
    {
        $directory = config('modpackinstaller.data_dir');

        if (is_string($directory) && $directory !== '') {
            return rtrim($directory, '/');
        }

        return '/var/lib/pterodactyl/modpack-installer';
    }

    private function positiveIntegerOrEmpty($value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (!ctype_digit($value) || (int) $value <= 0) {
            // Ignore invalid input rather than storing it; the page simply keeps the previously saved value.
            return '';
        }

        return (string) (int) $value;
    }
}
