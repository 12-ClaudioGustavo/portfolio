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
    $stats = [];
    
    // ================================
    // ESTATÍSTICAS GERAIS
    // ================================
    
    // Total de votos
    $totalVotos = $db->count('votos');
    $stats['total_votos'] = $totalVotos;
    
    // Total de candidatos ativos
    $totalCandidatos = $db->count('candidatos', ['ativo' => true]);
    $stats['total_candidatos'] = $totalCandidatos;
    
    // Total de categorias ativas
    $totalCategorias = $db->count('categorias', ['ativo' => true]);
    $stats['total_categorias'] = $totalCategorias;
    
    // Votantes únicos (dispositivos únicos)
    $votosUnicos = $db->select('votos', 'COUNT(DISTINCT dispositivo_id) as total');
    $stats['votantes_unicos'] = $votosUnicos && count($votosUnicos) > 0 
        ? (int)($votosUnicos[0]['total'] ?? 0) 
        : 0;
    
    // ================================
    // ESTATÍSTICAS DIÁRIAS
    // ================================
    
    $hoje = date('Y-m-d');
    $ontem = date('Y-m-d', strtotime('-1 day'));
    
    // Votos hoje
    $votosHoje = $db->count('votos', ['data_voto' => $hoje]);
    $stats['votos_hoje'] = $votosHoje;
    
    // Votos ontem (para comparação)
    $votosOntem = $db->count('votos', ['data_voto' => $ontem]);
    $stats['votos_ontem'] = $votosOntem;
    
    // Crescimento percentual
    if ($votosOntem > 0) {
        $crescimento = (($votosHoje - $votosOntem) / $votosOntem) * 100;
        $stats['crescimento_hoje'] = round($crescimento, 1);
    } else {
        $stats['crescimento_hoje'] = $votosHoje > 0 ? 100 : 0;
    }
    
    // Votantes únicos hoje
    $votantesHoje = $db->select('votos', 'COUNT(DISTINCT dispositivo_id) as total', [
        'data_voto' => $hoje
    ]);
    $stats['votantes_hoje'] = $votantesHoje && count($votantesHoje) > 0 
        ? (int)($votantesHoje[0]['total'] ?? 0) 
        : 0;
    
    // ================================
    // VOTOS POR CATEGORIA
    // ================================
    
    $categorias = $db->select('categorias', 'id,nome,icone,cor', ['ativo' => true]);
    $votosPorCategoria = [];
    
    foreach ($categorias as $categoria) {
        $votos = $db->count('votos', ['categoria_id' => $categoria['id']]);
        
        $votosPorCategoria[] = [
            'categoria_id' => $categoria['id'],
            'categoria_nome' => $categoria['nome'],
            'icone' => $categoria['icone'],
            'cor' => $categoria['cor'],
            'total_votos' => $votos,
            'percentual' => $totalVotos > 0 ? round(($votos / $totalVotos) * 100, 2) : 0
        ];
    }
    
    // Ordenar por total de votos
    usort($votosPorCategoria, function($a, $b) {
        return $b['total_votos'] - $a['total_votos'];
    });
    
    $stats['votos_por_categoria'] = $votosPorCategoria;
    
    // ================================
    // EVOLUÇÃO DE VOTOS (ÚLTIMOS 7 DIAS)
    // ================================
    
    $evolucao = [];
    for ($i = 6; $i >= 0; $i--) {
        $data = date('Y-m-d', strtotime("-{$i} days"));
        $votos = $db->count('votos', ['data_voto' => $data]);
        
        $evolucao[] = [
            'data' => $data,
            'data_formatada' => date('d/m', strtotime($data)),
            'dia_semana' => date('D', strtotime($data)),
            'total_votos' => $votos
        ];
    }
    
    $stats['evolucao_7_dias'] = $evolucao;
    
    // ================================
    // EVOLUÇÃO MENSAL (ÚLTIMOS 30 DIAS)
    // ================================
    
    if (isset($_GET['include_monthly']) && $_GET['include_monthly'] === 'true') {
        $evolucaoMensal = [];
        for ($i = 29; $i >= 0; $i--) {
            $data = date('Y-m-d', strtotime("-{$i} days"));
            $votos = $db->count('votos', ['data_voto' => $data]);
            
            $evolucaoMensal[] = [
                'data' => $data,
                'data_formatada' => date('d/m', strtotime($data)),
                'total_votos' => $votos
            ];
        }
        
        $stats['evolucao_30_dias'] = $evolucaoMensal;
    }
    
    // ================================
    // TOP 10 CANDIDATOS
    // ================================
    
    $topCandidatos = $db->select(
        'candidatos',
        'id,nome,foto_url,categoria_id,total_votos',
        ['ativo' => true],
        ['order' => 'total_votos', 'order_dir' => 'desc', 'limit' => 10]
    );
    
    if ($topCandidatos) {
        foreach ($topCandidatos as &$candidato) {
            $categoria = $db->select('categorias', 'nome,icone,cor', ['id' => $candidato['categoria_id']]);
            
            $candidato['categoria_nome'] = $categoria && count($categoria) > 0 
                ? $categoria[0]['nome'] 
                : 'Sem Categoria';
            
            $candidato['categoria_icone'] = $categoria && count($categoria) > 0 
                ? $categoria[0]['icone'] 
                : 'fa-question';
            
            $candidato['categoria_cor'] = $categoria && count($categoria) > 0 
                ? $categoria[0]['cor'] 
                : '#CCCCCC';
            
            // Calcular percentual
            $totalVotosCategoria = $db->count('votos', ['categoria_id' => $candidato['categoria_id']]);
            $candidato['percentual'] = $totalVotosCategoria > 0 
                ? round(($candidato['total_votos'] / $totalVotosCategoria) * 100, 2) 
                : 0;
        }
        
        $stats['top_candidatos'] = $topCandidatos;
    } else {
        $stats['top_candidatos'] = [];
    }
    
    // ================================
    // VOTOS POR HORA (HOJE)
    // ================================
    
    $votosPorHora = [];
    $votosHojeCompleto = $db->select('votos', 'hora_voto', ['data_voto' => $hoje]);
    
    // Inicializar array com todas as horas
    for ($h = 0; $h < 24; $h++) {
        $votosPorHora[$h] = 0;
    }
    
    // Contar votos por hora
    if ($votosHojeCompleto) {
        foreach ($votosHojeCompleto as $voto) {
            $hora = (int)date('H', strtotime($voto['hora_voto']));
            $votosPorHora[$hora]++;
        }
    }
    
    // Formatar para resposta
    $votosPorHoraFormatado = [];
    foreach ($votosPorHora as $hora => $total) {
        $votosPorHoraFormatado[] = [
            'hora' => str_pad($hora, 2, '0', STR_PAD_LEFT) . ':00',
            'total_votos' => $total
        ];
    }
    
    $stats['votos_por_hora_hoje'] = $votosPorHoraFormatado;
    
    // Encontrar pico de votos
    $picoVotos = max($votosPorHora);
    $horaPico = array_search($picoVotos, $votosPorHora);
    
    $stats['pico_votos'] = [
        'total' => $picoVotos,
        'hora' => str_pad($horaPico, 2, '0', STR_PAD_LEFT) . ':00'
    ];
    
    // ================================
    // ATIVIDADE RECENTE (ÚLTIMAS 20 AÇÕES)
    // ================================
    
    $atividadeRecente = $db->select(
        'historico_acoes',
        'id,admin_id,acao,tabela,registro_id,created_at,ip_address',
        [],
        ['order' => 'created_at', 'order_dir' => 'desc', 'limit' => 20]
    );
    
    if ($atividadeRecente) {
        foreach ($atividadeRecente as &$atividade) {
            // Buscar nome do admin
            if ($atividade['admin_id']) {
                $admin = $db->select('administradores', 'nome', ['id' => $atividade['admin_id']]);
                $atividade['admin_nome'] = $admin && count($admin) > 0 
                    ? $admin[0]['nome'] 
                    : 'Desconhecido';
            } else {
                $atividade['admin_nome'] = 'Sistema';
            }
            
            // Calcular tempo relativo
            $timestamp = strtotime($atividade['created_at']);
            $diff = time() - $timestamp;
            
            if ($diff < 60) {
                $atividade['tempo_relativo'] = 'Agora mesmo';
            } elseif ($diff < 3600) {
                $minutos = floor($diff / 60);
                $atividade['tempo_relativo'] = $minutos . ' minuto' . ($minutos > 1 ? 's' : '') . ' atrás';
            } elseif ($diff < 86400) {
                $horas = floor($diff / 3600);
                $atividade['tempo_relativo'] = $horas . ' hora' . ($horas > 1 ? 's' : '') . ' atrás';
            } else {
                $dias = floor($diff / 86400);
                $atividade['tempo_relativo'] = $dias . ' dia' . ($dias > 1 ? 's' : '') . ' atrás';
            }
            
            // Formatar data
            $atividade['created_at_formatted'] = date('d/m/Y H:i:s', $timestamp);
            
            // Mascarar IP
            $atividade['ip_address_masked'] = maskIP($atividade['ip_address']);
            unset($atividade['ip_address']);
        }
        
        $stats['atividade_recente'] = $atividadeRecente;
    } else {
        $stats['atividade_recente'] = [];
    }
    
    // ================================
    // DIAS ATÉ A GALA
    // ================================
    
    $dataGala = $db->getConfig('data_gala');
    if ($dataGala) {
        $timestamp = strtotime($dataGala);
        $diasRestantes = ceil(($timestamp - time()) / 86400);
        $stats['dias_ate_gala'] = max(0, $diasRestantes);
        $stats['data_gala'] = date('d/m/Y H:i', $timestamp);
    } else {
        $stats['dias_ate_gala'] = 0;
        $stats['data_gala'] = null;
    }
    
    // ================================
    // MÉDIA DE VOTOS POR DIA
    // ================================
    
    $primeiroVoto = $db->select('votos', 'data_voto', [], ['order' => 'data_voto', 'order_dir' => 'asc', 'limit' => 1]);
    
    if ($primeiroVoto && count($primeiroVoto) > 0) {
        $dataInicio = strtotime($primeiroVoto[0]['data_voto']);
        $diasVotacao = ceil((time() - $dataInicio) / 86400);
        
        if ($diasVotacao > 0) {
            $stats['media_votos_dia'] = round($totalVotos / $diasVotacao, 2);
            $stats['dias_votacao'] = $diasVotacao;
        } else {
            $stats['media_votos_dia'] = $totalVotos;
            $stats['dias_votacao'] = 1;
        }
    } else {
        $stats['media_votos_dia'] = 0;
        $stats['dias_votacao'] = 0;
    }
    
    // ================================
    // TAXA DE ENGAJAMENTO
    // ================================
    
    // Estimativa baseada em visitantes únicos vs votantes
    $stats['taxa_engajamento'] = $stats['votantes_unicos'] > 0 
        ? round(($stats['votantes_unicos'] / max($stats['votantes_unicos'], 10000)) * 100, 2)
        : 0;
    
    // ================================
    // RESPOSTA
    // ================================
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $stats,
        'timestamp' => date('Y-m-d H:i:s'),
        'generated_in' => round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3) . 's'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Erro ao buscar estatísticas: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao buscar estatísticas',
        'error' => $_ENV['DEBUG'] === 'true' ? $e->getMessage() : null
    ]);
}

/**
 * Mascara IP para privacidade
 */
function maskIP($ip) {
    if (empty($ip)) return '***.***.***.***.***';
    
    $parts = explode('.', $ip);
    if (count($parts) === 4) {
        // IPv4
        return $parts[0] . '.***.***.***';
    }
    
    // IPv6
    return substr($ip, 0, 5) . '***';
}