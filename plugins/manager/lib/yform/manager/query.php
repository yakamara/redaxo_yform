<?php

class rex_yform_manager_query implements IteratorAggregate, Countable
{
    private $table;

    private $alias;
    private $selectResetted = false;
    private $select = [];

    private $joins = [];

    private $whereOperator = 'AND';
    private $where = [];
    private $params = [];
    private $paramCounter = 1;

    private $orderBy = [];
    private $orderByResetted = false;

    private $groupBy = [];

    private $limit;

    /**
     * @param string $table
     */
    public function __construct($table)
    {
        $this->table = $table;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getQuery();
    }

    /**
     * @param string $table
     *
     * @return static
     */
    public static function get($table)
    {
        return new static($table);
    }

    /**
     * @return string
     */
    public function getTableName()
    {
        return $this->table;
    }

    /**
     * @return rex_yform_manager_table
     */
    public function getTable()
    {
        return rex_yform_manager_table::get($this->table);
    }

    /**
     * @param string $alias
     *
     * @return $this
     */
    public function alias($alias)
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * @return string
     */
    public function getTableAlias()
    {
        return $this->alias ?: $this->table;
    }

    /**
     * @return $this
     */
    public function resetSelect()
    {
        $this->select = [];
        $this->selectResetted = true;

        return $this;
    }

    /**
     * @param string|string[] $column
     * @param null|string     $alias
     *
     * @return $this
     */
    public function select($column, $alias = null)
    {
        if (!is_array($column)) {
            return $this->selectRaw($this->quoteIdentifier($column), $alias);
        }

        $this->resetSelect();
        foreach ($column as $s) {
            $this->select($s);
        }

        return $this;
    }

    /**
     * @param string|string[] $expression
     * @param null|string     $alias
     *
     * @return $this
     */
    public function selectRaw($expression, $alias = null)
    {
        if (is_array($expression)) {
            $this->resetSelect();
            $this->select = $expression;

            return $this;
        }

        if ($alias) {
            $expression .= ' AS '.$alias;
        }
        $this->select[] = $expression;

        return $this;
    }

    /**
     * @return $this
     */
    public function resetJoins()
    {
        $this->joins = [];

        return $this;
    }

    /**
     * @param string      $column
     * @param null|string $alias
     *
     * @return $this
     */
    public function joinRelation($column, $alias = null)
    {
        return $this->joinTypeRelation('INNER', $column, $alias);
    }

    /**
     * @param string      $column
     * @param null|string $alias
     *
     * @return $this
     */
    public function leftJoinRelation($column, $alias = null)
    {
        return $this->joinTypeRelation('LEFT', $column, $alias);
    }

    /**
     * @param string      $type   "INNER", "LEFT", "RIGHT"...
     * @param string      $column
     * @param null|string $alias
     *
     * @return $this
     */
    public function joinTypeRelation($type, $column, $alias = null)
    {
        $relation = $this->getTable()->getRelation($column);

        if (!$relation) {
            throw new InvalidArgumentException(sprintf('Column "%s" is not a be_manager_relation column', $column));
        }

        $relatedTable = $alias ?: $relation['table'];

        if (4 == $relation['type'] || 5 == $relation['type']) {
            return $this->joinType($type, $relation['table'], $alias, $this->getTableAlias().'.id', $relatedTable.'.'.$relation['field']);
        }

        $relatedField = $relatedTable.'.id';

        if (empty($relation['relation_table'])) {
            $column = $this->getTableAlias().'.'.$column;
            $operator = in_array($relation['type'], [1, 3]) ? 'FIND_IN_SET' : '=';

            return $this->joinType($type, $relation['table'], $alias, $relatedField, $column, $operator);
        }

        $relationColumns = $this->getTable()->getRelationTableColumns($column);

        return $this
            ->joinType($type, $relation['relation_table'], null, $this->getTableAlias().'.id', $relation['relation_table'].'.'.$relationColumns['source'])
            ->joinType($type, $relation['table'], $alias, $relation['relation_table'].'.'.$relationColumns['target'], $relatedField);
    }

    /**
     * @param string      $table
     * @param null|string $alias
     * @param null|string $column1
     * @param null|string $column2
     * @param null|string $operator
     *
     * @return $this
     */
    public function join($table, $alias = null, $column1 = null, $column2 = null, $operator = null)
    {
        return $this->joinType('INNER', $table, $alias, $column1, $column2, $operator);
    }

