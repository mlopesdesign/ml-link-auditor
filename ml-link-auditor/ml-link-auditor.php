<?php
/**
 * Plugin Name: ML Link Auditor
 * Plugin URI: https://mlopesdesign.com.br
 * Description: Audita links internos e externos em posts e páginas, executa varredura AJAX em lote, sugere correções, salva cópias em quarentena, pode mover conteúdos para rascunho e integra com o hub de licenças MLopes Design para recursos Pro.
 * Version: 1.3.2
 * Author: Marcio Lopes
 * Author URI: https://mlopesdesign.com.br
 * License: GPL2+
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Text Domain: ml-link-auditor
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ============================================================
 *  MLLA_LicenseManager — conversa com o ML License Hub
 *  ============================================================
 *
 *  - Cache local com TTL de 12h (transient)
 *  - Grace period de 72h em caso de falha de rede
 *  - feature_set server-authoritative, com fallback binário e plano local
 *  - Helpers globais: mlla_can(), mlla_license()
 *
 *  @since 1.3.2
 *  ============================================================ */

if (!defined('MLLA_VERSION')) {
    define('MLLA_VERSION', '1.3.2');
}

if (!class_exists('MLLA_LicenseManager', false)) {

final class MLLA_LicenseManager {
    private const OPTION = 'mlla_license_state';
    private const TRANSIENT = 'mlla_license_cache';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;
    private const GRACE_PERIOD = 72 * HOUR_IN_SECONDS;
    private const DEFAULT_HUB_URL = 'https://license.mlopesdesign.com.br/api/license.php';
    private const DEFAULT_PRODUCT_ID = 'ml-link-auditor';

    /** Capabilities por plano (fallback local caso feature_set venha vazio). */
    private const PLAN_CAPABILITIES = [
        'free' => ['scan', 'quarantine', 'manual_actions', 'basic_suggestions'],
        'trial' => [
            'scan', 'quarantine', 'manual_actions', 'basic_suggestions',
            'csv_export', 'pdf_export', 'cron_schedules', 'email_notify',
            'bulk_actions', 'migration_paths', 'per_post_scan',
        ],
        'full' => [
            'scan', 'quarantine', 'manual_actions', 'basic_suggestions',
            'csv_export', 'pdf_export', 'cron_schedules', 'email_notify',
            'bulk_actions', 'migration_paths', 'per_post_scan',
        ],
        'lifetime' => [
            'scan', 'quarantine', 'manual_actions', 'basic_suggestions',
            'csv_export', 'pdf_export', 'cron_schedules', 'email_notify',
            'bulk_actions', 'migration_paths', 'per_post_scan',
            'multisite', 'white_label', 'api_rest', 'webhooks',
        ],
        'agency' => [
            'scan', 'quarantine', 'manual_actions', 'basic_suggestions',
            'csv_export', 'pdf_export', 'cron_schedules', 'email_notify',
            'bulk_actions', 'migration_paths', 'per_post_scan',
            'multisite', 'white_label', 'api_rest', 'webhooks',
            'priority_support', 'custom_branding',
        ],
    ];

    private const STATUS_LABELS = [
        'active' => ['pt' => 'Ativa', 'en' => 'Active', 'es' => 'Activa'],
        'lifetime' => ['pt' => 'Vitalícia', 'en' => 'Lifetime', 'es' => 'Vitalicia'],
        'trial_active' => ['pt' => 'Trial ativo', 'en' => 'Trial active', 'es' => 'Trial activo'],
        'trial_expired' => ['pt' => 'Trial expirado', 'en' => 'Trial expired', 'es' => 'Trial expirado'],
        'free' => ['pt' => 'Free', 'en' => 'Free', 'es' => 'Free'],
        'expired' => ['pt' => 'Expirada', 'en' => 'Expired', 'es' => 'Expirada'],
        'not_found' => ['pt' => 'Não encontrada', 'en' => 'Not found', 'es' => 'No encontrada'],
        'domain_mismatch' => ['pt' => 'Domínio incorreto', 'en' => 'Domain mismatch', 'es' => 'Dominio incorrecto'],
        'deactivated' => ['pt' => 'Desativada', 'en' => 'Deactivated', 'es' => 'Desactivada'],
        'inactive' => ['pt' => 'Inativa', 'en' => 'Inactive', 'es' => 'Inactiva'],
        'hub_unreachable' => ['pt' => 'Hub indisponível', 'en' => 'Hub unreachable', 'es' => 'Hub no disponible'],
    ];

    /** @var array */
    private $state;

    public function __construct(?array $state = null) {
        if ($state !== null) {
            $this->state = $state;
            return;
        }
        $stored = get_option(self::OPTION, []);
        $defaults = [
            'serial' => '',
            'email' => '',
            'product_id' => self::DEFAULT_PRODUCT_ID,
            'hub_url' => self::DEFAULT_HUB_URL,
            'info_url' => '', // se vazio, deriva de hub_url (/updates/<product>.json)
            'status' => 'inactive',
            'plan' => 'free',
            'premium' => false,
            'feature_set' => [],
            'domain' => '',
            'site_url' => '',
            'home_url' => '',
            'expires_at' => '',
            'grace_until' => '',
            'days_left' => null,
            'last_check' => '',
            'last_message' => '',
        ];
        $this->state = wp_parse_args(is_array($stored) ? $stored : [], $defaults);
    }

    /* ============================================================
     *  Getters
     *  ============================================================ */

    public function get_state(): array { return $this->state; }
    public function get_plan(): string { return (string) ($this->state['plan'] ?? 'free'); }
    public function get_status(): string { return (string) ($this->state['status'] ?? 'inactive'); }
    public function is_premium(): bool { return (bool) ($this->state['premium'] ?? false); }
    public function get_days_left() {
        $dl = $this->state['days_left'] ?? null;
        return $dl === null ? null : (int) $dl;
    }
    public function get_serial(): string { return (string) ($this->state['serial'] ?? ''); }
    public function get_email(): string { return (string) ($this->state['email'] ?? ''); }
    public function get_hub_url(): string { return (string) ($this->state['hub_url'] ?? self::DEFAULT_HUB_URL); }
    public function get_info_url(): string {
        $info = (string) ($this->state['info_url'] ?? '');
        if ($info !== '') return $info;
        return $this->get_hub_url() . '/updates/' . self::DEFAULT_PRODUCT_ID . '.json';
    }
    public function set_info_url(string $url): void {
        $this->state['info_url'] = $url;
        update_option(self::OPTION, $this->state, false);
    }
    public function get_product_id(): string { return (string) ($this->state['product_id'] ?? self::DEFAULT_PRODUCT_ID); }
    public function get_expires_at(): string { return (string) ($this->state['expires_at'] ?? ''); }
    public function get_feature_set(): array { return (array) ($this->state['feature_set'] ?? []); }
    public function get_status_label(): string {
        $status = $this->get_status();
        $labels = self::STATUS_LABELS[$status] ?? self::STATUS_LABELS['inactive'];
        $lang = $this->detect_lang();
        return $labels[$lang] ?? $labels['en'];
    }

    /* ============================================================
     *  Capability gate — server-authoritative, com fallback local
     *  ============================================================ */

    public function can(string $capability): bool {
        // Features gratuitas sempre liberadas
        $free_caps = self::PLAN_CAPABILITIES['free'];
        if (in_array($capability, $free_caps, true)) {
            return true;
        }
        if (!$this->is_premium()) {
            return false;
        }
        $status = $this->get_status();
        // Status que ainda dão acesso premium
        $premium_status = ['active', 'lifetime', 'trial_active'];
        if (!in_array($status, $premium_status, true)) {
            return false;
        }
        // feature_set server-side tem prioridade (se vier como array de strings)
        $features = $this->get_feature_set();
        if (!empty($features)) {
            // Suporta dois formatos: lista de strings OU objeto com 'features' (futuro)
            if (isset($features[0]) && is_string($features[0])) {
                return in_array($capability, $features, true);
            }
            if (isset($features['features']) && is_array($features['features'])) {
                return in_array($capability, $features['features'], true);
            }
        }
        // feature_set binário ('full')
        if ($this->state['feature_set'] === 'full' || $this->state['feature_set'] === 'premium') {
            return true;
        }
        // Fallback: capabilities pelo plano local
        $caps = self::PLAN_CAPABILITIES[$this->get_plan()] ?? [];
        return in_array($capability, $caps, true);
    }

    /* ============================================================
     *  Ações contra o Hub
     *  ============================================================ */

    public function activate(string $license_key, string $email = ''): array {
        if ($license_key === '') {
            return ['success' => false, 'message' => 'Informe a chave de licença.'];
        }
        $license_key = trim($license_key);
        $email = trim($email) ?: (string) get_option('admin_email');

        $payload = $this->build_request_payload('activate_license', $license_key, $email);
        $response = $this->call_hub($payload);
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $this->save_state($response, $license_key, $email);
        return [
            'success' => !empty($response['valid']),
            'message' => (string) ($response['message'] ?? ''),
            'state' => $this->state,
        ];
    }

    public function start_trial(string $email = ''): array {
        $email = trim($email) ?: (string) get_option('admin_email');
        $payload = $this->build_request_payload('start_trial', '', $email);
        $response = $this->call_hub($payload);
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        $this->save_state($response, '', $email);
        return [
            'success' => !empty($response['valid']),
            'message' => (string) ($response['message'] ?? ''),
            'state' => $this->state,
        ];
    }

    public function deactivate(): array {
        $serial = $this->get_serial();
        if ($serial === '') {
            return ['success' => false, 'message' => 'Nenhuma licença ativa para desativar.'];
        }
        $payload = $this->build_request_payload('deactivate_license', $serial, $this->get_email());
        $response = $this->call_hub($payload);
        if (is_wp_error($response)) {
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        // Limpa estado local
        $this->state = wp_parse_args([
            'serial' => '',
            'status' => 'inactive',
            'plan' => 'free',
            'premium' => false,
            'feature_set' => [],
            'expires_at' => '',
            'days_left' => null,
            'last_message' => (string) ($response['message'] ?? 'Licença removida.'),
        ], $this->state);
        $this->state['email'] = ''; // também limpa email
        update_option(self::OPTION, $this->state, false);
        delete_transient(self::TRANSIENT);
        return ['success' => true, 'message' => (string) ($response['message'] ?? 'Licença removida.'), 'state' => $this->state];
    }

    public function validate(bool $force_remote = false): array {
        if (!$force_remote) {
            $cached = get_transient(self::TRANSIENT);
            if ($cached !== false && is_array($cached)) {
                $this->state = wp_parse_args($cached, $this->state);
                return $this->state;
            }
        }

        $serial = $this->get_serial();
        $payload = $this->build_request_payload('validate_license', $serial, $this->get_email());
        $response = $this->call_hub($payload);

        if (is_wp_error($response)) {
            return $this->handle_hub_failure($response);
        }

        $this->save_state($response, $serial, $this->get_email());
        set_transient(self::TRANSIENT, $this->state, self::CACHE_TTL);
        return $this->state;
    }

    /**
     * Lista de capabilities disponíveis para exibição na UI.
     * Retorna ['cap_id' => 'label traduzido'].
     */
    public function get_capabilities_catalog(): array {
        return [
            'scan'              => ['pt' => 'Varredura AJAX em lote', 'en' => 'Batched AJAX scan', 'es' => 'Escaneo AJAX en lotes'],
            'quarantine'        => ['pt' => 'Quarentena de conteúdo', 'en' => 'Content quarantine', 'es' => 'Cuarentena de contenido'],
            'manual_actions'    => ['pt' => 'Substituição e remoção manual', 'en' => 'Manual replace & remove', 'es' => 'Reemplazo y eliminación manual'],
            'basic_suggestions' => ['pt' => 'Sugestões automáticas', 'en' => 'Automatic suggestions', 'es' => 'Sugerencias automáticas'],
            'csv_export'        => ['pt' => 'Exportar resultados em CSV', 'en' => 'Export results as CSV', 'es' => 'Exportar resultados en CSV'],
            'pdf_export'        => ['pt' => 'Exportar relatórios em PDF', 'en' => 'Export reports as PDF', 'es' => 'Exportar informes en PDF'],
            'cron_schedules'    => ['pt' => 'Varreduras agendadas', 'en' => 'Scheduled scans', 'es' => 'Escaneos programados'],
            'email_notify'      => ['pt' => 'Notificações por e-mail', 'en' => 'Email notifications', 'es' => 'Notificaciones por correo'],
            'bulk_actions'      => ['pt' => 'Ações em massa (bulk)', 'en' => 'Bulk actions', 'es' => 'Acciones en masa'],
            'migration_paths'   => ['pt' => 'Mapeamento de paths de migração', 'en' => 'Migration path mapping', 'es' => 'Mapeo de rutas de migración'],
            'per_post_scan'     => ['pt' => 'Varredura por post específico', 'en' => 'Per-post scan', 'es' => 'Escaneo por entrada'],
            'multisite'         => ['pt' => 'Suporte multi-site', 'en' => 'Multisite support', 'es' => 'Soporte multisitio'],
            'white_label'       => ['pt' => 'White-label (logo e cores)', 'en' => 'White-label (logo & colors)', 'es' => 'Marca blanca'],
            'api_rest'          => ['pt' => 'API REST para integrações', 'en' => 'REST API for integrations', 'es' => 'API REST para integraciones'],
            'webhooks'          => ['pt' => 'Webhooks (Slack/Discord/Teams)', 'en' => 'Webhooks (Slack/Discord/Teams)', 'es' => 'Webhooks (Slack/Discord/Teams)'],
            'priority_support'  => ['pt' => 'Suporte prioritário', 'en' => 'Priority support', 'es' => 'Soporte prioritario'],
            'custom_branding'   => ['pt' => 'Branding customizado', 'en' => 'Custom branding', 'es' => 'Marca personalizada'],
        ];
    }

    /* ============================================================
     *  Internals
     *  ============================================================ */

    private function build_request_payload(string $action, string $license_key, string $email): array {
        return [
            'action' => $action,
            'product_id' => $this->get_product_id(),
            'license_key' => $license_key,
            'domain' => wp_parse_url(home_url(), PHP_URL_HOST) ?: '',
            'site_url' => site_url(),
            'home_url' => home_url(),
            'version' => defined('MLLA_VERSION') ? MLLA_VERSION : '1.3.2',
            'admin_email' => $email,
            'site_fingerprint' => $this->site_fingerprint(),
        ];
    }

    private function call_hub(array $payload) {
        $url = $this->get_hub_url();
        $response = wp_remote_post($url, [
            'timeout' => 10,
            'redirection' => 0,
            'sslverify' => true,
            'body' => $payload,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new WP_Error(
                'mlla_invalid_response',
                sprintf('Resposta inválida do hub de licenças (HTTP %d).', $code)
            );
        }
        return $data;
    }

    private function save_state(array $response, string $serial = '', string $email = ''): void {
        $this->state = wp_parse_args([
            'serial' => $serial ?: $this->state['serial'],
            'email' => $email ?: $this->state['email'],
            'status' => (string) ($response['status'] ?? $this->state['status']),
            'plan' => (string) ($response['plan'] ?? $this->state['plan']),
            'premium' => (bool) ($response['premium'] ?? $this->state['premium']),
            'feature_set' => $response['feature_set'] ?? $this->state['feature_set'],
            'domain' => (string) ($response['domain'] ?? $this->state['domain']),
            'site_url' => (string) ($response['site_url'] ?? $this->state['site_url']),
            'home_url' => (string) ($response['home_url'] ?? $this->state['home_url']),
            'expires_at' => (string) ($response['expires_at'] ?? $this->state['expires_at']),
            'grace_until' => (string) ($response['grace_until'] ?? $this->state['grace_until']),
            'days_left' => $response['days_left'] ?? $this->state['days_left'],
            'last_check' => current_time('mysql'),
            'last_message' => (string) ($response['message'] ?? $this->state['last_message']),
        ], $this->state);
        update_option(self::OPTION, $this->state, false);
    }

    private function handle_hub_failure(WP_Error $error): array {
        $last_check = strtotime((string) ($this->state['last_check'] ?? ''));
        $grace_remaining = $last_check ? (self::GRACE_PERIOD - (time() - $last_check)) : 0;
        if ($grace_remaining > 0 && !empty($this->state['plan']) && $this->state['plan'] !== 'free') {
            $this->state['last_message'] = sprintf(
                /* translators: %d: horas restantes de grace period */
                'Hub indisponível. Usando cache por mais %d horas.',
                max(1, (int) ceil($grace_remaining / HOUR_IN_SECONDS))
            );
            $this->state['status'] = $this->get_status();
            update_option(self::OPTION, $this->state, false);
            return $this->state;
        }
        // Grace expirado: bloqueia premium
        $this->state['premium'] = false;
        $this->state['status'] = 'hub_unreachable';
        $this->state['last_message'] = $error->get_error_message();
        update_option(self::OPTION, $this->state, false);
        return $this->state;
    }

    private function site_fingerprint(): string {
        $domain = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';
        return hash('sha256', strtolower($this->get_product_id()) . '|' . strtolower($domain));
    }

    private function detect_lang(): string {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        $locale = strtolower($locale);
        if (strpos($locale, 'pt') === 0) return 'pt';
        if (strpos($locale, 'es') === 0) return 'es';
        return 'en';
    }
}

} // end if (!class_exists('MLLA_LicenseManager'))

/* ============================================================
 *  MLLA_Updater — auto-update via WP update mechanism
 *  ============================================================
 *
 *  - Lê o info endpoint público do Hub (info.json, sem auth)
 *  - Injeta update no pre_set_site_transient_update_plugins
 *  - Fornece changelog e detalhes via plugins_api
 *  - Gera token assinado para download (Hub valida antes de servir ZIP)
 *  - Cache local em transient de 12h; cron força checagem diária
 *
 *  Contrato esperado (lado Hub):
 *    GET https://license.mlopesdesign.com.br/updates/<product>.json
 *    → { version, download_path, tested, requires, requires_php, homepage, changelog, sections }
 *    download_path = "updates/<product>-<version>.zip?token=<signed>"
 *
 *  @since 1.3.2
 *  ============================================================ */

final class MLLA_Updater {
    private const TRANSIENT = 'mlla_update_check';
    private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    /** @var string */
    private $plugin_file;
    /** @var string */
    private $plugin_slug;
    /** @var string */
    private $hub_url;
    /** @var string */
    private $info_url;
    /** @var string */
    private $product_id;
    /** @var string */
    private $current_version;

    public function __construct(string $plugin_file, string $product_id = 'ml-link-auditor') {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = dirname(plugin_basename($plugin_file));
        $this->product_id = $product_id;
        $license = new MLLA_LicenseManager();
        // hub_base continua usado para construir URL de download (que precisa estar no mesmo host do Hub pra ter o token validado).
        $hub_base = rtrim(preg_replace('~/api/[^/]*$~', '', $license->get_hub_url()), '/');
        $this->hub_url = $hub_base;
        $this->info_url = $license->get_info_url();
        if (function_exists('get_plugin_data')) {
            $data = get_plugin_data($plugin_file, false, false);
            $this->current_version = (string) ($data['Version'] ?? '0.0.0');
        } else {
            $this->current_version = defined('MLLA_VERSION') ? MLLA_VERSION : '1.3.2';
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'maybe_push_update']);
        add_filter('plugins_api', [$this, 'provide_plugin_info'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'before_download'], 10, 3);
        add_action('mlla_check_for_update', [$this, 'force_check']);
        // Agenda a checagem no hook 'init' (mais seguro que no construtor).
        add_action('init', [$this, 'register_update_schedule']);
    }

    /**
     * Registra cron de checagem de updates. Chamado via hook 'init'.
     */
    public function register_update_schedule(): void {
        if (!wp_next_scheduled('mlla_check_for_update')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', 'mlla_check_for_update');
        }
    }

    /**
     * Injeta informação de update no transient oficial do WP.
     * Só aparece se a licença for premium OU se o hub retornar info mesmo assim
     * (configurável no Hub via flag `show_updates_for_free`).
     */
    public function maybe_push_update($transient) {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }
        $info = $this->get_remote_info();
        if (!$info || empty($info->version)) {
            return $transient;
        }
        if (version_compare($this->current_version, $info->version, '>=')) {
            // Já estamos na última
            return $transient;
        }
        // Verifica licença — updates só aparecem pra quem tem licença ativa/trial.
        $license = new MLLA_LicenseManager();
        if (!$license->is_premium() && empty($info->allow_free_update)) {
            // Se for Free e Hub não permite update grátis, exibe como "info" mas sem package
            $update = (object) [
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_file,
                'new_version' => $info->version,
                'url' => $info->homepage ?? '',
                'package' => false, // sem package = WP mostra "automático só pra Pro"
                'icons' => [],
                'tested' => $info->tested ?? '',
                'requires' => $info->requires ?? '',
                'requires_php' => $info->requires_php ?? '',
                'compatibility' => new stdClass(),
                'upgrade_notice' => $info->upgrade_notice ?? '',
            ];
            $transient->response[$this->plugin_file] = $update;
            return $transient;
        }
        // Licença OK — pode atualizar
        $update = (object) [
            'slug' => $this->plugin_slug,
            'plugin' => $this->plugin_file,
            'new_version' => $info->version,
            'url' => $info->homepage ?? '',
            'package' => $this->build_download_url($info),
            'icons' => [],
            'tested' => $info->tested ?? '',
            'requires' => $info->requires ?? '',
            'requires_php' => $info->requires_php ?? '',
            'compatibility' => new stdClass(),
            'upgrade_notice' => $info->upgrade_notice ?? '',
        ];
        $transient->response[$this->plugin_file] = $update;
        return $transient;
    }

    /**
     * Fornece detalhes do plugin quando o usuário clica em "Ver detalhes".
     */
    public function provide_plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }
        $info = $this->get_remote_info();
        if (!$info) {
            return $result;
        }
        return (object) [
            'name' => $info->name ?? 'ML Link Auditor',
            'slug' => $this->plugin_slug,
            'plugin' => $this->plugin_file,
            'version' => $info->version ?? '',
            'author' => 'Marcio Lopes',
            'homepage' => $info->homepage ?? '',
            'sections' => [
                'description' => $info->sections->description ?? '',
                'changelog' => $info->sections->changelog ?? $info->changelog ?? '',
            ],
            'download_link' => $this->build_download_url($info),
            'requires' => $info->requires ?? '',
            'tested' => $info->tested ?? '',
            'requires_php' => $info->requires_php ?? '',
            'last_updated' => $info->last_updated ?? '',
            'banners' => [],
            'icons' => [],
        ];
    }

    /**
     * Hook antes do download — valida uma última vez a licença e mostra mensagem amigável.
     */
    public function before_download($reply, $package, $upgrader) {
        if (strpos((string) $package, 'mlla-token=') === false) {
            return $reply;
        }
        $license = new MLLA_LicenseManager();
        $state = $license->validate(true);
        if (empty($state['premium']) && empty($state['allow_free_update'])) {
            return new WP_Error(
                'mlla_no_license',
                'Sua licença ML Link Auditor não está ativa. Ative para receber atualizações automáticas.'
            );
        }
        return $reply;
    }

    /**
     * Força uma checagem agora (chamado pelo cron ou manualmente).
     */
    public function force_check(): array {
        delete_transient(self::TRANSIENT);
        // Limpa o transient oficial do WP pra forçar re-check
        $site_transient = get_site_transient('update_plugins');
        if ($site_transient) {
            delete_site_transient('update_plugins');
        }
        return ['ok' => true, 'message' => 'Update check forçado.'];
    }

    /* ==================== Internals ==================== */

    private function get_remote_info() {
        $cached = get_transient(self::TRANSIENT);
        if ($cached !== false && is_object($cached)) {
            return $cached;
        }
        $url = $this->info_url;
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'sslverify' => true,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body);
        if (!is_object($data)) {
            return null;
        }
        set_transient(self::TRANSIENT, $data, self::CACHE_TTL);
        return $data;
    }

    /**
     * Monta a URL de download com token assinado HMAC.
     * Token = base64url(timestamp) + '.' + hash_hmac(timestamp, secret_key)
     * O Hub valida o HMAC e o timestamp (janela de 5 minutos).
     */
    private function build_download_url(object $info): string {
        $license = new MLLA_LicenseManager();
        $serial = $license->get_serial();
        $domain = wp_parse_url(home_url(), PHP_URL_HOST) ?: '';
        $expires = time() + 600; // 10 min pra iniciar o download
        $payload = sprintf('%s|%s|%s|%d', $this->product_id, $serial, $domain, $expires);
        $signature = hash_hmac('sha256', $payload, $this->get_shared_secret());
        $token = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.' . $signature;
        $rel = $info->download_path ?? ('updates/' . $this->product_id . '-' . ($info->version ?? $this->current_version) . '.zip');
        return $this->hub_url . '/' . ltrim($rel, '/') . (strpos($rel, '?') === false ? '?' : '&') . 'token=' . $token;
    }

    private function get_shared_secret(): string {
        // Segredo compartilhado entre plugin e Hub.
        // O Hub deve usar o MESMO secret para validar tokens.
        $license = new MLLA_LicenseManager();
        $stored = get_option('mlla_updater_secret', '');
        if (!$stored) {
            $stored = wp_generate_password(48, false, false);
            update_option('mlla_updater_secret', $stored, false);
        }
        return $stored;
    }

    public function get_current_version(): string {
        return $this->current_version;
    }

    public function get_remote_version_cached() {
        $info = $this->get_remote_info();
        return $info ? $info->version ?? null : null;
    }
}

