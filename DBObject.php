<?php

/* PDO*/
//$dbh = new PDO('mysql:host=10.87.223.12;dbname=suckamc','root' , 'mmmmm');
//$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * class DBObject will act very similar to pear db dataobjects but using PDO and php5 only
 * it will do dynamic object creation based on a passed table name.
 * it will do table linking if you have a field named car_id it will link to the table car on teh id field
 * examples are at the bottom of this file
 * i had this idea to do this and found online somebody that had already done something similar using pear db which i modified completely and added a bunch of new stuff into it to make it usable. you can find the original at
 * http://www-128.ibm.com/developerworks/opensource/library/os-php-flexobj/
 *
 * @todo exception handling
 * @author tanguy de courson <tanguy@0x7a69.net>
 *
 */
class DBObject
{

    const INSERT_MODE_INSERT = 1;
    const INSERT_MODE_REPLACE = 2;

    //Cache arrays for the discovery data to optimize multiple requests to DBObject in the same session
    //Table cache
    static $tableCache = array();
    //Enum Cache
    static $enumCache = array();

    private $id = 0;
    private $table;
    private $database;
    public $dbh;
    /**
     * $fields is an array with teh key of the fieldname and the value of hte value
     *
     * @var array
     */
    private $fields = array();
    /**
     * $original_fields is a copy of fields upon any get/fetch methods in order for udpate() to just update changed fields
     *
     **/
    private $original_fields = array();
    /**
     * this is an array where the key is teh fieldname in camel notation and the value the fieldname
     *
     * @var unknown_type
     */
    private $fields_camel = array();
    /**
     * query will contain query['query'], query['result'], query['condition'], query['group_by'], query['limit'], query['order_by']
     *
     * @var unknown_type
     */
    private $query = array();
    /**
     * this is an associative array containing each of the linked objects
     * so if a get is done on this table and this table contains the filed car_id
     * $this->linked_objects['car'] will contain the car object linked to this one
     *
     * @var string $database the name of the database
     * @var string $table the name of the table
     * @var array $linked_objects
     */
    public $linked_objects = array();
    /**
     * this is a quick current cache of the rowcount that is public
     */
    public $cached_row_count = 0;
    /**
     * this will contain an array of the field types for each field
     * it is filled in with teh method _getFieldTypes
     * it is a multidimentional arrya so that you can do $field_types[0]['type'] and $field_types[0]['length'] the indexes correspond to $this->fields for the names
     *
     * @var array $field_types
     */
    private $field_types = array();
    /**
     * if this is set it will pass the order by when fetching
     * @var string
     **/
    private $order_by;
    /**
     * this will contain a key/val hash with the key being the ann array with key being the word value and the value the int value
     *
     **/
    public $enum_values = array();

    /**
     *this will allow someone to call whereAdd() to tag on a raw where extra to a query
     *@var string
     **/
    private $where_add;
    /**
     * this should call a limit
     */
    private $limit;

    public function __construct( $database, $table, $fields)
    {
        //global $dbh;

        //$this->dbh = $dbh;
        $this->table = $table;
        $this->database = $database;
        foreach( $fields as $key )
        {
            $this->fields[$key] = null;
            $this->direct[$key] = null;
            $fname = preg_replace("/_(\w)/e", "strtoupper('\\1')", ucfirst($key));
            $this->fields_camel[$fname] = $key;
        }


    }

    public function resetFields(){
        foreach($this->fields as $key => $val){
            $this->fields[$key] = null;
            $this->direct[$key] = null;
            $this->where_add = null;
            $this->order_by = null;
            $this->limit = null;
        }
    }

    /** accessor methods **/
    /** i want to be explicit with the accessor methods **/
    public function _getTable()
    {
        return $this->table;
    }
    public function _getDatabase()
    {
        return $this->database;
    }
    public function _getFields()
    {
        return $this->fields;
    }
    public function _getFieldsCamel()
    {
        return $this->fields_camel;
    }



    /**
     * This is method directExexcute
     *
     * @param mixed $dbh DB Handle
     * @param mixed $sql SQL to be executed
     * @param mixed $params Substitution Parameters for exec
     * @return PDOStatement PDO statement Handle
     *
     */    
    static function directExecute($dbh,$sql,$params=null) {
/*
                        if(1) {
                                try{
                                        //make key
                                        $key = md5($sql);
                                        //check cache
                                        $stmt = $dbh->prepare("select `key` from Sql_log where `key` = ?");
                                        $stmt->execute(array($key));
                                        $data = $stmt->fetch();
                                        if(empty($data)) {
                                                //Log it...
                                                $stmt = $dbh->prepare("insert into Sql_log (`key`,`sql`) values(?,?)");
                                                $stmt->execute(array($key,$sql));
                                        }
                                } catch(Exception $e) {
                                        $data=2;
                                        //Do nothing - dont let possible errors stop caller
                                }
                        }//log section
 */			
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
        return($stmt);
    }