    /**
     * @param string      $table
     * @param null|string $alias
     * @param null|string $column1
     * @param null|string $column2
     * @param null|string $operator
     *
     * @return $this
     */
    public function leftJoin($table, $alias = null, $column1 = null, $column2 = null, $operator = null)
    {
        return $this->joinType('LEFT', $table, $alias, $column1, $column2, $operator);
    }

    /**
     * @param string      $type     "INNER", "LEFT", "RIGHT"...
     * @param string      $table
     * @param null|string $alias
     * @param null|string $column1
     * @param null|string $column2
     * @param null|string $operator
     *
     * @return $this
     */
    public function joinType($type, $table, $alias = null, $column1 = null, $column2 = null, $operator = null)
    {
        $condition = null;
        if ($column1 && $column2) {
            $column1 = $this->quoteIdentifier($column1);
            $column2 = $this->quoteIdentifier($column2);
            $operator = mb_strtoupper($operator) ?: '=';
            if ('FIND_IN_SET' === $operator) {
                $condition = sprintf('FIND_IN_SET(%s, %s)', $column1, $column2);
            } else {
                $condition = sprintf('%s %s %s', $column1, $operator, $column2);
            }
        }

        return $this->joinRaw($type, $table, $alias, $condition);
    }

    /**
     * @param string      $type      "INNER", "LEFT", "RIGHT"...
     * @param string      $table
     * @param null|string $alias
     * @param null|string $condition
     *
     * @return $this
     */
    public function joinRaw($type, $table, $alias = null, $condition = null)
    {
        $join = sprintf('%s JOIN `%s`', mb_strtoupper($type), $table);
        if ($alias) {
            $join .= ' AS '.$alias;
        }
        if ($condition) {
            $join .= ' ON '.$condition;
        }
        $this->joins[] = $join;

        return $this;
    }

    /**
     * @return $this
     */
    public function resetWhere()
    {
        $this->where = [];
        $this->params = [];
        $this->paramCounter = 1;

        return $this;
    }

    /**
     * @param string $operator "AND" or "OR"
     *
     * @return $this
     */
    public function setWhereOperator($operator)
    {
        $this->whereOperator = $operator;

        return $this;
    }

    /**
     * @param string      $column
     * @param mixed       $value
     * @param null|string $operator
     *
     * @return $this
     */
    public function where($column, $value, $operator = null)
    {
        if (is_array($value)) {
            $param = [];
            foreach ($value as $v) {
                $param[] = $this->addParam($v);
            }
            $param = '('.implode(', ', $param).')';

            $operator = $operator ?: 'IN';
        } else {
            $param = $this->addParam($value);

            $operator = $operator ?: '=';
        }

        $this->where[] = sprintf('%s %s %s', $this->quoteIdentifier($column), $operator, $param);

        return $this;
    }

    /**
     * @param string $column
     * @param mixed  $value
     *
     * @return $this
     */
    public function whereNot($column, $value)
    {
        $operator = is_array($value) ? 'NOT IN' : '!=';

        return $this->where($column, $value, $operator);
    }

    /**
     * @param string $column
     *
     * @return $this
     */
    public function whereNull($column)
    {
        $this->where[] = $this->quoteIdentifier($column).' IS NULL';

        return $this;
    }

    /**
     * @param string $column
     *
     * @return $this
     */
    public function whereNotNull($column)
    {
        $this->where[] = $this->quoteIdentifier($column).' IS NOT NULL';

        return $this;
    }

    /**
     * @param string $column
     * @param mixed  $from
     * @param mixed  $to
     *
     * @return $this
     */
    public function whereBetween($column, $from, $to)
    {
        $this->where[] = sprintf('%s BETWEEN %s AND %s', $this->quoteIdentifier($column), $this->addParam($from), $this->addParam($to));

        return $this;
    }

    /**
     * @param string $column
     * @param mixed  $from
     * @param mixed  $to
     *
     * @return $this
     */
    public function whereNotBetween($column, $from, $to)
    {
        $this->where[] = sprintf('%s NOT BETWEEN %s AND %s', $this->quoteIdentifier($column), $this->addParam($from), $this->addParam($to));

        return $this;
    }

