<?php

/**
 * Plugin Pesquisa de Satisfação - pesquisas pendentes do usuário logado
 * (para onde o bloqueio de novos chamados leva o requerente)
 */

Session::checkLoginUser();

$C = PluginPesquisadesatisfacaoConfig::class;
$P = PluginPesquisadesatisfacaoPesquisa::class;
$e = [$C, 'e'];
$titulo = 'Minhas pesquisas pendentes';

if (Session::getCurrentInterface() === 'helpdesk') {
    Html::helpHeader($titulo);
} else {
    Html::header($titulo, '', 'tools', 'PluginPesquisadesatisfacaoMenu', 'pendentes');
}
echo $C::assets();

$uid = (int) Session::getLoginUserID();
$lista = $P::pendentesDoUsuario($uid);
$max = $C::inteiro('max_pendentes', 1, 100);

echo '<div class="pesquisadesatisfacao-pagina pesquisadesatisfacao-pendentes">';
echo '<div class="card pesquisadesatisfacao-card"><div class="card-header"><h5><i class="ti ti-mood-smile"></i> ' . $e($titulo) . '</h5>'
    . '<span class="pesquisadesatisfacao-contador-cabecalho">' . count($lista) . '</span></div><div class="card-body">';

if (!$lista) {
    echo '<div class="pesquisadesatisfacao-vazio"><i class="ti ti-circle-check"></i><div>Você não tem pesquisas de satisfação pendentes. Obrigado!</div></div>';
} else {
    echo '<p class="text-muted pesquisadesatisfacao-explicacao"><i class="ti ti-info-circle"></i> Cada pesquisa leva menos de um minuto.';
    if ($C::ligado('bloqueio_ativo')) {
        echo ' Com ' . $max . ' ou mais pendentes, não é possível abrir novos chamados.';
    }
    echo '</p><div class="pesquisadesatisfacao-lista-pendentes">';
    foreach ($lista as $p) {
        echo '<div class="pesquisadesatisfacao-pendente"><div><strong>Chamado #' . (int) $p['tickets_id'] . '</strong> ' . $e((string) $p['titulo'])
            . '<div class="small text-muted">Solucionado em ' . $e(Html::convDateTime((string) ($p['solvedate'] ?: $p['date_creation']))) . '</div></div>'
            . '<a class="btn btn-sm pesquisadesatisfacao-btn-principal" href="' . $e($C::urlPublica((string) $p['token'], false)) . '" target="_blank" rel="noopener">'
            . '<i class="ti ti-send"></i><span>Responder</span></a></div>';
    }
    echo '</div>';
}
echo '</div></div></div>';

if (Session::getCurrentInterface() === 'helpdesk') {
    Html::helpFooter();
} else {
    Html::footer();
}
