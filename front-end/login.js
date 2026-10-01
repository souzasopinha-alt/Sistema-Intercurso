
let formulario = document.getElementById("formLogin");

formulario.addEventListener("submit", function(event) {
    event.preventDefault();

    let email = document.getElementById("email").value;
    let senha = document.getElementById("senha").value;

    let mensagem = document.getElementById("mensagem");

    mensagem.textContent = "Enviando dados...";

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
        return resposta.json();
    })
    .then(function(resultado) {

        if (resultado.sucesso) {

            mensagem.textContent =
                "Login realizado com sucesso!";

            mensagem.className = "sucesso";

            if (resultado.tipo === "ALUNO") {
                window.location.href = "pedido.html";
            }

        } else {

            mensagem.textContent =
                resultado.erro || resultado.mensagem;

            mensagem.className = "erro";
        }

    })
    .catch(function() {

        mensagem.textContent =
            "Não foi possível realizar o login.";

        mensagem.className = "erro";

    });
});
