<?php

/**
 * Plugin Pesquisa de Satisfação - painel: indicadores, gráficos, rankings, comentários, lista e envios
 */

Session::checkLoginUser();

$C = PluginPesquisadesatisfacaoConfig::class;
$P = PluginPesquisadesatisfacaoPesquisa::class;
$R = PluginPesquisadesatisfacaoRelatorio::class;
$e = [$C, 'e'];

if (!$C::podeVerRelatorios()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$f = $R::filtros($_GET);

// ------------------------------------------------------------------ CSV
if (($_GET['exportar'] ?? '') === 'csv') {
    $conteudo = $R::csv($f);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pesquisa-satisfacao-' . $f['desde'] . '-a-' . $f['ate'] . '.csv"');
    header('Content-Length: ' . strlen($conteudo));
    echo $conteudo;
    exit;
}

global $CFG_GLPI;
Html::header('Pesquisa de satisfação', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginPesquisadesatisfacaoMenu', 'relatorios');
echo $C::assets();
echo '<script src="' . $e($CFG_GLPI['root_doc'] . '/lib/echarts.min.js') . '"></script>';

$indicadores = $R::indicadores($f);
$porPergunta = $R::porPergunta($f);
$evolucao = $R::evolucao($f);
$rotulos = $C::rotulos();
$params = $R::parametros($f);
$aba = in_array($_GET['aba'] ?? '', ['pesquisas', 'comentarios', 'envios'], true) ? $_GET['aba'] : 'pesquisas';

echo '<div class="pesquisadesatisfacao-pagina" data-pesquisadesatisfacao-painel data-ajax="' . $e($C::url('ajax.php')) . '" data-token="' . $e($C::tokenCsrf()) . '">';

// ------------------------------------------------------------------ filtros
echo '<form method="get" action="' . $e($C::url('relatorios.php')) . '" class="card pesquisadesatisfacao-card pesquisadesatisfacao-filtros">';
echo '<div class="card-body"><div class="pesquisadesatisfacao-filtros-linha">';
echo '<div class="pesquisadesatisfacao-filtro"><label>De</label>' . Html::showDateField('desde', ['value' => $f['desde'], 'display' => false, 'maybeempty' => false]) . '</div>';
echo '<div class="pesquisadesatisfacao-filtro"><label>Até</label>' . Html::showDateField('ate', ['value' => $f['ate'], 'display' => false, 'maybeempty' => false]) . '</div>';
echo '<div class="pesquisadesatisfacao-filtro pesquisadesatisfacao-filtro-largo"><label>Entidade</label>'
    . Entity::dropdown(['name' => 'entidade', 'value' => $f['entidade'], 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'display' => false, 'display_emptychoice' => true, 'emptylabel' => 'Todas']) . '</div>';
echo '<div class="pesquisadesatisfacao-filtro pesquisadesatisfacao-filtro-largo"><label>Técnico</label>'
    . User::dropdown(['name' => 'tecnico', 'value' => $f['tecnico'], 'right' => 'all', 'display' => false, 'display_emptychoice' => true, 'emptylabel' => 'Todos']) . '</div>';
echo '<div class="pesquisadesatisfacao-filtro pesquisadesatisfacao-filtro-largo"><label>Grupo</label>'
    . Group::dropdown(['name' => 'grupo', 'value' => $f['grupo'], 'display' => false, 'display_emptychoice' => true, 'emptylabel' => 'Todos']) . '</div>';
echo '<div class="pesquisadesatisfacao-filtro"><label>Status</label>'
    . Dropdown::showFromArray('status', [0 => 'Todos'] + array_map(fn($s) => $s[0], $P::STATUS), ['value' => $f['status'], 'display' => false]) . '</div>';
echo '<label class="pesquisadesatisfacao-filtro pesquisadesatisfacao-filtro-check"><input type="checkbox" class="pesquisadesatisfacao-check" name="negativas" value="1"' . ($f['negativas'] ? ' checked' : '') . '> Só avaliações negativas</label>';
echo '<div class="pesquisadesatisfacao-filtro-botoes">'
    . '<button type="submit" class="btn btn-sm pesquisadesatisfacao-btn-principal"><i class="ti ti-filter"></i><span>Filtrar</span></button>'
    . '<a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('relatorios.php')) . '"><i class="ti ti-x"></i><span>Limpar</span></a>'
    . '<a class="btn btn-sm btn-outline-secondary" href="' . $e($C::url('relatorios.php', $params + ['exportar' => 'csv'])) . '"><i class="ti ti-file-spreadsheet"></i><span>CSV</span></a>';
if ($C::ehAdmin()) {
    echo '<a class="btn btn-sm btn-ghost-secondary" href="' . $e($C::url('config.form.php')) . '" title="Configuração"><i class="ti ti-settings"></i></a>';
}
echo '</div></div></div></form>';
echo '<script>window.pesquisadesatisfacaoEntidadesOcultas = ' . json_encode($C::idsEntidadesFilhas()) . ';</script>';

// ------------------------------------------------------------------ indicadores
$cartao = function (string $icone, string $rotulo, string $valor, string $detalhe = '', string $tom = '') use ($e): string {
    return '<div class="pesquisadesatisfacao-kpi' . ($tom !== '' ? ' pesquisadesatisfacao-tom-' . $tom : '') . '"><i class="' . $icone . '"></i><div>'
        . '<span class="pesquisadesatisfacao-kpi-valor">' . $e($valor) . '</span><span class="pesquisadesatisfacao-kpi-rotulo">' . $e($rotulo) . '</span>'
        . ($detalhe !== '' ? '<span class="pesquisadesatisfacao-kpi-detalhe">' . $e($detalhe) . '</span>' : '') . '</div></div>';
};
echo '<div class="pesquisadesatisfacao-kpis">';
echo $cartao('ti ti-mood-smile', 'Satisfação', $C::pct($indicadores['satisfacao'], 1), 'média das respondidas', $P::tomNota($indicadores['satisfacao']));
echo $cartao('ti ti-send', 'Pesquisas enviadas', (string) $indicadores['total']);
echo $cartao('ti ti-circle-check', 'Respondidas', (string) $indicadores['respondidas'], 'taxa de resposta ' . $C::pct($indicadores['taxa'], 1));
echo $cartao('ti ti-clock', 'Pendentes', (string) $indicadores['pendentes']);
echo $cartao('ti ti-mood-empty', 'Encerradas sem resposta', (string) $indicadores['encerradas']);
echo $cartao('ti ti-alert-triangle', 'Avaliações negativas', (string) $indicadores['negativas'], 'com ao menos um "' . $rotulos[1] . '"', $indicadores['negativas'] > 0 ? 'erro' : '');
echo '</div>';

// ------------------------------------------------------------------ gráficos
$graficos = [
    'rotulos'   => $rotulos,
    'perguntas' => array_map(fn($p) => ['texto' => $p['texto'], 'contagem' => array_values($p['contagem']), 'satisfacao' => $p['satisfacao']], $porPergunta),
    'evolucao'  => $evolucao,
];
echo '<script type="application/json" id="pesquisadesatisfacao-graficos">' . json_encode($graficos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>';
echo '<div class="pesquisadesatisfacao-grade-2">';
echo '<div class="card pesquisadesatisfacao-card"><div class="card-header"><h5><i class="ti ti-chart-bar"></i> Respostas por pergunta</h5></div><div class="card-body">';
if ($porPergunta) {
    echo '<div class="pesquisadesatisfacao-grafico" data-pesquisadesatisfacao-grafico="perguntas" style="height:' . max(160, 70 + count($porPergunta) * 56) . 'px"></div>';
} else {
    echo '<div class="pesquisadesatisfacao-vazio"><i class="ti ti-chart-bar"></i><div>Nenhuma resposta no período.</div></div>';
}
echo '</div></div>';
echo '<div class="card pesquisadesatisfacao-card"><div class="card-header"><h5><i class="ti ti-chart-line"></i> Evolução mensal</h5></div><div class="card-body">'
    . '<div class="pesquisadesatisfacao-grafico" data-pesquisadesatisfacao-grafico="evolucao" style="height:260px"></div></div></div>';
echo '</div>';

// ------------------------------------------------------------------ rankings
$tabelaRanking = function (array $lista, string $titulo, string $icone, string $vazio) use ($e, $C, $P): string {
    $h = '<div class="card pesquisadesatisfacao-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body p-0">';
    if (!$lista) {
        return $h . '<div class="pesquisadesatisfacao-vazio"><i class="ti ti-mood-empty"></i><div>' . $e($vazio) . '</div></div></div></div>';
    }
    $h .= '<div class="pesquisadesatisfacao-rolagem"><table class="table table-sm table-hover pesquisadesatisfacao-tabela mb-0"><thead><tr><th>#</th><th>Nome</th><th class="text-end">Respostas</th><th>Satisfação</th><th class="text-end">Negativas</th></tr></thead><tbody>';
    foreach ($lista as $i => $l) {
        $h .= '<tr><td class="text-muted">' . ($i + 1) . '</td><td>' . $e($l['nome']) . '</td><td class="text-end">' . (int) $l['respostas'] . '</td>'
            . '<td><div class="pesquisadesatisfacao-barra pesquisadesatisfacao-tom-' . $P::tomNota($l['satisfacao']) . '"><div style="width:' . max(0, min(100, (float) $l['satisfacao'])) . '%"></div><span>' . $e($C::pct($l['satisfacao'], 1)) . '</span></div></td>'
            . '<td class="text-end">' . ((int) $l['negativas'] > 0 ? '<span class="pesquisadesatisfacao-selo pesquisadesatisfacao-tom-erro">' . (int) $l['negativas'] . '</span>' : '<span class="text-muted">0</span>') . '</td></tr>';
    }
    return $h . '</tbody></table></div></div></div>';
};
echo '<div class="pesquisadesatisfacao-grade-2">';
echo $tabelaRanking($R::ranking($f, 'tecnico'), 'Técnicos', 'ti ti-user-check', 'Nenhuma pesquisa respondida com técnico no período.');
echo $tabelaRanking($R::ranking($f, 'grupo'), 'Grupos', 'ti ti-users-group', 'Nenhuma pesquisa respondida com grupo no período.');
echo '</div>';

// ------------------------------------------------------------------ abas: pesquisas, comentários, envios
$abas = ['pesquisas' => ['ti ti-list', 'Pesquisas'], 'comentarios' => ['ti ti-message', 'Comentários'], 'envios' => ['ti ti-history', 'Envios de e-mail']];
echo '<div class="card pesquisadesatisfacao-card"><div class="card-header pesquisadesatisfacao-abas-cabecalho"><ul class="nav nav-tabs card-header-tabs" data-pesquisadesatisfacao-abas>';
foreach ($abas as $chave => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a class="nav-link' . ($aba === $chave ? ' active' : '') . '" href="#" data-aba="' . $chave . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul></div><div class="card-body">';

// Pesquisas (paginação no servidor)
$total = $R::contar($f);
$inicio = max(0, (int) ($_GET['inicio'] ?? 0));
if ($inicio >= $total) {
    $inicio = 0;
}
$lista = $R::listar($f, $inicio);
$nomes = $C::nomesUsuarios(array_merge(array_column($lista, 'users_id_requerente'), array_column($lista, 'users_id_tecnico')));
echo '<div data-aba-painel="pesquisas"' . ($aba !== 'pesquisas' ? ' hidden' : '') . '>';
if (!$lista) {
    echo '<div class="pesquisadesatisfacao-vazio"><i class="ti ti-mood-empty"></i><div>Nenhuma pesquisa com esses filtros.</div></div>';
} else {
    echo '<div class="table-responsive"><table class="table table-sm table-hover pesquisadesatisfacao-tabela"><thead><tr>'
        . '<th>Chamado</th><th>Entidade</th><th>Requerente</th><th>Técnico</th><th>Status</th><th>Satisfação</th><th>Criada</th><th>Respondida</th><th class="text-end">Lembretes</th><th class="no-export"></th></tr></thead><tbody>';
    foreach ($lista as $l) {
        $linkAba = Ticket::getFormURLWithID((int) $l['tickets_id']) . '&forcetab=PluginPesquisadesatisfacaoPesquisa$1';
        echo '<tr><td><a href="' . $e(Ticket::getFormURLWithID((int) $l['tickets_id'])) . '">#' . (int) $l['tickets_id'] . '</a> ' . $e(mb_strimwidth((string) $l['titulo'], 0, 60, '…')) . '</td>'
            . '<td>' . $e((string) $l['entidade']) . '</td>'
            . '<td>' . $e($nomes[(int) $l['users_id_requerente']] ?? $l['email_destino']) . '</td>'
            . '<td>' . $e($nomes[(int) $l['users_id_tecnico']] ?? '—') . '</td>'
            . '<td>' . $P::selo((int) $l['status']) . ($l['negativa'] ? ' <i class="ti ti-alert-triangle pesquisadesatisfacao-icone-erro" title="Avaliação negativa"></i>' : '') . '</td>'
            . '<td>' . ($l['nota'] === null ? '<span class="text-muted">—</span>' : '<span class="pesquisadesatisfacao-selo pesquisadesatisfacao-tom-' . $P::tomNota($l['nota']) . '">' . $e($C::pct($l['nota'], 0)) . '</span>') . '</td>'
            . '<td class="text-nowrap">' . $e(Html::convDateTime((string) $l['date_creation'])) . '</td>'
            . '<td class="text-nowrap">' . ($l['data_resposta'] ? $e(Html::convDateTime((string) $l['data_resposta'])) : '<span class="text-muted">—</span>') . '</td>'
            . '<td class="text-end">' . (int) $l['lembretes'] . '</td>'
            . '<td class="text-end"><a class="btn btn-sm btn-ghost-secondary" href="' . $e($linkAba) . '" title="Abrir a pesquisa no chamado"><i class="ti ti-eye"></i></a></td></tr>';
    }
    echo '</tbody></table></div>';

    // Paginação
    $paginas = (int) ceil($total / $R::POR_PAGINA);
    $atual = intdiv($inicio, $R::POR_PAGINA) + 1;
    echo '<div class="pesquisadesatisfacao-paginacao"><span class="text-muted small">' . ($inicio + 1) . '–' . min($total, $inicio + $R::POR_PAGINA) . ' de ' . $total . '</span>';
    if ($paginas > 1) {
        echo '<nav class="pesquisadesatisfacao-paginas">';
        $link = fn(int $pagina, string $texto, bool $ativo = false) => '<a class="btn btn-sm ' . ($ativo ? 'pesquisadesatisfacao-btn-principal' : 'btn-outline-secondary') . '" href="'
            . $e($C::url('relatorios.php', $params + ['inicio' => ($pagina - 1) * $R::POR_PAGINA, 'aba' => 'pesquisas'])) . '">' . $texto . '</a>';
        if ($atual > 1) {
            echo $link($atual - 1, '<i class="ti ti-chevron-left"></i>');
        }
        $de = max(1, $atual - 2);
        $ate = min($paginas, $de + 4);
        $de = max(1, $ate - 4);
        if ($de > 1) {
            echo $link(1, '1') . ($de > 2 ? '<span class="pesquisadesatisfacao-reticencias">…</span>' : '');
        }
        for ($i = $de; $i <= $ate; $i++) {
            echo $link($i, (string) $i, $i === $atual);
        }
        if ($ate < $paginas) {
            echo ($ate < $paginas - 1 ? '<span class="pesquisadesatisfacao-reticencias">…</span>' : '') . $link($paginas, (string) $paginas);
        }
        if ($atual < $paginas) {
            echo $link($atual + 1, '<i class="ti ti-chevron-right"></i>');
        }
        echo '</nav>';
    }
    echo '</div>';
}
echo '</div>';

// Comentários
$comentarios = $R::comentarios($f);
$nomesTec = $C::nomesUsuarios(array_column($comentarios, 'users_id_tecnico'));
echo '<div data-aba-painel="comentarios"' . ($aba !== 'comentarios' ? ' hidden' : '') . '>';
if (!$comentarios) {
    echo '<div class="pesquisadesatisfacao-vazio"><i class="ti ti-message"></i><div>Nenhuma justificativa ou comentário no período.</div></div>';
} else {
    echo '<div class="pesquisadesatisfacao-comentarios">';
    foreach ($comentarios as $c) {
        $v = $c['valor'] === null ? 0 : (int) $c['valor'];
        echo '<div class="pesquisadesatisfacao-comentario-item">'
            . '<div class="pesquisadesatisfacao-comentario-valor pesquisadesatisfacao-valor-' . $v . '">' . ($v > 0 ? PluginPesquisadesatisfacaoPergunta::carinha($v, 26) : '<i class="ti ti-message"></i>') . '</div>'
            . '<div><div class="pesquisadesatisfacao-comentario-meta"><a href="' . $e(Ticket::getFormURLWithID((int) $c['tickets_id']) . '&forcetab=PluginPesquisadesatisfacaoPesquisa$1') . '">#' . (int) $c['tickets_id'] . '</a>'
            . ' · ' . $e($c['pergunta']) . ($v > 0 ? ' · <strong>' . $e($rotulos[$v]) . '</strong>' : '')
            . ((int) $c['users_id_tecnico'] > 0 ? ' · ' . $e($nomesTec[(int) $c['users_id_tecnico']] ?? '') : '')
            . ' · ' . $e(Html::convDateTime((string) $c['data_resposta'])) . '</div>'
            . '<p>' . nl2br($e($c['texto'])) . '</p></div></div>';
    }
    echo '</div>';
}
echo '</div>';

// Envios
$restricao = (new DbUtils())->getEntitiesRestrictCriteria('p', 'entities_id', '', false);
echo '<div data-aba-painel="envios"' . ($aba !== 'envios' ? ' hidden' : '') . '>';
echo '<p class="text-muted pesquisadesatisfacao-explicacao"><i class="ti ti-info-circle"></i> Últimos 200 envios do período (convites, lembretes, encerramentos e alertas).</p>';
echo PluginPesquisadesatisfacaoEmail::tabelaEnvios(PluginPesquisadesatisfacaoEmail::listar(['desde' => $f['desde'], 'ate' => $f['ate'], 'where' => $restricao ? [$restricao] : []], 200));
echo '</div>';

echo '</div></div></div>';
Html::footer();
