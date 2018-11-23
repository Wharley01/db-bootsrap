<?php


namespace Data;
load_class("Database/Connection");

use Connection\DB;
use Connection\Mysql;
use Path\DatabaseException;

abstract class Model
{
    private   $conn;
    protected $table_name;
    private   $model_name;
    protected $primary_key     = "id";
    protected $updated_col     = "updated_at";
    protected $created_col     = "created_at";
    protected $record_per_page = 10;
    private $query_structure = [
        "WITH"              => "",
        "SELECT"            => "",
        "JOIN"              => "",//table to join
        "JOIN_TYPE"         => "",
        "ON"                => "",//associative array holding join condition
        "INSERT"            => "",
        "UPDATE"            => "",
        "WHERE"             => "",
        "GROUP_BY"          => "",
        "HAVING"            => "",
        "ORDER_BY"          => "",
        "LIMIT"             => ""
    ];

    public    $params               = [
        "WHERE"     => [],
        "UPDATE"    => [],
        "INSERT"    => [],
        "SORT"      => [],
        "LIMIT"     => []
    ];
    protected $writable_cols        = [];//writable columns(Can be overridden)
    protected $non_writable_cols       = [];//non writable (Can be overridden)

    protected $readable_cols        = [];//readable columns(Can be overridden)
    protected $non_readable_cols    = [];//non readable (Can be overridden)

    private   $writing              = [];//currently updated column and value
    public    $last_insert_id;
    private   $table_cols;

    protected $fetch_method         = "FETCH_ASSOC";
    private   $pages                = [];
    private $total_record;

    public function __construct()
    {
        $this->conn = (new Mysql())->connection;
        $this->table_cols = $this->getColumns($this->table_name);
        $this->model_name =  get_class($this);

    }
    private function getColumns($table){
        try{
            $q = $this->conn->query("DESCRIBE {$this->table_name}");
            $cols = [];
            foreach ($q as $k){
                $cols[] = $k["Field"];
            }
            return $cols;
        }catch (\PDOException $e){
            throw new DatabaseException($e->getMessage());
        }

    }
    public function __set($name, $value)
    {
        $class_name = get_class($this);
        if($this->writable_cols && !in_array($name,$this->writable_cols))
            throw new DatabaseException("\"{$name}\" column is not writable in {$class_name}");

        if($this->non_writable_cols && in_array($name,$this->non_writable_cols))
            throw new DatabaseException("\"{$name}\" column is not writable in {$class_name}");

        $this->writing[$name] = $value;
        // TODO: Implement __set() method.
    }


    public function raw_str(){

    }
    /**
     * @param $table
     * @return $this
     */
    public function table(String $table){
        $this->table_name = $table;
        return $this;
    }
    private function where_gen(
        $conditions,
        $logic_gate = "AND"
    ){
        if(is_array($conditions)){
            $where = $this->query_structure["WHERE"];
            $str   = "";

            foreach($conditions as $condition => $value){
                $str .= " {$condition} = ? {$logic_gate} ";
                $this->params["WHERE"][] = $value;
            }
//            Remove trailing "AND"
            $str = preg_replace("/($logic_gate)\s*$/","",$str);
            if(!$this->query_structure["WHERE"]){
//                If no WHERE Clause already specified, add new one
                @$this->query_structure["WHERE"] = $str;
            }else{
//                if there is already a WHERE clause, join with AND
                $this->query_structure["WHERE"] .= " {$logic_gate} ". $str;
            }
        }else if(preg_match("/\w+\s*[><=!]+\s*\w+/",$conditions)){
            $str   = "";
//          if conditions are in raw string
            $split = explode(",",$conditions);
            foreach ($split as $val){
                preg_match("/(\w+)\s*([><=!]*)\s*(\w+)/",$val,$matches);
                $str .= "{$matches[1]} {$matches[2]} ? {$logic_gate} ";
                $this->params["WHERE"][] = $matches[3];
            }
//            Remove trailing "AND"
            $str = preg_replace("/(".$logic_gate.")\s*$/","",$str);
            if(!$this->query_structure["WHERE"]){
                @$this->query_structure["WHERE"] = $str;
            }else{
                $this->query_structure["WHERE"] .= " {$logic_gate} ". $str;
            }
        }elseif(preg_match('/^[_\w]*$/',$conditions)){
            $this->query_structure["WHERE"] = $conditions;
        }else{
            throw new DatabaseException("Invalid WHERE condition");
        }
    }

