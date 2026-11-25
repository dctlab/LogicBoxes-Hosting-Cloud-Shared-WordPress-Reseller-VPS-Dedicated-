<?php
/**
 * Minimal in-memory stand-in for Illuminate\Database\Capsule\Manager, covering
 * only the query-builder subset this suite uses. Lets the lifecycle be tested
 * end-to-end on a machine without WHMCS or Laravel. NOT loaded in production.
 */

declare(strict_types=1);

namespace Illuminate\Database\Capsule;

final class Manager
{
    /** @var array<string, array<int, array<string,mixed>>> */
    public static array $tables = [];

    /** @var array<string, array<int, string[]>> table => list of unique column sets */
    public static array $unique = [];

    /** @var array<string,int> */
    public static array $autoinc = [];

    public static function reset(): void
    {
        self::$tables = [];
        self::$autoinc = [];
        self::$unique = ['mod_lbh_renewals' => [['service_id', 'period_key']], 'mod_lbh_services' => [['service_id']], 'mod_lbh_addons' => [['service_id', 'addon']], 'mod_lbh_manual_tasks' => [['service_id', 'item', 'target']]];
    }

    public static function table(string $name): FakeQuery
    {
        self::$tables[$name] = self::$tables[$name] ?? [];

        return new FakeQuery($name);
    }

    public static function schema(): FakeSchema
    {
        return new FakeSchema();
    }
}

final class FakeSchema
{
    public function hasTable(string $t): bool
    {
        return true;
    }

    public function hasColumn(string $t, string $c): bool
    {
        return true;
    }
}

final class FakeQuery
{
    private string $table;

    /** @var array<int, callable> */
    private array $wheres = [];

    /** @var array<int, array{0:string,1:string,2:string}> */
    private array $joins = [];

    private ?array $order = null;

    private ?int $limit = null;

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function join(string $table, string $a, string $op, string $b): self
    {
        $this->joins[] = [$table, $a, $b];

        return $this;
    }

    private static function col(array $row, string $col)
    {
        if (array_key_exists($col, $row)) {
            return $row[$col];
        }
        $short = strpos($col, '.') !== false ? substr($col, strpos($col, '.') + 1) : $col;

        return $row[$short] ?? null;
    }

    public function where($col, $op = null, $val = null): self
    {
        if ($col instanceof \Closure) {
            $sub = new self($this->table);
            $col($sub);
            $this->wheres[] = static fn (array $r) => $sub->matches($r);

            return $this;
        }
        if (func_num_args() === 2) {
            $val = $op;
            $op = '=';
        }
        $this->wheres[] = static function (array $r) use ($col, $op, $val) {
            $v = self::col($r, $col);
            switch ($op) {
                case '=': return (string) $v === (string) $val;
                case '!=': return (string) $v !== (string) $val;
                case '>=': return $v !== null && $v >= $val;
                case '<=': return $v !== null && $v <= $val;
                case '<': return $v !== null && $v < $val;
                case '>': return $v !== null && $v > $val;
            }

            return false;
        };

        return $this;
    }

    /** @var array<int, array<int, callable>> */
    private array $orGroups = [];

    public function orWhere($col, $op = null, $val = null): self
    {
        $prev = array_pop($this->wheres);
        $tmp = new self($this->table);
        func_num_args() === 2 ? $tmp->where($col, $op) : $tmp->where($col, $op, $val);
        $new = $tmp->wheres[0];
        $this->wheres[] = static fn (array $r) => ($prev ? $prev($r) : false) || $new($r);

        return $this;
    }

    public function whereIn(string $col, array $vals): self
    {
        $this->wheres[] = static fn (array $r) => in_array((string) self::col($r, $col), array_map('strval', $vals), true);

        return $this;
    }

    public function whereNotNull(string $col): self
    {
        $this->wheres[] = static fn (array $r) => self::col($r, $col) !== null;

        return $this;
    }

    public function matches(array $r): bool
    {
        foreach ($this->wheres as $w) {
            if (!$w($r)) {
                return false;
            }
        }

        return true;
    }

    public function orderBy(string $col, string $dir = 'asc'): self
    {
        $this->order = [$col, strtolower($dir)];

        return $this;
    }

    public function limit(int $n): self
    {
        $this->limit = $n;

        return $this;
    }

    private ?array $select = null;

    public function select($cols = []): self
    {
        $this->select = is_array($cols) ? $cols : func_get_args();

        return $this;
    }

    /** Apply a select() list: "t.col", "t.col as alias", "t.*" (joined tables use "t.col" keys). */
    private function project(array $row): array
    {
        if (!$this->select) {
            return $row;
        }
        $out = [];
        foreach ($this->select as $spec) {
            if (preg_match('/^(\w+)\.\*$/', $spec, $m)) {
                foreach ($row as $k => $v) {
                    if (strpos($k, $m[1] . '.') === 0) {
                        $out[substr($k, strlen($m[1]) + 1)] = $v;
                    } elseif ($m[1] === $this->table && strpos($k, '.') === false) {
                        $out[$k] = $v;
                    }
                }
                continue;
            }
            $alias = null;
            if (preg_match('/^(.+)\s+as\s+(\w+)$/i', $spec, $m)) {
                [$spec, $alias] = [trim($m[1]), $m[2]];
            }
            [$tbl, $c] = strpos($spec, '.') !== false ? explode('.', $spec, 2) : [$this->table, $spec];
            $val = $tbl === $this->table ? ($row[$c] ?? null) : ($row[$tbl . '.' . $c] ?? null);
            $out[$alias ?? $c] = $val;
        }

        return $out;
    }

