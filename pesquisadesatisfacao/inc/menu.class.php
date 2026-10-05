<?php

/**
 * Plugin Pesquisa de Satisfação - item em Ferramentas
 */
class PluginPesquisadesatisfacaoMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Pesquisa de satisfação';
    }

    public static function getMenuName(): string
    {
        return 'Pesquisa de satisfação';
    }

    public static function getIcon(): string
    {
        return 'ti ti-mood-smile';
    }

    public static function canView(): bool
    {
        return PluginPesquisadesatisfacaoConfig::podeVerRelatorios();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }
        $C = PluginPesquisadesatisfacaoConfig::class;
        $menu = [
            'title' => self::getMenuName(),
            'page'  => $C::url('relatorios.php'),
            'icon'  => self::getIcon(),
            'links' => ['search' => $C::url('relatorios.php')],
            'options' => [
                'relatorios' => [
                    'title' => 'Painel',
                    'page'  => $C::url('relatorios.php'),
                    'icon'  => 'ti ti-chart-bar',
                    'links' => ['search' => $C::url('relatorios.php')],
                ],
                'pendentes' => [
                    'title' => 'Minhas pesquisas pendentes',
                    'page'  => $C::url('pendentes.php'),
                    'icon'  => 'ti ti-clock',
                    'links' => ['search' => $C::url('pendentes.php')],
                ],
            ],
        ];
        if ($C::ehAdmin()) {
            $menu['links']['config'] = $C::url('config.form.php');
            $menu['options']['config'] = [
                'title' => 'Configuração',
                'page'  => $C::url('config.form.php'),
                'icon'  => 'ti ti-settings',
                'links' => ['search' => $C::url('config.form.php')],
            ];
        }
        return $menu;
    }
}
