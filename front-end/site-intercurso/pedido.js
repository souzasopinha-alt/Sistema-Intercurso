const etapaCurso = document.getElementById("etapaCurso");
const etapaPedido = document.getElementById("etapaPedido");
const formCurso = document.getElementById("formCurso");
const fundoTransicao = document.getElementById("fundoTransicao");
const texturaTransicao = document.querySelector(".textura-transicao");
const btnVoltarCurso = document.getElementById("btnVoltarCurso");

const formulario = document.getElementById("formPedido");
const modelo = document.getElementById("modelo");
const numero = document.getElementById("numero");
const tamanho = document.getElementById("tamanho");
const nomeCostas = document.getElementById("nomeCostas");

const erroModelo = document.getElementById("erroModelo");
const erroNumero = document.getElementById("erroNumero");
const erroTamanho = document.getElementById("erroTamanho");
const erroNome = document.getElementById("erroNome");
const mensagemSucesso = document.getElementById("mensagemSucesso");

const imagensPorCurso = {
    Informatica: "img/azul.jpeg",
    Contabilidade: "img/rosa.jpeg",
    Enfermagem: "img/verde.jpeg"
};

let cursoAtual = "";

function definirFundo(imagem) {
    document.body.style.backgroundImage =
        `url("${imagem}"), linear-gradient(#f4f4f2, #f4f4f2)`;
}

function animarTrocaDeFundo(cursoSelecionado, callback) {
    const imagem = imagensPorCurso[cursoSelecionado];

    if (!imagem) {
        callback?.();
        return;
    }

    fundoTransicao.style.backgroundImage = `url("${imagem}")`;

    fundoTransicao.classList.remove("transicao-ativa");
    texturaTransicao.classList.remove("ativa");

    // Reinicia as animações CSS.
    void fundoTransicao.offsetWidth;

    fundoTransicao.classList.add("transicao-ativa");
    texturaTransicao.classList.add("ativa");

    setTimeout(() => {
        definirFundo(imagem);
        fundoTransicao.classList.remove("transicao-ativa");
        texturaTransicao.classList.remove("ativa");
        callback?.();
    }, 1250);
}

function voltarParaSelecaoCurso() {
    fundoTransicao.style.backgroundImage = `url("img/branco.jpeg")`;
    fundoTransicao.classList.remove("transicao-ativa");
    texturaTransicao.classList.remove("ativa");

    void fundoTransicao.offsetWidth;

    fundoTransicao.classList.add("transicao-ativa");
    texturaTransicao.classList.add("ativa");

    setTimeout(() => {
        definirFundo("img/branco.jpeg");
        fundoTransicao.classList.remove("transicao-ativa");
        texturaTransicao.classList.remove("ativa");

        cursoAtual = "";

        etapaPedido.classList.remove("ativa");
        etapaCurso.classList.add("ativa");

        modelo.value = "";
    }, 1250);
}

formCurso.addEventListener("submit", function (event) {
    event.preventDefault();

    const cursoSelecionado =
        document.querySelector("input[name='curso']:checked")?.value;

    if (!cursoSelecionado) {
        return;
    }

    cursoAtual = cursoSelecionado;

    animarTrocaDeFundo(cursoSelecionado, () => {
        etapaCurso.classList.remove("ativa");
        etapaPedido.classList.add("ativa");
        modelo.value = cursoSelecionado;
    });
});

btnVoltarCurso.addEventListener("click", voltarParaSelecaoCurso);

modelo.addEventListener("change", function () {
    const novoCurso = modelo.value;

    if (!novoCurso) {
        cursoAtual = "";
        definirFundo("img/branco.jpeg");
        return;
    }

    if (novoCurso === cursoAtual) {
        return;
    }

    cursoAtual = novoCurso;
    animarTrocaDeFundo(novoCurso);
});

definirFundo("img/branco.jpeg");

formulario.addEventListener("submit", function (event) {
    event.preventDefault();

    erroModelo.textContent = "";
    erroNumero.textContent = "";
    erroTamanho.textContent = "";
    erroNome.textContent = "";
    mensagemSucesso.style.display = "none";

    let formularioValido = true;

    if (modelo.value === "") {
        erroModelo.textContent = "Selecione um curso.";
        formularioValido = false;
    }

    const numeroValor = Number(numero.value);

    if (numero.value === "") {
        erroNumero.textContent = "Digite o número desejado.";
        formularioValido = false;
    } else if (!Number.isInteger(numeroValor) || numeroValor < 1 || numeroValor > 99) {
        erroNumero.textContent = "Digite um número inteiro entre 1 e 99.";
        formularioValido = false;
    }

    if (tamanho.value === "") {
        erroTamanho.textContent = "Selecione um tamanho.";
        formularioValido = false;
    }

    const nome = nomeCostas.value.trim();

    if (nome === "") {
        erroNome.textContent = "Digite o nome que ficará nas costas.";
        formularioValido = false;
    } else if (nome.length < 2) {
        erroNome.textContent = "O nome deve ter pelo menos 2 caracteres.";
        formularioValido = false;
    }

    if (!formularioValido) {
        return;
    }

    mensagemSucesso.innerHTML =
        "Pedido enviado com sucesso!<br>" +
        `Curso: ${modelo.options[modelo.selectedIndex].text}<br>` +
        `Número: ${numero.value}<br>` +
        `Tamanho: ${tamanho.value}<br>` +
        `Nome nas costas: ${nome}`;

    mensagemSucesso.style.display = "block";
});

[
    [modelo, erroModelo],
    [numero, erroNumero],
    [tamanho, erroTamanho],
    [nomeCostas, erroNome]
].forEach(([campo, erro]) => {
    campo.addEventListener("input", () => erro.textContent = "");
    campo.addEventListener("change", () => erro.textContent = "");
});