    /**
     * @param $key
     * @return bool
     */
    private function isWritable($key){
        if($this->writable_cols && !in_array($key,$this->writable_cols))
            return false;

        if($this->non_writable_cols && in_array($key,$this->non_writable_cols))
            return false;

        return true;
    }
    private function isReadable($key){
        if($this->readable_cols && !in_array($key,$this->readable_cols))
            return false;

        if($this->non_readable_cols && in_array($key,$this->non_readable_cols))
            return false;

        return true;
    }
    private function filterNonWritable(Array $data){
        foreach ($data as $key => $value){
            if(!$this->isWritable($key))
                unset($data[$key]);
        }
        return $data;
    }
    private function filterNonReadable(Array $data){
        foreach ($data as $key => $value){
            if($this->isReadable($key))
                unset($data[$key]);
        }
        return $data;
    }

    /**
     * @param array $data
     * @param string $type
     */
    private function rawKeyValueBind(Array $data, $type = "UPDATE"){
        $string = "";
        foreach($data as $column => $value){
            if(@$this->query_structure[$type]){
                $string .= ",{$column} = ?,";
                $this->params[$type][] = $value;
            }else{
                $string .= "{$column} = ?,";
                $this->params[$type][] = $value;
            }

        }
        $string = preg_replace("/,\s*$/","",$string);//remove trailing comma

        @$this->query_structure[$type] .= $string;
    }
    /**
     * @param $conditions
     * @return $this
     */
    public function where(
        $conditions
    ){
        $this->where_gen($conditions,"AND");
        return $this;
    }
    public function rawWhere(
        $where,
        $params = null
    ){
        $this->params["WHERE"] = array_merge($this->params["WHERE"],$params);
        $this->query_structure["WHERE"] .= $where;
        return $this;
    }
    public function identify(
        $id = false
    ){
            if(!$this->primary_key)
                throw new DatabaseException("specify primary key in {$this->model_name}");
            if($id === false)
                throw new DatabaseException("specify id in identify method of \"{$this->model_name}\"");

        $this->where_gen([$this->primary_key => $id],"AND");
        return $this;
    }

    public function orWhere(
        $conditions,
        $params = null
    ){
        $this->where_gen($conditions,"OR");
        return $this;
    }
    private function rawColumnGen($cols){
        if($this->query_structure["SELECT"]){
            $this->query_structure["SELECT"] .= ",".join(",",$cols);
        }else{
            $this->query_structure["SELECT"] = join(",",$cols);
        }
    }
    private function buildWriteRawQuery($command = "UPDATE"){
        switch ($command){
            case "UPDATE":
                $params     = $this->query_structure[$command];
                $command    = "UPDATE ".$this->table_name." SET ";
                $query      = $command.$params;
                if($this->query_structure["WHERE"])
                    $query .= " WHERE ".$this->query_structure["WHERE"];
                break;
            case "INSERT":
                $params     = $this->query_structure[$command];
                $command    = "INSERT INTO {$this->table_name} SET {$params}";
                $query      = $command;
                break;
            case "DELETE":
            $query      = "DELETE FROM {$this->table_name} ";

            if($this->query_structure["WHERE"])
                $query .= " WHERE ".$this->query_structure["WHERE"];
            break;
            case "SELECT":
                $params     = $this->query_structure["SELECT"];
                $query      = "SELECT SQL_CALC_FOUND_ROWS {$params} FROM {$this->table_name} ";
                if(@$this->query_structure["WHERE"])
                    $query .= " WHERE ".$this->query_structure["WHERE"];
                if(@$this->query_structure['ORDER_BY'])
                    $query .= " ORDER BY ".$this->query_structure['ORDER_BY'];
                if(@$this->query_structure['LIMIT'])
                    $query .= " LIMIT ".$this->query_structure['LIMIT'];
                break;
            case "SORT":
                $params     = $this->query_structure["SELECT"];
                $query      = "SELECT {$params} FROM {$this->table_name} ";
                if($this->query_structure["WHERE"])
                    $query .= " WHERE ".$this->query_structure["WHERE"];
                break;
            default:
                return false;
        }


        return $query;
    }

