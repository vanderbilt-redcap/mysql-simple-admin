<?php

if (!SUPER_USER) exit("Only super users can access this page!");


// Get list of open requests
function getOpenRequests($mysql_ids=null)
{
	$current_mysql_process = db_thread_id();
	$reqs = array();
	$sql = "select r.mysql_process_id, r.php_process_id, v.user, v.project_id, v.full_url 
			from redcap_log_open_requests r, redcap_log_view v where v.log_view_id = r.log_view_id";
	if (is_array($mysql_ids)) {
		$sql .= " and r.mysql_process_id in (".prep_implode($mysql_ids).")";
	}
	$q = db_query($sql);
	while ($row = db_fetch_assoc($q)) {
		// Ignore the current MySQL process running THIS script
		if ($current_mysql_process == $row['mysql_process_id']) continue;
		// Add to array
		$reqs[$row['mysql_process_id']] = $row;
	}
	return $reqs;
}

// Header
include APP_PATH_DOCROOT . 'ControlCenter/header.php';
require_once dirname(__FILE__) . DS . 'PHPSQLParser.php';

// Get saved queries
$customQueries = array();
$queryStmts = $module->getSystemSetting('query');
$i = 1;
foreach ($module->getSystemSetting('title') as $key=>$val) {
	if (empty($val) || !isset($queryStmts[$key])) continue;
	$customQueries[$i++] = array('title'=>$val, 'query'=>$queryStmts[$key]);
}

$simpleAdmin = new Vanderbilt\SimpleMysqlAdmin\SimpleMysqlAdmin();
$baseUrl = $simpleAdmin->getPageUrl('index.php');

// Get formatted timestamp for NOW
list ($nowDate, $nowTime) = explode(" ", NOW, 2);
$nowTS = (method_exists('DateTimeRC', 'format_user_datetime')) ? DateTimeRC::format_user_datetime($nowDate, 'Y-M-D_24', null) . " $nowTime" : NOW;

?>
<style type="text/css">
#pagecontainer { max-width: 1600px; }
td.query_cell { padding:3px;border-top:1px solid #CCCCCC;font-size:10px;vertical-align:top; }
td.query_cell a { text-decoration:underline;font-size:8pt;font-family:verdana,arial; }
#west2 {
  border-right:1px solid #aaa;
  width:250px;
}
#center2 {
  padding:0 20px;
}
.rcf { display:none; }
.rcp a { text-decoration:underline !important;font-size:8pt !important; }
</style>
<script type="text/javascript">
var baseUrl = '<?=js_escape($baseUrl)?>';
function showMore() {
	$('.rcp').hide();
	$('.rcf').show();
}
function loadCustomQuery(querynum) {
	showProgress(1,1);
	window.location.href = baseUrl+'&q='+querynum;
}
</script>

<div style="padding-left:10px;">
<h4 style="color:#A00000;margin:0 0 10px;"><i class="fas fa-th"></i> MySQL Simple Admin</h4>
<p style="margin:20px 0;max-width:900px;">
	This module allows REDCap administrators to query REDCap's MySQL database. You may enter an SQL query into the text box below to execute it. 
	Since only "select" (i.e., read-only) queries are supported, this module is not able to modify database tables in any way.
	You may also save custom queries, which can be stored in the Configure dialog for this module on the External Modules page in the Control Center.
	Saving a custom query allows you to easily run the query at any time by simply clicking a link on the module's left-hand menu.
</p>
<?php

## DEFAULT SETTINGS
$query_limit = 500;
$query = "";
$display_result = "";



// Get list of tables in db
$q = db_query("show tables");
$table_list = array();
while ($row = db_fetch_array($q)) 
{
	$table_list[] = $row[0];
}

// If clicked a saved query
if ($_SERVER['REQUEST_METHOD'] != 'POST' && isset($_GET['q']) && isset($customQueries[$_GET['q']])) {
	$query = trim(html_entity_decode($customQueries[$_GET['q']]['query'], ENT_QUOTES));
}
// If query was submitted, then execute it
elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['query']) && !$isAjax)
{
	// Sanitize query
	$query = trim(html_entity_decode($_POST['query'], ENT_QUOTES));
}
// Do select * of selected table
elseif (isset($_GET['table']) && in_array($_GET['table'], $table_list))
{
	$query = "select * from " . htmlentities($_GET['table'], ENT_QUOTES);
	if (isset($_GET['field']) && isset($_GET['value'])) {
		$_GET['field'] = preg_replace("/[^0-9a-zA-Z_]/", "", $_GET['field']);
		$query .= " where " . prep($_GET['field']) . " = '" . prep($_GET['value']) . "'";
	}
}


