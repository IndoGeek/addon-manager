<?php
// Load the settings-page stylesheet directly (with a cache-busting query)
// instead of relying on Blueprint's admin.extensions.css @import chain, which
// browsers and CDNs cache aggressively.
$miStylePath = public_path('assets/extensions/modpackinstaller/admin.style.css');
$miStyleVersion = is_file($miStylePath) ? filemtime($miStylePath) : '1';
?>
<link rel="stylesheet" href="/assets/extensions/modpackinstaller/admin.style.css?v={{ $miStyleVersion }}">

<div class="mi-settings" style="margin-top: 10px;">

  <div class="row">
    <div class="col-xs-12">
      <div class="panel panel-default mi-panel">
        <div class="panel-heading">
          <h3 class="panel-title" style="font-weight:600;">
            <i class="bi bi-gear-fill" style="margin-right:6px;"></i>Extension settings
          </h3>
        </div>
        <div class="panel-body">
          <p class="text-muted" style="margin-bottom:18px;">
            These settings are stored in the panel database and take effect immediately —
            no <code>.env</code> edit or PHP-FPM restart required. Values left empty fall back
            to the panel's environment configuration.
          </p>

          <form method="POST" action="{{ $root }}" autocomplete="off">
            {{ csrf_field() }}
            <input type="hidden" name="_method" value="PATCH">

            <div class="form-group">
              <label class="control-label" style="font-weight:600;">CurseForge API key</label>
              <div class="input-group">
                <span class="input-group-addon"><i class="bi bi-key-fill"></i></span>
                <input
                  type="password"
                  class="form-control"
                  name="curseforge_api_key"
                  value=""
                  autocomplete="new-password"
                  placeholder="{{ $settings['curseforge_api_key'] !== '' ? '•••••••••••• saved — enter a new key to replace' : (empty($envValues['curseforge_api_key']) ? 'Not configured — CurseForge stays unavailable' : 'Using value from .env — override here') }}"
                />
              </div>
              <p class="text-muted small" style="margin-top:6px;">
                Enables the CurseForge provider. Get a free key at
                <a href="https://console.curseforge.com/" target="_blank" rel="noopener">console.curseforge.com</a>.
                The saved key is stored server-side and never displayed again — leave the field empty to keep it.
                @if($settings['curseforge_api_key'] !== '')
                  A key is currently saved.
                @elseif(!empty($envValues['curseforge_api_key']))
                  A key from <code>.env</code> is currently in use.
                @endif
              </p>
              @if($settings['curseforge_api_key'] !== '')
                <div class="checkbox">
                  <input type="checkbox" id="mi-clear-key" name="clear_curseforge_api_key" value="1" />
                  <label for="mi-clear-key">
                    <span>Remove the saved key (falls back to <code>.env</code>)</span>
                  </label>
                </div>
              @endif
            </div>

            <div class="row">
              <div class="col-xs-6">
                <div class="form-group">
                  <label class="control-label" style="font-weight:600;">Max download size (MB)</label>
                  <input
                    type="number"
                    min="1"
                    class="form-control"
                    name="max_download_mb"
                    value="{{ $settings['max_download_mb'] }}"
                    placeholder="{{ empty($envValues['max_download_mb']) ? 'No limit' : $envValues['max_download_mb'] . ' (.env)' }}"
                  />
                  <p class="text-muted small" style="margin-top:6px;">
                    Blocks modpack downloads larger than this. Leave empty for no limit.
                  </p>
                </div>
              </div>
              <div class="col-xs-6">
                <div class="form-group">
                  <label class="control-label" style="font-weight:600;">Catalog cache lifetime (seconds)</label>
                  <input
                    type="number"
                    min="1"
                    class="form-control"
                    name="catalog_cache_ttl"
                    value="{{ $settings['catalog_cache_ttl'] }}"
                    placeholder="{{ empty($envValues['catalog_cache_ttl']) ? '300' : $envValues['catalog_cache_ttl'] . ' (.env)' }}"
                  />
                  <p class="text-muted small" style="margin-top:6px;">
                    How long Modrinth/CurseForge search results stay cached. Lower = fresher results,
                    higher = faster browsing and less API traffic.
                  </p>
                </div>
              </div>
            </div>

            <div class="row">
              <div class="col-xs-4">
                <div class="form-group">
                  <label class="control-label" style="font-weight:600;">Default provider</label>
                  <select class="form-control" name="default_provider" style="border-radius:6px;">
                    <option value="" @if($settings['default_provider'] === '') selected @endif>Automatic</option>
                    <option value="modrinth" @if($settings['default_provider'] === 'modrinth') selected @endif>Modrinth</option>
                    <option value="curseforge" @if($settings['default_provider'] === 'curseforge') selected @endif>CurseForge</option>
                  </select>
                  <p class="text-muted small" style="margin-top:6px;">Catalog tab users see first.</p>
                </div>
              </div>
              <div class="col-xs-4">
                <div class="form-group">
                  <label class="control-label" style="font-weight:600;">Default sort</label>
                  <select class="form-control" name="default_sort" style="border-radius:6px;">
                    <option value="" @if($settings['default_sort'] === '') selected @endif>Relevance</option>
                    <option value="downloads" @if($settings['default_sort'] === 'downloads') selected @endif>Most downloads</option>
                    <option value="follows" @if($settings['default_sort'] === 'follows') selected @endif>Most follows</option>
                    <option value="newest" @if($settings['default_sort'] === 'newest') selected @endif>Newest</option>
                    <option value="updated" @if($settings['default_sort'] === 'updated') selected @endif>Recently updated</option>
                  </select>
                  <p class="text-muted small" style="margin-top:6px;">Initial sort order for searches.</p>
                </div>
              </div>
              <div class="col-xs-4">
                <div class="form-group">
                  <label class="control-label" style="font-weight:600;">Results per page</label>
                  <select class="form-control" name="page_size" style="border-radius:6px;">
                    <option value="" @if($settings['page_size'] === '') selected @endif>Default (20)</option>
                    <option value="10" @if($settings['page_size'] === '10') selected @endif>10</option>
                    <option value="20" @if($settings['page_size'] === '20') selected @endif>20</option>
                    <option value="30" @if($settings['page_size'] === '30') selected @endif>30</option>
                  </select>
                  <p class="text-muted small" style="margin-top:6px;">Initial catalog page size.</p>
                </div>
              </div>
            </div>

            <div class="form-group">
              <div class="checkbox">
                <input
                  type="checkbox"
                  id="mi-disable-curseforge"
                  name="disable_curseforge"
                  value="1"
                  @if($settings['disable_curseforge'] === '1') checked @endif
                />
                <label for="mi-disable-curseforge">
                  <span style="font-weight:600;">Disable CurseForge entirely</span>
                </label>
              </div>
              <p class="text-muted small" style="margin-top:2px;">
                Hides CurseForge from the catalog and rejects its installs. Modrinth keeps working.
              </p>
            </div>

            <div class="form-group">
              <label class="control-label" style="font-weight:600;">Server target mode</label>
              <select class="form-control" name="server_target" style="border-radius:6px;">
                <option value="local" @if(($settings['server_target'] ?: ($envValues['server_target'] ?: 'local')) === 'local') selected @endif>
                  Local filesystem — panel and game servers share this machine
                </option>
                <option value="wings" @if(($settings['server_target'] ?: ($envValues['server_target'] ?: 'local')) === 'wings') selected @endif>
                  Wings API — game servers live on remote nodes
                </option>
              </select>
              <p class="text-muted small" style="margin-top:6px;">
                Where modpack files are written. Use <strong>Wings API</strong> when your game servers run on
                separate nodes; use <strong>Local filesystem</strong> when the panel shares a disk with the servers.
              </p>
            </div>

            <button type="submit" class="btn btn-primary btn-sm" style="border-radius:6px; padding: 6px 18px;">
              <i class="bi bi-check2" style="margin-right:4px;"></i>Save settings
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-xs-12">
      <div class="panel panel-default mi-panel">
        <div class="panel-heading">
          <h3 class="panel-title" style="font-weight:600;">
            <i class="bi bi-clock-history" style="margin-right:6px;"></i>Install history
            <span class="text-muted small" style="font-weight:400;margin-left:8px;">(latest {{ count($history) }})</span>
          </h3>
        </div>
        <div class="panel-body" style="padding-top:6px;">
          @if(count($history) === 0)
            <p class="text-muted" style="margin-bottom:0;">
              No install activity recorded yet. Installs, updates, restores and uninstalls
              performed through the dashboard will appear here.
            </p>
          @else
            <div class="table-responsive">
              <table class="table table-hover table-condensed" style="margin-bottom:0;">
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Action</th>
                    <th>Modpack</th>
                    <th>Server</th>
                    <th>User</th>
                    <th>Details</th>
                  </tr>
                </thead>
                <tbody>
                  @foreach($history as $entry)
                    <tr>
                      <td class="small text-muted" style="white-space:nowrap;">{{ !empty($entry['time']) ? \Illuminate\Support\Carbon::parse($entry['time'])->diffForHumans() : '—' }}</td>
                      <td>
                        @switch($entry['action'] ?? '')
                          @case('install')
                            <span class="label label-success">install</span>
                          @break
                          @case('update')
                            <span class="label label-primary">update</span>
                          @break
                          @case('restore')
                            <span class="label label-info">restore</span>
                          @break
                          @case('uninstall')
                            <span class="label label-danger">uninstall</span>
                          @break
                          @default
                            <span class="label label-default">{{ $entry['action'] }}</span>
                        @endswitch
                      </td>
                      <td>
                        {{ $entry['modpack'] ?? '—' }}
                        @if(!empty($entry['version']))
                          <span class="text-muted small">({{ $entry['version'] }})</span>
                        @endif
                      </td>
                      <td class="small">{{ $entry['server_name'] ?? '—' }}</td>
                      <td class="small text-muted">{{ $entry['user'] ?? '—' }}</td>
                      <td class="small text-muted">{{ $entry['detail'] ?? '' }}</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div>
          @endif
        </div>
      </div>
    </div>
  </div>

  <div class="row">
    <div class="col-xs-6">
      <div class="panel panel-default mi-panel">
        <div class="panel-heading">
          <h3 class="panel-title" style="font-weight:600;">
            <i class="bi bi-info-circle-fill" style="margin-right:6px;"></i>About this extension
          </h3>
        </div>
        <div class="panel-body">
          <p style="margin-bottom:14px;">
            Browse and install Minecraft modpacks from
            <a href="https://modrinth.com" target="_blank" rel="noopener"><strong>Modrinth</strong></a> and
            <a href="https://www.curseforge.com" target="_blank" rel="noopener"><strong>CurseForge</strong></a>
            directly onto your Pterodactyl servers.
          </p>
          <ul class="list-unstyled" style="line-height:2; margin-bottom:0;">
            <li><i class="bi bi-people-fill" style="margin-right:8px;color:#6e56cf;"></i>Browse modpacks from the server dashboard</li>
            <li><i class="bi bi-download" style="margin-right:8px;color:#6e56cf;"></i>One-click install with live progress</li>
            <li><i class="bi bi-arrow-repeat" style="margin-right:8px;color:#6e56cf;"></i>Update, restore and uninstall installed packs</li>
            <li><i class="bi bi-shield-check" style="margin-right:8px;color:#6e56cf;"></i>API keys stay server-side, never exposed to clients</li>
          </ul>
        </div>
      </div>
    </div>
    <div class="col-xs-6">
      <div class="panel panel-default mi-panel">
        <div class="panel-heading">
          <h3 class="panel-title" style="font-weight:600;">
            <i class="bi bi-file-earmark-text-fill" style="margin-right:6px;"></i>Environment variables
          </h3>
        </div>
        <div class="panel-body">
          <p class="text-muted" style="margin-bottom:12px;">
            Advanced options that require editing <code>.env</code> on the server:
          </p>
          <p style="margin-bottom:10px; line-height:1.5;">
            <code>MODPACK_INSTALLER_SERVER_ROOT</code>
            <span class="text-muted small"> — custom server root path (default <code>/var/lib/pterodactyl/volumes</code>)</span>
          </p>
          <p style="margin-bottom:12px; line-height:1.5;">
            <code>MODPACK_INSTALLER_DATA_DIR</code>
            <span class="text-muted small"> — install records path (default <code>/var/lib/pterodactyl/modpack-installer</code>)</span>
          </p>
          <p class="text-muted small" style="margin-bottom:0;">
            After editing, run <code>php artisan config:cache</code> and restart PHP-FPM.
          </p>
        </div>
      </div>
    </div>
  </div>

</div>
