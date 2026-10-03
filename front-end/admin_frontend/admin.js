/* Admin alinhado ao pedido (pedido (1).html)
   Campos do pedido:
   - curso/modelo: Informatica, Contabilidade, Enfermagem
   - numero: 1 a 99
   - tamanho: PP, P, M, G, GG
   - nome nas costas: até 20 caracteres
*/

const ENDPOINTS = {
    auth: '../../backend/admin_backend/auth/verificar_sessao.php',
    logout: '../../backend/admin_backend/auth/logout.php',
    pedidos: '../../backend/admin_backend/admin_pedidos.php',
    resumo: '../../backend/admin_backend/admin.php',
    votos: '../../backend/admin_backend/admin_votos.php',
    aprovar: '../../backend/pagamentos/aprovar.php',
    recusar: '../../backend/pagamentos/recusar.php',
    usuarios: '../../backend/usuarios.php'
};

let pedidosCache = [];
let pedidoSelecionado = null;

const CURSOS = {
  Informatica: 'Informática',
  Contabilidade: 'Contabilidade',
  Enfermagem: 'Enfermagem'
};

function esc(v) {
  return String(v ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;'
  }[c]));
}

function normalizarPedido(p) {
  return {
    ...p,
    id: p.id,
    aluno: p.aluno ?? '',
    email: p.email ?? '',
    modelo: p.modelo ?? '',
    numero: p.numero ?? '',
    tamanho: p.tamanho ?? '',
    nomeCamisa: p.nome_camisa ?? p.nomeCamisa ?? '',
    status: p.status_pagamento ?? p.status ?? 'PENDENTE',
    data: p.data_pedido ?? p.data ?? '',

    comprovanteId: p.comprovante_id ?? null,
    comprovanteArquivo: p.comprovante_arquivo ?? null,
    comprovanteData: p.comprovante_data ?? null
  };
}

function nomeCurso(v) {
  return CURSOS[v] || v || '—';
}

function nomeStatus(v) {
  return v === 'APROVADO' ? 'Aprovado' : v === 'RECUSADO' ? 'Recusado' : 'Pendente';
}

function statusPill(v) {
  const classe = v === 'APROVADO' ? 'aprovado' : v === 'RECUSADO' ? 'recusado' : 'pendente';
  return `<span class="status-pill status-pill--${classe}">${nomeStatus(v)}</span>`;
}

function formatarData(v) {
  if (!v) return '—';
  const d = String(v).split(' ')[0].split('T')[0];
  const partes = d.split('-');
  return partes.length === 3 ? `${partes[2]}/${partes[1]}/${partes[0]}` : v;
}

async function buscarPedidos() {
  const resp = await fetch(ENDPOINTS.pedidos, {
    credentials: 'include'
  });

  const texto = await resp.text();

  console.log('Status:', resp.status);
  console.log('Resposta de admin_pedidos.php:', texto);

  if (!resp.ok) {
    throw new Error(
      `Erro ${resp.status} ao carregar pedidos: ${texto}`
    );
  }

  let dados;

  try {
    dados = JSON.parse(texto);
  } catch (e) {
    throw new Error(
      'admin_pedidos.php não retornou JSON válido. Veja a resposta no Console.'
    );
  }

  pedidosCache = Array.isArray(dados)
    ? dados.map(normalizarPedido)
    : [];

  return pedidosCache;
}

async function atualizarPedidos() {
  try {
    await buscarPedidos();
  } catch (e) {
    console.error(e);
    pedidosCache = [];
    mostrarToast('Não foi possível carregar os pedidos.');
  }

  renderPedidos();
  renderPendentes();
  atualizarResumoLocal();
  atualizarRelatorio();
}


