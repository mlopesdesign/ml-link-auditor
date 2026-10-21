<?php
/**
 * Licença do ML Link Auditor.
 *
 * 1.4.0: a licença passa a ser emitida e conferida pelo site tools.mlopesdesign.com.br
 * (módulo ML License Hub, cliente ML_License_Client_V2). O servidor antigo
 * (license.mlopesdesign.com.br/api/license.php) não é mais consultado. Regras do padrão ML:
 * 1 licença = 1 site (o Pacote Agência vale em vários sites); teste grátis de 30 dias
 * por site; sem teste/licença válidos, só o painel do plugin fica travado para gravar
 * (varrer, corrigir links, mover para rascunho, restaurar/limpar quarentena, salvar
 * configurações). Nada no site é desfeito e nenhum dado é apagado: resultados,
 * quarentena, posts já corrigidos e configurações continuam como estão.
 *
 * Quem já usava o plugin antes da 1.4.0 recebe o teste grátis automaticamente na
 * primeira visita ao painel (o painel não trava de surpresa após a atualização).
 *
 * A option antiga (mlla_license_state) é preservada no banco (não é mais usada).
 *
 * @package ML_Link_Auditor
 */

if (!defined('ABSPATH')) {
    exit;
}

// O cliente de licença é carregado ANTES da guarda abaixo: o PHP declara a classe deste
// arquivo já na compilação, então class_exists() é sempre verdadeiro aqui e o return
// impediria o carregamento do cliente (erro fatal quando nenhum outro plugin ML o carregou).
require_once __DIR__ . '/class-ml-license-client.php';

if (class_exists('MLLA_LicenseManager', false)) {
    return;
}


final class MLLA_LicenseManager {
    const API_BASE    = 'https://tools.mlopesdesign.com.br/wp-json/mllh/v1';
    const PUBLIC_KEY  = 'VGZQp2X3RJMuDmQBlIEXS4fqsZD1CnFlIJgfVtY/pHo=';
    const PRODUCT_ID  = 'ml-link-auditor';
    const BUY_URL     = 'https://tools.mlopesdesign.com.br/precos/';
    const ACCOUNT_URL = 'https://tools.mlopesdesign.com.br/minha-conta/';
    const AUTO_TRIAL  = 'mlla_auto_trial_tried';
    const MENU_SLUG   = 'ml-link-auditor';

    /** Recursos que sempre funcionaram sem licença (o critério de "Pro" agora é o teste/licença). */
    const FREE_CAPS = array('scan', 'quarantine', 'manual_actions', 'basic_suggestions');

    /** @var ML_License_Client_V2|null */
    private static $client = null;

    /** @var bool|null Resultado guardado durante a requisição (zera quando a licença muda). */
    private static $locked = null;

    public static function client() {
        if (null === self::$client) {
            self::$client = new ML_License_Client_V2(array(
                'api_base'   => self::API_BASE,
                'product'    => self::PRODUCT_ID,
                'public_key' => self::PUBLIC_KEY,
                'version'    => defined('MLLA_VERSION') ? MLLA_VERSION : '',
            ));
        }
        return self::$client;
    }

    /** Teste ou licença válidos neste site. */
    public static function is_premium() {
        return self::client()->is_premium();
    }

    /** Painel travado para gravar (sem teste nem licença). */
    public static function is_locked() {
        if (null === self::$locked) {
            self::$locked = !self::is_premium();
        }
        return self::$locked;
    }

    public static function forget() {
        self::$locked = null;
    }

    /** Recurso liberado? Os recursos básicos de sempre; os "Pro" dependem do teste/licença. */
    public static function can($capability) {
        if (in_array((string) $capability, self::FREE_CAPS, true)) {
            return true;
        }
        return self::is_premium();
    }

    /* ------------------------------------------------------------------ ganchos */

    public static function boot() {
        self::client(); // agenda a renovação diária
        add_action('admin_init', array(__CLASS__, 'maybe_auto_trial'), 5);
        add_action('admin_post_mlla_license_activate', array(__CLASS__, 'handle_activate_request'));
        add_action('admin_post_mlla_license_sync', array(__CLASS__, 'handle_sync_request'));
        add_action('admin_post_mlla_license_remove', array(__CLASS__, 'handle_remove_request'));
        add_action('init', array(__CLASS__, 'drop_old_cron'));
        $opt = self::client()->option_name();
        foreach (array('added_option', 'updated_option', 'deleted_option') as $hook) {
            add_action($hook, function ($name) use ($opt) {
                if ($name === $opt) {
                    MLLA_LicenseManager::forget();
                }
            });
        }
    }

    /** A validação diária antiga chamava o servidor antigo: desagenda (uma vez). */
    public static function drop_old_cron() {
        if (wp_next_scheduled('mlla_daily_validate')) {
            wp_clear_scheduled_hook('mlla_daily_validate');
        }
    }

    /** Primeira visita ao painel: começa o teste grátis sozinho (uma tentativa por site). */
    public static function maybe_auto_trial() {
        if (wp_doing_ajax() || !current_user_can('manage_options') || get_option(self::AUTO_TRIAL)) {
            return;
        }
        update_option(self::AUTO_TRIAL, time(), false);
        $state = self::client()->state();
        if (empty($state['active']) && 'none' === $state['mode'] && empty($state['trial_used'])) {
            self::client()->start_trial();
        }
    }

