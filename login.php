<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;

// Carregar variáveis de ambiente
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

// Função para gerar JWT Token
function generateJWT($payload, $secret) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode($payload);
    
    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
    
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

// Função para rate limiting (proteção contra brute force)
function checkRateLimit($ip) {
    $cacheFile = sys_get_temp_dir() . '/login_attempts_' . md5($ip) . '.json';
    $maxAttempts = 5;
    $lockoutTime = 15 * 60; // 15 minutos
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        
        // Verificar se está bloqueado
        if ($data['locked_until'] && time() < $data['locked_until']) {
            $remainingTime = ceil(($data['locked_until'] - time()) / 60);
            return [
                'allowed' => false,
                'message' => "Muitas tentativas de login. Tente novamente em {$remainingTime} minutos."
            ];
        }
        
        // Resetar se passou o tempo de lockout
        if (time() >= $data['locked_until']) {
            $data = ['attempts' => 0, 'locked_until' => null];
            file_put_contents($cacheFile, json_encode($data));
        }
        
        // Verificar número de tentativas
        if ($data['attempts'] >= $maxAttempts) {
            $data['locked_until'] = time() + $lockoutTime;
            file_put_contents($cacheFile, json_encode($data));
            
            return [
                'allowed' => false,
                'message' => "Muitas tentativas de login. Tente novamente em 15 minutos."
            ];
        }
    }
    
    return ['allowed' => true];
}

// Função para registrar tentativa de login
function registerAttempt($ip, $success = false) {
    $cacheFile = sys_get_temp_dir() . '/login_attempts_' . md5($ip) . '.json';
    
    if ($success) {
        // Limpar tentativas em caso de sucesso
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    } else {
        // Incrementar tentativas
        $data = ['attempts' => 1, 'locked_until' => null];
        
        if (file_exists($cacheFile)) {
            $existing = json_decode(file_get_contents($cacheFile), true);
            $data['attempts'] = $existing['attempts'] + 1;
            $data['locked_until'] = $existing['locked_until'];
        }
        
        file_put_contents($cacheFile, json_encode($data));
    }
}

// ================================
// PROCESSAR LOGIN
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
    $rateLimitCheck = checkRateLimit($ipAddress);
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
    
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $remember = $data['remember'] ?? false;
    
    // Validações básicas
    if (empty($email) || empty($password)) {
        registerAttempt($ipAddress, false);
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email e senha são obrigatórios'
        ]);
        exit;
    }
    
    // Validar formato de email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        registerAttempt($ipAddress, false);
        
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Email inválido'
        ]);
        exit;
    }
    
    // Conectar ao banco
    $db = new Database();
    
    // Buscar administrador
    $admin = $db->select('administradores', 'id,nome,email,senha,nivel_acesso,ativo', [
        'email' => $email
    ]);
    
    if (!$admin || count($admin) === 0) {
        registerAttempt($ipAddress, false);
        
        // Registrar tentativa falha na auditoria
        $db->insert('historico_acoes', [
            'acao' => 'login_falha',
            'tabela' => 'administradores',
            'detalhes' => json_encode([
                'email' => $email,
                'motivo' => 'usuario_nao_encontrado'
            ]),
            'ip_address' => $ipAddress
        ]);
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Email ou senha incorretos'
        ]);
        exit;
    }
    
    $admin = $admin[0];
    
    // Verificar se está ativo
    if (!$admin['ativo']) {
        registerAttempt($ipAddress, false);
        
        $db->insert('historico_acoes', [
            'acao' => 'login_falha',
            'tabela' => 'administradores',
            'registro_id' => $admin['id'],
            'detalhes' => json_encode([
                'email' => $email,
                'motivo' => 'usuario_inativo'
            ]),
            'ip_address' => $ipAddress
        ]);
        
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Conta desativada. Entre em contato com o administrador.'
        ]);
        exit;
    }
    
    // Verificar senha
    if (!password_verify($password, $admin['senha'])) {
        registerAttempt($ipAddress, false);
        
        $db->insert('historico_acoes', [
            'admin_id' => $admin['id'],
            'acao' => 'login_falha',
            'tabela' => 'administradores',
            'registro_id' => $admin['id'],
            'detalhes' => json_encode([
                'email' => $email,
                'motivo' => 'senha_incorreta'
            ]),
            'ip_address' => $ipAddress
        ]);
        
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Email ou senha incorretos'
        ]);
        exit;
    }
    
    // ================================
    // LOGIN BEM-SUCEDIDO
    // ================================
    
    // Limpar rate limiting
    registerAttempt($ipAddress, true);
    
    // Gerar JWT Token
    $jwtSecret = $_ENV['JWT_SECRET'] ?? 'change_this_secret_key_in_production';
    $expirationTime = $remember ? (30 * 24 * 60 * 60) : (24 * 60 * 60); // 30 dias ou 24 horas
    
    $payload = [
        'id' => $admin['id'],
        'email' => $admin['email'],
        'nome' => $admin['nome'],
        'nivel_acesso' => $admin['nivel_acesso'],
        'iat' => time(),
        'exp' => time() + $expirationTime
    ];
    
    $token = generateJWT($payload, $jwtSecret);
    
    // Registrar login bem-sucedido
    $db->insert('historico_acoes', [
        'admin_id' => $admin['id'],
        'acao' => 'login',
        'tabela' => 'administradores',
        'registro_id' => $admin['id'],
        'detalhes' => json_encode([
            'email' => $email,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'remember' => $remember
        ]),
        'ip_address' => $ipAddress
    ]);
    
    // Atualizar último login
    $db->update('administradores', [
        'updated_at' => date('Y-m-d H:i:s')
    ], [
        'id' => $admin['id']
    ]);
    
    // Resposta de sucesso
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login realizado com sucesso',
        'token' => $token,
        'expires_in' => $expirationTime,
        'user' => [
            'id' => $admin['id'],
            'nome' => $admin['nome'],
            'email' => $admin['email'],
            'nivel_acesso' => $admin['nivel_acesso']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro no servidor. Tente novamente mais tarde.',
        'error' => $_ENV['DEBUG'] === 'true' ? $e->getMessage() : null
    ]);
}