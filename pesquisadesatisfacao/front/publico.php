<?php

/**
 * Plugin Pesquisa de Satisfação - página pública (sem login e sem sessão):
 *   ?t=TOKEN   responder a pesquisa
 *   ?logo=N    logo configurado (usado na página e nos e-mails)
 */

// Carregado pelo GLPI 11/12 sem autenticação (Firewall NO_CHECK + caminho stateless no setup.php)

global $DB;
$C = PluginPesquisadesatisfacaoConfig::class;
$P = PluginPesquisadesatisfacaoPesquisa::class;
$e = [$C, 'e'];

// ------------------------------------------------------------------ logo
if (isset($_GET['logo'])) {
    $caminho = $C::caminhoLogo();
    if ($caminho === '') {
        http_response_code(404);
        exit;
    }
    $tipo = str_ends_with($caminho, '.svg') ? 'image/svg+xml' : ((new finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: 'image/png');
    header('Content-Type: ' . $tipo);
    header('Content-Length: ' . filesize($caminho));
    header('Cache-Control: public, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    if ($tipo === 'image/svg+xml') {
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
    }
    readfile($caminho);
    exit;
}

// Sem sessão: nome usado no histórico do GLPI quando o plugin fecha o chamado
if (!isset($_SESSION) || !is_array($_SESSION)) {
    $_SESSION = [];
}
$_SESSION['glpiname'] = $_SESSION['glpiname'] ?? 'Pesquisa de satisfação';

header('X-Robots-Tag: noindex, nofollow');

$token = trim((string) ($_POST['t'] ?? $_GET['t'] ?? ''));
$erros = [];
$valores = [];
$justificativas = [];
$comentario = '';
$respondidaAgora = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['acao'] ?? '') === 'responder') {
    foreach ((array) ($_POST['valor'] ?? []) as $pid => $v) {
        $valores[(int) $pid] = (int) $v;
    }
    foreach ((array) ($_POST['justificativa'] ?? []) as $pid => $v) {
        $justificativas[(int) $pid] = (string) $v;
    }
    $comentario = (string) ($_POST['comentario'] ?? '');
    $resultado = $P::responder($token, $valores, $justificativas, $comentario);
    $respondidaAgora = $resultado['ok'];
    $erros = $resultado['erros'];
}

$pesquisa = $P::porToken($token);
$titulo = '';
if ($pesquisa !== null) {
    foreach ($DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => (int) $pesquisa['tickets_id']], 'LIMIT' => 1]) as $r) {
        $titulo = (string) $r['name'];
    }
}

$org = $C::texto('org_nome');
$logo = $C::urlLogo();
$paginaTitulo = $C::texto('pagina_titulo');
$rotulos = $C::rotulos();

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo $e($paginaTitulo . ($org !== '' ? ' - ' . $org : '')); ?></title>
    <link rel="stylesheet" href="<?php echo $e($C::urlAsset('public/css/pesquisadesatisfacao.css')); ?>">
</head>
<body class="pesquisadesatisfacao-publico">
<main class="pesquisadesatisfacao-pub">
    <?php if ($logo !== '' || $org !== '') { ?>
    <header class="pesquisadesatisfacao-pub-marca">
        <?php if ($logo !== '') { ?><img src="<?php echo $e($logo); ?>" alt="<?php echo $e($org); ?>"><?php } ?>
        <?php if ($org !== '') { ?><span><?php echo $e($org); ?></span><?php } ?>
    </header>
    <?php } ?>

    <section class="pesquisadesatisfacao-pub-card">