    /**
     * Where the comma separated list column contains the given value or any of the given values.
     *
     * @param string           $column Column with comma separated list
     * @param string|int|int[] $value  Single value (string or int) or array of values (ints only)
     *
     * @return $this
     */
    public function whereListContains($column, $value)
    {
        if (!is_array($value)) {
            $this->where[] = sprintf('FIND_IN_SET(%s, %s)', $this->addParam($value), $this->quoteIdentifier($column));

            return $this;
        }

        $regex = '(^|,)('.implode('|', array_map('intval', $value)).')(,|$)';
        $this->where[] = sprintf('%s REGEXP %s', $this->quoteIdentifier($column), $this->addParam($regex));

        return $this;
    }

    /**
     * @param string $where
     *
     * @return $this
     */
    public function whereRaw($where, array $params = [])
    {
        $this->where[] = $where;
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    /**
     * @param array|callable $nested
     * @param string         $operator
     *
     * @return $this
     */
    public function whereNested($nested, $operator = 'AND')
    {
        $operator = strtoupper(trim($operator));

        if (is_array($nested)) {
            $this->where[] = $this->buildNestedWhere($nested, $operator);

            return $this;
        }

        $query = new self($this->table);
        $query->whereOperator = $operator;
        $query->paramCounter = $this->paramCounter;

        call_user_func($nested, $query);

        if ($query->where) {
            $this->where[] = '('.implode(' '.$operator.' ', $query->where).')';
            $this->params = array_merge($this->params, $query->params);
            $this->paramCounter = $query->paramCounter;
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function resetGroupBy()
    {
        $this->groupBy = [];

        return $this;
    }

    /**
     * @param string $column
     *
     * @return $this
     */
    public function groupBy($column)
    {
        $this->groupBy[] = $this->quoteIdentifier($column);

        return $this;
    }

    /**
     * @param string $expression
     *
     * @return $this
     */
    public function groupByRaw($expression)
    {
        $this->groupBy[] = $expression;

        return $this;
    }

    /**
     * @return $this
     */
    public function resetOrderBy()
    {
        $this->orderBy = [];
        $this->orderByResetted = true;

        return $this;
    }

    /**
     * @param string $column
     * @param string $direction
     *
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->orderBy[] = $this->quoteIdentifier($column).' '.$direction;

        return $this;
    }

    /**
     * @param string $expression
     * @param string $direction
     *
     * @return $this
     */
    public function orderByRaw($expression, $direction = 'asc')
    {
        $this->orderBy[] = $expression.' '.$direction;

        return $this;
    }

    /**
     * @return $this
     */
    public function resetLimit()
    {
        $this->limit = null;

        return $this;
    }

    /**
     * @param int $offsetOrRowCount
     * @param int $rowCount
     *
     * @return $this
     */
    public function limit($offsetOrRowCount, $rowCount = null)
    {
        $this->limit = $rowCount ? $offsetOrRowCount.', '.$rowCount : $offsetOrRowCount;

        return $this;
    }

    /**
     * @return string
     */
    public function getQuery()
    {
        $select = $this->select;
        if (!$this->selectResetted) {
            array_unshift($select, sprintf('`%s`.*', $this->alias ?: $this->table));
        }

        $query = sprintf('SELECT %s FROM `%s`', implode(', ', $select), $this->table);

        if ($this->alias) {
            $query .= ' AS '.$this->alias;
        }

        if ($this->joins) {
            $query .= ' '.implode(' ', $this->joins);
        }

        if ($this->where) {
            $query .= ' WHERE '.implode(' '.$this->whereOperator.' ', $this->where);
        }

        if ($this->groupBy) {
            $query .= ' GROUP BY '.implode(', ', $this->groupBy);
        }

        if (!$this->orderBy && !$this->orderByResetted) {
            $table = $this->getTable();
            $this->orderBy($this->getTableAlias().'.'.$table->getSortFieldName(), $table->getSortOrderName());
        }

        if ($this->orderBy) {
            $query .= ' ORDER BY '.implode(', ', $this->orderBy);
        }

        if ($this->limit) {
            $query .= ' LIMIT '.$this->limit;
        }

        return $query;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * @return rex_yform_manager_collection
     */
    public function getIterator()
    {
        return $this->find();
    }

    /**
     * @return rex_yform_manager_collection
     */
    public function find()
    {
        return rex_yform_manager_dataset::queryCollection($this->getQuery(), $this->getParams(), $this->table);
    }

    /**
     * @return rex_yform_manager_collection
     */
    public function paginate(rex_pager $pager)
    {
        $pager->setRowCount($this->count());
        $this->limit($pager->getCursor(), $pager->getRowsPerPage());

        return $this->find();
    }

    /**
     * @param int[] $ids
     *
     * @return rex_yform_manager_collection
     */
    public function findIds($ids)
    {
        return $this->where('id', $ids)->find();
    }

    /**
     * @return null|rex_yform_manager_dataset
     */
    public function findOne()
    {
        $this->limit(1);

        return rex_yform_manager_dataset::queryOne($this->getQuery(), $this->getParams(), $this->table);
    }

    /**
     * @param int $id
     *
     * @return null|rex_yform_manager_dataset
     */
    public function findId($id)
    {
        return $this->where('id', $id)->resetOrderBy()->findOne();
    }

    /**
     * @param string $column
     *
     * @return mixed
     */
    public function findValue($column)
    {
        return $this->findValueRaw($this->quoteIdentifier($column));
    }

    /**
     * @param string $expression
     *
     * @return mixed
     */
    public function findValueRaw($expression)
    {
        $query = clone $this;
        $query
            ->resetSelect()
            ->selectRaw($expression, 'value')
            ->limit(1);

        $sql = rex_sql::factory();
        $sql->setQuery($query->getQuery(), $query->getParams());

        return $sql->getRows() ? $sql->getValue('value') : null;
    }

    /**
     * @param string $column
     * @param string $keyColumn
     *
     * @return array
     */
    public function findValues($column, $keyColumn = null)
    {
        return $this->findValuesRaw($this->quoteIdentifier($column), $keyColumn);
    }

    /**
     * @param string $expression
     * @param string $keyColumn
     *
     * @return array
     */
    public function findValuesRaw($expression, $keyColumn = null)
    {
        $query = clone $this;
        $query
            ->resetSelect()
            ->selectRaw($expression, 'value');

        if ($keyColumn) {
            $query->select($keyColumn);
        }

        $sql = rex_sql::factory();
        $array = $sql->getArray($query->getQuery(), $query->getParams());

        return array_column($array, 'value', $keyColumn);
    }

    /**
     * @return int
     */
    public function count()
    {
        $query = clone $this;
        $query
            ->resetSelect()
            ->selectRaw('COUNT(*)', 'count')
            ->resetOrderBy();

        $sql = rex_sql::factory();
        $sql->setQuery($query->getQuery(), $query->getParams());

        return $sql->getValue('count');
    }

    /**
     * @return bool
     */
    public function exists()
    {
        $query = clone $this;
        $query
            ->resetSelect()
            ->selectRaw('1')
            ->resetOrderBy()
            ->limit(1);

        $sql = rex_sql::factory();
        $sql->setQuery($query->getQuery(), $query->getParams());

        return $sql->getRows() > 0;
    }

    public function quoteIdentifier($identifier)
    {
        $identifier = explode('.', $identifier, 2);
        foreach ($identifier as &$part) {
            if ('*' !== $part) {
                $part = '`'.$part.'`';
            }
        }

        return implode('.', $identifier);
    }

    private function normalizeValue($value)
    {
        if (is_bool($value)) {
            return (int) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(rex_sql::FORMAT_DATETIME);
        }

        if (is_object($value)) {
            return (string) $value;
        }

        return $value;
    }

    private function addParam($value)
    {
        $param = 'p'.$this->paramCounter++;
        $this->params[$param] = $this->normalizeValue($value);

        return ':'.$param;
    }

    private function buildNestedWhere(array $where, $operator = 'AND')
    {
        $nextOperator = 'AND' === $operator ? 'OR' : 'AND';

        foreach ($where as $key => &$value) {
            if (is_array($value)) {
                $value = $this->buildNestedWhere($value, $nextOperator);
                continue;
            }

            $value = sprintf('%s = %s', $this->quoteIdentifier($key), $this->addParam($value));
        }

        return implode(' '.$operator.' ', $where);
    }
}
