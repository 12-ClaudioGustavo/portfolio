<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php');

use Dotenv\Dotenv;
use App\Database;

// Carregar variáveis de ambiente
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Função para validar dispositivo ID
function validateDeviceId($deviceId) {
    // Deve ter entre 10 e 200 caracteres
    if (strlen($deviceId) < 10 || strlen($deviceId) > 200) {
        return false;
    }
    
    // Deve conter apenas caracteres alfanuméricos e alguns especiais
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $deviceId)) {
        return false;
    }
    
    return true;
}

// Função para rate limiting de votos
function checkVoteRateLimit($ip) {
    $cacheFile = sys_get_temp_dir() . '/vote_rate_' . md5($ip) . '.json';
    $maxVotesPerMinute = 10;
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        
        // Resetar se passou 1 minuto
        if (time() - $data['timestamp'] > 60) {
            $data = ['count' => 0, 'timestamp' => time()];
        }
        
        if ($data['count'] >= $maxVotesPerMinute) {
            return [
                'allowed' => false,
                'message' => 'Você está votando muito rápido. Aguarde um momento.'
            ];
        }
        
        $data['count']++;
        file_put_contents($cacheFile, json_encode($data));
    } else {
        file_put_contents($cacheFile, json_encode(['count' => 1, 'timestamp' => time()]));
    }
    
    return ['allowed' => true];
}

// ================================
// PROCESSAR VOTO
// ================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido'
    ]);
    exit;
}