/* Helper global: capability gate */
if (!function_exists('mlla_can')) {
    function mlla_can(string $capability): bool {
        static $license = null;
        if ($license === null) {
            $license = new MLLA_LicenseManager();
        }
        return $license->can($capability);
    }
}

/* Helper global: acesso ao LicenseManager */
if (!function_exists('mlla_license')) {
    function mlla_license(): MLLA_LicenseManager {
        static $license = null;
        if ($license === null) {
            $license = new MLLA_LicenseManager();
        }
        return $license;
    }
}

if (!class_exists('ML_Link_Auditor', false)) {

final class ML_Link_Auditor {
    private const VERSION = '1.3.2';
    private const OPTION_SETTINGS = 'mlla_settings';
    private const OPTION_JOB = 'mlla_scan_job';
    private const OPTION_LICENSE = 'mlla_license_state';
    private const NONCE_ACTION = 'mlla_admin_nonce';
    private const MENU_SLUG = 'ml-link-auditor';
    private const LOCK_TTL = 300;
    private const DEFAULT_BATCH_SIZE = 15;
    private const DEFAULT_TIMEOUT = 5;

    /** @var array<string,mixed> */
    private $settings = [];
    /** @var string */
    private $table_results = '';
    /** @var string */
    private $table_quarantine = '';
    /** @var MLLA_Updater|null */
    private $updater = null;

    public function __construct() {
        global $wpdb;
        $this->table_results = $wpdb->prefix . 'mlla_results';
        $this->table_quarantine = $wpdb->prefix . 'mlla_quarantine';
        $this->settings = $this->get_settings();

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Carrega .mo de /languages para que a função t() / __() funcione.
        add_action('init', [$this, 'load_textdomain']);
        add_action('admin_init', [$this, 'maybe_bootstrap_runtime']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_notices', [$this, 'maybe_show_update_notice']);
        add_action('admin_post_mlla_save_settings', [$this, 'handle_save_settings']);

        add_action('wp_ajax_mlla_start_scan', [$this, 'ajax_start_scan']);
        add_action('wp_ajax_mlla_process_scan', [$this, 'ajax_process_scan']);
        add_action('wp_ajax_mlla_get_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_mlla_pause_scan', [$this, 'ajax_pause_scan']);
        add_action('wp_ajax_mlla_resume_scan', [$this, 'ajax_resume_scan']);
        add_action('wp_ajax_mlla_stop_scan', [$this, 'ajax_stop_scan']);
        add_action('wp_ajax_mlla_reset_scan', [$this, 'ajax_reset_scan']);
        add_action('wp_ajax_mlla_apply_suggestion', [$this, 'ajax_apply_suggestion']);
        add_action('wp_ajax_mlla_replace_link', [$this, 'ajax_replace_link']);
        add_action('wp_ajax_mlla_remove_link', [$this, 'ajax_remove_link']);
        add_action('wp_ajax_mlla_move_post_to_draft', [$this, 'ajax_move_post_to_draft']);
        add_action('wp_ajax_mlla_restore_quarantine', [$this, 'ajax_restore_quarantine']);
        add_action('wp_ajax_mlla_delete_quarantine', [$this, 'ajax_delete_quarantine']);
        add_action('wp_ajax_mlla_refresh_tables', [$this, 'ajax_refresh_tables']);
        add_action('wp_ajax_mlla_restore_drafts_batch', [$this, 'ajax_restore_drafts_batch']);

        // Licença (v1.3.2)
        add_action('wp_ajax_mlla_activate_license', [$this, 'ajax_activate_license']);
        add_action('wp_ajax_mlla_start_trial', [$this, 'ajax_start_trial']);
        add_action('wp_ajax_mlla_deactivate_license', [$this, 'ajax_deactivate_license']);
        add_action('wp_ajax_mlla_validate_license_now', [$this, 'ajax_validate_license_now']);

        // Pro features (v1.3.2)
        add_action('wp_ajax_mlla_export_csv', [$this, 'ajax_export_csv']);
        add_action('wp_ajax_mlla_check_update', [$this, 'ajax_check_update']);

        // Cron diário (v1.3.2) — adia para hook 'init' (mais seguro)
        add_action('mlla_daily_validate', [$this, 'cron_validate_license']);
        add_action('init', [$this, 'register_license_cron']);

        // Auto-update (v1.3.2): instancia o Updater.
        $this->updater = new MLLA_Updater(__FILE__, 'ml-link-auditor');
    }

    /**
     * Registra cron de validação diária de licença. Chamado via hook 'init'.
     */
    public function register_license_cron(): void {
        if (!wp_next_scheduled('mlla_daily_validate')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'mlla_daily_validate');
        }
    }

    public function activate(): void {
        try {
            $this->create_tables();
            if (!get_option(self::OPTION_SETTINGS)) {
                add_option(self::OPTION_SETTINGS, $this->get_default_settings());
            }
            if (!get_option(self::OPTION_LICENSE)) {
                add_option(self::OPTION_LICENSE, [
                    'serial' => '',
                    'email' => '',
                    'status' => 'inactive',
                    'plan' => 'free',
                    'last_check' => '',
                ]);
            }
            if (!get_option(self::OPTION_JOB)) {
                add_option(self::OPTION_JOB, $this->default_job_state());
            }
            // Marca instalação no log
            if (function_exists('error_log')) {
                error_log('[ML Link Auditor v' . self::VERSION . '] Ativação concluída.');
            }
        } catch (\Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[ML Link Auditor] Erro na ativação: ' . $e->getMessage());
            }
            throw $e; // Re-throw para o WP mostrar erro
        }
    }

    /**
     * Carrega o text domain do plugin (necessário para que __() / _e() / t() funcione).
     * Antes dessa correção, o text domain nunca era carregado → toda a i18n era inerte.
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'ml-link-auditor',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Hook de desativação: limpa apenas o estado de job em andamento (lock) e transients.
     * Tabelas e options principais ficam, para permitir reativação sem perder dados.
     * Para limpeza total, o usuário deve desinstalar (uninstall.php).
     */
    public function deactivate(): void {
        $job = $this->get_job();
        $job['status'] = 'idle';
        $job['stop_requested'] = 0;
        $job['pause_requested'] = 0;
        $job['lock_until'] = 0;
        $job['run_id'] = '';
        $job['last_message'] = $this->t('Plugin desativado. Estado zerado.', 'Plugin deactivated. State cleared.', 'Plugin desactivado. Estado borrado.');
        $this->save_job($job);
    }


    public function maybe_bootstrap_runtime(): void {
        if ((function_exists('wp_doing_ajax') && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        $this->create_tables();
        $saved = get_option(self::OPTION_JOB, null);
        if (!is_array($saved)) {
            $this->save_job($this->default_job_state());
            return;
        }

        $job = wp_parse_args($saved, $this->default_job_state());

        if (!empty($job['status']) && in_array($job['status'], ['running', 'starting', 'stopping'], true)) {
            $job['status'] = 'paused';
            $job['pause_requested'] = 0;
            $job['stop_requested'] = 0;
            $job['lock_until'] = 0;
            $job['current_item'] = '';
            $job['last_message'] = $this->t(
                'Execução anterior pausada para evitar auto-início. Retome manualmente.',
                'Previous run paused to avoid auto-start. Resume manually.',
                'La ejecución anterior se pausó para evitar el autoarranque. Reanude manualmente.'
            );
            $this->push_event($job, $job['last_message']);
            $this->save_job($job);
        }
    }

    private function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql_results = "CREATE TABLE {$this->table_results} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            post_type VARCHAR(50) NOT NULL,
            post_status VARCHAR(20) NOT NULL,
            post_title TEXT NULL,
            link_url TEXT NOT NULL,
            link_url_raw TEXT NULL,
            normalized_url TEXT NOT NULL,
            link_text TEXT NULL,
            link_context LONGTEXT NULL,
            source_type VARCHAR(20) NOT NULL DEFAULT 'content',
            status_code VARCHAR(20) NOT NULL DEFAULT 'pending',
            http_code INT NULL,
            final_url TEXT NULL,
            suggestion_url TEXT NULL,
            suggestion_label VARCHAR(255) NULL,
            scan_hash CHAR(32) NOT NULL,
            note TEXT NULL,
            occurrences INT NOT NULL DEFAULT 1,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            fixed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_scan (scan_hash),
            KEY post_id (post_id),
            KEY status_code (status_code)
        ) $charset;";

        $sql_quarantine = "CREATE TABLE {$this->table_quarantine} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(50) NOT NULL,
            original_status VARCHAR(20) NOT NULL,
            original_content LONGTEXT NOT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            restored_at DATETIME NULL,
            deleted_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY created_at (created_at)
        ) $charset;";

        dbDelta($sql_results);
        dbDelta($sql_quarantine);
    }

    private function get_default_settings(): array {
        return [
            'batch_size' => self::DEFAULT_BATCH_SIZE,
            'request_timeout' => self::DEFAULT_TIMEOUT,
            'scan_post_types' => ['post', 'page'],
            'auto_draft_unresolved' => 0,
            'scan_external' => 1,
            'scan_internal' => 1,
            'user_agent' => 'ML Link Auditor/' . self::VERSION,
        ];
    }

    private function default_job_state(): array {
        return [
            'status' => 'idle',
            'queue' => [],
            'current_index' => 0,
            'processed_posts' => 0,
            'total_posts' => 0,
            'issues_found' => 0,
            'broken_found' => 0,
            'redirect_found' => 0,
            'ok_found' => 0,
            'current_item' => '',
            'last_message' => '',
            'started_at' => '',
            'finished_at' => '',
            'run_id' => '',
            'recent_events' => [],
            'lock_until' => 0,
            'stop_requested' => 0,
            'pause_requested' => 0,
        ];
    }

    private function get_settings(): array {
        $stored = get_option(self::OPTION_SETTINGS, []);
        return wp_parse_args(is_array($stored) ? $stored : [], $this->get_default_settings());
    }

    private function get_job(): array {
        $job = get_option(self::OPTION_JOB, $this->default_job_state());
        return wp_parse_args(is_array($job) ? $job : [], $this->default_job_state());
    }

    private function save_job(array $job): void {
        update_option(self::OPTION_JOB, $job, false);
    }

    private function get_current_language(): string {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        $locale = strtolower($locale);
        if (strpos($locale, 'pt') === 0) {
            return 'pt';
        }
        if (strpos($locale, 'es') === 0) {
            return 'es';
        }
        return 'en';
    }

    private function t(string $pt, string $en = '', string $es = ''): string {
        $lang = $this->get_current_language();
        if ($lang === 'pt') {
            return $pt;
        }
        if ($lang === 'es') {
            return $es !== '' ? $es : ($en !== '' ? $en : $pt);
        }
        return $en !== '' ? $en : $pt;
    }

    private function require_admin_ajax(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => $this->t('Acesso negado.', 'Access denied.', 'Acceso denegado.')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    public function register_admin_menu(): void {
        add_menu_page(
            'ML Link Auditor',
            'ML Link Auditor',
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page'],
            'dashicons-admin-links',
            58
        );
    }

    public function enqueue_assets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style('mlla-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], self::VERSION);
        wp_enqueue_script('mlla-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery'], self::VERSION, true);
        wp_localize_script('mlla-admin', 'MLLA', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'strings' => [
                'starting' => $this->t('Iniciando varredura...', 'Starting scan...', 'Iniciando escaneo...'),
                'running' => $this->t('Processando lote...', 'Processing batch...', 'Procesando lote...'),
                'paused' => $this->t('Varredura pausada.', 'Scan paused.', 'Escaneo pausado.'),
                'stopping' => $this->t('Encerrando com segurança...', 'Stopping safely...', 'Deteniendo con seguridad...'),
                'finished' => $this->t('Varredura concluída.', 'Scan finished.', 'Escaneo finalizado.'),
                'idle' => $this->t('Nenhuma varredura ativa.', 'No active scan.', 'Ningún escaneo activo.'),
                'error' => $this->t('Erro na varredura.', 'Scan error.', 'Error en el escaneo.'),
                'saved' => $this->t('Operação concluída.', 'Operation completed.', 'Operación completada.'),
                'confirmRemove' => $this->t('Remover o link e manter apenas o conteúdo interno?', 'Remove the link and keep inner content only?', '¿Eliminar el enlace y mantener solo el contenido interno?'),
                'confirmDraft' => $this->t('Mover este conteúdo para rascunho?', 'Move this content to draft?', '¿Mover este contenido a borrador?'),
                'confirmRestore' => $this->t('Restaurar esta cópia da quarentena?', 'Restore this quarantine copy?', '¿Restaurar esta copia de cuarentena?'),
                'confirmDeleteQuarantine' => $this->t('Apagar esta cópia da quarentena?', 'Delete this quarantine copy?', '¿Eliminar esta copia de cuarentena?'),
                'busy' => $this->t('Processando...', 'Processing...', 'Procesando...'),
                'manualUrlRequired' => $this->t('Informe a nova URL.', 'Enter the new URL.', 'Informe la nueva URL.'),
                'startScan' => $this->t('Iniciar varredura', 'Start scan', 'Iniciar escaneo'),
                'pause' => $this->t('Pausar', 'Pause', 'Pausar'),
                'resume' => $this->t('Retomar', 'Resume', 'Reanudar'),
                'stop' => $this->t('Parar', 'Stop', 'Detener'),
                'reset' => $this->t('Resetar estado', 'Reset state', 'Restablecer estado'),
                'refresh' => $this->t('Atualizar listas', 'Refresh lists', 'Actualizar listas'),
                'resetting' => $this->t('Resetando...', 'Resetting...', 'Restableciendo...'),
                'refreshing' => $this->t('Atualizando...', 'Refreshing...', 'Actualizando...'),
                'stopped' => $this->t('Varredura parada.', 'Scan stopped.', 'Escaneo detenido.'),
                'startBusy' => $this->t('Iniciando...', 'Starting...', 'Iniciando...'),
                'pauseBusy' => $this->t('Pausando...', 'Pausing...', 'Pausando...'),
                'resumeBusy' => $this->t('Retomando...', 'Resuming...', 'Reanudando...'),
                'stopBusy' => $this->t('Parando...', 'Stopping...', 'Deteniendo...'),
                'refreshDone' => $this->t('Listas atualizadas.', 'Lists refreshed.', 'Listas actualizadas.'),
                'partialUpdated' => $this->t('Resultados parciais atualizados.', 'Partial results updated.', 'Resultados parciales actualizados.'),
                'settingsSaved' => $this->t('Configurações salvas.', 'Settings saved.', 'Configuración guardada.'),
                'resetDone' => $this->t('Estado resetado do zero.', 'State reset from scratch.', 'Estado restablecido desde cero.'),
                'confirmRestoreDrafts' => $this->t('Restaurar até 100 rascunhos de posts de volta para publicado?', 'Restore up to 100 post drafts back to published?', '¿Restaurar hasta 100 borradores de entradas de nuevo a publicados?'),
                'restoreDrafts' => $this->t('Restaurar rascunhos', 'Restore drafts', 'Restaurar borradores'),
                'restoreDraftsBusy' => $this->t('Restaurando...', 'Restoring...', 'Restaurando...'),
                'restoreDraftsDone' => $this->t('Rascunhos restaurados em massa.', 'Drafts restored in bulk.', 'Borradores restaurados en lote.'),
            ],
        ]);
    }

    public function handle_save_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('mlla_save_settings');
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : [];
        $post_types = isset($input['scan_post_types']) && is_array($input['scan_post_types']) ? array_map('sanitize_key', $input['scan_post_types']) : ['post', 'page'];
        $settings = [
            'batch_size' => max(1, min(100, (int) ($input['batch_size'] ?? self::DEFAULT_BATCH_SIZE))),
            'request_timeout' => max(2, min(20, (int) ($input['request_timeout'] ?? self::DEFAULT_TIMEOUT))),
            'scan_post_types' => array_values(array_unique(array_filter($post_types))),
            'auto_draft_unresolved' => empty($input['auto_draft_unresolved']) ? 0 : 1,
            'scan_external' => empty($input['scan_external']) ? 0 : 1,
            'scan_internal' => empty($input['scan_internal']) ? 0 : 1,
            'user_agent' => sanitize_text_field((string) ($input['user_agent'] ?? 'ML Link Auditor/' . self::VERSION)),
        ];
        if (empty($settings['scan_post_types'])) {
            $settings['scan_post_types'] = ['post', 'page'];
        }
        update_option(self::OPTION_SETTINGS, $settings, false);
        $this->settings = $settings;
        wp_safe_redirect(add_query_arg(['page' => self::MENU_SLUG, 'tab' => 'settings', 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function render_admin_page(): void {
        global $wpdb;
        $job = $this->get_job();
        $stats = $this->get_dashboard_stats();
        $saved = isset($_GET['saved']) ? 1 : 0;
        $settings = $this->get_settings();
        $license = get_option(self::OPTION_LICENSE, []);
        $results = $wpdb->get_results("SELECT * FROM {$this->table_results} ORDER BY FIELD(status_code, 'broken', 'redirect', 'timeout', 'invalid', 'ok'), last_seen DESC LIMIT 200");
        $quarantine = $wpdb->get_results("SELECT * FROM {$this->table_quarantine} WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 100");
        $public_post_types = get_post_types(['public' => true], 'objects');
        $logo_url = plugin_dir_url(__FILE__) . 'assets/images/logo-mlopesdesign.png';
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'dashboard';
        if (!in_array($active_tab, ['dashboard', 'results', 'quarantine', 'settings', 'license'], true)) {
            $active_tab = 'dashboard';
        }
        ?>
        <div class="wrap mlla-wrap">
            <div id="mlla-settings-saved-flag" data-saved="<?php echo esc_attr((string) $saved); ?>" style="display:none"></div>

            <section class="mlla-hero">
                <div class="mlla-hero-brand">
                    <div class="mlla-hero-mark"><img src="<?php echo esc_url($logo_url); ?>" alt="MLopes Design"></div>
                    <div class="mlla-hero-copy">
                        <span class="mlla-hero-eyebrow"><?php echo esc_html($this->t('MLopes Design · Auditoria de Links', 'MLopes Design · Link Audit', 'MLopes Design · Auditoría de Enlaces')); ?></span>
                        <h1>ML Link Auditor</h1>
                        <p class="mlla-intro"><?php echo esc_html($this->t('Audita links internos e externos em posts e páginas, com varredura AJAX em lote, sugestões de correção, quarentena e controle de rascunhos.', 'Audits internal and external links in posts and pages, with batched AJAX scanning, fix suggestions, quarantine and draft control.', 'Audita enlaces internos y externos en posts y páginas, con escaneo AJAX por lotes, sugerencias de corrección, cuarentena y control de borradores.')); ?></p>
                    </div>
                </div>
                <div class="mlla-hero-meta">
                    <span class="mlla-version-badge">v<?php echo esc_html(self::VERSION); ?></span>
                    <div class="mlla-hero-tags">
                        <span class="mlla-chip"><?php echo esc_html($this->translate_status((string) $job['status'])); ?></span>
                        <span class="mlla-chip"><?php echo esc_html($this->t('Plano', 'Plan', 'Plan') . ': ' . strtoupper((string) ($license['plan'] ?? 'FREE'))); ?></span>
                    </div>
                </div>
            </section>

            <nav class="mlla-tab-nav" aria-label="<?php echo esc_attr($this->t('Navegação do plugin', 'Plugin navigation', 'Navegación del plugin')); ?>">
                <?php
                $tabs = [
                    'dashboard' => $this->t('Dashboard', 'Dashboard', 'Dashboard'),
                    'results' => $this->t('Resultados', 'Results', 'Resultados'),
                    'quarantine' => $this->t('Quarentena', 'Quarantine', 'Cuarentena'),
                    'settings' => $this->t('Configurações', 'Settings', 'Configuración'),
                    'license' => $this->t('Licença', 'License', 'Licencia'),
                ];
                foreach ($tabs as $tab_key => $tab_label) :
                ?>
                    <button type="button" class="mlla-tab-button <?php echo $active_tab === $tab_key ? 'is-active' : ''; ?>" data-tab-target="mlla-tab-<?php echo esc_attr($tab_key); ?>"><?php echo esc_html($tab_label); ?></button>
                <?php endforeach; ?>
            </nav>

            <section id="mlla-tab-dashboard" class="mlla-tab-panel <?php echo $active_tab === 'dashboard' ? 'is-active' : ''; ?>">
                <div id="mlla-summary-cards" class="mlla-summary-cards">
                    <?php foreach ($stats as $label => $value): ?>
                        <div class="mlla-summary-box">
                            <span><?php echo esc_html($label); ?></span>
                            <strong><?php echo esc_html((string) $value); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mlla-grid mlla-grid-1">
                    <article class="mlla-card">
                        <div class="mlla-card-header">
                            <div>
                                <h2><?php echo esc_html($this->t('Painel da varredura', 'Scan dashboard', 'Panel del escaneo')); ?></h2>
                                <p class="mlla-muted"><?php echo esc_html($this->t('Controle completo da auditoria em lotes, com progresso em tempo real.', 'Full control of the batched audit, with real-time progress.', 'Control completo de la auditoría por lotes, con progreso en tiempo real.')); ?></p>
                            </div>
                        </div>
                        <div class="mlla-job-status" data-status="<?php echo esc_attr($job['status']); ?>">
                            <p class="mlla-status-row"><strong><?php echo esc_html($this->t('Status', 'Status', 'Estado')); ?>:</strong> <span id="mlla-status-label"><?php echo esc_html($this->translate_status($job['status'])); ?></span></p>
                            <p class="mlla-status-row"><strong><?php echo esc_html($this->t('Item atual', 'Current item', 'Elemento actual')); ?>:</strong> <span id="mlla-current-item"><?php echo esc_html($job['current_item']); ?></span></p>
                            <p class="mlla-status-row"><strong><?php echo esc_html($this->t('Mensagem', 'Message', 'Mensaje')); ?>:</strong> <span id="mlla-last-message"><?php echo esc_html($job['last_message']); ?></span></p>
                            <div class="mlla-progress"><div id="mlla-progress-bar" style="width: <?php echo esc_attr($this->get_job_progress($job)); ?>%"></div></div>
                            <div class="mlla-metrics">
                                <span><?php echo esc_html($this->t('Posts processados', 'Posts processed', 'Posts procesados')); ?><br><strong id="mlla-processed-posts"><?php echo (int) $job['processed_posts']; ?></strong>/<strong id="mlla-total-posts"><?php echo (int) $job['total_posts']; ?></strong></span>
                                <span><?php echo esc_html($this->t('Problemas', 'Issues', 'Problemas')); ?><br><strong id="mlla-issues-found"><?php echo (int) $job['issues_found']; ?></strong></span>
                                <span><?php echo esc_html($this->t('Quebrados', 'Broken', 'Rotos')); ?><br><strong id="mlla-broken-found"><?php echo (int) $job['broken_found']; ?></strong></span>
                                <span><?php echo esc_html($this->t('Redirecionados', 'Redirects', 'Redirecciones')); ?><br><strong id="mlla-redirect-found"><?php echo (int) $job['redirect_found']; ?></strong></span>
                                <span><?php echo esc_html($this->t('OK', 'OK', 'OK')); ?><br><strong id="mlla-ok-found"><?php echo (int) $job['ok_found']; ?></strong></span>
                            </div>
                            <div class="mlla-actions">
                                <button class="button button-primary" id="mlla-start-scan"><?php echo esc_html($this->t('Iniciar varredura', 'Start scan', 'Iniciar escaneo')); ?></button>
                                <button class="button" id="mlla-pause-scan"><?php echo esc_html($this->t('Pausar', 'Pause', 'Pausar')); ?></button>
                                <button class="button" id="mlla-resume-scan"><?php echo esc_html($this->t('Retomar', 'Resume', 'Reanudar')); ?></button>
                                <button class="button mlla-btn-danger" id="mlla-stop-scan"><?php echo esc_html($this->t('Parar', 'Stop', 'Detener')); ?></button>
                                <button class="button mlla-btn-danger" id="mlla-reset-scan"><?php echo esc_html($this->t('Resetar estado', 'Reset state', 'Restablecer estado')); ?></button>
                                <button class="button" id="mlla-refresh-tables"><?php echo esc_html($this->t('Atualizar listas', 'Refresh lists', 'Actualizar listas')); ?></button>
                                <button class="button" id="mlla-restore-drafts-batch"><?php echo esc_html($this->t('Restaurar rascunhos (0)', 'Restore drafts (0)', 'Restaurar borradores (0)')); ?></button>
                            </div>
                            <textarea id="mlla-recent-events" readonly><?php echo esc_textarea(implode("\n", (array) $job['recent_events'])); ?></textarea>
                        </div>
                    </article>
                </div>
            </section>

            <section id="mlla-tab-results" class="mlla-tab-panel <?php echo $active_tab === 'results' ? 'is-active' : ''; ?>">
                <div class="mlla-grid mlla-grid-1">
                    <article class="mlla-card">
                        <div class="mlla-card-header">
                            <div>
                                <h2><?php echo esc_html($this->t('Resultados recentes', 'Recent results', 'Resultados recientes')); ?></h2>
                                <p class="mlla-muted"><?php echo esc_html($this->t('Links auditados com status, sugestão de correção e ações diretas.', 'Audited links with status, fix suggestion and direct actions.', 'Enlaces auditados con estado, sugerencia de corrección y acciones directas.')); ?></p>
                            </div>
                            <div class="mlla-card-actions">
                                <?php $this->render_export_csv_button(); ?>
                            </div>
                        </div>
                        <div id="mlla-results-table"><?php echo $this->render_results_table($results); ?></div>
                    </article>
                </div>
            </section>

            <section id="mlla-tab-quarantine" class="mlla-tab-panel <?php echo $active_tab === 'quarantine' ? 'is-active' : ''; ?>">
                <div class="mlla-grid mlla-grid-1">
                    <article class="mlla-card">
                        <div class="mlla-card-header">
                            <div>
                                <h2><?php echo esc_html($this->t('Quarentena', 'Quarantine', 'Cuarentena')); ?></h2>
                                <p class="mlla-muted"><?php echo esc_html($this->t('Toda ação destrutiva salva uma cópia do conteúdo original antes da alteração.', 'Every destructive action saves a copy of the original content before changing it.', 'Toda acción destructiva guarda una copia del contenido original antes del cambio.')); ?></p>
                            </div>
                        </div>
                        <div id="mlla-quarantine-table"><?php echo $this->render_quarantine_table($quarantine); ?></div>
                    </article>
                </div>
            </section>

            <section id="mlla-tab-settings" class="mlla-tab-panel <?php echo $active_tab === 'settings' ? 'is-active' : ''; ?>">
                <div class="mlla-grid mlla-grid-1">
                    <article class="mlla-card">
                        <div class="mlla-card-header">
                            <div>
                                <h2><?php echo esc_html($this->t('Configurações', 'Settings', 'Configuración')); ?></h2>
                                <p class="mlla-muted"><?php echo esc_html($this->t('Ajuste o comportamento da varredura e o escopo dos links auditados.', 'Adjust the scan behavior and the scope of audited links.', 'Ajuste el comportamiento del escaneo y el alcance de los enlaces auditados.')); ?></p>
                            </div>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('mlla_save_settings'); ?>
                            <input type="hidden" name="action" value="mlla_save_settings">
                            <div class="mlla-form-grid">
                                <div class="mlla-field">
                                    <label for="mlla-batch-size"><?php echo esc_html($this->t('Tamanho do lote', 'Batch size', 'Tamaño del lote')); ?></label>
                                    <input id="mlla-batch-size" type="number" min="1" max="100" name="settings[batch_size]" value="<?php echo esc_attr((string) $settings['batch_size']); ?>">
                                </div>
                                <div class="mlla-field">
                                    <label for="mlla-request-timeout"><?php echo esc_html($this->t('Timeout HTTP (seg)', 'HTTP timeout (sec)', 'Tiempo de espera HTTP (seg)')); ?></label>
                                    <input id="mlla-request-timeout" type="number" min="2" max="20" name="settings[request_timeout]" value="<?php echo esc_attr((string) $settings['request_timeout']); ?>">
                                </div>
                                <div class="mlla-field mlla-field-full">
                                    <label><?php echo esc_html($this->t('Tipos de conteúdo', 'Content types', 'Tipos de contenido')); ?></label>
                                    <?php foreach ($public_post_types as $pt): ?>
                                        <label class="mlla-inline-check"><input type="checkbox" name="settings[scan_post_types][]" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, (array) $settings['scan_post_types'], true)); ?>> <?php echo esc_html($pt->labels->singular_name); ?></label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mlla-field">
                                    <label><?php echo esc_html($this->t('Escopo dos links', 'Link scope', 'Alcance de enlaces')); ?></label>
                                    <label class="mlla-inline-check"><input type="checkbox" name="settings[scan_internal]" value="1" <?php checked(!empty($settings['scan_internal'])); ?>> <?php echo esc_html($this->t('Links internos', 'Internal links', 'Enlaces internos')); ?></label>
                                    <label class="mlla-inline-check"><input type="checkbox" name="settings[scan_external]" value="1" <?php checked(!empty($settings['scan_external'])); ?>> <?php echo esc_html($this->t('Links externos', 'External links', 'Enlaces externos')); ?></label>
                                </div>
                                <div class="mlla-field">
                                    <label><?php echo esc_html($this->t('Rascunho automático quando sem correção', 'Automatic draft when unresolved', 'Borrador automático cuando no haya corrección')); ?></label>
                                    <label class="mlla-inline-check"><input type="checkbox" name="settings[auto_draft_unresolved]" value="1" <?php checked(!empty($settings['auto_draft_unresolved'])); ?>> <?php echo esc_html($this->t('Ativar', 'Enable', 'Activar')); ?></label>
                                </div>
                                <div class="mlla-field mlla-field-full">
                                    <label for="mlla-user-agent"><?php echo esc_html($this->t('User-Agent', 'User-Agent', 'User-Agent')); ?></label>
                                    <input id="mlla-user-agent" type="text" name="settings[user_agent]" value="<?php echo esc_attr((string) $settings['user_agent']); ?>">
                                </div>
                            </div>
                            <div class="mlla-form-footer">
                                <button type="submit" class="button button-primary"><?php echo esc_html($this->t('Salvar configurações', 'Save settings', 'Guardar configuración')); ?></button>
                            </div>
                        </form>
                    </article>
                </div>
            </section>

            <section id="mlla-tab-license" class="mlla-tab-panel <?php echo $active_tab === 'license' ? 'is-active' : ''; ?>">
                <div class="mlla-grid">
                    <article class="mlla-card">
                        <div class="mlla-card-header">
                            <div>
                                <h2><?php echo esc_html($this->t('Status da licença', 'License status', 'Estado de la licencia')); ?></h2>
                                <p class="mlla-muted"><?php echo esc_html($this->t('Validação remota no servidor de licenças MLopes Design.', 'Remote validation on the MLopes Design license server.', 'Validación remota en el servidor de licencias MLopes Design.')); ?></p>
                            </div>
                        </div>
                        <?php
                        $state = mlla_license()->get_state();
                        $show_activation_form = empty($state['serial']) && ($state['status'] === 'inactive' || $state['status'] === 'free');
                        ?>
                        <div id="mlla-license-current-state">
                            <?php echo $this->render_license_state($state); ?>
                        </div>

                        <?php if ($show_activation_form): ?>
                            <div class="mlla-license-form-wrap">
                                <h3><?php echo esc_html($this->t('Ativar licença', 'Activate license', 'Activar licencia')); ?></h3>
                                <form id="mlla-license-form" class="mlla-form-grid">
                                    <div class="mlla-field mlla-field-full">
                                        <label for="mlla-license-key"><?php echo esc_html($this->t('Chave de licença', 'License key', 'Clave de licencia')); ?></label>
                                        <input id="mlla-license-key" type="text" name="license_key" placeholder="MLI-XXXXXX-XXXXXX-XXXXXX-XXXXXX" autocomplete="off">
                                    </div>
                                    <div class="mlla-field mlla-field-full">
                                        <label for="mlla-license-email"><?php echo esc_html($this->t('E-mail da compra', 'Purchase email', 'Correo de la compra')); ?></label>
                                        <input id="mlla-license-email" type="email" name="email" value="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                                    </div>
                                    <div class="mlla-form-footer">
                                        <button type="button" id="mlla-activate-license" class="button button-primary"><?php echo esc_html($this->t('Ativar licença', 'Activate license', 'Activar licencia')); ?></button>
                                        <button type="button" id="mlla-start-trial" class="button"><?php echo esc_html($this->t('Iniciar trial de 30 dias', 'Start 30-day trial', 'Iniciar trial de 30 días')); ?></button>
                                    </div>
                                    <p class="mlla-muted"><?php echo esc_html($this->t('Trial dá acesso a todos os recursos Pro por 30 dias, sem cartão.', 'Trial grants access to all Pro features for 30 days, no card required.', 'El trial da acceso a todas las funciones Pro por 30 días, sin tarjeta.')); ?></p>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="mlla-form-footer">
                                <button type="button" id="mlla-validate-license-now" class="button"><?php echo esc_html($this->t('Re-validar agora', 'Re-validate now', 'Re-validar ahora')); ?></button>
                                <button type="button" id="mlla-check-update" class="button"><?php echo esc_html($this->t('Verificar atualizações', 'Check for updates', 'Buscar actualizaciones')); ?></button>
                                <button type="button" id="mlla-deactivate-license" class="button mlla-btn-danger"><?php echo esc_html($this->t('Desativar neste site', 'Deactivate on this site', 'Desactivar en este sitio')); ?></button>
                            </div>
                        <?php endif; ?>
                        <div id="mlla-update-info" class="mlla-muted" style="margin-top:12px;font-size:12px"></div>
                    </article>
                    <article class="mlla-card">
                        <div class="mlla-card-header">
                            <div>
                                <h2><?php echo esc_html($this->t('Seu plano inclui', 'Your plan includes', 'Tu plan incluye')); ?></h2>
                                <p class="mlla-muted"><?php echo esc_html($this->t('Compare recursos disponíveis na sua assinatura.', 'Compare features available in your subscription.', 'Compara funciones disponibles en tu suscripción.')); ?></p>
                            </div>
                        </div>
                        <?php $this->render_features_catalog(); ?>
                        <div class="mlla-form-footer">
                            <a href="https://mlopesdesign.com.br/produtos/ml-link-auditor" target="_blank" rel="noopener" class="button"><?php echo esc_html($this->t('Comparar planos', 'Compare plans', 'Comparar planes')); ?></a>
                        </div>
                    </article>
                </div>
            </section>
        </div>
        <?php
    }

    private function get_dashboard_stats(): array {
        global $wpdb;
        $job = $this->get_job();
        $broken = max((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_results} WHERE status_code = %s", 'broken')), (int) ($job['broken_found'] ?? 0));
        $redirect = max((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_results} WHERE status_code = %s", 'redirect')), (int) ($job['redirect_found'] ?? 0));
        $timeout = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_results} WHERE status_code = %s", 'timeout'));
        $ok = max((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table_results} WHERE status_code = %s", 'ok')), (int) ($job['ok_found'] ?? 0));
        $quarantine = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_quarantine} WHERE deleted_at IS NULL");
        $processed = (int) ($job['processed_posts'] ?? 0);
        $total = (int) ($job['total_posts'] ?? 0);
        return [
            $this->t('Links quebrados', 'Broken links', 'Enlaces rotos') => $broken,
            $this->t('Redirecionamentos', 'Redirects', 'Redirecciones') => $redirect,
            $this->t('Timeouts', 'Timeouts', 'Timeouts') => $timeout,
            $this->t('Links OK', 'OK links', 'Enlaces OK') => $ok,
            $this->t('Itens em quarentena', 'Items in quarantine', 'Elementos en cuarentena') => $quarantine,
            $this->t('Rascunhos totais', 'Total drafts', 'Borradores totales') => $this->count_drafts_posts(),
            $this->t('Rascunhos do plugin', 'Plugin drafts', 'Borradores del plugin') => $this->count_plugin_drafts(),
            $this->t('Posts processados', 'Posts processed', 'Posts procesados') => $processed . '/' . $total,
        ];
    }


    
    private function count_drafts_posts(): int {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s AND post_type = %s",
            'draft',
            'post'
        );
        return (int) $wpdb->get_var($sql);
    }


    private function count_plugin_drafts(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$this->table_quarantine} WHERE deleted_at IS NULL AND restored_at IS NULL AND reason IN ('external_broken_auto_draft','auto_draft_unresolved','manual_draft')"
        );
    }


    private function get_job_progress(array $job): float {
        $total = max(0, (int) ($job['total_posts'] ?? 0));
        $processed = max(0, (int) ($job['processed_posts'] ?? 0));
        if ($total < 1) {
            return 0.0;
        }
        return round(min(100, ($processed / $total) * 100), 2);
    }

    private function translate_status(string $status): string {
        $map = [
            'idle' => $this->t('Parado', 'Idle', 'Inactivo'),
            'starting' => $this->t('Iniciando', 'Starting', 'Iniciando'),
            'running' => $this->t('Rodando', 'Running', 'Ejecutando'),
            'paused' => $this->t('Pausado', 'Paused', 'Pausado'),
            'stopping' => $this->t('Parando', 'Stopping', 'Deteniendo'),
            'finished' => $this->t('Concluído', 'Finished', 'Finalizado'),
            'error' => $this->t('Erro', 'Error', 'Error'),
        ];
        return $map[$status] ?? $status;
    }

    public function ajax_start_scan(): void {
        $this->require_admin_ajax();
        @set_time_limit(0);
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $previous = $this->get_job();
        $job = $this->default_job_state();
        $job['status'] = 'starting';
        $job['started_at'] = current_time('mysql');
        $job['run_id'] = wp_generate_uuid4();
        $job['recent_events'] = [];
        $job['queue'] = $this->build_post_queue();
        $job['total_posts'] = count($job['queue']);
        $job['status'] = $job['total_posts'] > 0 ? 'running' : 'finished';
        $job['last_message'] = $job['total_posts'] > 0
            ? $this->t('Fila pronta. Clique em Iniciar varredura novamente se quiser reiniciar do zero, ou aguarde o primeiro lote.', 'Queue ready. Wait for the first batch or click Start scan again if you want to restart from scratch.', 'Cola lista. Espere el primer lote o haga clic de nuevo en Iniciar escaneo si desea reiniciar desde cero.')
            : $this->t('Nenhum conteúdo encontrado para varrer.', 'No content found to scan.', 'No se encontró contenido para escanear.');
        $job['finished_at'] = $job['total_posts'] > 0 ? '' : current_time('mysql');
        $job['current_item'] = $previous['current_item'] ?? '';
        $this->push_event($job, $this->t('Preparando fila de posts...', 'Preparing posts queue...', 'Preparando cola de posts...'));
        $this->save_job($job);
        wp_send_json_success($this->get_refresh_payload());
    }