async function atualizarRelatorio() {
  try {
    const resp = await fetch(ENDPOINTS.resumo, {
      credentials: 'include'
    });

    if (!resp.ok) {
      throw new Error('Erro ao carregar relatório.');
    }

    const dados = await resp.json();

    const reportModelo = document.getElementById('reportModelo');
    const reportTamanho = document.getElementById('reportTamanho');
    const reportStatus = document.getElementById('reportStatus');

    // ==============================
    // POR CURSO
    // ==============================

    reportModelo.innerHTML = (dados.por_modelo || []).map(item => {
  const curso = String(item.modelo || '').toLowerCase();

  let classeCurso = 'curso-outro';

  if (curso === 'informatica') {
    classeCurso = 'curso-informatica';
  } else if (curso === 'contabilidade') {
    classeCurso = 'curso-contabilidade';
  } else if (curso === 'enfermagem') {
    classeCurso = 'curso-enfermagem';
  }

  return `
    <li class="${classeCurso}">
      <span>${esc(nomeCurso(item.modelo))}</span>
      <strong>${esc(item.quantidade)}</strong>
    </li>
  `;
}).join('');

    // ==============================
    // POR TAMANHO
    // ==============================

    reportTamanho.innerHTML = (dados.por_tamanho || []).map(item => `
      <li>
        <span>${esc(item.tamanho || '—')}</span>
        <strong>${esc(item.quantidade)}</strong>
      </li>
    `).join('');

    // ==============================
    // POR STATUS
    // ==============================

    const nomesStatus = {
      PENDENTE: 'Pendente',
      APROVADO: 'Aprovado',
      RECUSADO: 'Recusado'
    };

    reportStatus.innerHTML = (dados.por_status || []).map(item => `
      <li>
        <span>${esc(
          nomesStatus[item.status_pagamento] || item.status_pagamento
        )}</span>
        <strong>${esc(item.quantidade)}</strong>
      </li>
    `).join('');

  } catch (e) {
    console.error('Erro ao atualizar relatório:', e);

    document.getElementById('reportModelo').innerHTML =
      '<li>Não foi possível carregar.</li>';

    document.getElementById('reportTamanho').innerHTML =
      '<li>Não foi possível carregar.</li>';

    document.getElementById('reportStatus').innerHTML =
      '<li>Não foi possível carregar.</li>';
  }
}

function filtrarPedidos() {
  const status = document.getElementById('filtroStatus').value;
  const curso = document.getElementById('filtroModelo').value;
  const tamanho = document.getElementById('filtroTamanho').value;

  return pedidosCache.filter(p =>
    (!status || p.status === status) &&
    (!curso || p.modelo === curso) &&
    (!tamanho || p.tamanho === tamanho)
  );
}

function renderPedidos() {
  const tbody = document.getElementById('tabelaPedidos');
  const lista = filtrarPedidos();

  if (!lista.length) {
    tbody.innerHTML = '<tr><td colspan="8" class="table__empty">Nenhum pedido encontrado.</td></tr>';
    return;
  }

  tbody.innerHTML = lista.map(p => `
    <tr>
      <td>
        <strong>${esc(p.aluno || '—')}</strong>
        ${p.email ? `<span class="table-email">${esc(p.email)}</span>` : ''}
      </td>
      <td>${esc(nomeCurso(p.modelo))}</td>
      <td>${esc(p.numero || '—')}</td>
      <td>${esc(p.tamanho || '—')}</td>
      <td>${esc(p.nomeCamisa || '—')}</td>
      <td>${statusPill(p.status)}</td>
      <td>${esc(formatarData(p.data))}</td>
      <td>
        <div class="order-actions">
          ${p.status === 'PENDENTE'
            ? `<button class="btn btn--danger btn--small" data-recusar="${esc(p.id)}">Recusar</button>
               <button class="btn btn--success btn--small" data-aprovar="${esc(p.id)}">Aceitar</button>`
            : `<span class="action-muted">Sem ações</span>`}
        </div>
      </td>
    </tr>
  `).join('');

  tbody.querySelectorAll('[data-aprovar]').forEach(b =>
    b.addEventListener('click', () => abrirConfirmacao(b.dataset.aprovar, 'APROVADO'))
  );
  tbody.querySelectorAll('[data-recusar]').forEach(b =>
    b.addEventListener('click', () => abrirConfirmacao(b.dataset.recusar, 'RECUSADO'))
  );
}

