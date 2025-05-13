<?php
declare(strict_types=1);

namespace Application\Service;

use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Sql\Sql;
use Laminas\Db\Sql\Where;
use Laminas\Db\Sql\Predicate\Expression;

/**
 * Servicio para gestionar operaciones de base de datos
 * 
 * Proporciona métodos comunes para consultas, paginación y filtros
 */
class DatabaseService
{
    /** @var AdapterInterface */
    private $dbAdapter;
    
    /**
     * Constructor
     *
     * @param AdapterInterface $dbAdapter
     */
    public function __construct(AdapterInterface $dbAdapter)
    {
        $this->dbAdapter = $dbAdapter;
    }
    
    /**
     * Obtiene datos paginados de una tabla con filtros
     *
     * @param string $table
     * @param int $page
     * @param int $limit
     * @param array $filters
     * @param string $orderBy
     * @param string $orderDir
     * @return array
     */
    public function getPaginatedData(
        string $table, 
        int $page = 1, 
        int $limit = 50, 
        array $filters = [],
        string $orderBy = 'id',
        string $orderDir = 'DESC'
    ): array {
        // Crear SQL builder
        $sql = new Sql($this->dbAdapter);
        
        // Preparar la consulta principal
        $select = $sql->select($table);
        
        // Preparar la consulta de conteo
        $countSelect = $sql->select($table);
        $countSelect->columns(['total' => new Expression('COUNT(*)')]);
        
        // Aplicar condiciones de filtrado a ambas consultas
        if (!empty($filters)) {
            $where = new Where();
            
            foreach ($filters as $field => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                
                // Filtro de búsqueda global (se aplica a todos los campos)
                if ($field === 'search' && !empty($value)) {
                    $searchWhere = new Where();
                    
                    // Obtener los nombres de columnas de la tabla
                    $columns = $this->getTableColumns($table);
                    
                    foreach ($columns as $column) {
                        $searchWhere->or->like($column, '%' . $value . '%');
                    }
                    
                    $where->addPredicate($searchWhere);
                    continue;
                }
                
                // Filtros de fecha
                if (in_array($field, ['fecha_inicio', 'startDate']) && !empty($value)) {
                    $dateField = $field === 'fecha_inicio' ? 'fecha_creacion' : 'fecha_creacion';
                    $where->greaterThanOrEqualTo($dateField, $value . ' 00:00:00');
                    continue;
                }
                
                if (in_array($field, ['fecha_fin', 'endDate']) && !empty($value)) {
                    $dateField = $field === 'fecha_fin' ? 'fecha_creacion' : 'fecha_creacion';
                    $where->lessThanOrEqualTo($dateField, $value . ' 23:59:59');
                    continue;
                }
                
                // Filtros normales
                $where->equalTo($field, $value);
            }
            
            $select->where($where);
            $countSelect->where($where);
        }
        
        // Aplicar ordenamiento
        $select->order([$orderBy . ' ' . $orderDir]);
        
        // Aplicar paginación
        $offset = ($page - 1) * $limit;
        $select->limit($limit)->offset($offset);
        
        // Ejecutar consulta de conteo
        $countStatement = $sql->prepareStatementForSqlObject($countSelect);
        $countResult = $countStatement->execute();
        $countRow = $countResult->current();
        $total = $countRow ? (int)$countRow['total'] : 0;
        
        // Calcular total de páginas
        $totalPages = ceil($total / $limit);
        
        // Asegurar que la página solicitada esté dentro del rango válido
        $page = max(1, min($page, $totalPages));
        
        // Ejecutar consulta principal
        $statement = $sql->prepareStatementForSqlObject($select);
        $result = $statement->execute();
        
        // Convertir resultado a array
        $data = [];
        foreach ($result as $row) {
            $data[] = $row;
        }
        
        // Retornar datos y metadatos
        return [
            'data' => $data,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => $totalPages
        ];
    }
    
    /**
     * Obtiene las columnas de una tabla
     *
     * @param string $table
     * @return array
     */
    private function getTableColumns(string $table): array
    {
        $sql = "SHOW COLUMNS FROM `{$table}`";
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute();
        
        $columns = [];
        foreach ($result as $row) {
            $columns[] = $row['Field'];
        }
        
        return $columns;
    }
    
    /**
     * Ejecuta una consulta SQL y devuelve todos los resultados
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($params);
        
        $data = [];
        foreach ($result as $row) {
            $data[] = $row;
        }
        
        return $data;
    }
    
    /**
     * Ejecuta una consulta SQL y devuelve una fila
     *
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($params);
        
        if ($result->count() > 0) {
            return $result->current();
        }
        
        return null;
    }
    
    /**
     * Ejecuta una consulta de tipo INSERT, UPDATE o DELETE
     *
     * @param string $sql
     * @param array $params
     * @return int Número de filas afectadas
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($params);
        
        return $result->getAffectedRows();
    }
    
    /**
     * Inserta un lote de registros en una tabla
     *
     * @param string $table
     * @param array $columns
     * @param array $valuesBatch
     * @return int
     */
    public function batchInsert(string $table, array $columns, array $valuesBatch): int
    {
        if (empty($valuesBatch)) {
            return 0;
        }
        
        // Preparar la consulta
        $placeholders = [];
        foreach ($valuesBatch as $values) {
            $rowPlaceholders = [];
            foreach ($values as $value) {
                $rowPlaceholders[] = '?';
            }
            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }
        
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s',
            $table,
            implode(', ', array_map(function($col) { return "`$col`"; }, $columns)),
            implode(', ', $placeholders)
        );
        
        // Aplanar el array de valores
        $params = [];
        foreach ($valuesBatch as $values) {
            foreach ($values as $value) {
                $params[] = $value;
            }
        }
        
        // Ejecutar la consulta
        $statement = $this->dbAdapter->createStatement($sql);
        $result = $statement->execute($params);
        
        return $result->getAffectedRows();
    }
}