private function build_post_queue(): array {
        global $wpdb;
        $types = array_values(array_filter(array_map('sanitize_key', (array) $this->settings['scan_post_types'])));
        if (empty($types)) {
            $types = ['post', 'page'];
        }
        $in = implode(',', array_fill(0, count($types), '%s'));
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) ORDER BY ID ASC",
            ...$types
        );
        return array_map('intval', $wpdb->get_col($sql));
    }

    public function ajax_process_scan(): void {
        $this->require_admin_ajax();
        @set_time_limit(0);
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $request_run_id = sanitize_text_field((string) ($_POST['run_id'] ?? ''));
        // $control é a ÚNICA fonte de verdade. Antes, $job era lido uma vez
        // e $control relido dentro do loop, mas as mutações iam em $job local
        // e sobrescreviam o estado fresco salvo pelo usuário (pause/stop).
        $control = $this->get_job();

        if ($request_run_id === '' || $request_run_id !== (string) ($control['run_id'] ?? '')) {
            wp_send_json_success($this->get_refresh_payload());
        }

        if (!in_array($control['status'], ['running', 'starting'], true)) {
            wp_send_json_success($this->get_refresh_payload());
        }

        $now = time();
        if ((int) $control['lock_until'] > $now) {
            wp_send_json_success($this->get_refresh_payload());
        }

        $control['lock_until'] = $now + self::LOCK_TTL;
        if ($control['status'] === 'starting') {
            $control['status'] = 'running';
        }
        $this->save_job($control);

        $batch = max(1, (int) $this->settings['batch_size']);
        $processedThisCall = 0;
        $totalQueue = count((array) $control['queue']);

        while ($processedThisCall < $batch && (int) $control['current_index'] < $totalQueue) {
            // Refetch dentro do loop para captar pause/stop_requested em tempo real.
            $control = $this->get_job();

            if ($request_run_id !== (string) ($control['run_id'] ?? '')) {
                $control['lock_until'] = 0;
                $this->save_job($control);
                wp_send_json_success($this->get_refresh_payload());
            }

            if (!empty($control['stop_requested'])) {
                $control['status'] = 'idle';
                $control['finished_at'] = current_time('mysql');
                $control['last_message'] = $this->t('Varredura interrompida pelo usuário. Resultados parciais mantidos no painel.', 'Scan stopped by user. Partial results kept in the panel.', 'Escaneo detenido por el usuario. Los resultados parciales se mantienen en el panel.');
                $control['stop_requested'] = 0;
                $control['pause_requested'] = 0;
                $control['lock_until'] = 0;
                $control['run_id'] = '';
                $this->push_event($control, $control['last_message']);
                $this->save_job($control);
                wp_send_json_success($this->get_refresh_payload());
            }

            if (!empty($control['pause_requested'])) {
                $control['status'] = 'paused';
                $control['last_message'] = $this->t('Varredura pausada.', 'Scan paused.', 'Escaneo pausado.');
                $control['stop_requested'] = 0;
                $control['lock_until'] = 0;
                $this->push_event($control, $control['last_message']);
                $this->save_job($control);
                wp_send_json_success($this->get_refresh_payload());
            }

            $post_id = isset($control['queue'][$control['current_index']]) ? (int) $control['queue'][$control['current_index']] : 0;
            $control['current_index']++;
            if ($post_id < 1) {
                continue;
            }

            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }

            $control['current_item'] = sprintf('#%d %s', $post_id, wp_strip_all_tags($post->post_title));
            $this->save_job($control);

            try {
                $control = $this->scan_post($post, $control);
            } catch (\Throwable $e) {
                $control['issues_found']++;
                $this->push_event(
                    $control,
                    sprintf(
                        $this->t('Erro ao auditar o post #%d: %s', 'Error scanning post #%d: %s', 'Error al auditar el post #%d: %s'),
                        $post_id,
                        $e->getMessage()
                    )
                );
            }

            $control['processed_posts']++;
            $processedThisCall++;
            $control['lock_until'] = time() + self::LOCK_TTL;
            $this->save_job($control);
        }

        // Refetch para garantir que ainda estamos no mesmo run.
        $control = $this->get_job();
        if ($request_run_id !== (string) ($control['run_id'] ?? '')) {
            $control['lock_until'] = 0;
            $this->save_job($control);
            wp_send_json_success($this->get_refresh_payload());
        }

        if ((int) $control['current_index'] >= $totalQueue) {
            $control['status'] = 'finished';
            $control['finished_at'] = current_time('mysql');
            $control['last_message'] = $this->t('Varredura concluída.', 'Scan finished.', 'Escaneo finalizado.');
            $control['run_id'] = '';
            $this->push_event($control, $control['last_message']);
        } elseif ($processedThisCall > 0) {
            $control['status'] = 'paused';
            $control['last_message'] = sprintf(
                $this->t('Lote concluído com %1$d/%2$d posts processados. Clique em Retomar para continuar.', 'Batch finished with %1$d/%2$d posts processed. Click Resume to continue.', 'Lote finalizado con %1$d/%2$d posts procesados. Haga clic en Reanudar para continuar.'),
                $processedThisCall,
                $batch
            );
            $this->push_event($control, $control['last_message']);
        } else {
            $control['status'] = 'paused';
            $control['last_message'] = $this->t('Nenhum post publicável foi processado neste lote. Clique em Retomar para continuar.', 'No publishable post was processed in this batch. Click Resume to continue.', 'No se procesó ninguna entrada publicable en este lote. Haga clic en Reanudar para continuar.');
            $this->push_event($control, $control['last_message']);
        }

        $control['stop_requested'] = 0;
        $control['pause_requested'] = 0;
        $control['lock_until'] = 0;
        $this->save_job($control);

        wp_send_json_success($this->get_refresh_payload());
    }

