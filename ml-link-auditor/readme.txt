=== ML Link Auditor ===
Contributors: mlopesdesign
Tags: broken links, audit, ajax, quarantine, links, joomla migration
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audita links quebrados em posts e páginas com processamento AJAX em lote, sugestão de correção, quarentena, ação para rascunho e integração com o hub de licenças MLopes Design para recursos Pro.

== Description ==

O ML Link Auditor varre o conteúdo publicado do site procurando links internos e externos com problemas (quebrados, redirecionados, timeout ou inválidos), com:

* Varredura AJAX em lotes, com pausa, retomada, parada e reset.
* Sugestão automática de correção para URLs internas legadas e arquivos de uploads.
* Substituição de link com um clique (sugestão ou troca manual).
* Remoção segura de link preservando o conteúdo interno.
* Quarentena: toda ação destrutiva salva uma cópia do conteúdo original antes da alteração.
* Movimentação de posts problemáticos para rascunho e restauração em massa.
* Painel administrativo no padrão visual MLopes Design.
* **Integração com o ML License Hub** para ativação de licença e recursos Pro.
* **Trial automático de 30 dias** com todos os recursos Pro habilitados.

== Pro features (requer licença ativa) ==

* Exportar resultados da varredura em CSV.
* Exportar relatórios em PDF.
* Varreduras agendadas (diárias, semanais).
* Notificações por e-mail ao concluir varredura.
* Ações em massa (bulk) sobre os resultados.
* Mapeamento customizado de paths de migração (Joomla, Drupal, etc).
* Suporte multi-site (network).
* White-label (logo e cores).
* API REST para integrações.
* Webhooks (Slack, Discord, Teams).

== Installation ==

1. Envie o ZIP em Plugins > Adicionar novo > Enviar plugin.
2. Ative o ML Link Auditor.
3. Acesse o menu "ML Link Auditor" no admin e inicie a varredura.

== Changelog ==

= 1.3.2 =
* **URL de info separada do Hub**: novo campo info_url permite apontar checagem de versão para qualquer URL (ex: GitHub Releases) sem mexer no Hub.
* Correção: auto-update não disparava quando hub_url não terminava em /api/license.php. Agora o info_url é configurável.
* Atualize a v1.3.2 direto pelo painel (se a v1.3.1 já está ativa) ou instale manualmente.

= 1.3.1 =
* Correção: botão "Aplicar sugestão" não enviava result_id, retornava 404. Agora envia corretamente via parâmetro extraData.
* Correção: simpleAction() agora aceita payload extra via quinto parâmetro.

= 1.3.0 =
* **Lançamento Pro**: integração completa com o ML License Hub em license.mlopesdesign.com.br
* **Motor de auto-update**: o plugin consulta o Hub por novas versões, valida licença via token HMAC e atualiza com 1 clique no Painel do WordPress.
* Nova classe `MLLA_LicenseManager` com cache local de 12h, grace period de 72h e feature_set server-authoritative
* Helpers globais `mlla_can($cap)` e `mlla_license()` para verificação rápida de capabilities
* Nova aba Licença funcional: ativar chave, iniciar trial, desativar, re-validar
* Cron diário de validação via WP-Cron (`mlla_daily_validate`)
* **Primeiro recurso Pro: Exportar CSV** com filtros (status, post_type, search). Botão na aba Resultados com badge PRO para usuários Free
* Listagem visual de capabilities (✓ ou ✗) com badges PRO para features pagas
* Catálogo de capabilities em PT/EN/ES com 18 features catalogadas (trial/full/lifetime/agency)
* Compatibilidade futura: o LicenseManager é agnóstico de URL do hub, então quando tools.mlopesdesign.com.br assumir, basta trocar a URL
* Inclui as correções da 1.2.1 (restore_drafts_batch seguro, encoding, race condition do motor, i18n, etc.)
* Classes e funções globais blindadas com class_exists/function_exists para evitar "Cannot redeclare" em sites com outros plugins ML
* Cron jobs (validação de licença e checagem de update) movidos do construtor para o hook 'init' (mais seguro)
* Activation hook agora usa try/catch com error_log para facilitar diagnóstico

= 1.2.1 =
* Correção crítica: "Restaurar rascunhos" agora restaura apenas posts gerenciados pelo plugin (com snapshot em quarentena), nunca os drafts do usuário.
* Correção de encoding: substituições de link e remoção de âncora toleram `&amp;` no HTML original, eliminando falhas silenciosas em URLs com query string.
* Race condition corrigida no motor: o loop usa uma única fonte de verdade ($control) que é refetched em cada iteração, garantindo que pause/stop_requested sejam respeitados imediatamente.
* load_plugin_textdomain agora é chamado (a i18n estava inerte). Hook de desativação limpa o estado de job em andamento.
* occurrences agora é incrementado em re-detecções (antes era sempre resetado para 1).
* "Restaurar rascunhos (N)" agora usa contagem do plugin, não drafts arbitrários.
* Limitação de tamanho de resposta preservada no fallback de GET (antes permitia download ilimitado).
* Sugestão de URL para redirects agora inclui nota quando o destino do redirecionamento também está quebrado.
* Adicionada coluna link_url_raw na tabela de resultados para preservar a forma original da URL no HTML.

= 1.2.0 =
* Novo painel administrativo no padrão visual MLopes Design: hero com marca, navegação por abas, cards premium, badges, tabelas profissionais e botões primário/secundário/perigo.
* Abas dedicadas: Dashboard, Resultados, Quarentena, Configurações e Licença.
* CSS reescrito, limpo e sem duplicidade, com variáveis de tema e responsividade no WordPress Admin.
* Redirecionamento pós-salvamento retorna à aba Configurações.
* Validação de ID na exclusão de itens da quarentena.
* Sincronização de versão entre cabeçalho, constante e readme (Stable tag estava desatualizado).
* Remoção de arquivo de teste residual do pacote.

= 1.1.3 =
* Motor de varredura em lote com run_id, lock e controle de pausa/parada.
* Restauração de rascunhos em massa.

= 1.0.0 =
* Versão inicial.
