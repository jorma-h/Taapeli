<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="fi" lang="fi">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8 ">
<title>Taapeli Cypheriksi</title>
<style>
b { color:red }
.obj { color: brown; font-size: 120%; font-style: oblique; }
.lev { color: #d60; background-color: #eed; }
.pus { color: #7f4; }
table { border-spacing: 10px; }
.form { background-color: #dde; margin-left: auto; margin-right: auto; }
th,td { padding: 5px; }
.kd { font-family: monospace; background-color: #eed; vertical-align: top; }
.ds { font-size: 80% ; vertical-align: top; }
</style>
</head>

<body>
<h1>Taapeli testilataus</h1>
<p>Luetaan UTF-8 -merkist&ouml;&ouml;n muunnettua gedcom-tiedostoa ja poimitaan sielt&auml;
   henkil&ouml;it&auml;, perheit&auml; ja niiden v&auml;lisi&auml; suhteita. 
   Henkilöllä voi olla rinnakkaisia nimiä.
   Avioliiton solmimiseen liitet&auml;&auml;n simuloitu aika ja paikka.
   Objektien ja niiden suhteiden luonti koetetaan esitt&auml;&auml;  
   <a href="http://www.neo4j.org/learn/cypher">Cypher-kielell&auml;</a>,
   mutta sitä ei ole yhtään koeajettu.
   </p>
<?php
/*
* 	   Simple file Upload system with PHP by Tech Stream
*      http://techstream.org/Web-Development/PHP/Single-File-Upload-With-PHP
*/

// Gedcomin käsittelyn muuttujat
$sg = false;		// Näytetäänk&ouml; ged-koodi
$n = 0; 			// Luettu rivimäärä
$acttype = 0;  		// Ei ketään aktiivisena
$level = '0';		// Taso
$prev = -1;			// Edellinen taso
$arglist = "";		// rivin argumentit (jatkorivit yhdistettynä)
$count = 0;			// Entiteettien lukumäärä
$code = "";			// Generoitava koodi
$nmid = $plid = $coid = 0;	// Nimien, paikkojen ja yhteyksien numerointi alkaa 0:sta
$entits = array();	// Henkil&ouml;iden, perheiden ym. pino
$names = array();	// Rinnakkaisnimien pino
$conns = array();	// Yhteksien pino
//					// ? Entiteetit pinossa, taso 0, 1, 2, ...
$person = $connection = $family = $place = $placeConn = NULL;
static $nl_ind = '<br />&nbsp;&nbsp;';
static $nl_st  = '<br />';


function getEntityName($i) { // muunnos gedcom:sta Taapelin muuttujanimiksi
	static $entitytypes = array(
		'INDI' => 'Person', 
		'FAM'  => 'Family', 
		'SOUR' => 'Source', 
		'HEAD' => 'Header', 
		'SUBM' => 'Submitter',
		'CHIL' => 'Child',
		'MARR' => 'Marriage',
		'WIFE' => 'Wife',
		'HUSB' => 'Husband',
		'PLAC' => 'Place',
		'NAME' => 'AltName',
		'SOUND'=> 'Sounds');
	return ($entitytypes[$i]);
}

function cpr($txt, $alt = NULL) {
	// Ehdollinen tulostus
	global $sg;	// Show gedcom data
	
	if ($sg == true) { 
		echo $txt; 
	} else {
		if (! empty($alt)) {
			echo $alt;
		}
	}
}

function pr($sp, $txt) {
	// Tulostetaan tyylillä tai toisella
	switch ($sp) {
	case "lev":
	case "cod":
	case "obj":
	case "pus":
   		cpr(" <span class=\"$sp\">$txt</span> ");
		break;
	default:
		cpr($txt . ' ');
	}
}

function idtrim($id) {
	// Poistetaan id:n @-merkit
	return substr(trim($id), 1, -1);
}

/*------------------------------------------------------------------*/

class Entity {
	//------
	public $id;
	protected $type;
	protected $props = array();
	
	function __construct($settype, $setid) {
	//	cpr("<i>Luodaan objekti '$setid' tyyppiä $settype</i>\n");
		$this->type = $settype;
		$this->id = $setid;
	}
	
	function addProperty($key, $value) {
	//	cpr("<i>Lisätään '$value' avaimella $key</i>\n");
		if (array_key_exists($key, $this->props)) {
		//	echo "<b>$key: $value - $key jo m&auml;&auml;ritelty</b>\n";
			return true;
		} else {
			$this->props[$key] = $value;
			if ($key == "NAME") {	// Lisää soundex
				$this->props["SOUND"] = soundex($value);
			}
		}
		return false;
	}
	
	function getEntId() {
		return $this->id;
	}
	
	function getEntType() {
		return $this->type;
	}

	function cypherProps() {
		//print_r($this->props);
		$delim = '';
		$ret = '{';
		foreach ($this->props as $key => $value) {
			$ret .= "$delim$key: '$value'";
			$delim='; ';
		}
		return $ret.'}';
	}
	
	function cypher($first) {
		//cpr("<i>Entity {$this->id}:{$this->type}</i>\n");
		$t = getEntityName($this->type);
		$ret = "CREATE ({$this->id}:$t ";
		if (!empty($this->props)) {
			$ret .= $this->cypherProps();
		}
		$ret .= ');';
		return $ret;
	}
		
	public function nayta() {
		return "Objekti " . serialize($this);
	}
}  // Entity

class Connection extends Entity {
	//----------
	// Yhteys entiteetistä from to to tyyppiä key
	protected $from, $to;	// tyyppiä (Entity)
	protected $connType;	// Spouce tms
		
	function __construct($key, $from, $to) {
		global $coid;
		$this->from = $from;
		$this->to = $to;
		if (is_null($from) || is_null($to)) {
			echo '<b>Tyhjä argumentti: '; var_dump($this); echo '</b>';
			return false;
		}
		$id = 'C' . $coid++;
		$this->connType = getEntityName($key);
		//echo '<i>'; var_dump($this); echo '</i>';
	//	cpr("<i>Lisätään yhteys (" . $from->getEntId() . "-[r:"
	//		. $this->connType . "]->(" . $to->getId . ")</i>\n");

		parent::__construct($tp, $id);  // type, id
	}

	function getConnType() {
		return $this->connType;
	}

	function getPerson() {		// Haetaan yhteyden henkilöosapuoli
		if ($this->from->getEntType() == 'INDI') {
		//	echo '<i>from '.$this->from->getEntId().'</i> ';
			return $this->from;
		} else {
			if ($this->to->getEntType() == 'INDI') {
		//		echo '<i>to '.$this->to->getEntId().'</i> ';
				return $this->to;
			}
		}
		return null;
	}
	
	function cypher($first) {	// Luodaan yhteyslauseet
		$f = $this->from->id;
		$t = $this->to->id;
	//	cpr("<i>Connection ($f)-[".$this->id.":".$this->type."]->($t)</i>\n");
		$ret = "CREATE ($f:" . getEntityName($this->from->type);
		$ret .= ")-[{$this->id}:{$this->connType}]->(" . $this->to->id . ":";
		$ret .= getEntityName($this->to->type) . ");";
		return $ret;
	}
	
} // Connection

class Person extends Entity {
	//------ Henkil&ouml;
	function __construct($setid) {
		parent::__construct('INDI', $setid);
	}
}
class Family extends Entity {
	//------ Perhe
	function __construct($setid) {
		parent::__construct('FAM', $setid);
	}
	function cypher($conns) {
		// Luodaan perheenjäsenyys 
		//   MATCH (p:Person {id:'I149'}),(f:Family {id:'F59'})
		//   CREATE (p)<-[C0:Husband]-(f);
		global $nl_ind, $nl_st;

		$t = getEntityName($this->getEntType());	
		$ret = "CREATE ({$this->id}:$t ";
		if (!empty($this->props)) {
			$ret .= $this->cypherProps();
		}
		$ret .= ");";
	//	echo '<b>'; var_dump($conns); echo '</b>';
		foreach ($conns as $connection) {
			$x = $connection->getPerson();
			if (! empty($x)) {
				$ret .= "${nl_st}MATCH (p:Person {id:'".$x->getEntId().
					"'}),(f:Family {id:'".$this->id."'}){$nl_ind}";
			//	echo '<br /><b>'; var_dump($connection); echo '</b>';
				$ret .= 'CREATE (p)-['; 
				$ret .= $connection->getEntId() . ':';
				$ret .= $connection->getConnType() . ']->(f);';
			}
		};
		return $ret;
	}
} // Family

class PersonName extends Entity {
	//---- Rinnakkainen nimi
	protected $who;		// keneen liittyy
	protected $name;
	protected $connType;	// Spouce tms

	function __construct($setid, $name, $who) {
		global $nmid;
		static $key = 'NAME';
		// Olisi pitänyt ensin katsoa, onko tämä paikka jo luotu
		if (empty($setid)) {
			$setid = 'N' . $nmid++;
		}
		$this->who = $who;
		$this->name = $name;
		parent::__construct($key, $setid);
		$this->connType = getEntityName($key);
	}

	function cypher($first) {		// Luodaan nimitieto ja linkki siihen
	//	cpr("<i>Connection ($i)-[".$this->id.":".$this->connType."]->($t)</i>\n");
		global $nl_ind, $nl_st;

		$ret = "MATCH (p:Person {id:'"
			.$this->who->getEntId()."'}){$nl_ind}";
		$ret .= "CREATE ($this->id:" . getEntityName($this->type);
		$ret .= ", name=\"" . $this->name;
		if (!empty($this->props['SOUND'])) {
			$ret .= ", sounds=\"" . $this->props['SOUND'];
		}
		$ret .= "\")<-[:NameLink]-(p);";
		return $ret;
	}
	
} // PersonName

class Place extends Entity {
	//-----  Paikka luodaan aina nimellä 

	function __construct($setid, $name = null) {
		global $plid;
		// Olisiko pitänyt ensin katsoa, onko tämä paikka jo luotu
		if (empty($setid)) {
			$setid = 'L' . $plid++;
		}
		parent::__construct('PLAC', $setid);
		//? $this->addProperty('name', $name);
	}

	function cypher($first, $who = NULL) {		// Luodaan paikannimi ja linkki siihen
		// $who on pakollinen: perhe tai tapahtuma johon paikka liittyy
		global $nl_ind, $nl_st;

	//	echo '<br /><b>'; var_dump($this); echo '</b>';
		if (empty($who)) { 
			return 0;
		}
		$ret = "MATCH (p:".$x." {id:'".$who->getEntId()."'}){$nl_ind}";
		$ret .= "CREATE ($this->id:" . getEntityName($this->type);
		$ret .= ", name=\"" . $this->props['name'];
		$ret .= "\")<-[:NameLink]-(p);";
		return $ret;
	}
} // Place


function enter($key, $txt = NULL) {
	// Käsitellään gedcom-tieto {key, txt} ja tulostetaan siitä koodia

	global $code, $id, $entitytypes, $type, $ohita;
	global $person, $names, $conns, $family, $place, $placeConn;
	global $nl_st, $nl_ind;
	
	if (substr($key, 0, 1) == "#") {
		$metakey = substr($key, 1, 3);
		switch ($metakey) {
		case "NEW":
			$a = explode(' ', $key);
			$key = $a[1];
			$id = $txt;
			switch ($key) {
			case "INDI":
				$person = new Person($id);
	 			$person->addProperty('id', $id);
				$ohita = false;
				break;
			case "FAM":
				$family = new Family($id);
	 			$family->addProperty('id', $id);
				$ohita = false;
				break;
			default:
				$ohita = true;
			}
		    return;
		case "END":
			$code = '';
			$first = 0;
			if (!$ohita)	{ 
				if ( !empty($person) ) {
					// Luodaan henkilö
					// CREATE (I149:Person {id:'I149'; name:'J /Sihvola/'; gender:'M'})
					$code .= $person->cypher($first++);
				};
				if (! empty($names)) {
					// Luodaan vaihtoehtoinen nimi
					// CREATE (N0:AltName, NAME:"Helmi /Mäkeläinen/")<-[:NameLink]-
					//    (I7:Person)
					foreach ($names as $personName) {
						empty($code) || $code .= $nl_st;
						$code .= $personName->cypher($first++);
					}
				}
				if ( !empty($family) ) {
					// Luodaan perhe ja perheenjäsenyydet
					$code .= $family->cypher($conns);
				}
				if ( !empty($place) ) {
					// Luodaan (toistaiseksi) avioliiton paikkaviittaus
					empty($code) || $code .= $nl_st;
					$code .= $place->cypher($first++, $family);
				}
				if (empty($family) && ! empty($conns)) {
					foreach ($conns as $connection) {
						empty($code) || $code .= $nl_ind;
						$code .= $connection->cypher($first++);
					}
				}
				$conns = $names = null;
				$person = $personName = $family = $connection = $place = null;
			}
		    return;
		default:
			$code .= "[$key";
			if ($txt != "")	{ $code .= " $txt"; }
			$code .= ']';
		}
		return;
	}
	if ($ohita) { return 0; };
	
	switch ($key) {
	case "NAME":
	    if ($person->addProperty('name', $txt)) {
	    	$names[] = new PersonName(null, $txt, $person);
	    };
	    break;
	case "FAMS":	// Family:HUSB ja Family:WIFE korvaa Person:FAMS
	case "FAMC":	// Family:CHIL korvaa Person:FAMC
	break;
    case "CHIL":
    case "HUSB":
    case "WIFE":
		$p = new Person(idtrim($txt));
		//$connection = new Connection($key, $person, $family);
		//array_push
		$conns[] = new Connection($key, $p, $family);
		break;
    case "SEX":
	    $person->addProperty('gender', $txt);
	    break;
	case "MARR":	// Avioliiton solmiminen
		// Kuviteltu data
		$date = '1884-06-06';
		$name = 'Vakio, Kokkola';
	    $family->addProperty('marriage', $date);
	    $place = new Place(NULL, $name);
	    $place->addProperty('name', $name);
	    //$placeConn = new Connection($key, $family, $place);
		//array_push
		$conns[] = new Connection($key, $family, $place);
	    break;
	case "INDI":
	case "FAM":
	case "SOUR":
	case "SUBM":
	case "HEAD":
	    // Nämä käsiteltiin jo?
	    break;
    default:
		//pr("pus", "$key:$txt");
	}
}

/*-------------------------- Tiedoston luku ----------------------------*/

	if(isset($_FILES['image']) && $_FILES['image']['name'] != ""){
		// Tiedoston käsittelyn muuttujat
		$errors= array();
		$file_name = $_FILES['image']['name'];
		$file_size =$_FILES['image']['size'];
		$file_tmp =$_FILES['image']['tmp_name'];
		$max_lines = $_POST["maxlines"];
		$x=explode('.',$file_name);
		$x=end($x);
		$file_ext = strtolower($x);
		
		$expensions= array("ged","degcom","txt"); 		
		if(in_array($file_ext,$expensions)=== false){
			$errors[]="Väärä tiedostopääte. Anna Gedcom -tiedosto, jonka pääte on " .
			"ged, degcom tai txt";
		}
		if($file_size > 2097152){
			$errors[].='Tiedostokoko on nyt rajoitettu 2 Mb:een ';
		}				
		if(empty($errors)==true) {
			echo "<p><em>Ladattu ty&ouml;tiedosto: " . $file_tmp 
				. " (size=" . $file_size . ") <-- " . $file_name
				. ", charset=" . $_POST["charset"]
				. ", k&auml;sitell&auml;&auml;n enint&auml;&auml;n " . $max_lines
				. " rivi&auml;";
		
		if ($_POST["show"] == 'ged') {
			$sg = true;		// Näytetään ged-koodi
		}
		echo "</em><p>\n";
/*
** Luetaan tiedosto riveittäin
*/
			$file_handle = fopen($file_tmp, "r");
			cpr('<table>');
			cpr('<colgroup><col width="50%"><col width="50%"></colgroup>');
			
			while (!feof($file_handle)) {
				if ($max_lines < ++$n) { 
					echo "<b> - huh! Liikaa rivej&auml; ... </b>"; break;
				};
				$break = false;
/*
** Tässä gedcom-tiedoston rivin käsittely
*/
				// Edellinen rivi tehty, tulostetaan ged-sarakkeeseen
				if ($prev >= 0 ) { 
					if (substr($arg, 0, 1) == '@') {
						// Näytetään viittaus toiseen objektiin
						pr("none", $key.'=<a href="#'.idtrim($arg0).'">'.$arg.'</a>');
					} else {
						pr("none", "$key=$arg0"); 
					}
				};
				
				// Luetaan uusi rivi ja parsitaan se
				$line = fgets($file_handle);
				$a = explode(' ', $line, 3);
				$level = $a[0];
				if (($level < "0") || ($level > "9")) {	
					// Viallinen taso ohitetaan virheilmoituksella
			   		echo " <b>{$line}</b> ";
			   		continue;
				}
				$key = trim($a[1]);
				$arg = $arg0 = trim($a[2]);

				//
				// Tason muutokset
				//
				// 1. Jos taso = 0, tulosta loppusulut, entiteetti ja aloita uusi
				if ($level == 0) {
					if ($prev > $level) {	// Puuttuvat loppusulut
						while ($prev > $level) { 
							pr ("lev", ')');
							enter("}");
							//? $person = pop() tai $family = pop()
							///$arglist .= ")\n ";
							$prev--;
						}
					}
					// Tulostetaan taulukon koodisolu
					if ($count) {			// Edellisen lopputoimet
						enter ("#END");
						cpr("</td>\n<td class=\"kd\">", '<p class="kd">');
						///echo "$id $arglist";
						echo $code;
						cpr("</td></tr>\n", "</p>\n");
					};
					
					if ($key[0] == "@") {	// 0-tasolla id ja avainsana väärin päin
						$arg0 = $key;
						$id = idtrim($key);
						$key = $key0 = $arg;
						$arg = "id \"$id\" "; 
					}
					$arglist = $code = "";
				}

				// Jatkorivin liittäminen edelliseen 
				switch ($key) {
				case "CONT":
						if (substr($arglist, -2) == "\" ") {
						$arglist = substr($arglist, 0, -2);
					}
					$arglist .= "[br]" . $arg . "\" ";
					$break = true;
					break;
			    default:
				}

				// 2. Jos taso on sama, talleta "; key var "
				if (($prev == $level) && ($level > 0)) {
			   		pr ("lev", '&mdash;');
				} else {

				// 3. Jos taso nousee, talleta "( key var "
					if ($prev < $level) {
						while ($prev < $level) { 
						//	if ($prev >= 0) { pr ("lev", "&uarr;$level"); }
							if ($prev >= 0) { pr ("lev", '('); }
							if ($level > 0) {
								//? push($person) tai push($family)
								enter ("{");
								///$arglist .= "( ";
							}
							$prev++;
						}
					} else {

				// 4. Jos taso laskee, talleta ") key var "
						if ($prev > $level) { 
							while ($prev > $level) { 
							//	pr ("lev", "$prev&darr;");
								pr ("lev", ')');
								enter("}");
								//? $person = pop() tai $family = pop()
								///$arglist .= ")\n ";
								$prev--;
							}
						}
					}
				}
				if ( $break)	continue;
				
				// Talletetaan rivin tiedot
				//pr("obj", "${key}°{$arg}");
				if (($level == 0) || ($arg == "")) {
					$arglist .= "$key ";
				} else {
					$arglist .= "$key \"" . $arg . "\" ";
				}
				enter($key, $arg);

				if ($level == 0) {
					// Uusi taulukkorivi ja entiteetti
					cpr("\n<tr><td class=\"ds\"><a id=\"$id\" />");
					enter("#NEW {$key}", $id);
					$count++;
					switch ($key) {
					case "INDI":
					    pr("obj", "Henkil&ouml; [$id] ");
					    break;
					case "FAM":
					    pr("obj", "Perhe [$id] ");
					    break;
					case "SOUR":
					    pr("obj", "Lähde [$id] ");
					    break;
					case "HEAD":
					    pr("obj", "Otsikot [$id] ");
					    break;
					case "SUBM":
					    pr("obj", "Lähett&auml;j&auml; [$id] ");
					    break;
				    default:
		 				cpr("<b>(rivi {$n})</b> ");
					}
				}
/*
** Gedcom-rivi käsitelty
*/
			} // while feof
		}
			
			fclose($file_handle);
			if ($prev > 0) { echo ")"; }; 
			cpr("</table>");
			echo "<p><em>{$file_name} {$n} rivi&auml;</em></p>";

		} else {
			print_r($errors);
		}

/*-------------------------- Tiedoston valintalomake ----------------------------*/
?>

<form action="" method="POST" enctype="multipart/form-data"></p>
<table class="form">
<tr><td>
<h2>Anna ladattava gedcom-tiedosto</h2>
<p>Sy&ouml;te: <input type="file" name="image" required/></p>
<p>Merkist&ouml;: <input type="radio" name="charset" value="UTF-8" checked>UTF-8
   (<input type="radio" name="charset" value="UTF-16" disabled>UTF-16LE ei tarjolla)
</p>
<p><input type="checkbox" name="show" value="ged" checked>Näytä my&ouml;s gedcom-tietokentät</p>
<p>K&auml;sitelt&auml;v&auml; maksimi rivim&auml;&auml;r&auml;
   <input type="number" name="maxlines" value="999"></p>

</td><td style="vertical-align: bottom"> 
<input type="submit"/>
</td></tr>
</table>
</form>
</body>
</html>
