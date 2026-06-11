=== ML Link Auditor ===
Contributors: mlopesdesign
Tags: broken links, audit, ajax, quarantine, links
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audita links quebrados em posts e páginas com processamento AJAX em lote, sugestão de correção, quarentena e ação para rascunho.

== Description ==

O ML Link Auditor varre o conteúdo publicado do site procurando links internos e externos com problemas (quebrados, redirecionados, timeout ou inválidos), com:

* Varredura AJAX em lotes, com pausa, retomada, parada e reset.
* Sugestão automática de correção para URLs internas legadas e arquivos de uploads.
* Substituição de link com um clique (sugestão ou troca manual).
* Remoção segura de link preservando o conteúdo interno.
* Quarentena: toda ação destrutiva salva uma cópia do conteúdo original antes da alteração.
* Movimentação de posts problemáticos para rascunho e restauração em massa.
* Painel administrativo no padrão visual MLopes Design.

== Installation ==

1. Envie o ZIP em Plugins > Adicionar novo > Enviar plugin.
2. Ative o ML Link Auditor.
3. Acesse o menu "ML Link Auditor" no admin e inicie a varredura.

== Changelog ==

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
