<form id="form_gunit" method="post" action="<?php echo $base_url; ?>testpanel/taskcreate">
	<fieldset>
		<legend>Task Form</legend>
		
		<ul>
		<li>work_id, instance_id, work_keyword, work_domain, work_country, work_stop, work_status, work_mutex, work_commit, work_datetime, work_last</li>
		<li>instance_id: <input type="text" name="instance_id" value="<?php echo $instance_id; ?>" readonly="readonly"/></li>
		<li>work_id : <input type="text" name="work_id" readonly="readonly" /></li>
		<li>work_keyword : <input type="text" name="work_keyword" /></li>
		<li>work_domain : <input type="text" name="work_domain" /></li>
		<li>work_country : <input type="text" name="work_country" /></li>
		<li>work_stop : <input type="text" name="work_stop" value="20" /> (ex: 20)</li>
		<li>work_status : <input type="text" name="work_status" readonly="readonly" /></li>
		<li>work_mutex : <input type="text" name="work_status" readonly="readonly" /></li>
		<li>work_datetime : <input type="text" name="work_datetime" readonly="readonly" /></li>
		<li>work_last : <input type="text" name="work_last" readonly="readonly" /></li>
		</ul>

		<button type="submit">전송</button>
	</fieldset>
</form>

<h2>Results</h2>
<textarea id="results" style="width: 96%; height: 800px; font-size: 70%;"></textarea>

<h2>Previous Works List</h2>
<table border="1" cellspacing="0">
	<tbody>
		<tr>
<?php
			foreach($datafields as $k=>$v) {
?>
			<th><?php echo $v; ?></th>
<?php
			}
?>
		</tr>
<?php
		foreach($results as $row) {
?>
		<tr>
<?php
			foreach($datafields as $k=>$v) {
?>
			<th><?php echo $row->{$k}; ?></th>
<?php
			}
?>
		</tr>
<?php
		}
?>
	</tbody>
</table>
