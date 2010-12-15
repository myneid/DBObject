<?php
/**
 * generate classes that exted DBObject.php that define a table
 */

$databases = array('test');
$tables = 'all'; //$tables = array('table1', 'table2');

foreach($databases as $d)
{
	$dbh = new PDO("mysql:host=localhost;dbname=$d",'root' , '');
	$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	
	if(!is_array($tables) && $tables == 'all')
	{
		$tables = array();
		$stmt = $dbh->prepare("show tables");
		$row = array();

		$stmt->execute();
		while($row = $stmt->fetch() )
		{
			//print_r($row);
			$tables[] = $row[0];
		}
	}
	foreach($tables as $t)
	{
		
		$stmt = $dbh->prepare("desc $t");
		$row = array();
		$stmt->execute();
		$items = '';
		while($row = $stmt->fetch())
		{
			$fname = $row['Field'];
			if($items)
				$items .= ',';
				
			$items .= "'$fname'";
		}
		$classname = preg_replace("/_(\w)/e", 'strtoupper(\\1)', ucfirst($t));
		
		$class_contents = "<?php\n\nrequire_once 'DBObject.php';\n\nclass $classname extends DBObject\n{\n\tfunction __construct()\n\t{\n\t\tparent::__construct('$d', '$t', array($items));\n\t}\n}";
		
		$fp = fopen($classname . '.php' , 'w');
		fputs($fp, $class_contents);
		fclose($fp);
	}
}