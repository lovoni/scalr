{include file="inc/header.tpl"}
<link rel="stylesheet" href="css/grids.css" type="text/css" />
<div id="maingrid-ct" class="ux-gridviewer"></div>
<script type="text/javascript">

	var farm_status = '{$farm_status}';

{literal}
Ext.onReady(function () {
	// create the Data Store
    var store = new Ext.ux.scalr.Store({
    	reader: new Ext.ux.scalr.JsonReader({
	        root: 'data',
	        successProperty: 'success',
	        errorProperty: 'error',
	        totalProperty: 'total',
	        id: 'id',
	        	
	        fields: [
				{name: 'id', type: 'int'},
				'name', 'avail_zone', 'min_count', 'max_count', 'min_LA', 'max_LA', 'r_instances', 'sites','use_elastic_ips',
				'use_ebs', 'ami_id', 'farmid','shortcuts'
	        ]
    	}),
    	remoteSort: true,
		url: '/server/grids/farm_roles_list.php?a=1{/literal}{$grid_query_string}&farmid={$farmid}{literal}',
		listeners: { dataexception: Ext.ux.dataExceptionReporter }
    });
	
	function ebsRenderer(value, p, record) {
		if (value == 1)
			return '<img src="/images/true.gif"> [<a href="ebs_manage.php?role='+record.data.name+'&farmid='+record.data.farmid+'">View</a>]';
		else
			return '<img src="/images/false.gif">';
	}

	function eipRenderer(value, p, record) {
		if (value == 1)
			return '<img src="/images/true.gif"> [<a href="elastic_ips.php?role='+record.data.name+'&farmid='+record.data.farmid+'">View</a>]';
		else
			return '<img src="/images/false.gif">';
	}
	
	function sitesRenderer(value, p, record) {
		return value+' [<a href="/sites_view.php?ami_id='+record.data.ami_id+'">View</a>]';
	}

	
	function instancesRenderer(value, p, record) {
		return value+' [<a href="/instances_view.php?state=Running&farmid='+record.data.farmid+'&farm_roleid='+record.data.id+'">View</a>]';
	}
    	
    var renderers = Ext.ux.scalr.GridViewer.columnRenderers;
	var grid = new Ext.ux.scalr.GridViewer({
        renderTo: "maingrid-ct",
        height: 500,
        title: "Farm roles",
        id: 'farm_roles_list_'+GRID_VERSION,
        store: store,
        maximize: true,
        viewConfig: { 
        	emptyText: "No clients defined"
        },
		
        // Columns
        columns:[
			{header: "Role name", width: 60, dataIndex: 'name', sortable: false},
			{header: "Placement", width: 30, dataIndex: 'avail_zone', sortable: false},
			{header: "Min instances", width: 30, dataIndex: 'min_count', sortable: false, align:'center'},
			{header: "Max instances", width: 30, dataIndex: 'max_count', sortable: false, align:'center'},
			{header: "Running instances", width: 30, dataIndex: 'r_instances', renderer:instancesRenderer, sortable: false},
			{header: "Applications", width: 30, dataIndex: 'sites', renderer:sitesRenderer, sortable: false},
			{header: "Elastic IPs", width: 30, dataIndex: 'use_elastic_ips', renderer:eipRenderer, sortable: false},
			{header: "EBS", width: 30, dataIndex: 'use_ebs', renderer:ebsRenderer, sortable: false}
		],
		
    	// Row menu
    	rowOptionsMenu: [
			{id: "option.cfg", 			text:'Configure', 			  			href: "/farms_add.php?id={farmid}&ami_id={ami_id}&configure=1"},
			{id: "option.stat", 		text:'View statistics', 			  	href: "/farm_stats.php?role={name}&farmid={farmid}"},
			new Ext.menu.Separator({id: "option.mainSep"}),
			{id: "option.exec", 		text: 'Execute script', 				href: "/execute_script.php?ami_id={ami_id}&farmid={farmid}"},
			new Ext.menu.Separator({id: "option.eSep"}),
			{id: "option.sgEdit", 		text: 'Edit security group', 			href: "/sec_group_edit.php?role_name={name}"},
			new Ext.menu.Separator({id: "option.sgSep"}),
			{id: "option.launch", 		text: 'Launch new instance', 			href: "/roles_view.php?farmid={farmid}&task=launch_new_instance&ami_id={ami_id}"},
			
			new Ext.menu.Separator({id: "option.scSep"})
     	],

     	getRowOptionVisibility: function (item, record) {
			var data = record.data;

			if (item.id == "option.scSep")
				return (data.shortcuts.length > 0);
			
			if (item.id == 'option.stat' || item.id == 'option.cfg')
			{
				return true;
			}
			else
			{
				if (farm_status == 1)
					return true;
				else
					return false;
			}
			
			return true;
		},

		getRowMenuVisibility: function (record) {
			return true;
		},
		listeners:{
			'beforeshowoptions': {fn: function (grid, record, romenu, ev) {
				romenu.record = record;
				var data = record.data;

				var rows = romenu.items.items;
				for (k in rows)
				{
					if (rows[k].isshortcut == 1)
						romenu.remove(rows[k]);
				}

				if (data.shortcuts.length > 0)
				{
					for (i in data.shortcuts)
					{
						if (typeof(data.shortcuts[i]) != 'function')
						{
							romenu.add({
								id:'option.'+(Math.random()*100000),
								isshortcut:1,
								text:'Execute '+data.shortcuts[i].name,
								href:'execute_script.php?farmid='+data.shortcuts[i].farmid+'&task=execute&script='+data.shortcuts[i].event_name
							});
						}
					}
				}
				else
				{
					var rows = romenu.items.items;
					for (k in rows)
					{
						if (rows[k].isshortcut == 1)
							romenu.remove(rows[k]);
					}
				}
			}}
		}
    });
    grid.render();
    store.load();

	return;
});
{/literal}
</script>
{include file="inc/footer.tpl"}