function renderPendentes() {
  const box = document.getElementById('listaPagamentos');
  const lista = pedidosCache.filter(p => p.status === 'PENDENTE');

  if (!lista.length) {
    box.innerHTML = '<p class="table__empty">Não há pedidos pendentes.</p>';
    return;
  }

  box.innerHTML = lista.map(p => `
    <article class="payment-card">
      <div class="payment-card__student">${esc(p.aluno || 'Aluno não informado')}</div>
      <div class="payment-card__row"><span>Curso / modelo</span><strong>${esc(nomeCurso(p.modelo))}</strong></div>
      <div class="payment-card__row"><span>Número</span><strong>${esc(p.numero || '—')}</strong></div>
      <div class="payment-card__row"><span>Tamanho</span><strong>${esc(p.tamanho || '—')}</strong></div>
      <div class="payment-card__row"><span>Nome nas costas</span><strong>${esc(p.nomeCamisa || '—')}</strong></div>
      <div class="payment-card__row"><span>Data</span><strong>${esc(formatarData(p.data))}</strong></div>
      <div class="payment-card__actions">
        <button class="btn btn--danger" data-recusar="${esc(p.id)}">Recusar</button>
        <button class="btn btn--success" data-aprovar="${esc(p.id)}">Aceitar</button>
      </div>
    </article>
  `).join('');

  box.querySelectorAll('[data-aprovar]').forEach(b =>
    b.addEventListener('click', () => abrirConfirmacao(b.dataset.aprovar, 'APROVADO'))
  );
  box.querySelectorAll('[data-recusar]').forEach(b =>
    b.addEventListener('click', () => abrirConfirmacao(b.dataset.recusar, 'RECUSADO'))
  );
}

function atualizarResumoLocal() {
  const total = pedidosCache.length;
  const pend = pedidosCache.filter(p => p.status === 'PENDENTE').length;
  const aprov = pedidosCache.filter(p => p.status === 'APROVADO').length;
  const rec = pedidosCache.filter(p => p.status === 'RECUSADO').length;
  document.getElementById('sumTotalPedidos').textContent = total;
  document.getElementById('sumPendentes').textContent = pend;
  document.getElementById('sumAprovados').textContent = aprov;
  document.getElementById('sumRecusados').textContent = rec;
}

function abrirConfirmacao(id, acao) {
  const p = pedidosCache.find(x => String(x.id) === String(id));
  if (!p) return;

  pedidoSelecionado = { id: p.id, acao };
  const comprovanteUrl = p.comprovanteId
  ? `../../backend/pagamentos/visualizar_comprovante.php?id=${encodeURIComponent(p.comprovanteId)}`
  : null;

let comprovanteHtml = '';

if (!p.comprovanteId) {
  comprovanteHtml = `
    <div class="comprovante-modal">
      <h3>Comprovante de pagamento</h3>
      <p class="comprovante-modal__vazio">
        Nenhum comprovante enviado.
      </p>
    </div>
  `;
} else if (
  p.comprovanteArquivo &&
  /\.(jpg|jpeg|png|webp)$/i.test(p.comprovanteArquivo)
) {
  comprovanteHtml = `
    <div class="comprovante-modal">
      <h3>Comprovante de pagamento</h3>

      <div class="comprovante-modal__visualizacao">
        <img
          src="${comprovanteUrl}"
          alt="Comprovante de pagamento"
          class="comprovante-modal__imagem"
        >
      </div>
    </div>
  `;
} else if (
  p.comprovanteArquivo &&
  /\.pdf$/i.test(p.comprovanteArquivo)
) {
  comprovanteHtml = `
    <div class="comprovante-modal">
      <h3>Comprovante de pagamento</h3>

      <div class="comprovante-modal__visualizacao">
        <iframe
          src="${comprovanteUrl}"
          class="comprovante-modal__pdf"
          title="Comprovante de pagamento"
        ></iframe>
      </div>
    </div>
  `;
} else {
  comprovanteHtml = `
    <div class="comprovante-modal">
      <h3>Comprovante de pagamento</h3>
      <p>
        <a
          href="${comprovanteUrl}"
          target="_blank"
          rel="noopener noreferrer"
        >
          Abrir comprovante
        </a>
      </p>
    </div>
  `;
}

document.getElementById('pagamentoDetalhe').innerHTML = `
  <div class="payment-card__row">
    <span>Aluno: </span>
    <strong>${esc(p.aluno || '—')}</strong>
  </div>

  <div class="payment-card__row">
    <span>E-mail: </span>
    <strong>${esc(p.email || '—')}</strong>
  </div>

  <div class="payment-card__row">
    <span>Curso / modelo: </span>
    <strong>${esc(nomeCurso(p.modelo))}</strong>
  </div>

  <div class="payment-card__row">
    <span>Número: </span>
    <strong>${esc(p.numero || '—')}</strong>
  </div>

  <div class="payment-card__row">
    <span>Tamanho: </span>
    <strong>${esc(p.tamanho || '—')}</strong>
  </div>

  <div class="payment-card__row">
    <span>Nome nas costas: </span>
    <strong>${esc(p.nomeCamisa || '—')}</strong>
  </div>

  <div class="payment-card__row">
    <span>Status atual: </span>
    <strong>${statusPill(p.status)}</strong>
  </div>

  <div class="payment-card__row">
    <span>Data do pedido: </span>
    <strong>${esc(formatarData(p.data))}</strong>
  </div>

  ${comprovanteHtml}
`;

  document.getElementById('btnAprovarModal').style.display = acao === 'APROVADO' ? '' : 'none';
  document.getElementById('btnRecusarModal').style.display = acao === 'RECUSADO' ? '' : 'none';
  abrirModal();
}

