<?php

/**
 * Plugin Pesquisa de Satisfação - atalho para a configuração
 */

Session::checkLoginUser();
Html::redirect(PluginPesquisadesatisfacaoConfig::url('config.form.php'));