try {
    // Obter IP do cliente
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ipAddress = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    
    // Verificar rate limiting
    $rateLimitCheck = checkVoteRateLimit($ipAddress);
    if (!$rateLimitCheck['allowed']) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => $rateLimitCheck['message']
        ]);
        exit;
    }
    
    // Obter dados do POST
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Dados inválidos'
        ]);
        exit;
    }
    
    $candidatoId = $data['candidato_id'] ?? null;
    $categoriaId = $data['categoria_id'] ?? null;
    $dispositivoId = $data['dispositivo_id'] ?? null;
    
    // Validações básicas
    if (!$candidatoId || !$categoriaId || !$dispositivoId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Dados incompletos. Candidato, categoria e dispositivo são obrigatórios.'
        ]);
        exit;
    }
    
    // Validar dispositivo ID
    if (!validateDeviceId($dispositivoId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de dispositivo inválido'
        ]);
        exit;
    }
    
    // Conectar ao banco
    $db = new Database();
    
    // ================================
    // VERIFICAR STATUS DA VOTAÇÃO
    // ================================
    
    $votacaoAtiva = $db->getConfig('votacao_ativa');
    if ($votacaoAtiva !== 'true') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'A votação não está ativa no momento. Aguarde o período de votação.'
        ]);
        exit;
    }
    
    // ================================
    // VERIFICAR PERÍODO DE VOTAÇÃO
    // ================================
    
    $dataInicio = $db->getConfig('data_inicio_votacao');
    $dataFim = $db->getConfig('data_fim_votacao');
    $hoje = date('Y-m-d');
    
    if ($dataInicio && $hoje < $dataInicio) {
        http_response_code(403);
        $dataInicioFormatada = date('d/m/Y', strtotime($dataInicio));
        echo json_encode([
            'success' => false,
            'message' => "A votação ainda não começou. Inicia em {$dataInicioFormatada}."
        ]);
        exit;
    }
    
    if ($dataFim && $hoje > $dataFim) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'O período de votação já encerrou. Obrigado pela participação!'
        ]);
        exit;
    }
    
    // ================================
    // VERIFICAR SE CANDIDATO EXISTE E ESTÁ ATIVO
    // ================================
    
    $candidato = $db->select('candidatos', 'id,nome,categoria_id,ativo', [
        'id' => $candidatoId
    ]);
    
    if (!$candidato || count($candidato) === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Candidato não encontrado'
        ]);
        exit;
    }
    
    $candidato = $candidato[0];
    
    if (!$candidato['ativo']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Este candidato não está mais disponível para votação'
        ]);
        exit;
    }
    
    // ================================
    // VERIFICAR SE CANDIDATO PERTENCE À CATEGORIA
    // ================================
    
    if ($candidato['categoria_id'] != $categoriaId) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'O candidato não pertence a esta categoria'
        ]);
        exit;
    }
    
    // ================================
    // VERIFICAR SE CATEGORIA EXISTE E ESTÁ ATIVA
    // ================================
    
    $categoria = $db->select('categorias', 'id,nome,ativo', [
        'id' => $categoriaId
    ]);
    
    if (!$categoria || count($categoria) === 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Categoria não encontrada'
        ]);
        exit;
    }
    
    if (!$categoria[0]['ativo']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Esta categoria não está mais disponível para votação'
        ]);
        exit;
    }
    
    // ================================
    // VERIFICAR SE JÁ VOTOU HOJE NESTA CATEGORIA
    // ================================
    
    $dataHoje = date('Y-m-d');
    
    $votosExistentes = $db->select('votos', 'id', [
        'dispositivo_id' => $dispositivoId,
        'categoria_id' => $categoriaId,
        'data_voto' => $dataHoje
    ]);
    
    if ($votosExistentes && count($votosExistentes) > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Você já votou nesta categoria hoje! Volte amanhã para votar novamente.',
            'already_voted' => true,
            'next_vote_date' => date('d/m/Y', strtotime('+1 day'))
        ]);
        exit;
    }
    
    // ================================
    // REGISTRAR O VOTO
    // ================================
    
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $votoData = [
        'candidato_id' => $candidatoId,
        'categoria_id' => $categoriaId,
        'dispositivo_id' => $dispositivoId,
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
        'data_voto' => $dataHoje,
        'hora_voto' => date('Y-m-d H:i:s')
    ];
    
    $voto = $db->insert('votos', $votoData);
    
    if (!$voto) {
        throw new Exception('Erro ao registrar voto no banco de dados');
    }
    
    // ================================
    // ATUALIZAR CONTADOR DE VOTOS DO CANDIDATO
    // ================================
    // (Isso pode ser feito por trigger no banco, mas vamos fazer aqui também para garantir)
    
    $totalVotos = $db->count('votos', ['candidato_id' => $candidatoId]);
    
    $db->update('candidatos', [
        'total_votos' => $totalVotos
    ], [
        'id' => $candidatoId
    ]);
    
    // ================================
    // REGISTRAR NA AUDITORIA
    // ================================
    
    $db->insert('historico_acoes', [
        'acao' => 'votar',
        'tabela' => 'votos',
        'registro_id' => $voto[0]['id'] ?? null,
        'detalhes' => json_encode([
            'candidato_id' => $candidatoId,
            'candidato_nome' => $candidato['nome'],
            'categoria_id' => $categoriaId,
            'categoria_nome' => $categoria[0]['nome'],
            'dispositivo_id' => substr($dispositivoId, 0, 10) . '...' // Parcial por privacidade
        ]),
        'ip_address' => $ipAddress
    ]);
    
    // ================================
    // RESPOSTA DE SUCESSO
    // ================================
    
    // Verificar se deve mostrar confete
    $mostrarConfete = $db->getConfig('mostrar_confete') === 'true';
    
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Voto registrado com sucesso! Obrigado por participar! 🎉',
        'showConfetti' => $mostrarConfete,
        'data' => [
            'candidato' => $candidato['nome'],
            'categoria' => $categoria[0]['nome'],
            'data_voto' => $dataHoje,
            'hora_voto' => date('H:i:s'),
            'total_votos_candidato' => $totalVotos,
            'proxima_votacao' => date('d/m/Y', strtotime('+1 day'))
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao processar voto: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao processar seu voto. Por favor, tente novamente.',
        'error' => $_ENV['DEBUG'] === 'true' ? $e->getMessage() : null
    ]);
}