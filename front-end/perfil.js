const mensagem = document.getElementById("mensagem");
const listaPedidos = document.getElementById("listaPedidos");

const campoNome = document.getElementById("nome");
const campoEmail = document.getElementById("email");
const campoCurso = document.getElementById("curso");
const campoSerie = document.getElementById("serie");

const nomesStatus = {
    PENDENTE: "Pendente",
    APROVADO: "Aprovado",
    RECUSADO: "Recusado"
};

const classesStatus = {
    PENDENTE: "status-pendente",
    APROVADO: "status-aprovado",
    RECUSADO: "status-recusado"
};

function criarElemento(tag, classe, texto) {
    const elemento = document.createElement(tag);

    if (classe) {
        elemento.className = classe;
    }

    if (texto !== undefined && texto !== null) {
        elemento.textContent = String(texto);
    }

    return elemento;
}

function formatarData(data) {
    if (!data) {
        return "Não informada";
    }

    const dataValida = new Date(String(data).replace(" ", "T"));

    if (Number.isNaN(dataValida.getTime())) {
        return String(data);
    }

    return dataValida.toLocaleString("pt-BR", {
        dateStyle: "short",
        timeStyle: "short"
    });
}

function criarDadoPedido(titulo, valor) {
    const dado = criarElemento("div", "pedido-dado");

    dado.append(
        criarElemento("span", "", titulo),
        criarElemento(
            "strong",
            "",
            valor === null || valor === undefined || valor === ""
                ? "Não informado"
                : valor
        )
    );

    return dado;
}

function criarCartaoPedido(pedido, indice, podeSubstituir) {
    const cartao = criarElemento("article", "pedido");
    const cabecalho = criarElemento("div", "pedido-cabecalho");
    const identificacao = criarElemento("div", "");

    const status = pedido.status_pagamento || "PENDENTE";

    identificacao.append(
        criarElemento("p", "pedido-numero", `Pedido ${indice}`),
        criarElemento("h3", "", pedido.modelo || "Fardamento")
    );

    const etiqueta = criarElemento(
        "span",
        `status ${classesStatus[status] || "status-pendente"}`,
        nomesStatus[status] || "Em análise"
    );

    cabecalho.append(identificacao, etiqueta);

    const dados = criarElemento("div", "pedido-dados");

    [
        ["Número", pedido.numero],
        ["Tamanho", pedido.tamanho],
        ["Nome na camisa", pedido.nome_camisa],
        ["Data do pedido", formatarData(pedido.data_pedido)],
        ["Método de pagamento", pedido.metodo_pagamento]
    ].forEach(([titulo, valor]) => {
        dados.appendChild(criarDadoPedido(titulo, valor));
    });

    cartao.append(cabecalho, dados);

    if (status === "RECUSADO" && podeSubstituir) {
        const aviso = criarElemento(
            "p",
            "pedido-aviso-recusado",
            "Este pedido foi recusado. Clique em \"Fazer novamente\" para enviar um novo."
        );

        const acoes = criarElemento("div", "pedido-acoes-recusado");

        const botao = criarElemento(
            "button",
            "botao-primario",
            "Fazer novamente"
        );
        botao.type = "button";
        botao.addEventListener("click", () => {
            window.location.href = `pedido.html?substituir=${encodeURIComponent(pedido.id)}`;
        });

        acoes.appendChild(botao);
        cartao.append(aviso, acoes);
    }

    return cartao;
}

function criarBotaoNovoPedido(texto) {
    const botao = criarElemento("button", "botao-novo-pedido", texto);
    botao.type = "button";
    botao.addEventListener("click", () => {
        window.location.href = "pedido.html";
    });
    return botao;
}

function mostrarPedidos(pedidos, pedidosAbertos, podeCriarPedido) {
    listaPedidos.replaceChildren();

    const lista = Array.isArray(pedidos) ? pedidos : [];

    if (lista.length === 0) {
        const vazio = criarElemento("div", "vazio");

        vazio.append(
            criarElemento("h3", "", "Você ainda não tem um pedido"),
            criarElemento(
                "p",
                "",
                "Quando você fizer um pedido de fardamento, as informações aparecerão aqui."
            )
        );

        if (pedidosAbertos && podeCriarPedido) {
            vazio.appendChild(criarBotaoNovoPedido("Fazer um pedido"));
        }

        listaPedidos.appendChild(vazio);
        return;
    }

    lista.forEach((pedido, indice) => {
        const podeSubstituir = pedidosAbertos && indice === 0;
        listaPedidos.appendChild(criarCartaoPedido(pedido, indice + 1, podeSubstituir));
    });

    if (pedidosAbertos && podeCriarPedido) {
        const barra = criarElemento("div", "pedidos-acoes");
        barra.appendChild(criarBotaoNovoPedido("+ Fazer mais um pedido"));
        listaPedidos.appendChild(barra);
    }
}

async function carregarPerfil() {
    mensagem.textContent = "Carregando seu perfil...";
    mensagem.className = "mensagem";

    try {
        const resposta = await fetch("../backend/perfil_aluno.php", {
            method: "GET",
            credentials: "same-origin",
            headers: {
                Accept: "application/json"
            }
        });

        const resultado = await resposta.json();

        if (resposta.status === 401) {
            window.location.replace("login.html");
            return;
        }

        if (!resposta.ok || !resultado.sucesso) {
            throw new Error(
                resultado.erro || "Não foi possível carregar seu perfil."
            );
        }

        const usuario = resultado.usuario;

        campoNome.textContent = usuario.nome || "Não informado";
        campoEmail.textContent = usuario.email || "Não informado";
        campoCurso.textContent = usuario.curso || "Não informado";
        campoSerie.textContent = usuario.serie || "Não informada";

        mostrarPedidos(
            resultado.pedidos,
            resultado.pedidos_abertos === true,
            resultado.pode_criar_pedido === true
        );

        mensagem.textContent = "";
        mensagem.className = "mensagem";

    } catch (erro) {
        console.error("Erro ao carregar perfil:", erro);

        mensagem.textContent =
            erro.message || "Erro ao carregar o perfil. Tente novamente.";

        mensagem.className = "mensagem erro";
    }
}

document.getElementById("btnSair").addEventListener("click", async function () {
    this.disabled = true;

    try {
        const resposta = await fetch("../backend/logout.php", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json"
            }
        });

        if (!resposta.ok) {
            throw new Error("Falha ao encerrar a sessão.");
        }

        window.location.replace("login.html");

    } catch (erro) {
        console.error("Erro ao sair:", erro);

        mensagem.textContent = "Não foi possível sair. Tente novamente.";
        mensagem.className = "mensagem erro";

        this.disabled = false;
    }
});

carregarPerfil();
