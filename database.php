<?php
namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class Database {
    private $client;
    private $baseUrl;
    private $apiKey;
    
    public function __construct() {
        $this->baseUrl = $_ENV['SUPABASE_URL'] ?? '';
        $this->apiKey = $_ENV['SUPABASE_KEY'] ?? '';
        
        if (empty($this->baseUrl) || empty($this->apiKey)) {
            throw new \Exception('Credenciais do Supabase não configuradas');
        }
        
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'apikey' => $this->apiKey,
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation'
            ],
            'timeout' => 30,
            'http_errors' => false
        ]);
    }
    
    /**
     * Seleciona registros da tabela
     * 
     * @param string $table Nome da tabela
     * @param string $select Campos a selecionar (default: *)
     * @param array $filters Filtros ['campo' => 'valor'] ou ['campo' => ['operador', 'valor']]
     * @param array $options Opções adicionais: limit, offset, order
     * @return array|false
     */
    public function select($table, $select = '*', $filters = [], $options = []) {
        try {
            $query = "?select=" . urlencode($select);
            
            // Aplicar filtros
            foreach ($filters as $key => $value) {
                if (is_array($value)) {
                    // Operador personalizado: ['gte', 100] ou ['like', '*texto*']
                    $operator = $value[0];
                    $val = $value[1];
                    $query .= "&{$key}={$operator}." . urlencode($val);
                } else {
                    // Igualdade simples
                    $query .= "&{$key}=eq." . urlencode($value);
                }
            }
            
            // Aplicar limit
            if (isset($options['limit'])) {
                $query .= "&limit=" . (int)$options['limit'];
            }
            
            // Aplicar offset
            if (isset($options['offset'])) {
                $query .= "&offset=" . (int)$options['offset'];
            }
            
            // Aplicar ordenação
            if (isset($options['order'])) {
                $order = $options['order'];
                $direction = $options['order_dir'] ?? 'asc';
                $query .= "&order={$order}.{$direction}";
            }
            
            $response = $this->client->get("/rest/v1/{$table}{$query}");
            
            if ($response->getStatusCode() >= 400) {
                $this->logError("Select error on {$table}", $response);
                return false;
            }
            
            return json_decode($response->getBody(), true);
            
        } catch (GuzzleException $e) {
            error_log("Database select error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Insere um ou múltiplos registros
     * 
     * @param string $table Nome da tabela
     * @param array $data Dados a inserir (array associativo ou array de arrays)
     * @return array|false
     */
    public function insert($table, $data) {
        try {
            // Se for array de arrays, é inserção múltipla
            $isMultiple = isset($data[0]) && is_array($data[0]);
            
            $response = $this->client->post("/rest/v1/{$table}", [
                'json' => $isMultiple ? $data : [$data]
            ]);
            
            if ($response->getStatusCode() >= 400) {
                $this->logError("Insert error on {$table}", $response);
                return false;
            }
            
            $result = json_decode($response->getBody(), true);
            return $result;
            
        } catch (GuzzleException $e) {
            error_log("Database insert error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Atualiza registros
     * 
     * @param string $table Nome da tabela
     * @param array $data Dados a atualizar
     * @param array $filters Filtros para WHERE
     * @return array|false
     */
    public function update($table, $data, $filters) {
        try {
            if (empty($filters)) {
                throw new \Exception("Filtros são obrigatórios para UPDATE");
            }
            
            $query = "?";
            foreach ($filters as $key => $value) {
                $query .= "{$key}=eq." . urlencode($value) . "&";
            }
            $query = rtrim($query, '&');
            
            $response = $this->client->patch("/rest/v1/{$table}{$query}", [
                'json' => $data
            ]);
            
            if ($response->getStatusCode() >= 400) {
                $this->logError("Update error on {$table}", $response);
                return false;
            }
            
            return json_decode($response->getBody(), true);
            
        } catch (GuzzleException $e) {
            error_log("Database update error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Deleta registros
     * 
     * @param string $table Nome da tabela
     * @param array $filters Filtros para WHERE
     * @return bool
     */
    public function delete($table, $filters) {
        try {
            if (empty($filters)) {
                throw new \Exception("Filtros são obrigatórios para DELETE");
            }
            
            $query = "?";
            foreach ($filters as $key => $value) {
                $query .= "{$key}=eq." . urlencode($value) . "&";
            }
            $query = rtrim($query, '&');
            
            $response = $this->client->delete("/rest/v1/{$table}{$query}");
            
            if ($response->getStatusCode() >= 400) {
                $this->logError("Delete error on {$table}", $response);
                return false;
            }
            
            return true;
            
        } catch (GuzzleException $e) {
            error_log("Database delete error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Executa uma função RPC do Supabase
     * 
     * @param string $functionName Nome da função
     * @param array $params Parâmetros
     * @return mixed
     */
    public function rpc($functionName, $params = []) {
        try {
            $response = $this->client->post("/rest/v1/rpc/{$functionName}", [
                'json' => $params
            ]);
            
            if ($response->getStatusCode() >= 400) {
                $this->logError("RPC error on {$functionName}", $response);
                return false;
            }
            
            return json_decode($response->getBody(), true);
            
        } catch (GuzzleException $e) {
            error_log("Database RPC error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Conta registros em uma tabela
     * 
     * @param string $table Nome da tabela
     * @param array $filters Filtros opcionais
     * @return int
     */
    public function count($table, $filters = []) {
        try {
            $query = "?select=count";
            
            foreach ($filters as $key => $value) {
                if (is_array($value)) {
                    $query .= "&{$key}={$value[0]}." . urlencode($value[1]);
                } else {
                    $query .= "&{$key}=eq." . urlencode($value);
                }
            }
            
            $response = $this->client->get("/rest/v1/{$table}{$query}", [
                'headers' => [
                    'Prefer' => 'count=exact'
                ]
            ]);
            
            if ($response->getStatusCode() >= 400) {
                return 0;
            }
            
            // Supabase retorna o count no header Content-Range
            $contentRange = $response->getHeader('Content-Range');
            if (!empty($contentRange)) {
                $parts = explode('/', $contentRange[0]);
                return isset($parts[1]) ? (int)$parts[1] : 0;
            }
            
            return 0;
            
        } catch (GuzzleException $e) {
            error_log("Database count error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Busca uma configuração específica
     * 
     * @param string $chave Chave da configuração
     * @return string|null
     */
    public function getConfig($chave) {
        $result = $this->select('configuracoes', 'valor', ['chave' => $chave]);
        return $result && count($result) > 0 ? $result[0]['valor'] : null;
    }
    
    /**
     * Define uma configuração
     * 
     * @param string $chave Chave da configuração
     * @param mixed $valor Valor
     * @return bool
     */
    public function setConfig($chave, $valor) {
        $existing = $this->select('configuracoes', 'id', ['chave' => $chave]);
        
        if ($existing && count($existing) > 0) {
            return $this->update('configuracoes', 
                ['valor' => $valor, 'updated_at' => date('Y-m-d H:i:s')],
                ['chave' => $chave]
            ) !== false;
        } else {
            return $this->insert('configuracoes', [
                'chave' => $chave,
                'valor' => $valor
            ]) !== false;
        }
    }
    
    /**
     * Executa uma query SQL customizada (use com cuidado)
     * 
     * @param string $sql Query SQL
     * @return mixed
     */
    public function query($sql) {
        // Nota: Supabase não suporta SQL direto via REST API
        // Use RPC para queries complexas
        throw new \Exception("Use RPC functions para queries SQL customizadas");
    }
    
    /**
     * Inicia uma transação (Supabase usa auto-commit)
     * 
     * @return bool
     */
    public function beginTransaction() {
        // Nota: Supabase REST API não suporta transações explícitas
        // Use RPC functions para operações transacionais
        return true;
    }
    
    /**
     * Commit de transação
     * 
     * @return bool
     */
    public function commit() {
        return true;
    }
    
    /**
     * Rollback de transação
     * 
     * @return bool
     */
    public function rollback() {
        return true;
    }
    
    /**
     * Verifica conexão com o banco
     * 
     * @return bool
     */
    public function testConnection() {
        try {
            $response = $this->client->get('/rest/v1/');
            return $response->getStatusCode() < 400;
        } catch (GuzzleException $e) {
            return false;
        }
    }
    
    /**
     * Loga erros de forma consistente
     * 
     * @param string $message Mensagem
     * @param mixed $response Resposta
     */
    private function logError($message, $response) {
        $statusCode = $response->getStatusCode();
        $body = $response->getBody()->getContents();
        
        error_log("{$message} - Status: {$statusCode} - Body: {$body}");
    }
    
    /**
     * Sanitiza entrada para prevenir injeção
     * 
     * @param mixed $value Valor a sanitizar
     * @return mixed
     */
    public function sanitize($value) {
        if (is_string($value)) {
            return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
        }
        return $value;
    }
    
    /**
     * Valida email
     * 
     * @param string $email Email
     * @return bool
     */
    public function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Hash de senha
     * 
     * @param string $password Senha
     * @return string
     */
    public function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    /**
     * Verifica senha
     * 
     * @param string $password Senha em texto plano
     * @param string $hash Hash armazenado
     * @return bool
     */
    public function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}