    /**
     * This is method prepareExexcute
     *
     * @param mixed $sql SQL to be executed
     * @param mixed $params Substitution Parameters for exec
     * @return PDOStatement PDO statement Handle
     *
     */
    public function prepareExexcute($sql,$params=null) {
        return(self::directExecute($this->dbh,$sql,$params));
    }


    public function _getFieldTypes()
    {
        //a little more complex
        if(!@$dbh)
            global $dbh;

        $row = null;

        $stmt = self::directExecute($dbh,"desc ".$this->database.'.'.$this->table);
        $columns = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC) )
        {
            $fname = $row['Field'];

            /**
             * Array
             (
                 [Field] => id
                 [Type] => int(11)
                 [Null] =>
                 [Key] => PRI
                 [Default] =>
                 [Extra] => auto_increment
             )
             */
            $type_raw = $row['Type'];
            $tmp = array();
            if(preg_match("/(.*?)\(\d+\)/", $type_raw, $matches))
            {
                $tmp['type'] = $matches[1];
                $tmp['length'] = $matches[2];
            }
            else if(preg_match("/enum\((.*)\)/", $type_raw, $matches))
            {
                $tmp['type'] = 'enum';
                $tmp['options'] = array($matches[2]);
                $tmp['length'] = sizeof($tmp['options']);
            }
            else
            {
                $tmp['type'] = $type_raw;
                $tmp['length'] = 0;
            }
            $this->field_types[$row['Field']] = $tmp;


        }
        return $this->field_types;
    }
    /** end: accessor methods **/

    public function __call( $method, $args )
    {
        if ( preg_match( "/set(.*)/", $method, $found ) )
        {
            if ( isset($this->fields_camel[ $found[1]]) )
            {

                if(!empty($args[1]) && $args[1] == true) {
                    $this->direct[ $this->fields_camel[ $found[1]] ] =  $args[0];
                } else {
                    $this->fields[ $this->fields_camel[ $found[1]] ] = $args[0];
                }
                return true;
            }
            else if(@$this->enum_values[$found[1]])
            {
                $this->fields[$this->fields_camel[ 'Enum'.$found[1]]] = $this->enum_values[$found[1]][$args[0]];
            }
            else
            {
                //throw new DBObjectException("variable for setting not found : $method");
            }
        }
        else if ( preg_match( "/get(.*)/", $method, $found ) )
        {
            if ( isset($this->fields_camel[ $found[1]]) )
            {
                return $this->fields[ $this->fields_camel[ $found[1]] ];
            }
            else if(!empty($this->enum_values[$found[1]]))
            {
                return $this->enum_values[$found[1]][$this->fields[$this->fields_camel[ 'Enum'.$found[1]]]];
            }
            else
            {
                //throw new DBObjectException("variable for getting not found : $method");
            }
        }
        return false;
    }

    /**
     * this function will return this object by reading the fieldnames dynamcally
     *
     * @param string $database the database name
     * @param string $table the table name
     * @param pdo database handler optional, if not passed it will use the global var $dbh
     * @return object $this
     *
     */
    static function factory($database, $table, $dbh=null)
    {
        if(!$dbh)
            global $dbh;

        //cache key
        $ckey = "$database.$table";

        //check cache
        if(!isset(self::$tableCache[$ckey] )) 
        {
            $row = null;
            $stmt = self::directExecute($dbh,"desc $database.$table");
            $columns = array();
            $global_enum_values = array();
            while($row = $stmt->fetch(PDO::FETCH_ASSOC) )
            {
                $fname = $row['Field'];

                $columns[] = $fname;

                if(preg_match("/^enum_(\w+)/",$fname, $matches))
                {
                    $field_name = $matches[1];
                    $row2 = null;
                    $stmt2 = self::directExecute($dbh,"select enum_id, value from Enum_values where table_name=? and column_name=?",array($table, $field_name));
                    $enum_vals = array();
                    while($row2 = $stmt2->fetch(PDO::FETCH_ASSOC) )
                    {
                        $enum_vals[$row2['value']] = $row2['enum_id'];
                        $enum_vals[$row2['enum_id']] = $row2['value'];
                    }
                    $fnamecamel = preg_replace("/_(\w)/e", "strtoupper('\\1')", ucfirst($matches[1]));
                    $global_enum_values[$fnamecamel] = $enum_vals;
                }
            }
            self::$tableCache[$ckey] = $columns;
            self::$enumCache[$ckey] = $global_enum_values;
        }

        $t= new DBObject($database, $table, self::$tableCache[$ckey] );
        $t->dbh = $dbh;
        $t->_setEnumValues(self::$enumCache[$ckey]);	
        //$t->enum_values = self::$enumCache[$ckey];

        return $t;
    }

    public function _setEnumValues($values)
    {
        $this->enum_values = $values;
    }

    /**
     * get the record from the id field
     *
     * @param int $id
     */
    public function get( $id )
    {
        if(!$id)
            return false;
        $stmt = $this->prepareExexcute("SELECT * FROM ". $this->database . '.' .$this->table." WHERE id=?",array( $id ));
        $row = array();
        //$res->fetchInto( $row, DB_FETCHMODE_ASSOC );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if(sizeof($row) == 0 || !$row)
        {
            return false;
        }


        $this->id = $id;
        foreach(  $row  as $key => $val)
        {
            $this->fields[ $key ] = $val;
                        /** lets kill autolinking of tables for now
            if(preg_match("/(.*?)_id/", $key, $matches))
            {
                $this->linked_objects[$matches[1]] = DBObject::factory($matches[1]);
                $this->linked_objects[$matches[1]]->get($val);
            }
                        **/
        }
        $this->original_fields = $this->fields;
        return true;
    }

    /**
     * insert this new record
     *
     * @return int the rowid that was created
     */
    public function insert($mode = self::INSERT_MODE_INSERT)
    {

        $fields = '';
        $inspoints = array();
        $values = array();
        foreach($this->fields as $k=>$v)
        {
            if($fields != '')
                $fields .= ',';
            $fields .= "`$k`";

            if(isset($this->direct[$k])) {
                $val = $this->direct[$k];
            } else {
                $val = "?";
                $values []= $v;
            }
            $inspoints []= $val;
        }

        $inspt = join( ", ", $inspoints );

        $sql = "INSERT";
        if($mode == self::INSERT_MODE_REPLACE) {
            $sql = "REPLACE";
        }

        $sql .= " INTO ".$this->database . '.' .$this->table." ( $fields )
            VALUES ( $inspt )";

        $this->prepareExexcute($sql,$values);

        $id = $this->dbh->lastInsertId();
        @$this->setId($id);
        $this->id = $id;

        $this->original_fields = $this->fields;
        //clear direct mode fields so they dont interfere with updates
        foreach($this->fields as $key => $val){
            $this->direct[$key] = null;
        }

        return $id;
    }
    /**
     * update this record with new values
     *
     */
    public function update()
    {

        $sets = array();
        $values = array();
        foreach( array_keys( $this->fields ) as $field )
        {

            if($field != 'id' && (
                (isset($this->direct[$field]))
                || (isset($this->original_fields[$field]) && $this->fields[$field] != $this->original_fields[$field])
                || (!isset($this->original_fields[$field]) && isset($this->fields[$field]))
            ))
            {
                if(!isset($this->direct[$field])) {
                    $sets []= '`'.$field.'`=?';
                    $values []= $this->fields[ $field ];
                } else {
                    $sets []= '`'.$field.'`=' . $this->direct[$field];
                }
            }
        }

        if(sizeof($sets) > 0)
        {
            $set = join( ", ", $sets );
            $values []= $this->id;

            $sql = 'UPDATE '. $this->database . '.' .$this->table.' SET '.$set.' WHERE id=?';

            $this->prepareExexcute($sql,$values);

            $this->original_fields = $this->fields;
        }
    }
    /**
     * delete this one record, technically based only on the id that is set
     *
     */
    public function delete()
    {
        $this->prepareExexcute('DELETE FROM '. $this->database . '.'.$this->table.' WHERE id=?',array( $this->id ));
    }
    /**
     * delete everything in this table, i should disable this
     *
     */
    public function delete_all()
    {
        $this->prepareExexcute('DELETE FROM '. $this->database . '.' .$this->table);
    }
    /**
     * this function will create and execute the query based on set fields
     *
     * @param bool $autofetch if true will run fetch once to get one row
     * @return int $rows return the number of rows for htis query
     */
    function find($autofetch=false)
    {

        $query = "select * from " . $this->database . '.' . $this->table . " ";
        $where_clause = '';
        $valueArray = array();
        foreach($this->fields as $f => $v)
        {
            if($v)
            {
                if($where_clause)
                {
                    $where_clause .= " and ";
                }
                else
                    $where_clause = " where ";
                $where_clause .= " $f = ? ";
                $valueArray[] = $v;
            }
        }
        if($this->where_add)
        {
            if($where_clause)
            {
                $where_clause .= " and ";
            }
            else
                $where_clause = " where ";
            $where_clause .= $this->where_add;
        }
        if($this->order_by)
        {
            $where_clause .= " ORDER BY " . $this->order_by;
        }
        if($this->limit) {
            $where_clause .= " LIMIT " . $this->limit;
        }
        if(@$this->query['result'])
            $this->query['result']->closeCursor();
        $this->query['result'] = $this->prepareExexcute($query . ' ' . $where_clause,$valueArray);
        if($autofetch)
            $this->fetch();


        //i guess rowCount isnt guaranteed on all databases with pdo but i dont know what else to use
        $this->cached_row_count = $this->query['result']->rowCount();
        return $this->cached_row_count;
    }
    /**
     * get teh next row from the query['result'] and set this object to its values
     *
     * @return bool true if there is another row and false if there is no more rows or failure
     */
    public function fetch()
    {
        $row = array();
        if(!$this->query['result'])
            return false;

        $row = $this->query['result']->fetch(PDO::FETCH_ASSOC);

        if($row)
        {
            $this->setFrom($row);
            return true;
        }
        else
        {
            return false;
        }

    }
    /**
     * set this objects fields from waht is passed in, be it an object of this type with the same fieldnames with set/get methods or an array of the fieldnames and values
     *
     * @param array | object $from
     */
    public function setFrom($from)
    {

        foreach($this->fields as $f =>$v)
        {

            if(is_object($from))
            {
                $func_name = 'get' . $this->fields_camel[$f];
                $this->fields[$f] = $from->$func_name();
                //continue;

            }
            else
            {

                if($from[$f])
                {
                    $this->fields[$f] = $from[$f];
                    if($f === 'id')
                        $this->id = $from[$f];


                                        /** disable auto table linking for now
                    else if(preg_match("/(.*?)_id/", $f, $matches))
                    {
                        $this->linked_objects[$matches[1]] = DBObject::factory($matches[1]);
                        $this->linked_objects[$matches[1]]->get($from[$f]);
                    }
                                        **/
                } else {
                    $this->fields[$f] = false;
                }
            }
        }

        $this->original_fields = $this->fields;
    }

    public function OrderBy($orderby)
    {
        $this->order_by = $orderby;
    }
    public function order($orderby)
    {
        $this->OrderBy ( $orderby;)
    }
    public function WhereAdd($whereadd)
    {
        $this->where_add = $whereadd;
    }
    public function where($whereadd)
    {
        $this->WhereAdd ( $whereadd);
    }
    public function Limit($limit)
    {
        $this->limit = $limit;
    }
    public function limit($limit)
    {
	    $this->Limit($limit);
    }




}
/*
$book = new DBObject( 'library','book', array( 'id', 'author',
'title', 'publisher' ) );
$book->delete_all();
$book->setTitle( "PHP Hacks" );
$book->setAuthor( "Jack Herrington" );
$book->setPublisher( "O'Reilly" );
$id = $book->insert();

echo ( "New book id = $id\n" );

$book->setTitle( "Podcasting Hacks" );
$book->update();

$book2 = new DBObject( 'library','book', array( 'author',
'title', 'publisher' ) );
$book2->get( $id );
echo( "Title = ".$book2->getTitle()."\n" );
$book2->delete( );

$book3 = DBObject::factory('library','book');
$book->delete_all();
$book->setTitle( "PHP Hacks" );
$book->setAuthor( "Jack Herrington" );
$book->setPublisher( "O'Reilly" );
$id = $book->insert();

echo ( "New book id = $id\n" );

$book->setTitle( "Podcasting Hacks" );
$book->update();


$book = DBObject::factory('library','book');
$book->setTitle('PHP hacks');
$book->find(1);
echo( "Title = ".$book->getTitle()."\n" );

$book = DBObject('library','book');
$book->setAuthor('Mark Twain');
$book->find();
while($book->fetch())
{
    echo "title: " . $book->getTitle();
}

//linked objects with field name of tablename_id get put under linked_object
$book = new DBObject( 'library','book', array( 'id', 'author_id',
'title', 'publisher' ) );
//author_id links to the author table
$book->get(12);
echo "the authors name is " . $book->linked_objects['author']->getName();
 */
class DBObjectException extends Exception {}