function abrirModal() {
  const m = document.getElementById('modalPagamento');
  m.classList.add('is-open');
  m.setAttribute('aria-hidden', 'false');
}

function fecharModal() {
  const m = document.getElementById('modalPagamento');
  m.classList.remove('is-open');
  m.setAttribute('aria-hidden', 'true');
  pedidoSelecionado = null;
}

async function enviarDecisao(acao) {
  if (!pedidoSelecionado) return;
  const { id } = pedidoSelecionado;
  const endpoint = acao === 'APROVADO' ? ENDPOINTS.aprovar : ENDPOINTS.recusar;

  try {
    const resp = await fetch(endpoint, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id })
    });

    if (!resp.ok) throw new Error('Falha ao atualizar o pedido.');

    fecharModal();
    await atualizarPedidos();
    mostrarToast(acao === 'APROVADO' ? 'Pedido aceito.' : 'Pedido recusado.');
  } catch (e) {
    console.error(e);
    mostrarToast('Não foi possível atualizar o pedido.');
  }
}

async function carregarUsuarios() {
  const tbody = document.getElementById('tabelaUsuarios');
  try {
    const resp = await fetch(ENDPOINTS.usuarios, { credentials: 'include' });
    if (!resp.ok) throw new Error();
    const dados = await resp.json();

    if (!Array.isArray(dados) || !dados.length) {
      tbody.innerHTML = '<tr><td colspan="4" class="table__empty">Nenhum usuário cadastrado.</td></tr>';
      return;
    }

    tbody.innerHTML = dados.map(u => `
      <tr>
        <td>${esc(u.nome)}</td>
        <td>${esc(u.email)}</td>
        <td>${esc(u.tipo)}</td>
        <td>${esc(u.situacao)}</td>
      </tr>
    `).join('');
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="4" class="table__empty">Não foi possível carregar os usuários.</td></tr>';
  }
}


/* ============ VOTAÇÃO ============ */
async function carregarVotos() {
  const tbody = document.getElementById('tabelaVotos');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="2" class="table__empty">Carregando votos...</td></tr>';

  try {
    const resp = await fetch(ENDPOINTS.votos, {
      credentials: 'include',
      cache: 'no-store'
    });
    const dados = await resp.json();

    if (!resp.ok || !dados.success) {
      throw new Error(dados.message || 'Não foi possível carregar os votos.');
    }

    if (!Array.isArray(dados.opcoes) || !dados.opcoes.length) {
      tbody.innerHTML = '<tr><td colspan="2" class="table__empty">Nenhuma opção encontrada.</td></tr>';
      return;
    }

    tbody.innerHTML = dados.opcoes.map(v => `
      <tr>
        <td><strong>${esc(v.nome || `Opção ${v.numero}`)}</strong></td>
        <td><strong>${Number(v.total_votos || 0)}</strong></td>
      </tr>
    `).join('');
  } catch (e) {
    console.error(e);
    tbody.innerHTML =
      '<tr><td colspan="2" class="table__empty">' +
      esc(e.message || 'Não foi possível carregar os votos.') +
      '</td></tr>';
  }
}