private function scan_post(WP_Post $post, array $job): array {
        global $wpdb;
        $links = $this->extract_links((string) $post->post_content);
        if (empty($links)) {
            $this->push_event($job, sprintf($this->t('Post #%d sem links.', 'Post #%d without links.', 'Post #%d sin enlaces.'), (int) $post->ID));
            return $job;
        }

        foreach ($links as $link) {
            $url = trim((string) ($link['url'] ?? ''));
            $url_raw = (string) ($link['url_raw'] ?? $url);
            if ($url === '') {
                continue;
            }
            $normalized = $this->normalize_url($url);
            $scope = $this->classify_url_scope($normalized);
            if (($scope === 'internal' && empty($this->settings['scan_internal'])) || ($scope === 'external' && empty($this->settings['scan_external']))) {
                continue;
            }

            $result = $this->validate_url($normalized);
            $hash = md5($post->ID . '|' . $normalized);
            $data = [
                'post_id' => (int) $post->ID,
                'post_type' => (string) $post->post_type,
                'post_status' => (string) $post->post_status,
                'post_title' => (string) $post->post_title,
                'link_url' => $url,
                'link_url_raw' => $url_raw,
                'normalized_url' => $normalized,
                'link_text' => mb_substr((string) ($link['text'] ?? ''), 0, 255),
                'link_context' => mb_substr((string) ($link['html'] ?? ''), 0, 5000),
                'source_type' => 'content',
                'status_code' => $result['status_code'],
                'http_code' => $result['http_code'],
                'final_url' => $result['final_url'],
                'suggestion_url' => $result['suggestion_url'],
                'suggestion_label' => $result['suggestion_label'],
                'scan_hash' => $hash,
                'note' => $result['note'],
                'occurrences' => 1,
                'first_seen' => current_time('mysql'),
                'last_seen' => current_time('mysql'),
            ];

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_results} WHERE scan_hash = %s", $hash));
            if ($existing) {
                // Re-detecção: atualiza contexto e incrementa occurrences em vez de resetar.
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table_results} SET
                        post_status = %s,
                        post_title = %s,
                        link_url = %s,
                        link_url_raw = %s,
                        link_text = %s,
                        link_context = %s,
                        status_code = %s,
                        http_code = %s,
                        final_url = %s,
                        suggestion_url = %s,
                        suggestion_label = %s,
                        note = %s,
                        last_seen = %s,
                        occurrences = occurrences + 1
                     WHERE id = %d",
                    $data['post_status'],
                    $data['post_title'],
                    $data['link_url'],
                    $data['link_url_raw'],
                    $data['link_text'],
                    $data['link_context'],
                    $data['status_code'],
                    $data['http_code'],
                    $data['final_url'],
                    $data['suggestion_url'],
                    $data['suggestion_label'],
                    $data['note'],
                    $data['last_seen'],
                    (int) $existing
                ));
            } else {
                $wpdb->insert($this->table_results, $data);
            }

            if ($result['status_code'] === 'broken') {
                $job['issues_found']++;
                $job['broken_found']++;
                $shouldDraft = false;
                if ($scope === 'external') {
                    $shouldDraft = true;
                } elseif (!empty($this->settings['auto_draft_unresolved']) && empty($result['suggestion_url'])) {
                    $shouldDraft = true;
                }
                if ($shouldDraft) {
                    $this->move_post_to_draft_with_quarantine((int) $post->ID, $scope === 'external' ? 'external_broken_auto_draft' : 'auto_draft_unresolved');
                }
            } elseif ($result['status_code'] === 'redirect') {
                $job['issues_found']++;
                $job['redirect_found']++;
            } elseif ($result['status_code'] === 'ok') {
                $job['ok_found']++;
            } elseif ($result['status_code'] === 'timeout' || $result['status_code'] === 'invalid') {
                $job['issues_found']++;
            }
        }

        $this->push_event($job, sprintf($this->t('Post #%1$d auditado: %2$d links.', 'Post #%1$d scanned: %2$d links.', 'Post #%1$d auditado: %2$d enlaces.'), (int) $post->ID, count($links)));
        return $job;
    }

    private function extract_links(string $html): array {
        $links = [];
        if ($html === '') {
            return $links;
        }

        if (preg_match_all('/<a\b[^>]*href=("|\')(.*?)\1[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $raw = (string) ($m[2] ?? '');
                $links[] = [
                    'url' => html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    // Preserva a forma como a URL aparece no HTML (&amp; intacto)
                    // para permitir replace confiável no conteúdo original.
                    'url_raw' => $raw,
                    'text' => trim(wp_strip_all_tags((string) ($m[3] ?? ''))),
                    'html' => (string) ($m[0] ?? ''),
                ];
            }
        }

        if (empty($links)) {
            if (preg_match_all('~https?://[^\s"\'<>\]]+~i', $html, $urlMatches)) {
                foreach ((array) ($urlMatches[0] ?? []) as $url) {
                    $links[] = [
                        'url' => html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'url_raw' => (string) $url,
                        'text' => '',
                        'html' => (string) $url,
                    ];
                }
            }
        }

        $unique = [];
        foreach ($links as $link) {
            $key = md5((string) $link['url'] . '|' . (string) $link['html']);
            $unique[$key] = $link;
        }
        return array_values($unique);
    }

    private function normalize_url(string $url): string {
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') {
        return $url;
    }
    if (strpos($url, '//') === 0) {
        $scheme = is_ssl() ? 'https:' : 'http:';
        $url = $scheme . $url;
    }

    $specialSchemes = ['mailto:', 'tel:', 'javascript:', '#'];
    foreach ($specialSchemes as $prefix) {
        if (stripos($url, $prefix) === 0) {
            return $url;
        }
    }

    $homeHost = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    $urlHost = (string) wp_parse_url($url, PHP_URL_HOST);
    $path = (string) wp_parse_url($url, PHP_URL_PATH);

    if ($homeHost !== '' && $urlHost !== '' && strtolower($homeHost) === strtolower($urlHost) && $path !== '') {
        $legacyPrefixes = [
            '/wordpress/wp-content/uploads/' => '/wp-content/uploads/',
            '/wordpress/wp-content/' => '/wp-content/',
            '/wordpress/' => '/',
        ];
        foreach ($legacyPrefixes as $legacy => $target) {
            if (strpos($path, $legacy) === 0) {
                $normalizedPath = $target . ltrim(substr($path, strlen($legacy)), '/');
                $normalizedPath = preg_replace('#/+#', '/', $normalizedPath);
                $query = (string) wp_parse_url($url, PHP_URL_QUERY);
                $fragment = (string) wp_parse_url($url, PHP_URL_FRAGMENT);
                $url = home_url($normalizedPath);
                if ($query !== '') {
                    $url .= '?' . $query;
                }
                if ($fragment !== '') {
                    $url .= '#' . $fragment;
                }
                break;
            }
        }
    }

    return $url;
}

    private function classify_url_scope(string $url): string {
    $lower = strtolower($url);
    foreach (['mailto:', 'tel:', 'javascript:', '#'] as $prefix) {
        if (strpos($lower, $prefix) === 0) {
            return 'special';
        }
    }
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    $urlHost = wp_parse_url($url, PHP_URL_HOST);
    if (!$urlHost || $urlHost === $host) {
        return 'internal';
    }
    return 'external';
}

    private function validate_url(string $url): array {
    $result = [
        'status_code' => 'invalid',
        'http_code' => null,
        'final_url' => $url,
        'suggestion_url' => '',
        'suggestion_label' => '',
        'note' => '',
    ];

    if ($url === '') {
        $result['note'] = $this->t('URL vazia.', 'Empty URL.', 'URL vacía.');
        return $result;
    }

    $scope = $this->classify_url_scope($url);
    if ($scope === 'special') {
        $result['status_code'] = 'ok';
        $result['note'] = $this->t('Link especial não verificável automaticamente, mas válido no conteúdo.', 'Special link not auto-checkable, but valid in content.', 'Enlace especial no verificable automáticamente, pero válido en el contenido.');
        return $result;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $result['note'] = $this->t('URL inválida.', 'Invalid URL.', 'URL no válida.');
        return $result;
    }

    if ($scope === 'internal') {
        $internal = $this->validate_internal_url($url);
        if (!empty($internal['suggestion_url'])) {
            $result['suggestion_url'] = $internal['suggestion_url'];
            $result['suggestion_label'] = $internal['suggestion_label'];
        }
        return array_merge($result, $internal);
    }

    $args = [
        'timeout' => max(2, (int) $this->settings['request_timeout']),
        'redirection' => 3,
        'sslverify' => false,
        'user-agent' => (string) $this->settings['user_agent'],
        'limit_response_size' => 1024,
        'reject_unsafe_urls' => false,
    ];

    $response = wp_remote_head($url, $args);
    if (is_wp_error($response)) {
        // Fallback para GET, mas preservando limit_response_size para não baixar GB de HTML.
        $response = wp_remote_get($url, array_merge($args, [
            'method' => 'GET',
            'limit_response_size' => 1024,
        ]));
        if (is_wp_error($response)) {
            $message = strtolower($response->get_error_message());
            $result['status_code'] = (strpos($message, 'timed out') !== false || strpos($message, 'timeout') !== false) ? 'timeout' : 'broken';
            $result['note'] = $response->get_error_message();
            return $result;
        }
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $final = '';
    if (isset($response['http_response']) && is_object($response['http_response']) && method_exists($response['http_response'], 'get_response_object')) {
        $obj = $response['http_response']->get_response_object();
        if (is_object($obj) && isset($obj->url)) {
            $final = (string) $obj->url;
        }
    }
    if ($final === '') {
        $location = wp_remote_retrieve_header($response, 'location');
        if (is_array($location)) {
            $location = end($location);
        }
        $final = is_string($location) ? $location : '';
    }

    if ($code >= 200 && $code < 300) {
        $result['status_code'] = 'ok';
    } elseif ($code === 401 || $code === 403) {
        $result['status_code'] = 'ok';
        $result['note'] = $this->t('Recurso protegido, mas acessível.', 'Protected resource, but reachable.', 'Recurso protegido, pero accesible.');
    } elseif ($code >= 300 && $code < 400) {
        $result['status_code'] = 'redirect';
        $result['final_url'] = $final !== '' ? $final : $url;
        $result['suggestion_url'] = $result['final_url'];
        $result['suggestion_label'] = $this->t('URL final do redirecionamento', 'Redirect final URL', 'URL final de redirección');
        // Revalida o destino do redirect: se também estiver quebrado, anota na nota
        // para o usuário decidir antes de aplicar a sugestão cega.
        if ($result['final_url'] !== '' && $result['final_url'] !== $url) {
            $destCheck = $this->probe_url($result['final_url']);
            if (is_wp_error($destCheck)) {
                $result['note'] = $this->t('Redireciona para uma URL que falhou: ', 'Redirects to a URL that failed: ', 'Redirige a una URL que falló: ') . $destCheck->get_error_message();
            } else {
                $destCode = (int) wp_remote_retrieve_response_code($destCheck);
                if ($destCode >= 400 || $destCode < 200) {
                    $result['note'] = $this->t('Redireciona para uma URL que retorna HTTP ', 'Redirects to a URL returning HTTP ', 'Redirige a una URL que devuelve HTTP ') . $destCode . '.';
                }
            }
        }
    } elseif ($code == 404 || $code == 410 || $code >= 500) {
        $result['status_code'] = 'broken';
    } else {
        $result['status_code'] = 'timeout';
    }
    $result['http_code'] = $code;
    return $result;
}

/**
 * Faz um HEAD simples numa URL para verificar se está acessível.
 * Retorna WP_Error ou o response array.
 */
private function probe_url(string $url) {
    return wp_remote_head($url, [
        'timeout' => max(2, (int) $this->settings['request_timeout']),
        'redirection' => 0,
        'sslverify' => false,
        'user-agent' => (string) $this->settings['user_agent'],
        'limit_response_size' => 1024,
        'reject_unsafe_urls' => false,
    ]);
}

private function validate_internal_url(string $url): array {
    $result = [
        'status_code' => 'broken',
        'http_code' => null,
        'final_url' => $url,
        'suggestion_url' => '',
        'suggestion_label' => '',
        'note' => '',
    ];
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    $urlHost = wp_parse_url($url, PHP_URL_HOST);
    if ($urlHost && $urlHost !== $host) {
        return $this->validate_url($url);
    }

    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $canonicalUrl = $this->normalize_url($url);
    $canonicalPath = (string) wp_parse_url($canonicalUrl, PHP_URL_PATH);

    if ($canonicalUrl !== $url) {
        $result['suggestion_url'] = $canonicalUrl;
        $result['suggestion_label'] = $this->t('URL interna legada normalizada', 'Normalized legacy internal URL', 'URL interna heredada normalizada');
    }

    $candidatePaths = array_values(array_unique(array_filter([$canonicalPath, $path])));

    foreach ($candidatePaths as $candidatePath) {
        if (strpos($candidatePath, '/wp-content/uploads/') !== false) {
            $relative = ltrim($candidatePath, '/');
            $abs = trailingslashit(ABSPATH) . $relative;
            if (file_exists($abs)) {
                $result['status_code'] = 'ok';
                if ($canonicalUrl !== $url) {
                    $result['note'] = $this->t('Caminho legado detectado. A URL canônica foi sugerida.', 'Legacy path detected. Canonical URL suggested.', 'Ruta heredada detectada. Se sugirió la URL canónica.');
                }
                return $result;
            }
        }
    }

    foreach ($candidatePaths as $candidatePath) {
        if (strpos($candidatePath, '/wp-content/uploads/') !== false) {
            $basename = wp_basename($candidatePath);
            $uploads = wp_get_upload_dir();
            if (!empty($uploads['basedir']) && $basename !== '') {
                $found = $this->find_upload_file_by_basename($uploads['basedir'], $basename);
                if ($found) {
                    $relativeFound = str_replace(wp_normalize_path(trailingslashit($uploads['basedir'])), '', wp_normalize_path($found));
                    $relativeFound = ltrim(str_replace('\\', '/', $relativeFound), '/');
                    $foundUrl = trailingslashit($uploads['baseurl']) . $relativeFound;
                    $result['suggestion_url'] = $foundUrl;
                    $result['suggestion_label'] = $this->t('Arquivo com mesmo nome encontrado em uploads', 'Same filename found in uploads', 'Archivo con el mismo nombre encontrado en uploads');
                }
            }
        }
    }

    if (empty($result['suggestion_url'])) {
        $candidate = $this->find_internal_replacement_candidate($canonicalUrl !== '' ? $canonicalUrl : $url);
        if ($candidate) {
            $result['suggestion_url'] = $candidate;
            $result['suggestion_label'] = $this->t('Conteúdo interno parecido encontrado', 'Similar internal content found', 'Contenido interno similar encontrado');
        }
    }

    $post_id = url_to_postid($canonicalUrl !== '' ? $canonicalUrl : $url);
    if ($post_id > 0 && get_post_status($post_id) === 'publish') {
        $result['status_code'] = 'ok';
        return $result;
    }

    return $result;
}

private function find_internal_replacement_candidate(string $url): string {
        global $wpdb;
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $basename = wp_basename($path);
        $basenameSansExt = pathinfo($basename, PATHINFO_FILENAME);

        if ($basename !== '') {
            $attachment = $wpdb->get_var($wpdb->prepare(
                "SELECT guid FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s ORDER BY ID DESC LIMIT 1",
                '%' . $wpdb->esc_like($basename)
            ));
            if ($attachment) {
                return (string) $attachment;
            }
        }

        $slug = sanitize_title($basenameSansExt !== '' ? $basenameSansExt : trim($path, '/'));
        if ($slug !== '') {
            $post = get_page_by_path($slug, OBJECT, ['post', 'page']);
            if ($post instanceof WP_Post && $post->post_status === 'publish') {
                return get_permalink($post);
            }
        }

        return '';
    }


private function find_upload_file_by_basename(string $baseDir, string $basename): string {
    $baseDir = wp_normalize_path($baseDir);
    if ($baseDir === '' || !is_dir($baseDir) || $basename === '') {
        return '';
    }

    $fast = glob($baseDir . '/*/*/' . $basename);
    if (is_array($fast) && !empty($fast[0]) && file_exists($fast[0])) {
        return (string) $fast[0];
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getFilename() === $basename) {
                return (string) $file->getPathname();
            }
        }
    } catch (Throwable $e) {
        return '';
    }

    return '';
}

    private function push_event(array &$job, string $message): void {
        $events = array_values((array) ($job['recent_events'] ?? []));
        $events[] = '[' . current_time('H:i:s') . '] ' . $message;
        if (count($events) > 20) {
            $events = array_slice($events, -20);
        }
        $job['recent_events'] = $events;
        $job['last_message'] = $message;
    }

    public function ajax_get_status(): void {
        $this->require_admin_ajax();
        wp_send_json_success(['job' => $this->get_status_payload()]);
    }

    private function get_status_payload(): array {
        $job = $this->get_job();
        $job['progress'] = $this->get_job_progress($job);
        $job['status_label'] = $this->translate_status((string) $job['status']);
        return $job;
    }

    public function ajax_pause_scan(): void {
    $this->require_admin_ajax();
    $job = $this->get_job();
    if (in_array($job['status'], ['running', 'starting'], true)) {
        $job['pause_requested'] = 1;
        $job['stop_requested'] = 0;
        $job['status'] = 'paused';
        $job['lock_until'] = 0;
        $this->push_event($job, $this->t('Pausa solicitada.', 'Pause requested.', 'Pausa solicitada.'));
        $this->save_job($job);
    }
    wp_send_json_success($this->get_refresh_payload());
}