    /**
     * @return array
     */
    public function getPages(): array
    {
        return $this->pages;
    }

    private function compileData($data){

    }
    public function update(array $data = null){
        if(!$data)
            $data = $this->writing;
        elseif($data AND is_array($data))
            $data = array_merge($this->filterNonWritable($data),$this->writing);

        if(!$data)
            throw new DatabaseException("Error Attempting to update Empty data set");
        if(!$this->table_name)
            throw new DatabaseException("No Database table name specified, Configure Your model or  ");

//        GET and set raw query from array
        $this->rawKeyValueBind($data,"UPDATE");
//        Process and execute query

        $query      = $this->buildWriteRawQuery("UPDATE");
        $params     = array_merge($this->params["UPDATE"],$this->params["WHERE"]);
//        var_dump($params);
//        echo PHP_EOL.$query;
        try{
            $prepare    = $this->conn->prepare($query);//Prepare query\
            $prepare    ->execute($params);
        }catch (\PDOException $e){
            throw new DatabaseException($e->getMessage());
        }

        return $this;
    }
    public function insert(array $data = null){
        if(!$data)
            $data = $this->writing;
        elseif($data AND is_array($data))
            $data = array_merge($this->filterNonWritable($data),$this->writing);

        if(!$data)
            throw new DatabaseException("Error Attempting to update Empty data set");
        if(!$this->table_name)
            throw new DatabaseException("No Database table name specified, Configure Your model or  ");

//        GET and set raw query from array
        $this->rawKeyValueBind($data,"INSERT");
//        Process and execute query

        $query      = $this->buildWriteRawQuery("INSERT");
        $params     = $this->params["INSERT"];
//        var_dump($params);
//        echo PHP_EOL.$query;

        try{
            $prepare    = $this->conn->prepare($query);//Prepare query\
            $prepare    ->execute($params);
            $this       ->last_insert_id = $this->conn->lastInsertId();
        }catch (\PDOException $e){
            throw new DatabaseException($e->getMessage());
        }
        return $this;
    }
    public function delete(){
        if(!$this->table_name)
            throw new DatabaseException("No Database table name specified, Configure Your model or  ");

        $query = $this->buildWriteRawQuery("DELETE");
        $params = $this->params["WHERE"];
//        var_dump($params);
//        echo PHP_EOL.$query;
        try{
            $prepare    = $this->conn->prepare($query);//Prepare query\
            $prepare    ->execute($params);
        }catch (\PDOException $e){
            throw new DatabaseException($e->getMessage());
        }
        return $this;
    }

    /**
     * @param array $cols
     * @param bool $sing_record
     * @return array|mixed
     * @throws DatabaseException
     */
    public function all(
        $cols = [],
        $sing_record = false
    ){
        if(is_array($cols)){
            if(!$cols)
                $cols = $this->filterNonReadable($this->table_cols);

            if(!$cols)
                throw new DatabaseException("Error Attempting to update Empty data set");
            if(!$this->table_name)
                throw new DatabaseException("No Database table name specified, Configure Your model or  ");

            $this->rawColumnGen($cols);
        }
        $query      = $this->buildWriteRawQuery("SELECT");
        $params     = array_merge($this->params["WHERE"]);

//        var_dump($params);
//        echo "<br>".$query."<br>";
        try{
            $prepare                = $this->conn->prepare($query);//Prepare query\
            $prepare                ->execute($params);
            $this->total_record     = $this->conn->query("SELECT FOUND_ROWS()")->fetchColumn();
            if($sing_record)
                return $prepare->fetch(constant("\PDO::{$this->fetch_method}"));
            else
                return $prepare->fetchAll(constant("\PDO::{$this->fetch_method}"));
        }catch (\PDOException $e){
            throw new DatabaseException($e->getMessage());
        }
    }