<?php
$icone = function (string $nome): string {
    $caminhos = [
        'ok'     => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        'aviso'  => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>',
        'relogio' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
    return '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($caminhos[$nome] ?? '') . '</svg>';
};
$chamado = $pesquisa === null ? '' : '<p class="pesquisadesatisfacao-pub-chamado">Chamado #' . (int) $pesquisa['tickets_id'] . ($titulo !== '' ? ' — ' . $e($titulo) : '') . '</p>';

if ($pesquisa === null) {
    echo '<div class="pesquisadesatisfacao-pub-final pesquisadesatisfacao-tom-aviso">' . $icone('aviso')
        . '<h1>Pesquisa não encontrada</h1><p>O link não é válido. Se você o recebeu por e-mail, confira se ele foi copiado por completo.</p></div>';
} elseif ($respondidaAgora) {
    echo '<div class="pesquisadesatisfacao-pub-final pesquisadesatisfacao-tom-ok">' . $icone('ok') . '<h1>Obrigado!</h1>' . $chamado
        . '<p>' . $e($C::texto('pagina_agradecimento')) . '</p></div>';
} elseif ((int) $pesquisa['status'] === $P::RESPONDIDA) {
    echo '<div class="pesquisadesatisfacao-pub-final pesquisadesatisfacao-tom-ok">' . $icone('ok') . '<h1>Pesquisa já respondida</h1>' . $chamado
        . '<p>Esta pesquisa foi respondida em ' . $e(date('d/m/Y', strtotime((string) $pesquisa['data_resposta']))) . '. Obrigado pela participação!</p></div>';
} elseif ((int) $pesquisa['status'] === $P::ENCERRADA) {
    echo '<div class="pesquisadesatisfacao-pub-final pesquisadesatisfacao-tom-neutro">' . $icone('relogio') . '<h1>Pesquisa encerrada</h1>' . $chamado
        . '<p>O prazo para responder terminou e o link não é mais válido. Se quiser relatar algo sobre o atendimento, abra um novo chamado.</p></div>';
} else {
    $perguntas = PluginPesquisadesatisfacaoPergunta::listar();
    echo '<div class="pesquisadesatisfacao-pub-cabecalho"><h1>' . $e($paginaTitulo) . '</h1>' . $chamado
        . '<p>' . $e($C::texto('pagina_texto')) . '</p></div>';
    if (isset($erros['geral'])) {
        echo '<div class="pesquisadesatisfacao-pub-erro" role="alert">' . $e($erros['geral']) . '</div>';
    } elseif ($erros) {
        echo '<div class="pesquisadesatisfacao-pub-erro" role="alert">Confira as perguntas destacadas abaixo.</div>';
    }
    echo '<form method="post" action="' . $e($C::url('publico.php')) . '" class="pesquisadesatisfacao-pub-form" data-pesquisadesatisfacao-publico novalidate>';
    echo '<input type="hidden" name="acao" value="responder"><input type="hidden" name="t" value="' . $e($token) . '">';
    $n = 0;
    foreach ($perguntas as $pid => $p) {
        $n++;
        $valor = $valores[$pid] ?? 0;
        $regra = (string) $p['justificativa'];
        echo '<fieldset class="pesquisadesatisfacao-pub-pergunta' . (isset($erros[$pid]) ? ' com-erro' : '') . '" data-regra="' . $e($regra) . '">';
        echo '<legend><span class="pesquisadesatisfacao-pub-numero">' . $n . '</span>' . $e($p['texto']) . '</legend>';
        echo '<div class="pesquisadesatisfacao-pub-opcoes">';
        foreach ([1, 2, 3] as $v) {
            echo '<label class="pesquisadesatisfacao-pub-opcao pesquisadesatisfacao-valor-' . $v . '">'
                . '<input type="radio" name="valor[' . (int) $pid . ']" value="' . $v . '"' . ($valor === $v ? ' checked' : '') . ' required>'
                . PluginPesquisadesatisfacaoPergunta::carinha($v, 44) . '<span>' . $e($rotulos[$v]) . '</span></label>';
        }
        echo '</div>';
        if ($regra !== 'nao') {
            $obrigatoria = $regra === 'ruim';
            echo '<div class="pesquisadesatisfacao-pub-justificativa" data-justificativa>'
                . '<label for="pesquisadesatisfacao-just-' . (int) $pid . '" data-rotulo-opcional="Quer contar mais? (opcional)" data-rotulo-obrigatorio="Conte o que aconteceu (obrigatório)">'
                . ($obrigatoria ? 'Se a resposta for "' . $e($rotulos[1]) . '", conte o que aconteceu' : 'Quer contar mais? (opcional)') . '</label>'
                . '<textarea id="pesquisadesatisfacao-just-' . (int) $pid . '" name="justificativa[' . (int) $pid . ']" rows="3" maxlength="2000">' . $e($justificativas[$pid] ?? '') . '</textarea></div>';
        }
        if (isset($erros[$pid])) {
            echo '<p class="pesquisadesatisfacao-pub-msg-erro">' . $e($erros[$pid]) . '</p>';
        }
        echo '</fieldset>';
    }
    if ($C::ligado('comentario_final')) {
        echo '<div class="pesquisadesatisfacao-pub-comentario"><label for="pesquisadesatisfacao-comentario">Algum comentário ou sugestão? (opcional)</label>'
            . '<textarea id="pesquisadesatisfacao-comentario" name="comentario" rows="3" maxlength="2000">' . $e($comentario) . '</textarea></div>';
    }
    echo '<div class="pesquisadesatisfacao-pub-enviar"><button type="submit" class="pesquisadesatisfacao-pub-botao">Enviar respostas</button></div>';
    echo '</form>';
}
?>
    </section>
    <p class="pesquisadesatisfacao-pub-rodape">Suas respostas são usadas apenas para melhorar o atendimento.</p>
</main>
<script src="<?php echo $e($C::urlAsset('public/js/publico.js')); ?>"></script>
</body>
</html>
<?php
exit;