function renderRelatorio() {
  const contar = campo => pedidosCache.reduce((acc, p) => {
    const v = p[campo] || 'Não informado';
    acc[v] = (acc[v] || 0) + 1;
    return acc;
  }, {});

  const preencher = (id, dados, transform = x => x) => {
    const el = document.getElementById(id);
    el.innerHTML = Object.entries(dados).map(([k,v]) =>
      `<li><span>${esc(transform(k))}</span><strong>${v}</strong></li>`
    ).join('') || '<li><span>Sem dados</span></li>';
  };

  preencher('reportModelo', contar('modelo'), nomeCurso);
  preencher('reportTamanho', contar('tamanho'));
  preencher('reportStatus', contar('status'), nomeStatus);
}

async function protegerPainel() {
  try {
    const resp = await fetch(ENDPOINTS.auth, { credentials: 'include' });
    if (!resp.ok) throw new Error();
    const d = await resp.json();
    if (!d.logado || !d.admin) {
      window.location.href = '../login.html';
      return;
    }
    document.getElementById('userName').textContent = d.nome || 'Administrador';
    document.getElementById('userRole').textContent = 'Administrador';
  } catch (e) {
    document.getElementById('userName').textContent = 'Administrador';
    document.getElementById('userRole').textContent = 'Administrador';
  }
}

async function sair() {
  try {
    await fetch(ENDPOINTS.logout, { method:'POST', credentials:'include' });
  } finally {
    window.location.href = '../login.html';
  }
}

function mostrarToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.classList.add('is-visible');
  setTimeout(() => t.classList.remove('is-visible'), 2600);
}

function configurar() {
  document.querySelectorAll('.sidebar__link').forEach(link => {
    link.addEventListener('click', async () => {
      document.querySelectorAll('.sidebar__link').forEach(x => x.classList.remove('is-active'));
      link.classList.add('is-active');
      document.querySelectorAll('.view').forEach(x => x.classList.remove('is-active'));
      document.getElementById(`view-${link.dataset.view}`).classList.add('is-active');

      if (link.dataset.view === 'pedidos' || link.dataset.view === 'pendentes') await atualizarPedidos();
      if (link.dataset.view === 'usuarios') await carregarUsuarios();
      if (link.dataset.view === 'relatorio') { await atualizarPedidos(); renderRelatorio(); }
    });
  });

  ['filtroStatus','filtroModelo','filtroTamanho'].forEach(id =>
    document.getElementById(id).addEventListener('change', renderPedidos)
  );

  document.getElementById('btnLimparFiltros').addEventListener('click', () => {
    document.getElementById('filtroStatus').value = '';
    document.getElementById('filtroModelo').value = '';
    document.getElementById('filtroTamanho').value = '';
    renderPedidos();
  });

  document.getElementById('btnLogout').addEventListener('click', sair);
  document.getElementById('btnLogoutSide').addEventListener('click', sair);

  document.querySelectorAll('[data-close-modal]').forEach(el =>
    el.addEventListener('click', fecharModal)
  );

  document.getElementById('btnAprovarModal').addEventListener('click', () => enviarDecisao('APROVADO'));
  document.getElementById('btnRecusarModal').addEventListener('click', () => enviarDecisao('RECUSADO'));
}

document.addEventListener('DOMContentLoaded', async () => {
  configurar();
  await protegerPainel();
  await atualizarPedidos();
  renderRelatorio();
});

document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('btnAtualizarVotos');
  if (btn) btn.addEventListener('click', carregarVotos);
});