    /**
     * @param int $_from
     * @param int $_to
     * @return $this
     */
    public function batch($_from = 0, $_to = 10){
        $this->query_structure["LIMIT"] = "{$_from},{$_to}";
        $this->params["LIMIT"]          = [$_from,$_to];
        return $this;
    }
    public function sortBy($sort){
        if(is_array($sort)){
            $str = "";
            foreach ($sort as $key => $val){
                $str .=" {$key} {$val},";
            }
            $str = preg_replace("/,$/","",$str);
            if($this->query_structure["ORDER_BY"]){
                $this->query_structure["ORDER_BY"] .= ", ".$str;
            }else{
                $this->query_structure["ORDER_BY"] = $str;
            }
        }else{
            if($this->query_structure["ORDER_BY"]){
                $this->query_structure["ORDER_BY"] .= ", ".$sort;
            }else{
                $this->query_structure["ORDER_BY"] = $sort;
            }
        }
        return $this;
    }
    public function like($wild_card){
        if(!$this->query_structure["WHERE"])
            throw new DatabaseException("WHERE Clause is empty");

        $this->query_structure["WHERE"] .= " LIKE ?";
        $this->params["WHERE"][] = "$wild_card";
        return $this;
    }
    public function notLike($wild_card){
        if(!$this->query_structure["WHERE"])
            throw new DatabaseException("WHERE Clause is empty");

        $this->query_structure["WHERE"] .= " NOT LIKE ?";
        $this->params["WHERE"][] = "$wild_card";
        return $this;
    }
    public function between($start,$stop){
        if(!$this->query_structure["WHERE"])
            throw new DatabaseException("WHERE Clause is empty");

        $this->query_structure["WHERE"] .= " BETWEEN ? AND ?";
        $this->params[] = $start;
        $this->params[] = $stop;
        return $this;
    }
    public function join($table,$on){
        return $this;
    }

    /**
     * @param array $cols
     * @return object
     */
    public function first($cols = []):object {
        $this->query_structure["ORDER_BY"] = "{$this->primary_key} ASC";
        $this->query_structure["LIMIT"]    = "0,1";
        return (object)$this->all($cols,true);
    }

    /**
     * @param array $cols
     * @return object
     */
    public function last($cols = []):object {
        $this->query_structure["ORDER_BY"] = "{$this->primary_key} DESC";
        $this->query_structure["LIMIT"]    = "0,1";
        return (object)$this->all($cols,true);
    }

    public function count(){
        $this->query_structure["SELECT"] = "COUNT({$this->primary_key}) as total";
        return $this->all(null,true)["total"];
    }
    public function max($col){
        $this->query_structure["ORDER_BY"] = "{$col} DESC";
        $this->query_structure["LIMIT"]    = "0,1";
        return $this;
    }
    public function min($col){
        $this->query_structure["ORDER_BY"] = "{$col} ASC";
        $this->query_structure["LIMIT"]    = "0,1";
        return $this;
    }
    public function groupBy($col){
        return $this;
    }

    /**
     * @return bool
     */
    public function exists():bool {
        $this->query_structure["SELECT"] .= ", COUNT({$this->primary_key}) as total";
        return $this->all(null,true)["total"] > 0;
    }

    /**
     * @return bool
     */
    public function doesntExists():bool {
        $this->query_structure["SELECT"] .= ", COUNT({$this->primary_key}) as total";
        return $this->all(null,true)["total"] < 1;
    }
}