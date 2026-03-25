<?php

namespace App\Core;

use PDO;

abstract class BaseModel
{
    protected $db;
    protected $table;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Generic paginated fetch with search and filtering.
     * 
     * @param string $sql The base SQL query (e.g. "SELECT * FROM users")
     * @param array $params Initial PDO parameters (e.g. [':tenant_id' => 1])
     * @param array $filters Request filters (page, per_page, search, etc.)
     * @param array $searchColumns Columns to search against (e.g. ['name', 'email'])
     * @param string|null $orderBy Optional ORDER BY clause
     * @return array ['data' => ..., 'pagination' => ...]
     */
    protected function fetchPaginated($sql, $params, $filters, $searchColumns = [], $orderBy = null, $idColumn = 'id')
    {
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));
        $offset  = ($page - 1) * $perPage;

        // 1. Build WHERE clause for Search
        $whereSearch = "";
        if (!empty($filters['search']) && !empty($searchColumns)) {
            $searchParts = [];
            foreach ($searchColumns as $index => $col) {
                $paramName = ":search_" . $index;
                $searchParts[] = "$col LIKE $paramName";
                $params[$paramName] = '%' . $filters['search'] . '%';
            }
            $whereSearch = "(" . implode(" OR ", $searchParts) . ")";
        }

        // 2. Inject Search into Sql
        if ($whereSearch) {
            $hasWhere = stripos($sql, 'WHERE') !== false;
            $sql .= ($hasWhere ? " AND " : " WHERE ") . $whereSearch;
        }

        // 3. Get Total Count
        $countSql = "SELECT COUNT(*) as total FROM ($sql) as subquery";
        $stmt = $this->db->prepare($countSql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->execute();
        $total = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // 4. Build Final Sort (Ensuring Stability)
        $finalSort = $orderBy;
        if (!empty($filters['sort'])) {
            $direction = (isset($filters['order']) && strtolower($filters['order']) === 'desc') ? 'DESC' : 'ASC';
            $finalSort = $filters['sort'] . " " . $direction;
        }

        // Always append a unique ID column to the sort for absolute stability across pages
        if ($finalSort) {
            // Check if $idColumn is already in $finalSort to avoid duplication
            if (stripos($finalSort, $idColumn) === false) {
                $sql .= " ORDER BY $finalSort, $idColumn DESC";
            } else {
                $sql .= " ORDER BY $finalSort";
            }
        } else {
            $sql .= " ORDER BY $idColumn DESC";
        }

        $sql .= " LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $stmt->execute();
        
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page,
                'per_page'     => $perPage,
                'total'        => $total,
                'last_page'    => (int) ceil($total / $perPage),
            ]
        ];
    }
}
