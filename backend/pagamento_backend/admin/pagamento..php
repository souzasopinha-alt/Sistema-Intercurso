<?php
// Atalho: redireciona para a tela de pagamento mantendo o número do pedido (?pedido=123).
$destino = 'Projeto/pagamento.html';
if (isset($_GET['pedido']) && ctype_digit((string) $_GET['pedido'])) {
    $destino .= '?pedido=' . $_GET['pedido'];
}
header('Location: ' . $destino);
exit;
