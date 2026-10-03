<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

const SUPABASE_URL = 'https://ecjnysmrbylbpibsibzh.supabase.co';

function resposta(bool $ok, string $message = '', int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['success'=>$ok, 'message'=>$message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
function supabaseGet(string $url, string $key): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>[
        'apikey: '.$key, 'Authorization: Bearer '.$key, 'Accept: application/json'
    ], CURLOPT_TIMEOUT=>30]);
    $body=curl_exec($ch); $err=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($body===false) throw new RuntimeException($err ?: 'Erro de conexão com o Supabase.');
    return [$status,$body];
}
function buscarOpcoes(string $key): array {
    [$status,$body]=supabaseGet(SUPABASE_URL.'/rest/v1/opcoes?select=id,imagem1,imagem2,imagem3&aprovado=eq.true&order=id.asc',$key);
    if($status<200||$status>=300) throw new RuntimeException('Não foi possível carregar as opções do Supabase.');
    $rows=json_decode($body,true); return is_array($rows)?$rows:[];
}

if (empty($_SESSION['usuario_id'])) resposta(false,'Você precisa estar logado para votar.',401);
if (!isset($pdo) || !($pdo instanceof PDO)) resposta(false,'Conexão com o MariaDB não encontrada.',500);
$key=getenv('SUPABASE_SERVICE_ROLE_KEY');
if(!$key) resposta(false,'SUPABASE_SERVICE_ROLE_KEY não está configurada no servidor.',500);

$method=$_SERVER['REQUEST_METHOD'];
try {
    if($method==='GET') {
        $opcoes=buscarOpcoes($key);
        $ids=array_map(fn($r)=>(int)$r['id'],$opcoes);
        $cont=[];
        if($ids){
            $in=implode(',',array_fill(0,count($ids),'?'));
            $st=$pdo->prepare("SELECT opcao_id,total_votos FROM contagem_votos WHERE opcao_id IN ($in)");
            $st->execute($ids);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cont[(int)$r['opcao_id']]=(int)$r['total_votos'];
        }
        $jaVotou=false;
        $st=$pdo->prepare('SELECT 1 FROM votos_realizados WHERE usuario_id=? LIMIT 1'); $st->execute([(int)$_SESSION['usuario_id']]);
        $jaVotou=(bool)$st->fetchColumn();
        foreach($opcoes as $i=>&$o){ $o['numero']=$i+1; $o['total_votos']=$cont[(int)$o['id']]??0; }
        resposta(true,'',200,['opcoes'=>$opcoes,'ja_votou'=>$jaVotou]);
    }
    if($method!=='POST') resposta(false,'Método não permitido.',405);
    $dados=json_decode(file_get_contents('php://input'),true);
    $opcaoId=filter_var($dados['opcao_id']??null,FILTER_VALIDATE_INT);
    if(!$opcaoId || $opcaoId<1) resposta(false,'Opção inválida.',400);

    $opcoes=buscarOpcoes($key);
    $valida=false; foreach($opcoes as $o) if((int)$o['id']===$opcaoId){$valida=true;break;}
    if(!$valida) resposta(false,'A opção escolhida não existe ou não está disponível.',400);

    $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('INSERT INTO votos_realizados (usuario_id) VALUES (?)');
        $st->execute([(int)$_SESSION['usuario_id']]);
    } catch(PDOException $e) {
        $pdo->rollBack();
        if((int)$e->errorInfo[1]===1062) resposta(false,'Você já votou. Cada usuário pode votar apenas uma vez.',409);
        throw $e;
    }
    $st=$pdo->prepare('INSERT INTO contagem_votos (opcao_id,total_votos) VALUES (?,1) ON DUPLICATE KEY UPDATE total_votos=total_votos+1');
    $st->execute([$opcaoId]);
    $pdo->commit();
    resposta(true,'Voto registrado com sucesso.');
} catch(Throwable $e) {
    if($pdo->inTransaction()) $pdo->rollBack();
    error_log('votar.php: '.$e->getMessage());
    resposta(false,'Não foi possível concluir a votação.',500);
}