public function ajax_resume_scan(): void {
    $this->require_admin_ajax();
    $job = $this->get_job();
    if ($job['status'] === 'paused') {
        if ((int) ($job['current_index'] ?? 0) >= (int) ($job['total_posts'] ?? 0)) {
            $job['status'] = 'finished';
            $job['last_message'] = $this->t('Não há mais posts pendentes. Inicie nova varredura ou resete o estado.', 'There are no more pending posts. Start a new scan or reset the state.', 'No hay más posts pendientes. Inicie un nuevo escaneo o restablezca el estado.');
            $this->push_event($job, $job['last_message']);
            $this->save_job($job);
            wp_send_json_success($this->get_refresh_payload());
        }
        if (empty($job['run_id'])) {
            $job['run_id'] = wp_generate_uuid4();
        }
        $job['status'] = 'running';
        $job['pause_requested'] = 0;
        $job['stop_requested'] = 0;
        $job['lock_until'] = 0;
        $this->push_event($job, $this->t('Varredura retomada.', 'Scan resumed.', 'Escaneo reanudado.'));
        $this->save_job($job);
    }
    wp_send_json_success($this->get_refresh_payload());
}

public function ajax_stop_scan(): void {
    $this->require_admin_ajax();
    $job = $this->get_job();
    if (in_array($job['status'], ['running', 'paused', 'starting'], true)) {
        $job['stop_requested'] = 0;
        $job['pause_requested'] = 0;
        $job['status'] = 'idle';
        $job['finished_at'] = current_time('mysql');
        $job['lock_until'] = 0;
        $job['run_id'] = '';
        $job['last_message'] = $this->t('Varredura interrompida pelo usuário. Resultados parciais mantidos no painel.', 'Scan stopped by user. Partial results kept in the panel.', 'Escaneo detenido por el usuario. Los resultados parciales se mantienen en el panel.');
        $this->push_event($job, $job['last_message']);
        $this->save_job($job);
    }
    wp_send_json_success($this->get_refresh_payload());
}

