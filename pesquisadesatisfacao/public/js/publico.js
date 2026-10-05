/**
 * Plugin Pesquisa de Satisfação - página pública: mostra a justificativa quando faz sentido,
 * valida antes de enviar (o servidor valida de novo) e evita envio duplo.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-pesquisadesatisfacao-publico]');
    if (!form) { return; }

    function valor(pergunta) {
        var marcado = pergunta.querySelector('input[type=radio]:checked');
        return marcado ? parseInt(marcado.value, 10) : 0;
    }

    function atualizar(pergunta) {
        var v = valor(pergunta);
        pergunta.querySelectorAll('.pesquisadesatisfacao-pub-opcao').forEach(function (o) {
            o.classList.toggle('selecionada', o.querySelector('input').checked);
        });
        var bloco = pergunta.querySelector('[data-justificativa]');
        if (!bloco) { return; }
        var regra = pergunta.getAttribute('data-regra');
        var area = bloco.querySelector('textarea');
        var rotulo = bloco.querySelector('label');
        var obrigatoria = regra === 'ruim' && v === 1;
        bloco.hidden = !(obrigatoria || (regra === 'opcional' && v > 0) || (regra === 'ruim' && area.value.trim() !== '' && v > 0));
        rotulo.textContent = obrigatoria ? rotulo.getAttribute('data-rotulo-obrigatorio') : rotulo.getAttribute('data-rotulo-opcional');
        area.required = obrigatoria;
    }

    function erro(pergunta, mensagem) {
        var p = pergunta.querySelector('.pesquisadesatisfacao-pub-msg-erro');
        if (!mensagem) {
            pergunta.classList.remove('com-erro');
            if (p) { p.remove(); }
            return;
        }
        pergunta.classList.add('com-erro');
        if (!p) {
            p = document.createElement('p');
            p.className = 'pesquisadesatisfacao-pub-msg-erro';
            pergunta.appendChild(p);
        }
        p.textContent = mensagem;
    }

    var perguntas = Array.prototype.slice.call(form.querySelectorAll('.pesquisadesatisfacao-pub-pergunta'));
    perguntas.forEach(function (pergunta) {
        atualizar(pergunta);
        pergunta.addEventListener('change', function (ev) {
            if (ev.target.type === 'radio') {
                atualizar(pergunta);
                erro(pergunta, '');
                var area = pergunta.querySelector('[data-justificativa]:not([hidden]) textarea[required]');
                if (area) { area.focus(); }
            }
        });
        pergunta.addEventListener('input', function (ev) {
            if (ev.target.tagName === 'TEXTAREA' && ev.target.value.trim() !== '') { erro(pergunta, ''); }
        });
    });

    form.addEventListener('submit', function (ev) {
        var primeira = null;
        perguntas.forEach(function (pergunta) {
            var v = valor(pergunta);
            var area = pergunta.querySelector('[data-justificativa] textarea');
            var mensagem = '';
            if (!v) {
                mensagem = 'Escolha uma resposta.';
            } else if (area && area.required && area.value.trim() === '') {
                mensagem = 'Conte o motivo desta resposta.';
            }
            erro(pergunta, mensagem);
            if (mensagem && !primeira) { primeira = pergunta; }
        });
        if (primeira) {
            ev.preventDefault();
            primeira.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        var botao = form.querySelector('.pesquisadesatisfacao-pub-botao');
        if (botao) {
            botao.disabled = true;
            botao.textContent = 'Enviando...';
        }
    });
}());
