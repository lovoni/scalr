{include file="inc/header.tpl"}
	<script language="Javascript">
	
	var instance_id = '{$iid}';
	
	{literal}
	var TableLoader = Class.create();
    TableLoader.prototype = {
    	
    	initialize:function()
		{
		
		},
		
		Load: function(contaner_name, uri)
		{
			var url = '/server/server.php?'+uri; 
		
			$('table_loader').style.display = '';
			$('table_body_list').style.display = 'none';
			$('table_refresh_icon').style.display = 'none';
		
			new Ajax.Request(url,
			{ 
				method: 'get',
				contaner_name: contaner_name, 
				onSuccess: function(transport)
				{ 
					try
					{
						$(transport.request.options.contaner_name).update(transport.responseText);
						$('table_loader').style.display = 'none';
						$('table_body_list').style.display = '';
						$('table_refresh_icon').style.display = '';
						
						window.setTimeout('webtacp.reloadTables()', 1000);
					}
					catch(e)
					{
						alert(e.message);
					}					 
				} 
			});
		}
    };
	
	var tb = new TableLoader();
	
	Event.observe(window, 'load', function(){
		tb.Load('table_body_list','_cmd=get_instance_process_list&iid='+instance_id);
	});
	
	function ReloadPage() {
		tb.Load('table_body_list','_cmd=get_instance_process_list&iid='+instance_id);
	};
	
	</script>
	{/literal}
    {include file="inc/table_header.tpl" show_reload_icon=1 reload_action='ReloadPage();' nofilter=1}
    <table class="Webta_Items" rules="groups" frame="box" cellpadding="4" id="Webta_Items">
	<thead>
		<tr>
			<th>Process</th>
			<!-- <th>CPU Usage</th>  -->
			<th width="150">RAM Usage</th>
			<th width="150">Type</th>
			<th width="150" nowrap>Status</th>
		</tr>
	</thead>
	<tbody>
		<tr id="table_loader">
			<td colspan="30" align="center">
				<img style="vertical-align:middle;" src="/images/snake-loader.gif"> Loading process list. Please wait...
			</td>
		</tr>
	</tbody>
	<tbody id="table_body_list">
	
	</tbody>
	</table>
	{include file="inc/table_footer.tpl" disable_footer_line=1}	
{include file="inc/footer.tpl"}