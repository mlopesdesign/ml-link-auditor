<?php
/**
 * Uninstall — ML Link Auditor.
 *
 * Remove resultados de varredura (regeneraveis), opcoes, caches e agendamentos.
 * PRESERVA a tabela de quarentena ({prefix}mlla_quarantine): ela guarda copias de conteudo
 * salvas antes de alteracoes, e apaga-las pode significar perda de dados do site.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}mlla_results");
delete_option('mlla_settings');
delete_option('mlla_scan_job');
delete_option('mlla_license_state');
delete_option('mlla_updater_secret');
delete_transient('mlla_update_check');
delete_transient('mlla_license_cache');
wp_clear_scheduled_hook('mlla_check_for_update');
wp_clear_scheduled_hook('mlla_daily_validate');
