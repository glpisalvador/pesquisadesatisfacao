<?php

/**
 * Plugin Pesquisa de Satisfação - GLPI 11 e 12
 * Pesquisa enviada ao requerente quando o chamado é solucionado, com lembretes,
 * encerramento automático, alerta de avaliação negativa, aba no chamado e painel.
 */

define('PLUGIN_PESQUISADESATISFACAO_VERSION', '2.0.0');
define('PLUGIN_PESQUISADESATISFACAO_MIN_GLPI', '11.0.0');
define('PLUGIN_PESQUISADESATISFACAO_MAX_GLPI', '12.99.99');

function plugin_init_pesquisadesatisfacao(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['pesquisadesatisfacao'] = true;

    // Página pública de resposta e logo: sem login e sem sessão
    if (class_exists(\Glpi\Http\Firewall::class)) {
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts('pesquisadesatisfacao', '#^/front/publico\.php$#', \Glpi\Http\Firewall::STRATEGY_NO_CHECK);
    }
    if (class_exists(\Glpi\Http\SessionManager::class)) {
        \Glpi\Http\SessionManager::registerPluginStatelessPath('pesquisadesatisfacao', '#^/front/publico\.php$#');
    }

    $plugin = new Plugin();
    if (!$plugin->isActivated('pesquisadesatisfacao')) {
        return;
    }

    Plugin::registerClass('PluginPesquisadesatisfacaoConfig');
    Plugin::registerClass('PluginPesquisadesatisfacaoMenu');
    Plugin::registerClass('PluginPesquisadesatisfacaoPergunta');
    Plugin::registerClass('PluginPesquisadesatisfacaoPesquisa', ['addtabon' => ['Ticket']]);

    $PLUGIN_HOOKS['config_page']['pesquisadesatisfacao'] = 'front/config.form.php';
    $PLUGIN_HOOKS['menu_toadd']['pesquisadesatisfacao'] = ['tools' => 'PluginPesquisadesatisfacaoMenu'];

    $PLUGIN_HOOKS['item_update']['pesquisadesatisfacao'] = ['Ticket' => ['PluginPesquisadesatisfacaoPesquisa', 'aposAtualizarChamado']];
    $PLUGIN_HOOKS['pre_item_update']['pesquisadesatisfacao'] = ['Ticket' => ['PluginPesquisadesatisfacaoPesquisa', 'antesAtualizarChamado']];
    $PLUGIN_HOOKS['pre_item_add']['pesquisadesatisfacao'] = ['Ticket' => ['PluginPesquisadesatisfacaoPesquisa', 'antesCriarChamado']];
    $PLUGIN_HOOKS['item_purge']['pesquisadesatisfacao'] = ['Ticket' => ['PluginPesquisadesatisfacaoPesquisa', 'aposExcluirChamado']];
}

function plugin_version_pesquisadesatisfacao(): array
{
    return [
        'name'         => 'Pesquisa de Satisfação',
        'version'      => PLUGIN_PESQUISADESATISFACAO_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PESQUISADESATISFACAO_MIN_GLPI,
                'max' => PLUGIN_PESQUISADESATISFACAO_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_pesquisadesatisfacao_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_PESQUISADESATISFACAO_MIN_GLPI, '>=');
}

function plugin_pesquisadesatisfacao_check_config($verbose = false): bool
{
    return true;
}