    public static function locked_message() {
        return 'Ative a licença (ou comece o teste grátis) para fazer varreduras e alterar links, quarentena ou configurações do ML Link Auditor. O que já foi feito no site continua como está e nada é apagado.';
    }

    /**
     * Para ações de gravação do painel (AJAX de varredura/correção/quarentena e salvar
     * configurações). Sem teste/licença, responde com a mensagem e não grava nada.
     */
    public static function guard_write($ajax = false) {
        if (!self::is_locked()) {
            return;
        }
        if ($ajax) {
            // Status 200 para o painel mostrar a mensagem no aviso (o JS lê data.message).
            wp_send_json_error(array('message' => self::locked_message(), 'code' => 'mlla_locked', 'upgrade_url' => self::license_url()));
        }
        self::redirect_with_notice('error', self::locked_message());
    }

    public static function license_url() {
        return add_query_arg(array('page' => self::MENU_SLUG, 'tab' => 'license'), admin_url('admin.php'));
    }

    /* ------------------------------------------------------------------ tela */

    /** Estado pronto para a tela. */
    public static function get_license_summary() {
        $state  = self::client()->state();
        $active = !empty($state['active']);
        $mode   = (string) ($state['mode'] ?? 'none');
        $plan   = strtolower((string) ($state['plan'] ?? ''));
        $days   = $state['days_left'] ?? null;

        $plans = array(
            'trial'    => 'Teste grátis',
            'monthly'  => 'Mensal',
            'annual'   => 'Anual',
            'yearly'   => 'Anual',
            'lifetime' => 'Vitalícia',
            'manual'   => 'Cortesia',
            'courtesy' => 'Cortesia',
        );
        $plan_label = $plans[$plan] ?? ('' !== $plan ? ucfirst($plan) : '—');

        if ($active && 'trial' === $mode) {
            $label   = 'Teste';
            $tone    = 'trial';
            $message = null === $days ? 'Teste grátis ativo neste site.' : sprintf(1 === (int) $days ? 'Teste grátis ativo: falta %d dia.' : 'Teste grátis ativo: faltam %d dias.', (int) $days);
        } elseif ($active) {
            $label   = 'Ativa';
            $tone    = 'full';
            $message = 'Licença ativa neste site.';
        } else {
            $label = 'Sem licença';
            $tone  = 'free';
            if ('trial_expired' === ($state['status'] ?? '')) {
                $message = 'O teste grátis terminou. Ative uma licença para voltar a varrer e corrigir links. O que já foi feito no site continua como está.';
            } elseif ('' !== (string) ($state['message'] ?? '')) {
                $message = (string) $state['message'];
            } else {
                $message = 'Comece o teste grátis de 30 dias ou ative a sua licença.';
            }
        }

        $expires_label = '';
        if (!empty($state['expires_at'])) {
            $ts = strtotime((string) $state['expires_at']);
            if ($ts) {
                $expires_label = wp_date(get_option('date_format', 'd/m/Y'), $ts);
            }
        }

        return array(
            'active'          => $active,
            'mode'            => $mode,
            'is_trial'        => $active && 'trial' === $mode,
            'label'           => $label,
            'tone'            => $tone,
            'message'         => $message,
            'plan'            => $plan,
            'plan_label'      => $plan_label,
            'expires_label'   => $expires_label,
            'days_left'       => $days,
            'key_masked'      => (string) ($state['key_masked'] ?? ''),
            'can_start_trial' => !$active && empty($state['trial_used']) && 'license' !== $mode,
            'domain'          => (string) wp_parse_url(home_url(), PHP_URL_HOST),
        );
    }

    /* ------------------------------------------------------------------ ações */

    public static function handle_activate_request() {
        self::guard_admin_request('mlla_license_activate');
        $key    = isset($_POST['license_key']) ? sanitize_text_field(wp_unslash($_POST['license_key'])) : '';
        $result = self::client()->activate($key);
        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }
        self::redirect_with_notice('success', 'Licença ativada neste site.');
    }

    /** Começa o teste grátis ou confere a licença agora. */
    public static function handle_sync_request() {
        self::guard_admin_request('mlla_license_sync');
        $op     = isset($_POST['mlla_op']) ? sanitize_key(wp_unslash($_POST['mlla_op'])) : 'check';
        $result = 'trial' === $op ? self::client()->start_trial() : self::client()->refresh();
        if (is_wp_error($result)) {
            self::redirect_with_notice('error', $result->get_error_message());
        }
        self::redirect_with_notice('success', 'trial' === $op ? 'Teste grátis de 30 dias ativo neste site.' : 'Licença conferida.');
    }

    public static function handle_remove_request() {
        self::guard_admin_request('mlla_license_remove');
        self::client()->deactivate();
        self::redirect_with_notice('success', 'Licença removida deste site. A chave pode ser usada em outro site.');
    }

    private static function guard_admin_request($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('Permissão insuficiente.'));
        }
        check_admin_referer($nonce_action);
    }

    public static function redirect_with_notice($type, $message) {
        $url = add_query_arg(array(
            'page'             => self::MENU_SLUG,
            'tab'              => 'license',
            'mlla_notice_type' => rawurlencode($type),
            'mlla_notice'      => rawurlencode($message),
        ), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
