# .harness/reins/

Configuração de agentes para automações locais. Reservado para uso futuro
(agent definitions, hooks, rules). Por enquanto vazio — qualquer automação
deve usar os scripts em `scripts/` na raiz do projeto.

## Onde fica o quê

- `AGENTS.md` (raiz): regras permanentes para qualquer agente que mexer no projeto.
- `scripts/`: pipelines PowerShell/bash para backup, package, sync-version e release.
- `.github/workflows/`: CI/CD no GitHub Actions (cria release no push de tag `v*`).

## Padrão seguido

Esta estrutura replica o padrão estabelecido em `ml-gallery-pro/.harness/reins/`.
Mantenha simétrico ao repositório de referência para que agentes possam
operar os dois projetos com as mesmas convenções.