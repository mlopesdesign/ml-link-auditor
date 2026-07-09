# ML Link Auditor

Plugin WordPress comercial da **MLopes Design** para auditoria de links em posts e páginas.

## Recursos

- Varredura AJAX em lotes com pausa, retomada, parada e reset.
- Detecção de links quebrados, redirecionados, com timeout ou inválidos (internos e externos).
- Sugestão automática de correção para URLs internas legadas e arquivos em `wp-content/uploads`.
- Aplicação de sugestão, troca manual de URL e remoção de link preservando o conteúdo interno.
- Quarentena: cópia do conteúdo original antes de qualquer ação destrutiva, com restauração individual.
- Movimentação de posts problemáticos para rascunho e restauração de rascunhos em massa.
- Painel administrativo no padrão visual MLopes Design (hero, abas, cards, badges e tabelas premium).
- **Integração com o ML License Hub** para ativação de licença e recursos Pro.
- **Auto-update via Hub** — o plugin consulta `license.mlopesdesign.com.br` por novas versões.
- **Trial automático de 30 dias** com todos os recursos Pro habilitados.

## Recursos Pro (licença ativa)

- Exportar resultados em CSV (com filtros).
- Exportar relatórios em PDF.
- Varreduras agendadas (diárias/semanais).
- Notificações por e-mail.
- Ações em massa.
- Mapeamento de paths de migração (Joomla, Drupal, etc).
- Suporte multi-site, white-label, API REST, webhooks.

## Requisitos

- WordPress 6.0+
- PHP 7.4+

## Instalação

1. Baixe o ZIP `ml-link-auditor-vX.Y.Z.zip` da release.
2. No WordPress: Plugins → Adicionar novo → Enviar plugin → selecione o ZIP → Instalar → Ativar.
3. Acesse o menu **ML Link Auditor** e clique em **Iniciar varredura**.

## Estrutura

```
ml-link-auditor/
├── ml-link-auditor.php   # Núcleo: menu, AJAX, varredura, quarentena, settings
├── uninstall.php         # Limpeza de tabelas e options na desinstalação
├── readme.txt            # Readme padrão WordPress.org
└── assets/
    ├── admin.css         # UI padrão MLopes Design
    ├── admin.js          # Abas, varredura em lote, ações de linha e toasts
    └── images/logo-mlopesdesign.png
```

## Segurança

- `manage_options` em todas as ações administrativas.
- Nonce em formulários (`check_admin_referer`) e AJAX (`check_ajax_referer`).
- Entrada sanitizada (`sanitize_*`, `absint`, `esc_url_raw`) e saída escapada (`esc_html`, `esc_attr`, `esc_url`).
- SQL com `$wpdb->prepare()`.
- Desinstalação remove apenas tabelas e options do próprio plugin.

## Licença

GPLv2 ou posterior. © MLopes Design — https://mlopesdesign.com.br
