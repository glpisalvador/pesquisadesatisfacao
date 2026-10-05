# Pesquisa de Satisfação para GLPI

> Autor: **GLPI Salvador** · Licença: **GPLv3** · Compatível com GLPI **11.0.0 a 12.x**

**Pesquisa de satisfação** enviada automaticamente ao requerente quando o chamado é **solucionado**. Ele responde por uma página pública, sem login, e a equipe acompanha os resultados num painel.

## O que o plugin faz

### Ciclo da pesquisa
1. O chamado é **solucionado**: a pesquisa é criada e o convite vai por e-mail.
2. O requerente responde na **página pública**, pelo link com token, sem precisar entrar no GLPI.
3. **Lembretes** saem depois dos dias configurados, e a pesquisa é **encerrada** se não houver resposta.
4. O chamado é **fechado** depois da resposta, ou depois do prazo. Se o chamado for **reaberto**, a pesquisa pendente é descartada.

### Perguntas
- **Configuráveis** pela tela, com ordem ajustável.
- Escala de **3 carinhas**: ruim, regular e bom, com os rótulos editáveis.
- **Justificativa** pedida quando faz sentido, e comentário final opcional.

### Regras opcionais
- **Segurar o fechamento** do chamado até a resposta, ou até o prazo.
- **Bloquear novos chamados** do requerente que tem pesquisas pendentes acima de um limite. Ele é levado para a página das suas pesquisas pendentes.
- **Alerta de avaliação negativa** para o técnico, usuários e e-mails escolhidos, e como acompanhamento no chamado.
- Tipos de chamado e entidades que ficam de fora.

### Painel
- **Indicadores**, **gráficos** (ECharts do GLPI), **rankings** de técnicos, **comentários**, lista de pesquisas e envios de e-mail.
- Exportação **CSV**.
- Sempre restrito às entidades ativas do usuário.
- Aba **Pesquisa** no chamado, para criar, reenviar ou ver a resposta.
- Colunas da pesquisa disponíveis na busca de chamados.

## Configuração

Opções da página de configuração:
- nome da organização e **logo**, usados na página e nos e-mails;
- remetente;
- textos da página pública: título, texto e agradecimento;
- **modelos dos três e-mails** (convite, lembrete e encerramento), com prévia e envio de teste;
- prazos de lembrete, encerramento e fechamento;
- bloqueio, alertas e o acesso ao painel por perfis e usuários.

O painel fica em **Ferramentas → Pesquisa de satisfação**.

---

## Download e instalação

1. Baixe o arquivo `pesquisadesatisfacao-X.Y.Z.zip` da **[última versão](../../releases/latest)**. Use o arquivo anexado à release, não o "Source code".
2. Descompacte dentro da pasta `plugins/` do GLPI. O resultado deve ser `plugins/pesquisadesatisfacao/setup.php`.
3. Ajuste o dono dos arquivos para o usuário do servidor web, por exemplo:
   ```bash
   chown -R www-data:www-data /var/www/glpi/plugins/pesquisadesatisfacao
   ```
4. No GLPI, vá em **Configurar → Plugins** e clique em **Instalar** e depois em **Ativar**. Pela linha de comando:
   ```bash
   php bin/console plugin:install pesquisadesatisfacao -u <usuário administrador>
   php bin/console plugin:activate pesquisadesatisfacao
   ```

A instalação cria as tabelas, as configurações padrão e as ações automáticas do plugin, e funciona num GLPI sem nada configurado antes.

### Atualização

Substitua a pasta `plugins/pesquisadesatisfacao` pela versão nova e rode **Instalar** de novo, ou `php bin/console plugin:install pesquisadesatisfacao -f`. Depois, ative o plugin. As tabelas e colunas novas são criadas sem perder os dados.

### Desinstalação

A desinstalação **não apaga as tabelas do plugin**: reinstalar recupera os dados.

## Versões

O histórico, com o que mudou em cada versão e o arquivo para download, está em **[Releases](../../releases)**. Cada versão entrou por um **[pull request](../../pulls?q=is%3Apr)**.

## Licença

Distribuído sob a **GNU General Public License v3.0**. Veja o arquivo [LICENSE](LICENSE).