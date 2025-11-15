<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

$db = new Database();

try {
    // ================================
    // PROCESSAR PARÂMETROS DE FILTRO
    // ================================
    
    $filters = [];
    $options = [];
    
    // Filtro por categoria
    if (isset($_GET['categoria_id']) && !empty($_GET['categoria_id'])) {
        $filters['categoria_id'] = (int)$_GET['categoria_id'];
    }
    
    // Filtro por status ativo
    if (isset($_GET['ativo'])) {
        $filters['ativo'] = $_GET['ativo'] === 'true';
    }
    
    // Filtro por ID específico
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $filters['id'] = (int)$_GET['id'];
    }
    
    // Busca por nome (LIKE)
    $searchTerm = $_GET['search'] ?? '';
    if (!empty($searchTerm)) {
        // Para busca com LIKE no Supabase, usamos o operador 'ilike'
        $filters['nome'] = ['ilike', "*{$searchTerm}*"];
    }
    
    // ================================
    // ORDENAÇÃO
    // ================================
    
    $validOrderFields = ['nome', 'total_votos', 'created_at', 'id'];
    $orderBy = $_GET['order_by'] ?? 'created_at';
    $orderDir = $_GET['order_dir'] ?? 'desc';
    
    if (!in_array($orderBy, $validOrderFields)) {
        $orderBy = 'created_at';
    }
    
    if (!in_array($orderDir, ['asc', 'desc'])) {
        $orderDir = 'desc';
    }
    
    $options['order'] = $orderBy;
    $options['order_dir'] = $orderDir;
    
    // ================================
    // PAGINAÇÃO
    // ================================
    
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? min(100, max(1, (int)$_GET['per_page'])) : 20;
    
    $options['offset'] = ($page - 1) * $perPage;
    $options['limit'] = $perPage;
    
    // ================================
    // BUSCAR CANDIDATOS
    // ================================
    
    $candidatos = $db->select(
        'candidatos',
        'id,categoria_id,nome,foto_url,biografia,descricao_curta,total_votos,ativo,created_at,updated_at',
        $filters,
        $options
    );
    
    if ($candidatos === false) {
        throw new Exception('Erro ao buscar candidatos no banco de dados');
    }
    
    // ================================
    // ENRIQUECER DADOS
    // ================================
    
    $hoje = date('Y-m-d');
    
    foreach ($candidatos as &$candidato) {
        // Buscar informações da categoria
        $categoria = $db->select('categorias', 'nome,icone,cor', [
            'id' => $candidato['categoria_id']
        ]);
        
        if ($categoria && count($categoria) > 0) {
            $candidato['categoria_nome'] = $categoria[0]['nome'];
            $candidato['categoria_icone'] = $categoria[0]['icone'];
            $candidato['categoria_cor'] = $categoria[0]['cor'];
        } else {
            $candidato['categoria_nome'] = 'Sem Categoria';
            $candidato['categoria_icone'] = 'fa-question';
            $candidato['categoria_cor'] = '#CCCCCC';
        }
        
        // Buscar votos de hoje
        $votosHoje = $db->count('votos', [
            'candidato_id' => $candidato['id'],
            'data_voto' => $hoje
        ]);
        
        $candidato['votos_hoje'] = $votosHoje;
        
        // Calcular percentual de votos (se houver votação)
        $totalVotosCategoria = $db->count('votos', [
            'categoria_id' => $candidato['categoria_id']
        ]);
        
        if ($totalVotosCategoria > 0) {
            $candidato['percentual_votos'] = round(($candidato['total_votos'] / $totalVotosCategoria) * 100, 2);
        } else {
            $candidato['percentual_votos'] = 0;
        }
        
        // Formatar datas
        $candidato['created_at_formatted'] = date('d/m/Y H:i', strtotime($candidato['created_at']));
        
        if ($candidato['updated_at']) {
            $candidato['updated_at_formatted'] = date('d/m/Y H:i', strtotime($candidato['updated_at']));
        }
        
        // Truncar biografia para preview (opcional)
        if (isset($_GET['preview']) && $_GET['preview'] === 'true') {
            $candidato['biografia_preview'] = strlen($candidato['biografia']) > 200
                ? substr($candidato['biografia'], 0, 200) . '...'
                : $candidato['biografia'];
        }
    }
    
    // ================================
    // CALCULAR TOTAL (para paginação)
    // ================================
    
    // Remover limit/offset para contar total
    $totalCandidatos = $db->count('candidatos', $filters);
    
    $totalPages = ceil($totalCandidatos / $perPage);
    
    // ================================
    // ESTATÍSTICAS ADICIONAIS (opcional)
    // ================================
    
    $includeStats = isset($_GET['include_stats']) && $_GET['include_stats'] === 'true';
    $stats = null;
    
    if ($includeStats) {
        $totalVotos = 0;
        $candidatosAtivos = 0;
        
        foreach ($candidatos as $c) {
            $totalVotos += $c['total_votos'];
            if ($c['ativo']) $candidatosAtivos++;
        }
        
        $stats = [
            'total_candidatos' => $totalCandidatos,
            'candidatos_ativos' => $candidatosAtivos,
            'candidatos_inativos' => $totalCandidatos - $candidatosAtivos,
            'total_votos' => $totalVotos,
            'media_votos' => $totalCandidatos > 0 ? round($totalVotos / $totalCandidatos, 2) : 0
        ];
    }
    
    // ================================
    // RESPOSTA
    // ================================
    
    $response = [
        'success' => true,
        'data' => $candidatos,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalCandidatos,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1,
            'from' => ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $totalCandidatos)
        ]
    ];
    
    if ($stats) {
        $response['stats'] = $stats;
    }
    
    // Incluir filtros aplicados na resposta (útil para debug)
    if (isset($_GET['debug']) && $_GET['debug'] === 'true') {
        $response['filters_applied'] = $filters;
        $response['order'] = ['by' => $orderBy, 'dir' => $orderDir];
    }
    
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Erro ao listar candidatos: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao buscar candidatos',
        'error' => $_ENV['DEBUG'] === 'true' ? $e->getMessage() : null
    ]);
}