if ($query != "")
{
	// Add query limit (unless already exists in query)
	$query_executed = (strpos(strtolower($query), "limit ") === false) ? "$query limit 0,$query_limit" : $query;
	// Execute query
	$foreign_key_array = array();
	$found_rows = 0;
	$mtime = explode(" ", microtime());
	$starttime = $mtime[1] + $mtime[0]; 

	$allowedQueryTypes = [
		'select',
		'show',
		'explain'
	];

	if ($module->isQueryType($query, $allowedQueryTypes))
	{
		// SELECT
		if ($module->isQueryType($query, 'select'))
		{
			// Find total rows that could be returned
			$q = db_query("select SQL_CALC_FOUND_ROWS " . substr($query_executed, 7));
			$mtime = explode(" ", microtime());
			$endtime = $mtime[1] + $mtime[0]; 
			// Check for errors
			$query_error = db_error();
			$query_errno = db_errno();
			// Get total row count possible
			$q2 = db_query("SELECT FOUND_ROWS() as found_rows");
			$found_rows = ($q2 ? db_result($q2, 0) : 0);
			
			## FOREIGN KEYS
			// Place all SQL into strings, segregating create table statements and foreign key statements
			$foreign_key_array = $query_tables = array();
			$parser = new \PHPSQLParser($query);
			foreach ($parser->parsed['FROM'] as $attr) {
				$query_tables[] = $attr['table'];
			}
			// Now do "create table" to obtain all the FK for each table
			foreach ($query_tables as $this_table)
			{
				// Do create table
				$q3 = db_query("show create table `$this_table`");				
				$row3 = db_fetch_assoc($q3);
				$create_table_statement = $row3['Create Table'];
				// Make sure all line breaks are \n and not \r
				$create_array = explode("\n", str_replace(array("\r\n", "\r", "\n\n"), array("\n", "\n", "\n"), trim($create_table_statement)));
				// Check each line
				foreach ($create_array as $line) 
				{
					// Trim the line
					$line = trim($line);
					// If a foreign key
					if (substr($line, 0, 11) == 'CONSTRAINT ') {
						// Format the line
						$fkword_pos = strpos($line, "FOREIGN KEY ");
						$fkline = trim(substr($line, $fkword_pos));
						if (substr($fkline, -1) == ',') $fkline = substr($fkline, 0, -1);
						// Isolate the field names
						$first_paren_pos = strpos($fkline, "(")+1;
						$fk_field = trim(str_replace("`", "", substr($fkline, $first_paren_pos, strpos($fkline, ")")-$first_paren_pos)));
						// Get reference table						
						$fkword_pos = strpos($line, "REFERENCES `");
						$fkline = trim(substr($line, $fkword_pos+strlen("REFERENCES `")));
						$fk_ref_table = trim(substr($fkline, 0, strpos($fkline, "`")));
						// Get reference field
						$ref_field = trim(substr($fkline, strpos($fkline, "(`")+strlen("(`"), strpos($fkline, "`)")-strpos($fkline, "(`")-strlen("(`")));
						// Add FK line to FK array
						$foreign_key_array[$this_table][$fk_field] = array('ref_table'=>$fk_ref_table, 'ref_field'=>$ref_field);
					} 
				}
			}
		}
		// SHOW or EXPLAIN
		else
		{
			$q = db_query($query);
			$mtime = explode(" ", microtime());
			$endtime = $mtime[1] + $mtime[0]; 
			$found_rows = db_num_rows($q);
			// Check for errors
			$query_error = db_error();
			$query_errno = db_errno();
		}
	} 
	else 
	{
		$query_error = "Can only accept the following query types: " . strtoupper(implode(', ', $allowedQueryTypes));
	}
    $total_execution_time = round($endtime - $starttime, 4);
	// Query failed
	if (!$q || $query_error != "")
	{
		$display_result .= "<div class='red' style='font-family:arial;'><b>MySQL error $query_errno:</b><br>$query_error</div>";
	}
	// Successful query, give results
	else
	{
		$query_field_info = db_fetch_fields($q);			
		$num_rows = db_num_rows($q);
		$num_cols = db_num_fields($q);
		
		$display_result .= "<p>
								Returned <b>$num_rows</b> rows of <b>$found_rows</b>
								&nbsp; <i>(executed in $total_execution_time seconds)</i>
							</p>";
		
		$display_result .= "<table class='dt2' style='font-family:Verdana;font-size:11px;'>
								<tr class='grp2'>
									<td colspan='$num_cols'>
										<div style='width:600px;font-size:12px;font-weight:normal;padding:5px 0 10px;color:#A00000;'>
											" . htmlentities($query_executed, ENT_QUOTES) . "
										</div>
									</td>
								</tr>
								<tr class='hdr2' style='white-space:normal;'>";
			
		// Display column names as table headers
		for ($i = 0; $i < $num_cols; $i++) {			
			$this_fieldname = db_field_name($q,$i);			
			//Display the Label and Field name
			$display_result .= "	<td style='padding:5px;font-size:10px;'>$this_fieldname</td>";
		}			
		$display_result .= "    </tr>";	
		
		// Display each table row
		$j = 1;
		while ($row = db_fetch_array($q)) 
		{
			$class = ($j%2==1) ? "odd" : "even";
			$display_result .= "<tr class='$class'>";			
			for ($i = 0; $i < $num_cols; $i++) 
			{
				// Display value
				if ($row[$i] === null) {
					$this_display = $this_value = "<i style='color:#aaa;'>NULL</i>";
				} else {
					$this_value = nl2br(htmlspecialchars($row[$i], ENT_QUOTES));
					if (strlen($this_value) > 200) {
						$this_display = "<div class='rcp'>
											" . substr($this_value, 0, strpos(wordwrap($this_value, 200), "\n")) . "<br>
											(<a href='javascript:showMore();'>...show more</a>)
										 </div>
										 <div class='rcf'>$this_value</div>";
					} else {
						$this_display = $this_value;
						// Foreign Key linkage: Get this column's table and field name
						if (isset($foreign_key_array[$query_field_info[$i]->orgtable][$query_field_info[$i]->orgname])) {
							$ref_table = $foreign_key_array[$query_field_info[$i]->orgtable][$query_field_info[$i]->orgname]['ref_table'];
							$ref_field = $foreign_key_array[$query_field_info[$i]->orgtable][$query_field_info[$i]->orgname]['ref_field'];
							// Make value into link to other table
							$this_display = "<a href='$baseUrl&table=$ref_table&field=$ref_field&value=".htmlspecialchars($this_display, ENT_QUOTES)."'>$this_display</a>";
						}
					}
				}
				// Cell contents
				$display_result .= "<td class='query_cell'>$this_display</td>";
			}			
			$display_result .= "</tr>";
			$j++;
		}
		// If returned nothing
		if ($j == 1)
		{
			$display_result .= "<tr class='$class'>
									<td colspan='$num_cols' style='color:#777;padding:3px;border-top:1px solid #CCCCCC;font-size:10px;'>
										Zero rows returned
									</td>
								</tr>";
		
		}
			
		$display_result .= "</table>";
	}
}











