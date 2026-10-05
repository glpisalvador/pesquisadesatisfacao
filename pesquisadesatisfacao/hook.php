<?php

/**
 * Plugin Pesquisa de Satisfação - instalação, tarefa automática e colunas na busca de chamados
 */

function plugin_pesquisadesatisfacao_install(): bool
{
    global $DB;

    $opcoes = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';
    $prefixo = 'glpi_plugin_pesquisadesatisfacao_';

    if (!$DB->tableExists($prefixo . 'configs')) {
        $DB->doQuery("CREATE TABLE `{$prefixo}configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` longtext,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }

    if (!$DB->tableExists($prefixo . 'perguntas')) {
        $DB->doQuery("CREATE TABLE `{$prefixo}perguntas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `texto` varchar(500) NOT NULL DEFAULT '',
            `ordem` int NOT NULL DEFAULT 0,
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `justificativa` varchar(20) NOT NULL DEFAULT 'ruim' COMMENT 'nao, opcional, ruim (obrigatória quando Ruim)',
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ordem` (`ordem`),
            KEY `is_active` (`is_active`)
        ) $opcoes");
    }

    if (!$DB->tableExists($prefixo . 'pesquisas')) {
        $DB->doQuery("CREATE TABLE `{$prefixo}pesquisas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tickets_id` int unsigned NOT NULL DEFAULT 0,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `users_id_requerente` int unsigned NOT NULL DEFAULT 0,
            `users_id_tecnico` int unsigned NOT NULL DEFAULT 0,
            `groups_id` int unsigned NOT NULL DEFAULT 0,
            `email_destino` varchar(255) NOT NULL DEFAULT '',
            `token` varchar(64) NOT NULL DEFAULT '',
            `status` tinyint NOT NULL DEFAULT 1 COMMENT '1 pendente, 2 respondida, 3 encerrada sem resposta',
            `nota` decimal(5,1) NULL DEFAULT NULL COMMENT 'satisfação de 0 a 100',
            `negativa` tinyint(1) NOT NULL DEFAULT 0,
            `comentario` longtext,
            `lembretes` int NOT NULL DEFAULT 0,
            `data_ultimo_envio` timestamp NULL DEFAULT NULL,
            `data_resposta` timestamp NULL DEFAULT NULL,
            `data_encerramento` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tickets_id` (`tickets_id`),
            UNIQUE KEY `token` (`token`),
            KEY `status` (`status`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id_requerente` (`users_id_requerente`),
            KEY `users_id_tecnico` (`users_id_tecnico`),
            KEY `groups_id` (`groups_id`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    if (!$DB->tableExists($prefixo . 'respostas')) {
        $DB->doQuery("CREATE TABLE `{$prefixo}respostas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `pesquisas_id` int unsigned NOT NULL DEFAULT 0,
            `perguntas_id` int unsigned NOT NULL DEFAULT 0,
            `pergunta` varchar(500) NOT NULL DEFAULT '' COMMENT 'texto da pergunta no momento da resposta',
            `valor` tinyint NOT NULL DEFAULT 0 COMMENT '1 ruim, 2 regular, 3 bom',
            `justificativa` longtext,
            PRIMARY KEY (`id`),
            UNIQUE KEY `pesquisa_pergunta` (`pesquisas_id`, `perguntas_id`),
            KEY `perguntas_id` (`perguntas_id`),
            KEY `valor` (`valor`)
        ) $opcoes");
    }

    if (!$DB->tableExists($prefixo . 'envios')) {
        $DB->doQuery("CREATE TABLE `{$prefixo}envios` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `pesquisas_id` int unsigned NOT NULL DEFAULT 0,
            `tickets_id` int unsigned NOT NULL DEFAULT 0,
            `tipo` tinyint NOT NULL DEFAULT 1 COMMENT '1 convite, 2 lembrete, 3 encerramento, 4 alerta, 5 teste',
            `email_destino` varchar(255) NOT NULL DEFAULT '',
            `sucesso` tinyint(1) NOT NULL DEFAULT 0,
            `mensagem_erro` text,
            `users_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'quem disparou (0 = automático)',
            `data_envio` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `pesquisas_id` (`pesquisas_id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `tipo` (`tipo`),
            KEY `data_envio` (`data_envio`)
        ) $opcoes");
    }

    include_once __DIR__ . '/inc/config.class.php';
    include_once __DIR__ . '/inc/pergunta.class.php';

    // Configurações padrão (somente as que ainda não existem)
    foreach (PluginPesquisadesatisfacaoConfig::padroes() as $nome => $valor) {
        if (count($DB->request(['FROM' => $prefixo . 'configs', 'WHERE' => ['name' => $nome], 'LIMIT' => 1])) === 0) {
            $DB->insert($prefixo . 'configs', ['name' => $nome, 'value' => is_array($valor) ? json_encode($valor) : (string) $valor]);
        }
    }

    // Perguntas iniciais (as mesmas do plugin original), só na primeira instalação
    if (count($DB->request(['FROM' => $prefixo . 'perguntas', 'LIMIT' => 1])) === 0) {
        foreach (PluginPesquisadesatisfacaoPergunta::iniciais() as $ordem => $texto) {
            $DB->insert($prefixo . 'perguntas', ['texto' => $texto, 'ordem' => $ordem + 1, 'is_active' => 1, 'justificativa' => 'ruim']);
        }
    }

    // Pasta do logo
    $pasta = GLPI_PLUGIN_DOC_DIR . '/pesquisadesatisfacao';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0755, true);
    }

    // Tarefa automática: lembretes, encerramento sem resposta e fechamento dos chamados
    CronTask::register('PluginPesquisadesatisfacaoPesquisa', 'processar', 15 * MINUTE_TIMESTAMP, [
        'state'        => CronTask::STATE_WAITING,
        'mode'         => CronTask::MODE_INTERNAL,
        'logs_lifetime' => 30,
        'comment'      => 'Pesquisa de satisfação: lembretes, encerramento sem resposta e fechamento dos chamados',
    ]);

    return true;
}

/** As tabelas e configurações são mantidas: reinstalar recupera tudo */
function plugin_pesquisadesatisfacao_uninstall(): bool
{
    CronTask::unregister('pesquisadesatisfacao');
    return true;
}

/** Colunas extras na busca de chamados */
function plugin_pesquisadesatisfacao_getAddSearchOptionsNew($itemtype): array
{
    if ($itemtype !== 'Ticket' || !class_exists('PluginPesquisadesatisfacaoConfig') || !PluginPesquisadesatisfacaoConfig::podeVerRelatorios()) {
        return [];
    }
    $tabela = 'glpi_plugin_pesquisadesatisfacao_pesquisas';
    return [
        [
            'id'   => 'pesquisadesatisfacao',
            'name' => 'Pesquisa de satisfação',
        ],
        [
            'id'            => 77910,
            'table'         => $tabela,
            'field'         => 'status',
            'name'          => 'Pesquisa - status',
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'searchequalsonfield' => true, // compara o status, e não o id da pesquisa
            'massiveaction' => false,
            'nosort'        => false,
            'joinparams'    => ['jointype' => 'child'],
        ],
        [
            'id'            => 77911,
            'table'         => $tabela,
            'field'         => 'nota',
            'name'          => 'Pesquisa - satisfação (%)',
            'datatype'      => 'decimal',
            'massiveaction' => false,
            'joinparams'    => ['jointype' => 'child'],
        ],
        [
            'id'            => 77912,
            'table'         => $tabela,
            'field'         => 'data_resposta',
            'name'          => 'Pesquisa - respondida em',
            'datatype'      => 'datetime',
            'massiveaction' => false,
            'joinparams'    => ['jointype' => 'child'],
        ],
    ];
}
