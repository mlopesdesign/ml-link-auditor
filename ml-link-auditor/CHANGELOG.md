# Changelog — ML Link Auditor

## 1.3.2 — 2026-06-30

### ✨ Adicionado
- **URL de info separada do Hub**: novo campo `info_url` no estado da licença. Permite apontar a checagem de versão para qualquer URL (ex: GitHub Releases) sem mexer no Hub de licenciamento.

### 🐛 Bugs corrigidos
- **Auto-update não disparava quando o hub_url não terminava em `/api/license.php`**: o Updater derivava o caminho do info.json a partir do hub_url via regex `~/api/[^/]*$~`. Agora a URL de info é uma propriedade separada, configurável via `LicenseManager::set_info_url()` ou pelo próprio `info_url` salvo no estado. Se vazia, usa o fallback do Hub.

## 1.3.1 — 2026-06-30

### 🐛 Bugs corrigidos
- **Botão "Aplicar sugestão" retornava 404**: o handler JS `.mlla-apply-suggestion` não estava enviando o `result_id` no payload da requisição AJAX, então o PHP recebia `result_id=0` e retornava "Sugestão não encontrada". Agora o ID é incluído corretamente via parâmetro `extraData` do `simpleAction`.
- **`simpleAction` não aceitava payload extra**: a função foi atualizada para receber um quinto parâmetro `extraData` que é mesclado na requisição POST. Isso garante consistência entre todos os handlers de ação.

## 1.3.0 — 2026-06-30

### ✨ Adicionado
- Integração completa com ML License Hub em `license.mlopesdesign.com.br`.
- Nova classe `MLLA_LicenseManager`: cache 12h, grace period 72h, feature_set server-authoritative.
- Helpers globais `mlla_can()` e `mlla_license()` para gates de capability em 1 linha.
- Aba Licença funcional: formulário de ativação, trial, desativação e re-validação manual.
- Cron diário de validação (`mlla_daily_validate`) via WP-Cron.
- **Primeiro recurso Pro**: Exportar CSV na aba Resultados (com filtros).
- **Trial de 30 dias** com todos os recursos Pro liberados automaticamente.
- Catálogo de 18 capabilities com tradução PT/EN/ES.
- Badges PRO visuais para features pagas bloqueadas.
- **Motor de auto-update integrado** (`MLLA_Updater`): checa versões via `info.json` no Hub, injeta updates no WP, valida licença via token HMAC antes do download, suporta updates Pro-only.
- Botão "Verificar atualizações" na aba Licença + notice automático no admin quando há update disponível.
- Hooks: `pre_set_site_transient_update_plugins`, `plugins_api`, `upgrader_pre_download` — padrão WordPress completo.

### 🐛 Bugs corrigidos (Fase 1 — incluídos neste minor)
- `ajax_restore_drafts_batch` agora restaura apenas posts gerenciados pelo plugin (com snapshot em quarentena), nunca os drafts do usuário. **Risco de publicação indevida eliminado.**
- Substituições de link e remoção de âncora toleram `&amp;` no HTML original, eliminando falhas silenciosas em URLs com query string.
- Race condition corrigida no motor: o loop usa uma única fonte de verdade (`$control`) que é refetched em cada iteração, garantindo que pause/stop_requested sejam respeitados imediatamente.
- `load_plugin_textdomain` agora é chamado (a i18n estava inerte). Hook de desativação limpa o estado de job em andamento.
- `occurrences` agora é incrementado em re-detecções (antes era sempre resetado para 1).
- "Restaurar rascunhos (N)" agora usa contagem do plugin, não drafts arbitrários.
- Limitação de tamanho de resposta preservada no fallback de GET (antes permitia download ilimitado).
- Sugestão de URL para redirects agora inclui nota quando o destino do redirecionamento também está quebrado.
- Adicionada coluna `link_url_raw` na tabela de resultados para preservar a forma original da URL no HTML.

### 🐛 Bugs corrigidos (neste minor)
- **Cannot redeclare fatal**: classes `MLLA_LicenseManager`, `ML_Link_Auditor` e funções `mlla_can()`, `mlla_license()` agora são declaradas apenas se não existirem. Evita conflito fatal em sites com outros plugins ML_*.
- **wp_schedule_event cedo demais**: jobs cron movidos do construtor para o hook `init` (mais seguro, evita warning em alguns ambientes WP).
- **Activation hook sem tratamento**: agora usa try/catch com `error_log` para facilitar diagnóstico.

### 🏗️ Arquitetura
- LicenseManager é agnóstico da URL do hub — quando `tools.mlopesdesign.com.br` assumir, basta trocar 1 constante.
- feature_set server-authoritative com fallback em 3 níveis: array, 'full'/binário, mapa local por plano.
- Capability gate isolado em uma única função (`mlla_can()`) para reuso em qualquer ponto do código.
- Updater separado do LicenseManager — pode ser desativado via filtro `mlla_disable_updater` se você usar outro sistema (EDD, etc).

### Compatibilidade
- Banco de dados compatível com v1.2.1 — mesma estrutura, mesma migration.
- Nenhum hook público removido.

## 1.2.1 — 2026-06-28

### ✨ Adicionado
- Correções da Fase 1 (8 bugs): restore_drafts_batch seguro, encoding de URL, race condition do motor, i18n, etc.

## 1.2.0 — 2026-06-11

### Adicionado
- Painel administrativo completo no padrão visual MLopes Design: hero com logo e marca, navegação por abas (Dashboard, Resultados, Quarentena, Configurações, Licença), cards premium, badges de status, tabelas profissionais com wrapper responsivo e botões primário/secundário/perigo.
- Aba de Licença preparada (plano, status, serial, e-mail, última verificação) para futura validação remota.
- `README.md`, `CHANGELOG.md`, `.gitignore` e `LICENSE` no repositório.

### Alterado
- `assets/admin.css` reescrito do zero: variáveis de tema, sem regras duplicadas, responsivo no WordPress Admin.
- Tabelas de resultados e quarentena com novo markup premium (mesmos IDs e classes funcionais preservados).
- Redirecionamento pós-salvamento de configurações retorna à aba Configurações.

### Corrigido
- `Stable tag` do readme.txt desatualizado (1.0.0) sincronizado com a versão real.
- Exclusão de quarentena aceitava ID inválido (0); agora retorna erro 400.
- Blocos de toast duplicados no CSS antigo removidos.
- Arquivo residual `test.txt` removido do pacote.

### Preservado
- Todo o motor 1.1.3: varredura em lote com run_id/lock, pausa/retomada/parada/reset, sugestões, troca manual, remoção de link, quarentena, rascunho automático e restauração em massa de rascunhos.
- Slug `ml-link-auditor`, arquivo principal `ml-link-auditor.php`, tabelas, options, ações AJAX e hooks.

## 1.1.3
- Motor de varredura em lote com run_id, lock e controle de pausa/parada; restauração de rascunhos em massa.

## 1.0.0
- Versão inicial.