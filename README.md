# GLPI Microsoft Teams Integration

Plugin para integrar o GLPI 11+ ao Microsoft Teams, mantendo o GLPI como
sistema oficial de registro dos chamados.

## Estado atual

Esta entrega implementa as etapas iniciais de skeleton, autenticação e
notificações GLPI → Teams:

- ciclo de instalação e desinstalação;
- tabelas próprias para rotas, vínculos, eventos, outbox e logs;
- configuração administrativa baseada no armazenamento oficial de configuração
  do GLPI;
- registro de segredos para criptografia pelo GLPIKey;
- serviço-base de logging com `request_id` e redação de segredos;
- serviço-base de outbox e tarefa cron compatível com o ciclo do GLPI.
- fluxo inicial de vínculo GLPI OAuth 2.0 com `state` descartável;
- armazenamento cifrado dos tokens OAuth por usuário;
- callback protegido para associar a identidade Teams ao usuário GLPI autenticado.
- hooks oficiais `item_add`/`item_update` para chamados e acompanhamentos;
- outbox persistente com idempotência, cron, retry limitado e backoff;
- cliente Bot Connector para mensagens proativas e Adaptive Cards.

O primeiro fluxo Teams → GLPI também está implementado: endpoint de atividade do
Bot Framework, validação JWT com OpenID/JWKS, idempotência por atividade, vínculo
OAuth iniciado pelo comando `vincular` e comandos textuais para criação, consulta,
acompanhamento e alteração de status. A listagem filtra os tickets retornados pela
API de alto nível do GLPI cujo `team` identifica o usuário como solicitante.

Adaptive Cards para ações interativas, captura administrativa de múltiplas rotas,
Graph para provisionamento do aplicativo e testes de integração reais ainda
permanecem nas etapas seguintes. A tela administrativa já oferece teste de
conexão, envio de mensagem de teste, contagem do outbox e token CSRF compatível
com a validação central do GLPI 11. O envio atual continua usando `serviceUrl` e
`conversationId` já conhecidos na configuração.

As notificações para o canal/conversa padrão agora também incluem o nome e o
e-mail do requerente do chamado. Essa primeira etapa não exige permissões extras
no tenant nem tenta enviar mensagens privadas; o vínculo por e-mail e o envio
individual podem ser adicionados em uma etapa posterior.

O guia de instalação definitivo será consolidado nesta documentação somente na
etapa final do projeto.

## Requisitos

- GLPI 11.0 ou superior;
- PHP 8.2 ou superior, conforme a linha GLPI 11;
- MySQL/MariaDB suportado pelo GLPI.

## Instalação durante o desenvolvimento

1. Copie a pasta `glpimsteams` para o diretório `plugins/` do GLPI.
2. No GLPI, abra **Configuração > Plugins**.
3. Instale e ative **GLPI Microsoft Teams Integration**.
4. Abra a página de configuração do plugin.

O uninstall remove apenas as tabelas e configurações pertencentes ao plugin.

## Licença

GPL-3.0-or-later.
