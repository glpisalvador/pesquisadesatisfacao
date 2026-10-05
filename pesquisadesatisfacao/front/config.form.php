<?php

/**
 * Plugin Pesquisa de Satisfação - configuração (acessível pelo marketplace e pelo menu).
 * Cada aba é um formulário próprio que faz POST para esta mesma página.
 */

Session::checkLoginUser();

$C = PluginPesquisadesatisfacaoConfig::class;
$Q = PluginPesquisadesatisfacaoPergunta::class;
$M = PluginPesquisadesatisfacaoEmail::class;
$e = [$C, 'e'];

if (!$C::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$ABAS = [
    'regras'    => ['ti ti-adjustments', 'Regras'],
    'perguntas' => ['ti ti-list-check', 'Perguntas'],
    'aparencia' => ['ti ti-mail', 'E-mails e página'],
    'alertas'   => ['ti ti-alert-triangle', 'Alertas'],
    'acesso'    => ['ti ti-shield-lock', 'Acesso'],
];
$aba = (string) ($_POST['aba'] ?? $_GET['aba'] ?? 'regras');
if (!isset($ABAS[$aba])) {
    $aba = 'regras';
}

$ids = fn(string $campo) => array_values(array_unique(array_filter(array_map('intval', (array) ($_POST[$campo] ?? [])), fn($v) => $v > 0)));
$numero = fn(string $campo, int $min, int $max) => (string) max($min, min($max, (int) ($_POST[$campo] ?? 0)));
$marcado = fn(string $campo) => !empty($_POST[$campo]) ? '1' : '0';
$linha = fn(string $campo, int $max = 255) => mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) ($_POST[$campo] ?? ''))), 0, $max);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_action'])) {
    switch ((string) $_POST['save_action']) {
        case 'salvar_regras':
            $tipos = array_values(array_intersect([Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], array_map('intval', (array) ($_POST['tipos'] ?? []))));
            $C::setArrayConfig('tipos', $tipos);
            $C::setArrayConfig('entidades_excluidas', $ids('entidades_excluidas'));
            $C::setConfig('dias_lembrete', $numero('dias_lembrete', 0, 365));
            $C::setConfig('dias_encerrar', $numero('dias_encerrar', 0, 365));
            $C::setConfig('bloquear_fechamento', $marcado('bloquear_fechamento'));
            $C::setConfig('dias_para_fechar', $numero('dias_para_fechar', 0, 365));
            $C::setConfig('bloqueio_ativo', $marcado('bloqueio_ativo'));
            $C::setConfig('max_pendentes', $numero('max_pendentes', 1, 100));
            Session::addMessageAfterRedirect($tipos ? 'Regras salvas.' : 'Regras salvas. Nenhum tipo de chamado marcado: as pesquisas só serão criadas manualmente.', false, $tipos ? INFO : WARNING);
            break;

        case 'salvar_perguntas':
            $erro = $Q::salvarLista((array) ($_POST['pergunta'] ?? []), (array) ($_POST['ordem'] ?? []));
            if ($erro !== '') {
                Session::addMessageAfterRedirect($erro, false, ERROR);
                break;
            }
            foreach ([1, 2, 3] as $v) {
                $rotulo = $linha('rotulo_' . $v, 40);
                $C::setConfig('rotulo_' . $v, $rotulo !== '' ? $rotulo : $C::padroes()['rotulo_' . $v]);
            }
            $C::setConfig('comentario_final', $marcado('comentario_final'));
            Session::addMessageAfterRedirect('Perguntas salvas.', false, INFO);
            break;

        case 'salvar_aparencia':
            $remetente = trim((string) ($_POST['remetente_email'] ?? ''));
            if ($remetente !== '' && !filter_var($remetente, FILTER_VALIDATE_EMAIL)) {
                Session::addMessageAfterRedirect('O e-mail do remetente não é válido.', false, ERROR);
                break;
            }
            $C::setConfig('remetente_email', $remetente);
            $C::setConfig('remetente_nome', $linha('remetente_nome', 120));
            $C::setConfig('org_nome', $linha('org_nome', 120));
            $C::setConfig('pagina_titulo', $linha('pagina_titulo', 120));
            $C::setConfig('pagina_texto', $linha('pagina_texto', 500));
            $C::setConfig('pagina_agradecimento', $linha('pagina_agradecimento', 500));
            foreach ([1, 2, 3] as $t) {
                $C::setConfig('email_assunto_' . $t, $linha('email_assunto_' . $t, 200));
                $corpo = (string) ($_POST['email_corpo_' . $t] ?? '');
                $C::setConfig('email_corpo_' . $t, trim(strip_tags($corpo)) === '' && !str_contains($corpo, '{BOTAO}') ? '' : $corpo);
            }
            Session::addMessageAfterRedirect('E-mails e página salvos.', false, INFO);
            break;

        case 'salvar_alertas':
            $invalidos = [];
            $validos = [];
            foreach (preg_split('/[\s,;]+/', (string) ($_POST['alerta_emails'] ?? '')) as $email) {
                $email = trim($email);
                if ($email === '') {
                    continue;
                }
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validos[] = $email;
                } else {
                    $invalidos[] = $email;
                }
            }
            if ($invalidos) {
                Session::addMessageAfterRedirect('E-mails inválidos: ' . implode(', ', $invalidos), false, ERROR);
                break;
            }
            $C::setConfig('alerta_ativo', $marcado('alerta_ativo'));
            $C::setConfig('alerta_tecnico', $marcado('alerta_tecnico'));
            $C::setArrayConfig('alerta_usuarios', $ids('alerta_usuarios'));
            $C::setConfig('alerta_emails', implode(', ', array_unique($validos)));
            $C::setConfig('alerta_acompanhamento', $marcado('alerta_acompanhamento'));
            Session::addMessageAfterRedirect('Alertas salvos.', false, INFO);
            break;

        case 'salvar_acesso':
            $C::setArrayConfig('acesso_perfis', $ids('acesso_perfis'));
            $C::setArrayConfig('acesso_usuarios', $ids('acesso_usuarios'));
            Session::addMessageAfterRedirect('Acesso salvo.', false, INFO);
            break;
    }
}