    public function selectRaw(string $sql): self
    {
        return $this;
    }

    private ?string $group = null;

    public function groupBy(string $col): self
    {
        $this->group = $col;

        return $this;
    }

    /** @return array<int, array<string,mixed>> */
    private function rows(): array
    {
        $rows = Manager::$tables[$this->table];
        foreach ($this->joins as [$t, $a, $b]) {
            $out = [];
            foreach ($rows as $r) {
                foreach (Manager::$tables[$t] ?? [] as $j) {
                    $pref = [];
                    foreach ($j as $k => $v) {
                        $pref[$t . '.' . $k] = $v;
                    }
                    $merged = $r + $pref;
                    $left = self::col($merged, $a);
                    $right = self::col($merged, $b);
                    if ($left !== null && (string) $left === (string) $right) {
                        $out[] = $merged;
                    }
                }
            }
            $rows = $out;
        }
        $rows = array_values(array_filter($rows, fn ($r) => $this->matches($r)));
        if ($this->order) {
            [$c, $d] = $this->order;
            usort($rows, static fn ($x, $y) => ($d === 'desc' ? -1 : 1) * (self::col($x, $c) <=> self::col($y, $c)));
        }
        if ($this->limit !== null) {
            $rows = array_slice($rows, 0, $this->limit);
        }

        return $rows;
    }

    public function get(): FakeCollection
    {
        return new FakeCollection(array_map(fn ($r) => (object) $this->project($r), $this->rows()));
    }

    public function first(): ?object
    {
        $r = $this->rows();

        return $r ? (object) $this->project($r[0]) : null;
    }

    public function value(string $col)
    {
        $r = $this->rows();

        return $r ? self::col($r[0], $col) : null;
    }

    public function exists(): bool
    {
        return (bool) $this->rows();
    }

    public function count(): int
    {
        return count($this->rows());
    }

    public function max(string $col)
    {
        $vals = array_map(static fn ($r) => self::col($r, $col), $this->rows());

        return $vals ? max($vals) : null;
    }

    public function sum(string $col)
    {
        return array_sum(array_map(static fn ($r) => self::col($r, $col), $this->rows()));
    }

    public function pluck(string $col, ?string $key = null): FakeCollection
    {
        $out = [];
        if ($this->group !== null) { // only "count(*) grouped" is supported
            foreach ($this->rows() as $r) {
                $g = (string) self::col($r, $this->group);
                $out[$g] = ($out[$g] ?? 0) + 1;
            }

            return new FakeCollection($out);
        }
        foreach ($this->rows() as $r) {
            $key === null ? $out[] = self::col($r, $col) : $out[self::col($r, $key)] = self::col($r, $col);
        }

        return new FakeCollection($out);
    }

    public function insert(array $row): bool
    {
        $this->insertGetId($row);

        return true;
    }

    public function insertGetId(array $row): int
    {
        foreach (Manager::$unique[$this->table] ?? [] as $cols) {
            foreach (Manager::$tables[$this->table] as $existing) {
                $dup = true;
                foreach ($cols as $c) {
                    if ((string) ($existing[$c] ?? '') !== (string) ($row[$c] ?? '')) {
                        $dup = false;
                    }
                }
                if ($dup) {
                    throw new \RuntimeException('Duplicate entry for unique key on ' . $this->table);
                }
            }
        }
        $id = (Manager::$autoinc[$this->table] = (Manager::$autoinc[$this->table] ?? 0) + 1);
        if (!isset($row['id'])) {
            $row = ['id' => $id] + $row;
        }
        Manager::$tables[$this->table][] = $row;

        return (int) $row['id'];
    }

    public function update(array $vals): int
    {
        $n = 0;
        foreach (Manager::$tables[$this->table] as $i => $r) {
            if ($this->matches($r)) {
                Manager::$tables[$this->table][$i] = array_merge($r, $vals);
                $n++;
            }
        }

        return $n;
    }

    public function updateOrInsert(array $attrs, array $vals): bool
    {
        foreach ($attrs as $k => $v) {
            $this->where($k, $v);
        }
        if ($this->exists()) {
            $this->update($vals);
        } else {
            (new self($this->table))->insert(array_merge($attrs, $vals));
        }

        return true;
    }

    public function delete(): int
    {
        $before = count(Manager::$tables[$this->table]);
        Manager::$tables[$this->table] = array_values(array_filter(Manager::$tables[$this->table], fn ($r) => !$this->matches($r)));

        return $before - count(Manager::$tables[$this->table]);
    }
}

final class FakeCollection implements \IteratorAggregate
{
    private array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