public function ajax_reset_scan(): void {
    $this->require_admin_ajax();
    global $wpdb;
    $wpdb->query("TRUNCATE TABLE {$this->table_results}");
    $wpdb->query("TRUNCATE TABLE {$this->table_quarantine}");
    $this->save_job($this->default_job_state());
    wp_send_json_success($this->get_refresh_payload());
}

private function save_quarantine_snapshot(int $post_id, string $reason, array $meta = []): int {
        global $wpdb;
        $post = get_post($post_id);
        if (!$post) {
            return 0;
        }
        $wpdb->insert($this->table_quarantine, [
            'post_id' => $post_id,
            'reason' => $reason,
            'original_status' => (string) $post->post_status,
            'original_content' => (string) $post->post_content,
            'meta_json' => wp_json_encode($meta),
            'created_at' => current_time('mysql'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function move_post_to_draft_with_quarantine(int $post_id, string $reason): bool {
        $post = get_post($post_id);
        if (!$post || $post->post_status === 'draft') {
            return false;
        }
        $this->save_quarantine_snapshot($post_id, $reason, ['action' => 'draft']);
        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
        return true;
    }

    public function ajax_apply_suggestion(): void {
        $this->require_admin_ajax();
        $result_id = isset($_POST['result_id']) ? (int) $_POST['result_id'] : 0;
        $record = $this->get_result_record($result_id);
        if (!$record || empty($record->suggestion_url)) {
            wp_send_json_error(['message' => $this->t('Sugestão não encontrada.', 'Suggestion not found.', 'Sugerencia no encontrada.')], 404);
        }
        $this->apply_link_replacement((int) $record->post_id, (string) $record->link_url, (string) $record->suggestion_url, 'suggestion');
        wp_send_json_success($this->get_refresh_payload());
    }

    public function ajax_replace_link(): void {
        $this->require_admin_ajax();
        $result_id = isset($_POST['result_id']) ? (int) $_POST['result_id'] : 0;
        $new_url = isset($_POST['new_url']) ? esc_url_raw(wp_unslash($_POST['new_url'])) : '';
        if ($new_url === '') {
            wp_send_json_error(['message' => $this->t('Informe a nova URL.', 'Enter the new URL.', 'Informe la nueva URL.')], 400);
        }
        $record = $this->get_result_record($result_id);
        if (!$record) {
            wp_send_json_error(['message' => $this->t('Registro não encontrado.', 'Record not found.', 'Registro no encontrado.')], 404);
        }
        $this->apply_link_replacement((int) $record->post_id, (string) $record->link_url, $new_url, 'manual');
        wp_send_json_success($this->get_refresh_payload());
    }

    private function apply_link_replacement(int $post_id, string $old_url, string $new_url, string $mode): void {
        global $wpdb;
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        $this->save_quarantine_snapshot($post_id, 'replace_link', ['old_url' => $old_url, 'new_url' => $new_url, 'mode' => $mode]);
        $content = (string) $post->post_content;
        $updated = $this->replace_url_in_content($content, $old_url, $new_url);
        if ($updated !== $content) {
            wp_update_post(['ID' => $post_id, 'post_content' => $updated]);
        }
        $hash = md5($post_id . '|' . $this->normalize_url($old_url));
        $wpdb->update($this->table_results, [
            'status_code' => 'fixed',
            'final_url' => $new_url,
            'fixed_at' => current_time('mysql'),
            'note' => $this->t('Link substituído.', 'Link replaced.', 'Enlace sustituido.'),
        ], ['scan_hash' => $hash]);
    }

    /**
     * Substitui uma URL dentro do conteúdo HTML do post de forma tolerante a entidades HTML.
     *
     * Problema corrigido: URLs com & eram salvas DECODADAS no banco, mas o post_content
     * mantém &amp;. str_replace direto falhava silenciosamente. Agora aceitamos
     * opcionalmente `&amp;` no conteúdo original.
     *
     * @param string $content  Conteúdo HTML do post (com possíveis &amp;)
     * @param string $old_url  URL decodificada (forma canônica para match)
     * @param string $new_url  Nova URL (deve estar sanitizada)
     * @return string Conteúdo atualizado (ou original se nada casar)
     */
    private function replace_url_in_content(string $content, string $old_url, string $new_url): string {
        if ($old_url === '' || $content === '') {
            return $content;
        }
        // Estratégia 1: match exato na forma decodificada.
        if (strpos($content, $old_url) !== false) {
            return str_replace($old_url, $new_url, $content);
        }
        // Estratégia 2: tolerar &amp; → & na busca. Tenta variações codificadas.
        $escaped = preg_quote($old_url, '/');
        // Substitui & literal por &(amp;)? na regex.
        $pattern = '/' . str_replace('&', '&(?:amp;)?', $escaped) . '/';
        $updated = preg_replace($pattern, $new_url, $content);
        return is_string($updated) ? $updated : $content;
    }

    public function ajax_remove_link(): void {
        $this->require_admin_ajax();
        $result_id = isset($_POST['result_id']) ? (int) $_POST['result_id'] : 0;
        $record = $this->get_result_record($result_id);
        if (!$record) {
            wp_send_json_error(['message' => $this->t('Registro não encontrado.', 'Record not found.', 'Registro no encontrado.')], 404);
        }
        $post = get_post((int) $record->post_id);
        if (!$post) {
            wp_send_json_error(['message' => $this->t('Post não encontrado.', 'Post not found.', 'Post no encontrado.')], 404);
        }
        $this->save_quarantine_snapshot((int) $record->post_id, 'remove_link', ['link_url' => $record->link_url]);
        $content = (string) $post->post_content;
        // Tenta primeiro com link_url (decodificado); se não casar,
        // tenta tolerando &amp; na entidade.
        $url_decoded = (string) $record->link_url;
        $url_raw = isset($record->link_url_raw) && $record->link_url_raw !== ''
            ? (string) $record->link_url_raw
            : $url_decoded;

        $updated = $this->remove_anchor_with_url($content, $url_raw);
        if ($updated === $content) {
            // Fallback: tenta com a versão decodificada (compatibilidade com registros antigos).
            $updated = $this->remove_anchor_with_url($content, $url_decoded);
        }
        if ($updated !== $content) {
            wp_update_post(['ID' => (int) $post->ID, 'post_content' => $updated]);
        }
        global $wpdb;
        $wpdb->update($this->table_results, [
            'status_code' => 'removed',
            'fixed_at' => current_time('mysql'),
            'note' => $this->t('Link removido e conteúdo interno preservado.', 'Link removed and inner content preserved.', 'Enlace eliminado y contenido interno preservado.'),
        ], ['id' => $result_id]);
        wp_send_json_success($this->get_refresh_payload());
    }

    /**
     * Remove a tag <a> inteira cuja href contenha $url,
     * tolerando a forma da URL (decodificada ou com &amp;).
     */
    private function remove_anchor_with_url(string $content, string $url): string {
        if ($url === '') return $content;
        $escaped = preg_quote($url, '/');
        // Aceita & ou &amp; no match.
        $escaped = str_replace('&', '&(?:amp;)?', $escaped);
        $pattern = '/<a\b[^>]*href=("|\')' . $escaped . '\1[^>]*>(.*?)<\/a>/is';
        $updated = preg_replace($pattern, '$2', $content);
        return is_string($updated) ? $updated : $content;
    }

    public function ajax_move_post_to_draft(): void {
        $this->require_admin_ajax();
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($post_id < 1) {
            wp_send_json_error(['message' => $this->t('Post inválido.', 'Invalid post.', 'Post inválido.')], 400);
        }
        $this->move_post_to_draft_with_quarantine($post_id, 'manual_draft');
        wp_send_json_success($this->get_refresh_payload());
    }

    public function ajax_restore_quarantine(): void {
        $this->require_admin_ajax();
        global $wpdb;
        $id = isset($_POST['quarantine_id']) ? (int) $_POST['quarantine_id'] : 0;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_quarantine} WHERE id = %d", $id));
        if (!$row) {
            wp_send_json_error(['message' => $this->t('Cópia não encontrada.', 'Copy not found.', 'Copia no encontrada.')], 404);
        }
        wp_update_post([
            'ID' => (int) $row->post_id,
            'post_content' => (string) $row->original_content,
            'post_status' => (string) $row->original_status,
        ]);
        $wpdb->update($this->table_quarantine, ['restored_at' => current_time('mysql')], ['id' => $id]);
        wp_send_json_success($this->get_refresh_payload());
    }

    public function ajax_delete_quarantine(): void {
        $this->require_admin_ajax();
        global $wpdb;
        $id = isset($_POST['quarantine_id']) ? (int) $_POST['quarantine_id'] : 0;
        if ($id < 1) {
            wp_send_json_error(['message' => $this->t('Cópia inválida.', 'Invalid copy.', 'Copia no válida.')], 400);
        }
        $wpdb->update($this->table_quarantine, ['deleted_at' => current_time('mysql')], ['id' => $id]);
        wp_send_json_success($this->get_refresh_payload());
    }


    /**
     * Restaura apenas posts que foram movidos para rascunho PELO PLUGIN
     * (existe snapshot em quarentena ainda não restaurado).
     *
     * Antes dessa correção: publicava QUALQUER draft do tipo 'post' no site,
     * incluindo os que o usuário estava escrevendo manualmente.
     */
    public function ajax_restore_drafts_batch(): void {
        $this->require_admin_ajax();
        global $wpdb;

        $snapshots = $wpdb->get_results(
            "SELECT q.id AS quarantine_id, q.post_id, q.original_status, q.original_content, q.reason
             FROM {$this->table_quarantine} q
             INNER JOIN {$wpdb->posts} p ON p.ID = q.post_id
             WHERE q.restored_at IS NULL
               AND q.deleted_at IS NULL
               AND p.post_status = 'draft'
               AND q.reason IN ('external_broken_auto_draft','auto_draft_unresolved','manual_draft')
             ORDER BY q.post_id ASC, q.created_at DESC
             LIMIT 100"
        );

        $restored = 0;
        $seen_posts = [];
        foreach ((array) $snapshots as $snap) {
            $post_id = (int) $snap->post_id;
            if ($post_id < 1 || isset($seen_posts[$post_id])) {
                continue;
            }
            $seen_posts[$post_id] = true;

            $updated = wp_update_post([
                'ID' => $post_id,
                'post_status' => (string) $snap->original_status,
                'post_content' => (string) $snap->original_content,
            ], true);

            if (!is_wp_error($updated)) {
                $wpdb->update(
                    $this->table_quarantine,
                    ['restored_at' => current_time('mysql')],
                    ['id' => (int) $snap->quarantine_id]
                );
                $restored++;
            }
        }

        $job = $this->get_job();
        $message = $restored > 0
            ? sprintf($this->t('%d rascunhos do plugin restaurados.', '%d plugin drafts restored.', '%d borradores del plugin restaurados.'), $restored)
            : $this->t('Nenhum rascunho do plugin para restaurar.', 'No plugin drafts to restore.', 'No hay borradores del plugin para restaurar.');
        $this->push_event($job, $message);
        $this->save_job($job);

        wp_send_json_success($this->get_refresh_payload());
    }

    public function ajax_refresh_tables(): void {
        $this->require_admin_ajax();
        wp_send_json_success($this->get_refresh_payload());
    }

    /* ============================================================
     *  Licença + Pro features (v1.3.2)
     *  ============================================================ */

    public function ajax_activate_license(): void {
        $this->require_admin_ajax();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Acesso negado.'], 403);
        }
        $key = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $license = new MLLA_LicenseManager();
        $result = $license->activate($key, $email);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'state' => $result['state'],
            'state_html' => $this->render_license_state($result['state']),
        ]);
    }

    public function ajax_start_trial(): void {
        $this->require_admin_ajax();
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $license = new MLLA_LicenseManager();
        $result = $license->start_trial($email);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'state' => $result['state'],
            'state_html' => $this->render_license_state($result['state']),
        ]);
    }

    public function ajax_deactivate_license(): void {
        $this->require_admin_ajax();
        $license = new MLLA_LicenseManager();
        $result = $license->deactivate();
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message']]);
        }
        wp_send_json_success([
            'message' => $result['message'],
            'state' => $result['state'],
            'state_html' => $this->render_license_state($result['state']),
        ]);
    }

    public function ajax_validate_license_now(): void {
        $this->require_admin_ajax();
        $license = new MLLA_LicenseManager();
        $state = $license->validate(true);
        wp_send_json_success([
            'message' => $state['last_message'] ?? 'Validação concluída.',
            'state' => $state,
            'state_html' => $this->render_license_state($state),
        ]);
    }

    /**
     * Exporta os resultados da varredura em CSV.
     * Feature Pro (gate: csv_export). Bloqueia com 402 se não tiver licença ativa.
     */
    public function ajax_export_csv(): void {
        $this->require_admin_ajax();
        if (!mlla_can('csv_export')) {
            wp_send_json_error([
                'message' => 'Recurso Pro. Ative sua licença para exportar.',
                'upgrade_url' => admin_url('admin.php?page=ml-link-auditor&tab=license'),
            ], 402);
        }
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }

        global $wpdb;
        $filter_status = isset($_POST['status_filter']) ? sanitize_key((string) wp_unslash($_POST['status_filter'])) : '';
        $filter_post_type = isset($_POST['post_type_filter']) ? sanitize_key((string) wp_unslash($_POST['post_type_filter'])) : '';
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

        $where = '1=1';
        $params = [];
        if ($filter_status !== '') {
            $where .= ' AND status_code = %s';
            $params[] = $filter_status;
        }
        if ($filter_post_type !== '') {
            $where .= ' AND post_type = %s';
            $params[] = $filter_post_type;
        }
        if ($search !== '') {
            $where .= ' AND (link_url LIKE %s OR post_title LIKE %s OR normalized_url LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $sql = "SELECT id, post_id, post_type, post_status, post_title, link_url, normalized_url, status_code, http_code, suggestion_url, suggestion_label, occurrences, note, first_seen, last_seen, fixed_at
                FROM {$this->table_results} WHERE $where ORDER BY FIELD(status_code, 'broken', 'redirect', 'timeout', 'invalid', 'ok', 'fixed', 'removed'), last_seen DESC LIMIT 5000";
        if (!empty($params)) {
            $results = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        } else {
            $results = $wpdb->get_results($sql, ARRAY_A);
        }

        $filename = 'ml-link-auditor-' . gmdate('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'w');
        // BOM para Excel reconhecer UTF-8 corretamente
        fwrite($out, "\xEF\xBB\xBF");
        // Cabeçalho
        fputcsv($out, [
            'ID', 'Post ID', 'Post Type', 'Post Status', 'Post Title',
            'Link URL', 'Normalized URL', 'Status', 'HTTP Code',
            'Suggestion URL', 'Suggestion Label', 'Occurrences', 'Note',
            'First Seen', 'Last Seen', 'Fixed At',
        ], ';', '"');
        // Linhas
        foreach ((array) $results as $row) {
            fputcsv($out, [
                $row['id'] ?? '',
                $row['post_id'] ?? '',
                $row['post_type'] ?? '',
                $row['post_status'] ?? '',
                $row['post_title'] ?? '',
                $row['link_url'] ?? '',
                $row['normalized_url'] ?? '',
                $row['status_code'] ?? '',
                $row['http_code'] ?? '',
                $row['suggestion_url'] ?? '',
                $row['suggestion_label'] ?? '',
                $row['occurrences'] ?? 0,
                $row['note'] ?? '',
                $row['first_seen'] ?? '',
                $row['last_seen'] ?? '',
                $row['fixed_at'] ?? '',
            ], ';', '"');
        }
        fclose($out);
        exit;
    }

    public function cron_validate_license(): void {
        $license = new MLLA_LicenseManager();
        $license->validate(true);
    }

    /**
     * AJAX para o admin forçar checagem de update agora.
     */
    public function ajax_check_update(): void {
        $this->require_admin_ajax();
        if (!$this->updater) {
            wp_send_json_error(['message' => 'Updater não instanciado.']);
        }
        $result = $this->updater->force_check();
        $current = $this->updater->get_current_version();
        $remote = $this->updater->get_remote_version_cached();
        wp_send_json_success([
            'message' => sprintf(
                'Versão local: %s · Versão remota: %s · Atualize a página de Plugins para ver.',
                $current ?: 'desconhecida',
                $remote ?: 'desconhecida'
            ),
            'current' => $current,
            'remote' => $remote,
        ]);
    }

    /**
     * Notice no admin quando há update disponível.
     */
    public function maybe_show_update_notice(): void {
        if (!$this->updater || !current_user_can('manage_options')) {
            return;
        }
        $remote = $this->updater->get_remote_version_cached();
        $current = $this->updater->get_current_version();
        if (!$remote || version_compare($current, $remote, '>=')) {
            return;
        }
        $update_url = wp_nonce_url(
            self_admin_url('update.php?action=upgrade-plugin&plugin=' . rawurlencode(plugin_basename(__FILE__))),
            'upgrade-plugin_' . plugin_basename(__FILE__)
        );
        echo '<div class="notice notice-info is-dismissible"><p>';
        echo '<strong>ML Link Auditor:</strong> nova versão <code>' . esc_html($remote) . '</code> disponível. ';
        echo '<a href="' . esc_url($update_url) . '">Atualizar agora</a>';
        echo '</p></div>';
    }

    /**
     * Renderiza o HTML do estado de licença para uso via AJAX.
     */
    private function render_license_state(array $state): string {
        $status = (string) ($state['status'] ?? 'inactive');
        $plan = strtoupper((string) ($state['plan'] ?? 'FREE'));
        $is_premium = !empty($state['premium']);
        $days_left = $state['days_left'] ?? null;
        $expires_at = $state['expires_at'] ?? '';
        $last_check = $state['last_check'] ?? '';
        $last_message = $state['last_message'] ?? '';

        $status_badges = [
            'active' => 'mlla-badge-success',
            'lifetime' => 'mlla-badge-success',
            'trial_active' => 'mlla-badge-info',
            'free' => 'mlla-badge-muted',
            'expired' => 'mlla-badge-danger',
            'trial_expired' => 'mlla-badge-warning',
            'hub_unreachable' => 'mlla-badge-warning',
        ];
        $cls = $status_badges[$status] ?? 'mlla-badge-muted';

        ob_start();
        ?>
        <ul class="mlla-list mlla-license-state">
            <li><strong>Status:</strong> <span class="mlla-badge <?php echo esc_attr($cls); ?>"><?php echo esc_html(ucfirst(str_replace('_', ' ', $status))); ?></span></li>
            <li><strong>Plano:</strong> <?php echo esc_html($plan); ?></li>
            <?php if ($expires_at !== ''): ?>
                <li><strong>Expira em:</strong> <?php echo esc_html($expires_at); ?>
                    <?php if ($days_left !== null): ?>
                        (<?php echo (int) $days_left; ?> dias)
                    <?php endif; ?>
                </li>
            <?php elseif ($status === 'lifetime'): ?>
                <li><strong>Expira em:</strong> Vitalício</li>
            <?php endif; ?>
            <li><strong>Última verificação:</strong> <?php echo esc_html($last_check ?: 'Nunca'); ?></li>
            <?php if ($last_message !== ''): ?>
                <li><strong>Mensagem:</strong> <?php echo esc_html($last_message); ?></li>
            <?php endif; ?>
        </ul>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renderiza o botão "Exportar CSV" com gate de capability.
     * Se o usuário não tem csv_export, mostra botão desabilitado com badge PRO
     * e tooltip apontando pra aba Licença.
     */
    private function render_export_csv_button(): void {
        $has_cap = mlla_can('csv_export');
        $disabled = $has_cap ? '' : 'disabled aria-disabled="true"';
        $title = $has_cap
            ? esc_attr($this->t('Exportar resultados em CSV', 'Export results as CSV', 'Exportar resultados en CSV'))
            : esc_attr($this->t('Recurso Pro. Ative sua licença para exportar.', 'Pro feature. Activate your license to export.', 'Función Pro. Activa tu licencia para exportar.'));
        ?>
        <div class="mlla-export-csv-wrap" data-pro-cap="csv_export">
            <button type="button"
                    id="mlla-export-csv"
                    class="button"
                    <?php echo $disabled; ?>
                    data-has-cap="<?php echo $has_cap ? '1' : '0'; ?>"
                    title="<?php echo $title; ?>">
                <?php echo esc_html($this->t('Exportar CSV', 'Export CSV', 'Exportar CSV')); ?>
                <?php if (!$has_cap): ?><span class="mlla-pro-badge">PRO</span><?php endif; ?>
            </button>
            <?php if (!$has_cap): ?>
                <small class="mlla-pro-hint">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ml-link-auditor&tab=license')); ?>">
                        <?php echo esc_html($this->t('Ativar licença', 'Activate license', 'Activar licencia')); ?>
                    </a>
                </small>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renderiza o catálogo de features com check/x baseado em can($cap).
     */
    private function render_features_catalog(): void {
        $license = mlla_license();
        $catalog = $license->get_capabilities_catalog();
        $lang = function_exists('determine_locale') ? (string) determine_locale() : (string) get_locale();
        $lang = strtolower($lang);
        if (strpos($lang, 'pt') === 0) $lang = 'pt';
        elseif (strpos($lang, 'es') === 0) $lang = 'es';
        else $lang = 'en';
        ?>
        <ul class="mlla-list mlla-features-list">
            <?php foreach ($catalog as $cap => $labels):
                $has = mlla_can($cap);
                $label = $labels[$lang] ?? $labels['en'];
                $is_pro = !in_array($cap, ['scan', 'quarantine', 'manual_actions', 'basic_suggestions'], true);
                ?>
                <li class="mlla-feature-row <?php echo $has ? 'is-active' : 'is-locked'; ?>">
                    <span class="mlla-feature-icon" aria-hidden="true"><?php echo $has ? '✓' : '✗'; ?></span>
                    <span class="mlla-feature-label"><?php echo esc_html($label); ?></span>
                    <?php if ($is_pro && !$has): ?>
                        <span class="mlla-pro-badge">PRO</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    private function get_refresh_payload(): array {
        global $wpdb;
        $results = $wpdb->get_results("SELECT * FROM {$this->table_results} ORDER BY FIELD(status_code, 'broken', 'redirect', 'timeout', 'invalid', 'ok'), last_seen DESC LIMIT 200");
        $quarantine = $wpdb->get_results("SELECT * FROM {$this->table_quarantine} WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 100");
        return [
            'job' => $this->get_status_payload(),
            'results_html' => $this->render_results_table($results),
            'quarantine_html' => $this->render_quarantine_table($quarantine),
            'summary' => $this->get_dashboard_stats(),
            // Antes: count_drafts_posts() contava TODOS os drafts (incluindo os do usuário)
            // Agora: conta apenas drafts gerenciados pelo plugin.
            'restore_drafts_count' => $this->count_plugin_drafts(),
        ];
    }

    private function get_result_record(int $id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_results} WHERE id = %d", $id));
    }

    private function render_results_table(array $results): string {
        ob_start();
        ?>
        <div class="mlla-table-wrap">
        <table class="mlla-table">
            <thead>
            <tr>
                <th>ID</th>
                <th><?php echo esc_html($this->t('Post', 'Post', 'Post')); ?></th>
                <th><?php echo esc_html($this->t('Status', 'Status', 'Estado')); ?></th>
                <th><?php echo esc_html($this->t('Link', 'Link', 'Enlace')); ?></th>
                <th><?php echo esc_html($this->t('Sugestão', 'Suggestion', 'Sugerencia')); ?></th>
                <th><?php echo esc_html($this->t('Ações', 'Actions', 'Acciones')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($results)): ?>
                <tr><td colspan="6"><?php echo esc_html($this->t('Nenhum resultado ainda.', 'No results yet.', 'Sin resultados todavía.')); ?></td></tr>
            <?php else: ?>
                <?php foreach ($results as $row): ?>
                    <tr>
                        <td><?php echo (int) $row->id; ?></td>
                        <td>
                            <strong>#<?php echo (int) $row->post_id; ?> - <?php echo esc_html((string) $row->post_title); ?></strong><br>
                            <small><?php echo esc_html((string) $row->post_type . ' / ' . (string) $row->post_status); ?></small>
                        </td>
                        <td><span class="mlla-badge mlla-status-<?php echo esc_attr((string) $row->status_code); ?>"><?php echo esc_html((string) $row->status_code); ?></span><?php if ($row->http_code): ?><br><small>HTTP <?php echo (int) $row->http_code; ?></small><?php endif; ?></td>
                        <td>
                            <code><?php echo esc_html((string) $row->link_url); ?></code>
                            <?php if (!empty($row->note)): ?><br><small><?php echo esc_html((string) $row->note); ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row->suggestion_url)): ?>
                                <code><?php echo esc_html((string) $row->suggestion_url); ?></code>
                                <?php if (!empty($row->suggestion_label)): ?><br><small><?php echo esc_html((string) $row->suggestion_label); ?></small><?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <div class="mlla-row-actions">
                                <?php if (!empty($row->suggestion_url) && !in_array((string) $row->status_code, ['fixed', 'removed'], true)): ?><button class="button button-small mlla-apply-suggestion" data-result-id="<?php echo (int) $row->id; ?>"><?php echo esc_html($this->t('Aplicar sugestão', 'Apply suggestion', 'Aplicar sugerencia')); ?></button><?php endif; ?>
                                <button class="button button-small mlla-manual-replace" data-result-id="<?php echo (int) $row->id; ?>"><?php echo esc_html($this->t('Troca manual', 'Manual replace', 'Reemplazo manual')); ?></button>
                                <button class="button button-small mlla-btn-danger mlla-remove-link" data-result-id="<?php echo (int) $row->id; ?>"><?php echo esc_html($this->t('Remover link', 'Remove link', 'Eliminar enlace')); ?></button>
                                <button class="button button-small mlla-btn-danger mlla-draft-post" data-post-id="<?php echo (int) $row->post_id; ?>"><?php echo esc_html($this->t('Rascunho', 'Draft', 'Borrador')); ?></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_quarantine_table(array $rows): string {
        ob_start();
        ?>
        <div class="mlla-table-wrap">
        <table class="mlla-table">
            <thead>
            <tr>
                <th>ID</th>
                <th><?php echo esc_html($this->t('Post', 'Post', 'Post')); ?></th>
                <th><?php echo esc_html($this->t('Motivo', 'Reason', 'Motivo')); ?></th>
                <th><?php echo esc_html($this->t('Data', 'Date', 'Fecha')); ?></th>
                <th><?php echo esc_html($this->t('Ações', 'Actions', 'Acciones')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="5"><?php echo esc_html($this->t('Nenhuma cópia em quarentena.', 'No quarantine copies.', 'No hay copias en cuarentena.')); ?></td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo (int) $row->id; ?></td>
                        <td>#<?php echo (int) $row->post_id; ?></td>
                        <td><?php echo esc_html((string) $row->reason); ?></td>
                        <td><?php echo esc_html((string) $row->created_at); ?></td>
                        <td>
                            <button class="button button-small mlla-restore-quarantine" data-quarantine-id="<?php echo (int) $row->id; ?>"><?php echo esc_html($this->t('Restaurar', 'Restore', 'Restaurar')); ?></button>
                            <button class="button button-small mlla-btn-danger mlla-delete-quarantine" data-quarantine-id="<?php echo (int) $row->id; ?>"><?php echo esc_html($this->t('Limpar', 'Clean', 'Limpiar')); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

} // end if (!class_exists('ML_Link_Auditor'))

if (class_exists('ML_Link_Auditor', false)) {
    new ML_Link_Auditor();
}
