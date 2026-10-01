let formulario = document.getElementById("formLogin");

formulario.addEventListener("submit", function(event) {
    event.preventDefault();

    let email = document.getElementById("email").value;
    let senha = document.getElementById("senha").value;

    let mensagem = document.getElementById("mensagem");

    mensagem.textContent = "Enviando dados...";
    mensagem.className = "";

    fetch("../backend/login.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: new URLSearchParams({
            email: email,
            senha: senha
        })
    })
    .then(function(resposta) {

        return resposta.text().then(function(texto) {

            console.log("Resposta do servidor:", texto);

            try {
                return JSON.parse(texto);
            } catch (erro) {
                throw new Error(
                    "O servidor não retornou um JSON válido: " + texto
                );
            }

        });

    })
    .then(function(resultado) {

        console.log("Resultado:", resultado);

        if (resultado.sucesso) {

            mensagem.textContent =
                "Login realizado com sucesso!";

            mensagem.className = "sucesso";

            if (resultado.tipo === "ADMIN") {

                window.location.href = "../backend/admin.php";

            } else if (resultado.tipo === "ALUNO") {

                window.location.href = "pedido.html";

            } else if (resultado.tipo === "LIDER") {

                // Por enquanto, permanece na página.
                // Depois podemos criar o painel do líder.

            }

        } else {

            mensagem.textContent =
                resultado.erro ||
                resultado.mensagem ||
                "Não foi possível realizar o login.";

            mensagem.className = "erro";
        }

    })
    .catch(function(erro) {

        console.error("Erro no login:", erro);

        mensagem.textContent =
            "Erro ao realizar o login. Veja o Console do navegador.";

        mensagem.className = "erro";
    });
});