?>

<table style="width:100%;">
	<tr>
		<td valign="top" id="west2">
			<!-- TABLE MENU -->
			<div style="width:95%;">
				<div style="font-weight:bold;padding:0 3px 5px 0;">Saved Queries:</div>
				<?php if (sizeof($customQueries)){ ?>
				<ol style="margin-bottom:0;padding-inline-start:15px;">
					<?php foreach ($customQueries as $key => $cattr) { ?>
					<li style="line-height:12px;margin:3px 0;font-size:10px;font-family:tahoma;">
						<a href="javascript:;" style="text-decoration:underline;font-size:10px;font-family:tahoma;" onclick="loadCustomQuery(<?=$key?>);"><?php echo htmlspecialchars($cattr['title'], ENT_QUOTES, 'UTF-8') ?></a>
					</li>
					<?php } ?>
				</ol>
				<?php } else { ?>
					<a href="<?=APP_URL_EXTMOD?>manager/control_center.php" style="text-decoration:underline;font-size:11px;font-family:tahoma;color:#A00000;line-height:1.3;display:inline-block;">Add saved queries using the<br>EM Configure dialog</a>
				<?php } ?>
				<hr>
				<div style="font-weight:bold;padding:0 3px 5px 0;">REDCap database tables:</div>
				<?php foreach ($table_list as $this_table) { ?>
				<div style="padding-left:5px;line-height:12px;">
					<a href="javascript:;" style="text-decoration:underline;font-size:10px;font-family:tahoma;" onclick="window.location.href='<?php echo $baseUrl ?>&table=<?php echo $this_table ?>';"><?php echo $this_table ?></a>
				</div>
				<?php } ?>
			</div>
		</td>
		<td valign="top" id="center2">
			<!-- MAIN WINDOW -->
			<div style="font-weight:bold;margin-bottom:2px;">SQL Query:</div>
			<form action="<?php echo $baseUrl ?>" enctype="multipart/form-data" target="_self" method="post" name="form" id="form">
				<textarea id="query" name="query" style="resize:auto;width:100%;max-width:600px;font-size:13px;height:150px;padding:5px;" placeholder="select * from redcap_config"><?php echo htmlentities($query, ENT_QUOTES) ?></textarea>
				<div class="">
					<button class="btn btn-sm btn-primaryrc" onclick="showProgress(1,1);$('#form').submit();">Execute</button>
				</div>
			</form>
			<!-- RESULT -->
			<?php if ($display_result != "") echo "<div style='padding:20px 0;margin-top:30px;border-top:1px solid #aaa;'>$display_result</div>"; ?>		
		</td>
	</tr>
</table>
</div>

<?php
include APP_PATH_DOCROOT . 'ControlCenter/footer.php';