Html::header('Pesquisa de satisfação', $_SERVER['PHP_SELF'] ?? '', 'tools', 'PluginPesquisadesatisfacaoMenu', 'config');
echo $C::assets();

$form = function (string $acao, string $abaForm) use ($C, $e): string {
    // O token CSRF (GLPI 11) entra pelo Html::closeForm()
    return '<form method="post" action="' . $e($C::url('config.form.php')) . '" class="pesquisadesatisfacao-form">'
        . '<input type="hidden" name="save_action" value="' . $e($acao) . '">'
        . '<input type="hidden" name="aba" value="' . $e($abaForm) . '">';
};
$salvar = '<div class="pesquisadesatisfacao-rodape-form"><button type="submit" class="btn btn-sm pesquisadesatisfacao-btn-principal"><i class="ti ti-device-floppy"></i><span>Salvar</span></button></div>';
$interruptor = fn(string $nome, string $rotulo, bool $ligado, string $ajuda = '') => '<div class="form-check form-switch pesquisadesatisfacao-switch">'
    . '<input class="form-check-input" type="checkbox" role="switch" id="pesquisadesatisfacao-' . $e($nome) . '" name="' . $e($nome) . '" value="1"' . ($ligado ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="pesquisadesatisfacao-' . $e($nome) . '">' . $e($rotulo) . '</label>'
    . ($ajuda !== '' ? '<small>' . $e($ajuda) . '</small>' : '') . '</div>';
$numeroCampo = fn(string $nome, string $rotulo, int $valor, int $min, int $max, string $sufixo, string $ajuda = '') => '<div class="pesquisadesatisfacao-campo">'
    . '<label for="pesquisadesatisfacao-' . $e($nome) . '">' . $e($rotulo) . '</label>'
    . '<div class="input-group input-group-sm pesquisadesatisfacao-curto"><input type="number" class="form-control" id="pesquisadesatisfacao-' . $e($nome) . '" name="' . $e($nome) . '" min="' . $min . '" max="' . $max . '" value="' . $valor . '">'
    . '<span class="input-group-text">' . $e($sufixo) . '</span></div>' . ($ajuda !== '' ? '<small>' . $e($ajuda) . '</small>' : '') . '</div>';
$textoCampo = fn(string $nome, string $rotulo, string $valor, string $ajuda = '', string $tipo = 'text', int $max = 255) => '<div class="pesquisadesatisfacao-campo">'
    . '<label for="pesquisadesatisfacao-' . $e($nome) . '">' . $e($rotulo) . '</label>'
    . '<input type="' . $tipo . '" class="form-control form-control-sm" id="pesquisadesatisfacao-' . $e($nome) . '" name="' . $e($nome) . '" maxlength="' . $max . '" value="' . $e($valor) . '">'
    . ($ajuda !== '' ? '<small>' . $e($ajuda) . '</small>' : '') . '</div>';
$cartao = fn(string $icone, string $titulo, string $corpo) => '<div class="card pesquisadesatisfacao-card"><div class="card-header"><h5><i class="' . $icone . '"></i> ' . $e($titulo) . '</h5></div><div class="card-body">' . $corpo . '</div></div>';

echo '<div class="pesquisadesatisfacao-pagina pesquisadesatisfacao-config" data-pesquisadesatisfacao-config data-ajax="' . $e($C::url('ajax.php')) . '" data-token="' . $e($C::tokenCsrf()) . '">';
echo '<ul class="nav nav-tabs pesquisadesatisfacao-abas" data-pesquisadesatisfacao-abas data-atualizar-url="1">';
foreach ($ABAS as $chave => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a class="nav-link' . ($aba === $chave ? ' active' : '') . '" href="#" data-aba="' . $chave . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';

// ------------------------------------------------------------------ Regras
$tipos = array_map('intval', $C::getArrayConfig('tipos'));
echo '<div data-aba-painel="regras"' . ($aba !== 'regras' ? ' hidden' : '') . '>' . $form('salvar_regras', 'regras');
echo '<div class="pesquisadesatisfacao-grade-2">';
echo $cartao('ti ti-send', 'Quando enviar',
    '<p class="text-muted pesquisadesatisfacao-explicacao"><i class="ti ti-info-circle"></i> A pesquisa é criada quando o chamado passa para Solucionado e o convite vai para o e-mail do requerente. Se o chamado for reaberto, a pesquisa pendente é descartada.</p>'
    . '<div class="pesquisadesatisfacao-campo"><label>Tipos de chamado</label><div class="pesquisadesatisfacao-linha-checks">'
    . '<label class="pesquisadesatisfacao-inline"><input type="checkbox" class="pesquisadesatisfacao-check" name="tipos[]" value="' . Ticket::INCIDENT_TYPE . '"' . (in_array(Ticket::INCIDENT_TYPE, $tipos, true) ? ' checked' : '') . '> Incidente</label>'
    . '<label class="pesquisadesatisfacao-inline"><input type="checkbox" class="pesquisadesatisfacao-check" name="tipos[]" value="' . Ticket::DEMAND_TYPE . '"' . (in_array(Ticket::DEMAND_TYPE, $tipos, true) ? ' checked' : '') . '> Requisição</label></div></div>'
    . '<div class="pesquisadesatisfacao-campo"><label>Entidades sem pesquisa</label>' . $C::multiselect('entidades_excluidas', $C::listarEntidadesPai(), $C::ids('entidades_excluidas'), 'Nenhuma')
    . '<small>As sub-entidades das entidades marcadas também ficam de fora.</small></div>');
echo $cartao('ti ti-clock', 'Lembretes e encerramento',
    $numeroCampo('dias_lembrete', 'Reenviar lembrete a cada', (int) $C::getConfig('dias_lembrete'), 0, 365, 'dias', '0 = não enviar lembretes.')
    . $numeroCampo('dias_encerrar', 'Encerrar sem resposta após', (int) $C::getConfig('dias_encerrar'), 0, 365, 'dias', 'Contados da criação da pesquisa. 0 = nunca encerra (continua lembrando).'));
echo '</div><div class="pesquisadesatisfacao-grade-2">';
echo $cartao('ti ti-lock', 'Fechamento do chamado',
    $interruptor('bloquear_fechamento', 'Manter o chamado em Solucionado enquanto a pesquisa estiver pendente', $C::ligado('bloquear_fechamento'), 'O fechamento automático do GLPI espera; o plugin fecha o chamado depois da resposta ou do encerramento da pesquisa.')
    . $numeroCampo('dias_para_fechar', 'Fechar o chamado', (int) $C::getConfig('dias_para_fechar'), 0, 365, 'dias após a resposta', '0 = fecha na próxima execução da tarefa automática após a resposta (até 15 minutos).'));
echo $cartao('ti ti-ticket', 'Novos chamados',
    $interruptor('bloqueio_ativo', 'Pedir a resposta antes de abrir outro chamado', $C::ligado('bloqueio_ativo'), 'Vale para o próprio requerente. Técnicos abrindo chamado para outra pessoa, o coletor de e-mails e a API não são bloqueados.')
    . $numeroCampo('max_pendentes', 'Bloquear a partir de', (int) $C::getConfig('max_pendentes'), 1, 100, 'pesquisas pendentes'));
echo '</div>' . $salvar . Html::closeForm(false) . '</div>';

// ------------------------------------------------------------------ Perguntas
$rotulos = $C::rotulos();
echo '<div data-aba-painel="perguntas"' . ($aba !== 'perguntas' ? ' hidden' : '') . '>' . $form('salvar_perguntas', 'perguntas');
$linhaPergunta = function (string $chave, array $p) use ($e, $Q): string {
    $novo = $chave === 'novo';
    $h = '<tr data-pergunta' . ($novo ? ' class="pesquisadesatisfacao-nova"' : '') . '><td class="pesquisadesatisfacao-ordem">'
        . '<input type="hidden" name="ordem[]" value="' . $e($chave) . '">'
        . ($novo ? '<i class="ti ti-plus text-muted"></i>' : '<div class="pesquisadesatisfacao-mover"><button type="button" class="btn btn-sm btn-ghost-secondary" data-mover="-1" title="Subir"><i class="ti ti-chevron-up"></i></button>'
            . '<button type="button" class="btn btn-sm btn-ghost-secondary" data-mover="1" title="Descer"><i class="ti ti-chevron-down"></i></button></div>')
        . '</td><td><input type="text" class="form-control form-control-sm" name="pergunta[' . $e($chave) . '][texto]" maxlength="500" value="' . $e($p['texto'] ?? '') . '"'
        . ($novo ? ' placeholder="Nova pergunta (deixe vazio para não incluir)"' : ' required') . '></td>'
        . '<td>' . Dropdown::showFromArray('pergunta[' . $chave . '][justificativa]', $Q::JUSTIFICATIVAS, ['value' => $p['justificativa'] ?? 'ruim', 'display' => false, 'width' => '100%']) . '</td>'
        . '<td class="text-center"><input type="checkbox" class="pesquisadesatisfacao-check" name="pergunta[' . $e($chave) . '][ativa]" value="1"' . (($p['is_active'] ?? 1) ? ' checked' : '') . ' title="Ativa"></td>'
        . '<td class="text-center">' . ($novo ? '' : '<input type="checkbox" class="pesquisadesatisfacao-check" name="pergunta[' . $e($chave) . '][excluir]" value="1" title="Excluir">') . '</td></tr>';
    return $h;
};
$corpo = '<p class="text-muted pesquisadesatisfacao-explicacao"><i class="ti ti-info-circle"></i> Cada pergunta é respondida com três carinhas. Excluir uma pergunta não apaga as respostas já dadas: elas continuam no painel com o texto da época.</p>'
    . '<div class="table-responsive"><table class="table table-sm pesquisadesatisfacao-tabela pesquisadesatisfacao-perguntas"><thead><tr><th style="width:70px">Ordem</th><th>Pergunta</th><th style="width:300px">Justificativa</th><th class="text-center" style="width:70px">Ativa</th><th class="text-center" style="width:70px">Excluir</th></tr></thead><tbody data-pesquisadesatisfacao-perguntas>';
foreach ($Q::listar(false) as $pid => $p) {
    $corpo .= $linhaPergunta((string) $pid, $p);
}
$corpo .= $linhaPergunta('novo', ['texto' => '', 'justificativa' => 'ruim', 'is_active' => 1]) . '</tbody></table></div>';
echo $cartao('ti ti-list-check', 'Perguntas', $corpo);
$escala = '<div class="pesquisadesatisfacao-escala">';
foreach ([1, 2, 3] as $v) {
    $escala .= '<div class="pesquisadesatisfacao-escala-item pesquisadesatisfacao-valor-' . $v . '">' . $Q::carinha($v, 34)
        . '<input type="text" class="form-control form-control-sm" name="rotulo_' . $v . '" maxlength="40" value="' . $e($rotulos[$v]) . '"></div>';
}
$escala .= '</div>' . $interruptor('comentario_final', 'Mostrar o campo "Algum comentário ou sugestão?" no fim da pesquisa', $C::ligado('comentario_final'));
echo $cartao('ti ti-mood-smile', 'Escala e comentário', $escala);
echo $salvar . Html::closeForm(false) . '</div>';

// ------------------------------------------------------------------ E-mails e página
echo '<div data-aba-painel="aparencia"' . ($aba !== 'aparencia' ? ' hidden' : '') . '>';
$logo = $C::urlLogo();
echo $cartao('ti ti-photo', 'Logo',
    '<div class="pesquisadesatisfacao-logo" data-pesquisadesatisfacao-logo>'
    . '<div class="pesquisadesatisfacao-logo-previa">' . ($logo !== '' ? '<img src="' . $e($logo) . '" alt="">' : '<span class="text-muted small">Sem logo</span>') . '</div>'
    . '<div class="pesquisadesatisfacao-logo-acoes"><input type="file" class="form-control form-control-sm" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" data-logo-arquivo>'
    . '<button type="button" class="btn btn-sm pesquisadesatisfacao-btn-principal" data-logo-enviar><i class="ti ti-upload"></i><span>Enviar</span></button>'
    . '<button type="button" class="btn btn-sm btn-outline-danger" data-logo-remover' . ($logo === '' ? ' hidden' : '') . '><i class="ti ti-trash"></i><span>Remover</span></button></div>'
    . '<small>PNG, JPG, GIF, WEBP ou SVG até 2 MB. Aparece no topo da página de resposta e dos e-mails.</small></div>');

echo $form('salvar_aparencia', 'aparencia');
$remetentePadrao = $C::remetente();
echo '<div class="pesquisadesatisfacao-grade-2">';
echo $cartao('ti ti-building', 'Identificação',
    $textoCampo('org_nome', 'Nome da organização', (string) $C::getConfig('org_nome'), 'Mostrado na página de resposta e nos e-mails.', 'text', 120)
    . $textoCampo('remetente_email', 'E-mail do remetente', (string) $C::getConfig('remetente_email'), 'Vazio = remetente das notificações do GLPI' . ($remetentePadrao['email'] !== '' && (string) $C::getConfig('remetente_email') === '' ? ' (' . $remetentePadrao['email'] . ')' : '') . '.', 'email', 255)
    . $textoCampo('remetente_nome', 'Nome do remetente', (string) $C::getConfig('remetente_nome'), 'Vazio = nome da organização.', 'text', 120));
echo $cartao('ti ti-world', 'Página de resposta',
    $textoCampo('pagina_titulo', 'Título', $C::texto('pagina_titulo'), '', 'text', 120)
    . $textoCampo('pagina_texto', 'Texto de apresentação', $C::texto('pagina_texto'), '', 'text', 500)
    . $textoCampo('pagina_agradecimento', 'Mensagem de agradecimento', $C::texto('pagina_agradecimento'), '', 'text', 500));
echo '</div>';

$variaveis = '<div class="pesquisadesatisfacao-variaveis"><span class="text-muted small">Variáveis:</span>';
foreach ($C::VARIAVEIS as $var => $desc) {
    $variaveis .= '<code title="' . $e($desc) . '">' . $e($var) . '</code>';
}
$variaveis .= '</div>';
$modelos = [$M::CONVITE => ['ti ti-send', 'Convite (ao solucionar)'], $M::LEMBRETE => ['ti ti-bell', 'Lembrete'], $M::ENCERRAMENTO => ['ti ti-mail-off', 'Encerramento sem resposta']];
echo '<div class="card pesquisadesatisfacao-card"><div class="card-header"><h5><i class="ti ti-mail"></i> Modelos de e-mail</h5></div><div class="card-body">';
echo $variaveis;
echo '<ul class="nav nav-pills pesquisadesatisfacao-subabas" data-pesquisadesatisfacao-abas data-grupo="modelos">';
foreach ($modelos as $t => [$icone, $rotulo]) {
    echo '<li class="nav-item"><a class="nav-link' . ($t === $M::CONVITE ? ' active' : '') . '" href="#" data-aba="modelo' . $t . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a></li>';
}
echo '</ul>';
foreach ($modelos as $t => [$icone, $rotulo]) {
    echo '<div data-aba-painel="modelo' . $t . '" data-grupo="modelos"' . ($t !== $M::CONVITE ? ' hidden' : '') . '>';
    echo $textoCampo('email_assunto_' . $t, 'Assunto', $C::texto('email_assunto_' . $t), '', 'text', 200);
    echo '<div class="pesquisadesatisfacao-campo"><label>Mensagem</label>';
    echo Html::textarea([
        'name'            => 'email_corpo_' . $t,
        'value'           => $C::texto('email_corpo_' . $t),
        'enable_richtext' => true,
        'enable_images'   => false,
        'cols'            => 100,
        'rows'            => 8,
        'display'         => false,
    ]);
    echo '<small>' . ($t === $M::ENCERRAMENTO ? 'O link não vale mais depois do encerramento.' : 'Use {BOTAO} onde o botão "Responder pesquisa" deve aparecer.') . ' Mensagem vazia volta para o texto padrão.</small></div>';
    echo '<div class="pesquisadesatisfacao-teste"><button type="button" class="btn btn-sm btn-outline-secondary" data-previa="' . $t . '"><i class="ti ti-eye"></i><span>Pré-visualizar (texto salvo)</span></button>'
        . '<input type="email" class="form-control form-control-sm" data-teste-email="' . $t . '" value="' . $e($C::emailUsuario((int) Session::getLoginUserID())) . '" placeholder="e-mail para o teste">'
        . '<button type="button" class="btn btn-sm btn-outline-secondary" data-teste="' . $t . '"><i class="ti ti-send"></i><span>Enviar teste</span></button></div>';
    echo '</div>';
}
echo '</div></div>';
echo $salvar . Html::closeForm(false);
echo '<div class="modal fade" id="pesquisadesatisfacao-modal-previa" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">'
    . '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-eye"></i> <span data-previa-assunto></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>'
    . '<div class="modal-body p-0"><iframe class="pesquisadesatisfacao-previa" title="Prévia do e-mail" sandbox=""></iframe></div></div></div></div>';
echo '</div>';

// ------------------------------------------------------------------ Alertas
echo '<div data-aba-painel="alertas"' . ($aba !== 'alertas' ? ' hidden' : '') . '>' . $form('salvar_alertas', 'alertas');
echo '<div class="pesquisadesatisfacao-grade-2">';
echo $cartao('ti ti-alert-triangle', 'Avaliação negativa',
    '<p class="text-muted pesquisadesatisfacao-explicacao"><i class="ti ti-info-circle"></i> A avaliação é negativa quando pelo menos uma pergunta recebe "' . $e($rotulos[1]) . '".</p>'
    . $interruptor('alerta_ativo', 'Avisar quando chegar uma avaliação negativa', $C::ligado('alerta_ativo'))
    . $interruptor('alerta_tecnico', 'Enviar o aviso ao técnico do chamado', $C::ligado('alerta_tecnico'))
    . $interruptor('alerta_acompanhamento', 'Registrar as respostas como acompanhamento privado no chamado', $C::ligado('alerta_acompanhamento'), 'O acompanhamento não reabre o chamado.'));
echo $cartao('ti ti-users', 'Também avisar',
    '<div class="pesquisadesatisfacao-campo"><label>Usuários</label>' . $C::multiselect('alerta_usuarios', $C::listarUsuarios(), $C::ids('alerta_usuarios'), 'Ninguém') . '</div>'
    . '<div class="pesquisadesatisfacao-campo"><label for="pesquisadesatisfacao-alerta_emails">Outros e-mails</label>'
    . '<textarea class="form-control form-control-sm" id="pesquisadesatisfacao-alerta_emails" name="alerta_emails" rows="2" placeholder="qualidade@empresa.com.br, gestor@empresa.com.br">' . $e((string) $C::getConfig('alerta_emails')) . '</textarea>'
    . '<small>Separe por vírgula.</small></div>');
echo '</div>' . $salvar . Html::closeForm(false) . '</div>';

// ------------------------------------------------------------------ Acesso
echo '<div data-aba-painel="acesso"' . ($aba !== 'acesso' ? ' hidden' : '') . '>' . $form('salvar_acesso', 'acesso');
echo '<p class="text-muted pesquisadesatisfacao-explicacao"><i class="ti ti-info-circle"></i> Quem pode ver o painel, a aba "Pesquisa de satisfação" nos chamados e as colunas da pesquisa na busca. Administradores (Configuração: atualizar) sempre têm acesso. Qualquer usuário vê as próprias pesquisas pendentes.</p>';
echo '<div class="pesquisadesatisfacao-grade-2">';
echo $cartao('ti ti-id-badge', 'Perfis', $C::multiselect('acesso_perfis', $C::listarPerfis(), $C::ids('acesso_perfis'), 'Nenhum perfil'));
echo $cartao('ti ti-user-check', 'Usuários', $C::multiselect('acesso_usuarios', $C::listarUsuarios(), $C::ids('acesso_usuarios'), 'Nenhum usuário'));
echo '</div>' . $salvar . Html::closeForm(false) . '</div>';

echo '</div>';
Html::footer();
