<?php

namespace Ozdemir\Datatables;

use Ozdemir\Datatables\DB\DatabaseInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class Datatables
 *
 * @package Ozdemir\Datatables
 */
class Datatables
{
    /**
     * @var \Ozdemir\Datatables\DB\DatabaseInterface
     */
    protected $db;

    /**
     * @var array
     */
    protected $data;

    /**
     * @var
     */
    protected $recordstotal;

    /**
     * @var
     */
    protected $recordsfiltered;

    /**
     * @var \Ozdemir\Datatables\ColumnCollection
     */
    protected $columns;

    /**
     * @var \Ozdemir\Datatables\Query
     */
    protected $query;

    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    protected $request;

    /**
     * Datatables constructor.
     *
     * @param \Ozdemir\Datatables\DB\DatabaseInterface $db
     * @param \Symfony\Component\HttpFoundation\Request|null $request
     */
    public function __construct(DatabaseInterface $db, Request $request = null)
    {
        $this->db = $db->connect();
        $this->request = $request ?: Request::createFromGlobals();
    }

    /**
     * @param $query
     * @return $this
     */
    public function query($query)
    {
        $this->query = new Query($query);
        $this->columns = new ColumnCollection($this->query);

        return $this;
    }

    /**
     * @param $column
     * @param $closure callable
     * @return $this
     */
    public function add($column, $closure)
    {
        $this->columns->add($column, $closure);

        return $this;
    }

    /**
     * @param $column
     * @param $closure callable
     * @return $this
     */
    public function edit($column, $closure)
    {
        $this->columns->edit($column, $closure);

        return $this;
    }

    /**
     * @param $request
     * @return mixed
     */
    public function get($request)
    {
        switch ($request) {
            case 'columns':
                return $this->columns->names();
            case 'query':
                return $this->query->full;
        }
    }

    /**
     * @param $columns
     * @return $this
     */
    public function hide($columns)
    {
        if (!is_array($columns)) {
            $columns = func_get_args();
        }
        foreach ($columns as $name) {
            $this->columns->get($name)->hide();
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function execute()
    {
        $this->columns->setAttr($this->request);

        $this->recordstotal = $this->db->count($this->query->base); // unfiltered data count is here.
        $where = $this->filter();
        $this->recordsfiltered = $this->db->count($this->query->base.$where);  // filtered data count is here.

        $this->query->full = $this->query->base.$where.$this->orderBy().$this->limit();
        $this->data = $this->db->query($this->query->full);

        return $this;
    }

    /**
     * @return string
     */
    protected function filter()
    {
        $filter = array_filter([$this->filterGlobal(), $this->filterIndividual()]);

        if (count($filter) > 0) {
            return ' WHERE '.implode(' AND ', $filter);
        }

        return '';
    }

    /**
     * @return string
     */
    protected function filterGlobal()
    {
        $searchinput = $this->request->get('search')['value'];

        if ($searchinput === null) {
            return '';
        }

        $columns = $this->columns->getSearchable();

        if (count($columns) === 0) {
            return '';
        }

        $search = [];
        $searchinput = preg_replace("/\W+/u", ' ', $searchinput);

        foreach (explode(' ', $searchinput) as $word) {
            $look = [];

            foreach ($columns as $column) {
                $look[] = $column->name.' LIKE '.$this->db->escape($word);
            }

            $search[] = '('.implode(' OR ', $look).')';
        }

        return implode(' AND ', $search);
    }

    /**
     * @return string
     */
    protected function filterIndividual()
    {
        $columns = $this->columns->getSearchableWithSearchValue();

        if (count($columns) === 0) {
            return '';
        }

        $look = [];

        foreach ($columns as $column) {
            $look[] = $column->name.' LIKE '.$this->db->escape($column->searchValue());
        }

        return ' ('.implode(' AND ', $look).')';
    }

    /**
     * @return string
     */
    protected function limit()
    {
        $take = 10;
        $skip = (integer)$this->request->get('start');

        if ($this->request->get('length')) {
            $take = (integer)$this->request->get('length');
        }

        if ($take === -1 || !$this->request->get('draw')) {
            return '';
        }

        return " LIMIT $take OFFSET $skip";
    }

    /**
     * @return string
     */
    protected function orderBy()
    {
        $orders = $this->request->get('order') ?: [];

        $orders = array_filter($orders, function ($order) {
            return in_array($order['dir'], ['asc', 'desc'],
                    true) && $this->columns->getByIndex($order['column'])->isOrderable();
        });

        $o = [];

        if (count($orders) === 0) {
            if ($this->query->hasDefaultOrder()) {
                return '';
            }
            $o[] = $this->columns->getByIndex(0)->name.' asc';
        }

        foreach ($orders as $order) {
            $o[] = $this->columns->getByIndex($order['column'])->name.' '.$order['dir'];
        }

        return ' ORDER BY '.implode(',', $o);
    }

    /**
     * @param bool $json
     * @return mixed
     */
    public function generate($json = true)
    {
        $this->execute();
        $data = $this->formatData();

        return $this->response($data, $json);
    }

    /**
     * @return array
     */
    private function formatData()
    {
        $formatted_data = [];
        $columns = $this->columns->all(false);

        foreach ($this->data as $row) {
            $formatted_row = [];

            foreach ($columns as $column) {
                $attr = $column->attr('data');
                if (is_numeric($attr)) {
                    $formatted_row[] = $column->closure($row, $column->name);
                } else {
                    $formatted_row[$column->name] = $column->closure($row, $column->name);
                }
            }
            $formatted_data[] = $formatted_row;

        }

        return $formatted_data;
    }

    /**
     * @param $data
     * @param bool $json
     * @return mixed
     */
    protected function response($data, $json = true)
    {
        $response = [];
        $response['draw'] = (integer)$this->request->get('draw');
        $response['recordsTotal'] = $this->recordstotal;
        $response['recordsFiltered'] = $this->recordsfiltered;
        $response['data'] = $data;

        if ($json) {
            header('Content-type: application/json');

            return json_encode($response);

        }

        return $response;
    }
}
